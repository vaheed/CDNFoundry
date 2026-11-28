<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Edge;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The scheduler runs 22 jobs every minute. Each artisan command that does is run on an idle database, then again with hundreds of domains
 * and edges that need nothing done, and its queries are counted. A command whose queries grow with the number of idle domains is an
 * amplifier (one query per domain a minute); a command over its fixed bound is unnecessary work. The measured counts are printed.
 */
class SchedulerIdleCostTest extends TestCase
{
    use RefreshDatabase;

    /** The most queries one per-minute command may run on an idle database, and the most it may add for 300 idle domains and 20 edges. */
    private const FIXED_BOUND = 25;

    private const GROWTH_BOUND = 12;

    /** The health publisher evaluates every component once a minute (about 42 queries, none per domain); it has its own bound. */
    private const OWN_BOUND = ['cdnf:health:publish' => 60];

    /** @return list<string> */
    private function everyMinuteCommands(): array
    {
        return collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => $event->expression === '* * * * *' && str_contains((string) $event->command, 'artisan'))
            ->map(fn (Event $event): string => trim((string) preg_replace('/^.*artisan[\'"]?\s+/', '', (string) $event->command), "'\" "))
            ->unique()->values()->all();
    }

    private function queries(string $command): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        try {
            Artisan::call($command);
        } catch (\Throwable) {
            // A command that needs a service this test does not run (ClickHouse, PowerDNS) still ran its database work first.
        }

        return $count;
    }

    public function test_per_minute_commands_do_a_bounded_amount_of_idle_work_that_does_not_grow_with_idle_domains(): void
    {
        $commands = $this->everyMinuteCommands();
        $this->assertGreaterThanOrEqual(15, count($commands), 'the per-minute commands were found');
        $idle = [];
        foreach ($commands as $command) {
            $idle[$command] = $this->queries($command);
        }
        foreach (range(1, 300) as $index) {
            Domain::query()->create(['name' => "idle-$index.example", 'display_name' => "Idle $index", 'lifecycle_state' => 'active', 'revision' => 1, 'nameservers_verified_at' => now()]);
        }
        foreach (range(1, 20) as $index) {
            Edge::query()->create(['name' => "idle-edge-$index", 'country_code' => 'IR', 'continent_code' => 'AS', 'enabled' => true, 'registered_at' => now(), 'last_heartbeat_at' => now()]);
        }
        $loaded = [];
        foreach ($commands as $command) {
            $loaded[$command] = $this->queries($command);
        }
        fwrite(STDERR, "\nper-minute command: queries idle -> with 300 idle domains and 20 edges\n");
        foreach ($commands as $command) {
            fwrite(STDERR, sprintf("  %-55s %3d -> %3d\n", $command, $idle[$command], $loaded[$command]));
        }
        fwrite(STDERR, sprintf("  %-55s %3d -> %3d\n", 'total', array_sum($idle), array_sum($loaded)));
        foreach ($commands as $command) {
            $this->assertLessThanOrEqual(self::OWN_BOUND[$command] ?? self::FIXED_BOUND, $idle[$command], "$command runs too many queries when nothing needs doing");
            $this->assertLessThanOrEqual($idle[$command] + self::GROWTH_BOUND, $loaded[$command], "$command grows with the number of idle domains and edges");
        }
    }
}
