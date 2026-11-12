<?php

namespace App\Jobs;

use App\Models\LogPushDestination;
use App\Models\Operation;
use App\Support\LogPush\LogPushSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Sends one synthetic line to a destination and records the outcome on the operation the caller polls. The cursor and counters are untouched. */
class TestLogPushDestination implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public string $operationId)
    {
        $this->onQueue('log-push');
    }

    public function handle(LogPushSender $sender): void
    {
        $operation = Operation::query()->findOrFail($this->operationId);
        $operation->update(['status' => 'running', 'started_at' => now(), 'attempts' => $operation->attempts + 1]);
        $destination = LogPushDestination::query()->find($operation->input['destination_id'] ?? '');
        if ($destination === null) {
            $operation->update(['status' => 'failed', 'error' => 'The destination no longer exists.', 'finished_at' => now()]);

            return;
        }
        try {
            $line = json_encode(['dataset' => 'test', 'message' => 'CDNFoundry log push test', 'occurred_at' => now()->toIso8601String()])."\n";
            $sender->send($destination, 'test', (string) gzencode($line, 6), '00000000');
            $operation->update(['status' => 'succeeded', 'result' => ['destination_id' => $destination->id, 'delivered' => true], 'error' => null, 'finished_at' => now()]);
        } catch (Throwable $exception) {
            $operation->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 500), 'finished_at' => now()]);
        }
    }
}
