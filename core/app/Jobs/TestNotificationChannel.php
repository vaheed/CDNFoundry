<?php

namespace App\Jobs;

use App\Models\NotificationChannel;
use App\Models\Operation;
use App\Notifications\PlatformEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Sends one test message to a channel and records the outcome on the operation the caller polls. A test is never rate limited and never pauses a channel. */
class TestNotificationChannel implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public string $operationId)
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $operation = Operation::query()->findOrFail($this->operationId);
        $operation->update(['status' => 'running', 'started_at' => now(), 'attempts' => $operation->attempts + 1]);
        $channel = NotificationChannel::query()->find($operation->input['channel_id'] ?? '');
        if ($channel === null) {
            $operation->update(['status' => 'failed', 'error' => 'The channel no longer exists.', 'finished_at' => now()]);

            return;
        }
        try {
            $channel->notifyNow(new PlatformEvent([
                'event' => 'test', 'title' => 'Test notification', 'body' => 'This message confirms that the channel can deliver.',
                'domain' => null, 'occurred_at' => now()->toIso8601String(),
            ]));
            $operation->update(['status' => 'succeeded', 'result' => ['channel_id' => $channel->id, 'delivered' => true], 'error' => null, 'finished_at' => now()]);
        } catch (Throwable $exception) {
            $operation->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 500), 'finished_at' => now()]);
        }
    }
}
