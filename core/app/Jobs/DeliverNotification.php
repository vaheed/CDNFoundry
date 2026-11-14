<?php

namespace App\Jobs;

use App\Models\NotificationChannel;
use App\Notifications\PlatformEvent;
use App\Support\PlatformSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Sends one message to one channel from the notifier's own queue lane. A slow or failing provider only delays its own
 * channel: retries back off, repeats beyond the per-minute limit merge into one digest, and a channel that keeps
 * failing is paused with its reason.
 */
class DeliverNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [10, 30, 120, 600];

    /** @param array{event: string, title: string, body: string, domain: ?string, occurred_at: string, count?: int} $message */
    public function __construct(public string $channelId, public array $message, public bool $bypassLimit = false)
    {
        $this->onQueue('notifications');
    }

    public function handle(PlatformSettings $settings): void
    {
        $channel = NotificationChannel::query()->find($this->channelId);
        if ($channel === null || ! self::mayDeliver($channel, $settings)) {
            return;
        }
        $message = $this->message;
        if ($message['event'] === 'digest') {
            $message = $this->digest();
            if ($message === null) {
                return;
            }
        } elseif (! $this->bypassLimit && ! RateLimiter::attempt("notify:{$channel->id}", $settings->integer('notifications', 'channel_messages_per_minute'), fn (): bool => true, 60)) {
            $this->buffer($channel);

            return;
        }
        $channel->notifyNow(new PlatformEvent($message));
        // Success is recorded at most every ten minutes, so a busy channel costs no database write per message.
        if ($channel->consecutive_failures > 0 || $channel->last_success_at === null || $channel->last_success_at->lt(now()->subMinutes(10))) {
            $channel->forceFill(['consecutive_failures' => 0, 'last_success_at' => now(), 'last_error' => null])->save();
        }
    }

    /** A paused channel sends nothing, and a user channel stops while the administrator switch is off or the user is disabled. */
    public static function mayDeliver(NotificationChannel $channel, PlatformSettings $settings): bool
    {
        if ($channel->status !== 'active') {
            return false;
        }
        if ($channel->isPlatform()) {
            return true;
        }

        return $settings->get('notifications', 'domain_user_channels') === true && $channel->user !== null && ! $channel->user->isDisabled();
    }

    public function failed(Throwable $exception): void
    {
        $channel = NotificationChannel::query()->find($this->channelId);
        if ($channel === null) {
            return;
        }
        $failures = $channel->consecutive_failures + 1;
        $reason = mb_substr($exception->getMessage(), 0, 500);
        $attributes = ['consecutive_failures' => $failures, 'last_failure_at' => now(), 'last_error' => $reason];
        if ($failures >= app(PlatformSettings::class)->integer('notifications', 'pause_after_failures')) {
            $attributes += ['status' => 'paused', 'paused_reason' => "Paused after {$failures} failed deliveries in a row. Last error: {$reason}"];
        }
        $channel->forceFill($attributes)->save();
    }

    /** Events beyond the per-minute limit wait in a small bounded buffer and leave as one digest message. */
    private function buffer(NotificationChannel $channel): void
    {
        $key = "notify:digest:{$channel->id}";
        $buffered = Cache::get($key, ['count' => 0, 'titles' => []]);
        $buffered['count']++;
        if (count($buffered['titles']) < 10) {
            $buffered['titles'][] = $this->message['title'];
        }
        Cache::put($key, $buffered, now()->addMinutes(10));
        if ($buffered['count'] === 1) {
            self::dispatch($channel->id, ['event' => 'digest'] + $this->message, true)->delay(now()->addSeconds(60));
        }
    }

    /** The buffered summary a delayed digest job sends, or null when nothing is waiting. */
    private function digest(): ?array
    {
        $buffered = Cache::pull("notify:digest:{$this->channelId}", ['count' => 0, 'titles' => []]);

        return $buffered['count'] === 0 ? null : [
            'event' => 'digest', 'title' => "{$buffered['count']} more notifications", 'body' => implode("\n", $buffered['titles']),
            'domain' => null, 'occurred_at' => now()->toIso8601String(), 'count' => $buffered['count'],
        ];
    }
}
