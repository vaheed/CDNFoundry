<?php

namespace App\Logging;

use App\Models\Domain;
use Illuminate\Support\Facades\Context;
use Monolog\LogRecord;

class OperationalContextProcessor
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = Context::only(['request_id', 'operation_id', 'job_id', 'domain_id']);

        if (app()->bound('request')) {
            $request = request();
            $route = $request->route();
            $domain = is_object($route) ? $route->parameter('domain') : null;
            $startedAt = Context::get('request_started_at');

            $extra['actor_id'] = $request->user()?->getAuthIdentifier();
            $extra['route'] = is_object($route) ? $route->getName() : null;
            $extra['domain_id'] ??= $domain instanceof Domain ? $domain->getKey() : null;
            $extra['duration_ms'] = is_int($startedAt)
                ? round((hrtime(true) - $startedAt) / 1_000_000, 3)
                : null;
        }

        return $record->with(extra: $extra);
    }
}
