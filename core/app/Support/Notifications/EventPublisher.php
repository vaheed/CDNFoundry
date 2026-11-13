<?php

namespace App\Support\Notifications;

use App\Jobs\DeliverNotification;
use App\Models\Domain;
use App\Models\NotificationChannel;
use App\Support\PlatformSettings;
use Illuminate\Support\Collection;

/**
 * Turns a platform event into one queued delivery per matching channel. Administrator channels receive every event;
 * a domain user's channel receives a domain event only for a domain assigned to that user, and only while the
 * administrator switch is on. Nothing here sends: delivery runs on the notifier's own queue lane.
 */
final class EventPublisher
{
    /** Hard ceiling on deliveries from one event, so a mass assignment cannot flood the queue. */
    private const MAX_RECIPIENTS = 500;

    public static function publish(string $event, ?Domain $domain, string $title, string $body = '', ?NotificationChannel $only = null): int
    {
        if (! array_key_exists($event, NotificationEvents::all())) {
            throw new \InvalidArgumentException("Unknown notification event: {$event}");
        }
        $message = [
            'event' => $event, 'title' => mb_substr($title, 0, 200), 'body' => mb_substr($body, 0, 1000),
            'domain' => $domain?->name, 'occurred_at' => now()->toIso8601String(),
        ];
        $queued = 0;
        foreach (self::recipients($event, $domain, $only) as $channel) {
            DeliverNotification::dispatch($channel->id, $message)->afterCommit();
            $queued++;
        }

        return $queued;
    }

    /**
     * The active administrator channels: one bounded indexed query. Not cached: the cache store refuses to unserialize
     * objects (serializable_classes is false), so a cached collection comes back as an incomplete class.
     */
    private static function platformChannels(): Collection
    {
        return NotificationChannel::query()->whereNull('user_id')->where('status', 'active')->limit(self::MAX_RECIPIENTS)->get();
    }

    /** @return iterable<NotificationChannel> */
    public static function recipients(string $event, ?Domain $domain, ?NotificationChannel $only = null): iterable
    {
        if ($only !== null) {
            return [$only];
        }
        $channels = self::platformChannels();
        if ($domain !== null && NotificationEvents::isDomainEvent($event) && app(PlatformSettings::class)->get('notifications', 'domain_user_channels') === true) {
            $assigned = $domain->users()->pluck('users.id');
            $channels = $channels->concat(
                NotificationChannel::query()->whereIn('user_id', $assigned)->where('status', 'active')->with('user')->limit(self::MAX_RECIPIENTS)->get()
                    ->reject(fn (NotificationChannel $channel): bool => $channel->user === null || $channel->user->isDisabled()),
            );
        }

        return $channels->filter(fn (NotificationChannel $channel): bool => $channel->wantsEvent($event))->values();
    }
}
