<?php

namespace Tests\Unit;

use App\Logging\OperationalJsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

class OperationalJsonFormatterTest extends TestCase
{
    public function test_it_emits_only_bounded_allowlisted_context_and_preserves_exception_stack(): void
    {
        $formatter = new OperationalJsonFormatter;
        $record = new LogRecord(
            datetime: now()->toDateTimeImmutable(),
            channel: 'test',
            level: Level::Error,
            message: 'Candidate validation failed',
            context: [
                'event' => 'runtime_activation_failed',
                'domain_id' => 42,
                'authorization' => 'Bearer must-not-appear',
                'request_body' => ['password' => 'must-not-appear'],
                'exception' => new \RuntimeException('bounded failure'),
            ],
        );

        $encoded = $formatter->format($record);
        $decoded = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('error', $decoded['level']);
        $this->assertSame('runtime_activation_failed', $decoded['event']);
        $this->assertSame(42, $decoded['domain_id']);
        $this->assertStringContainsString('OperationalJsonFormatterTest', $decoded['stack_trace']);
        $this->assertStringNotContainsString('must-not-appear', $encoded);
        $this->assertArrayNotHasKey('authorization', $decoded);
        $this->assertArrayNotHasKey('request_body', $decoded);
    }
}
