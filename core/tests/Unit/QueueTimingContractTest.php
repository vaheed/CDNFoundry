<?php

namespace Tests\Unit;

use Tests\TestCase;

class QueueTimingContractTest extends TestCase
{
    public function test_retry_after_exceeds_every_worker_and_job_timeout(): void
    {
        $retryAfter = (int) config('queue.connections.redis.retry_after');
        $timeouts = [];
        foreach (config('horizon.defaults') as $name => $supervisor) {
            $timeouts["supervisor {$name}"] = (int) $supervisor['timeout'];
        }
        foreach (glob(app_path('Jobs/*.php')) as $file) {
            if (preg_match('/public int \$timeout = (\d+);/', (string) file_get_contents($file), $match)) {
                $timeouts['job '.basename($file, '.php')] = (int) $match[1];
            }
        }

        foreach ($timeouts as $owner => $timeout) {
            $this->assertGreaterThan($timeout, $retryAfter, "retry_after {$retryAfter} must exceed the {$owner} timeout of {$timeout} s.");
        }
    }
}
