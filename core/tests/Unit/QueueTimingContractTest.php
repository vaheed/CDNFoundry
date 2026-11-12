<?php

namespace Tests\Unit;

use App\Support\SystemHealth;
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

    public function test_every_job_runs_on_a_lane_with_a_supervisor_and_a_health_entry(): void
    {
        // The notifications lane has no Horizon supervisor: the notifier container is its only worker, so Horizon never needs egress to the providers, so Horizon never needs egress to the providers.
        $lanes = [...collect(config('horizon.defaults'))->flatMap(fn (array $supervisor) => $supervisor['queue'])->all(), 'notifications'];
        $this->assertEqualsCanonicalizing($lanes, SystemHealth::QUEUES);

        foreach (glob(app_path('Jobs/*.php')) as $file) {
            $this->assertSame(1, preg_match("/onQueue\\('(\\w+)'\\)/", (string) file_get_contents($file), $match), basename($file).' must name its lane.');
            $this->assertContains($match[1], $lanes, basename($file).' uses a lane no supervisor serves.');
        }
    }
}
