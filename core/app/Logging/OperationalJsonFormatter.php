<?php

namespace App\Logging;

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;
use Throwable;

class OperationalJsonFormatter implements FormatterInterface
{
    private const CONTEXT_KEYS = [
        'event', 'request_id', 'operation_id', 'job_id', 'task_id', 'domain_id',
        'edge_id', 'cell_id', 'revision_id', 'actor_id', 'route', 'duration_ms', 'error_code',
    ];

    public function format(LogRecord $record): string
    {
        $context = array_merge($record->context, $record->extra);
        $exception = $record->context['exception'] ?? null;
        $payload = [
            'timestamp' => $record->datetime->format('Y-m-d\TH:i:s.uP'),
            'level' => $this->normalizeLevel($record->level->getName()),
            'service' => 'core',
            'event' => $this->boundedScalar($context['event'] ?? 'application_log', 128),
            'message' => $this->boundedScalar($record->message, 16384),
        ];

        foreach (self::CONTEXT_KEYS as $key) {
            if ($key !== 'event') {
                $payload[$key] = $this->boundedScalar($context[$key] ?? null, 512);
            }
        }

        $payload['stack_trace'] = $exception instanceof Throwable
            ? mb_substr($exception->getTraceAsString(), 0, 65536)
            : null;

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n";
    }

    public function formatBatch(array $records): string
    {
        return implode('', array_map($this->format(...), $records));
    }

    private function normalizeLevel(string $level): string
    {
        return match (strtolower($level)) {
            'warn', 'warning' => 'warning',
            'err', 'error' => 'error',
            'emergency', 'alert', 'critical' => 'critical',
            'debug', 'notice' => strtolower($level),
            default => 'info',
        };
    }

    private function boundedScalar(mixed $value, int $limit): string|int|float|bool|null
    {
        if ($value === null || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        return is_string($value) ? mb_substr($value, 0, $limit) : null;
    }
}
