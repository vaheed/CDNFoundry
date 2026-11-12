<?php

namespace App\Http\Controllers;

use App\Support\Notifications\EventPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The private receiver Alertmanager posts to. It is reachable only with the monitoring token, answers 404 otherwise, and
 * queues one administrator notification per payload. The always-firing Watchdog is recorded as a heartbeat, never sent.
 */
class AlertmanagerReceiverController extends Controller
{
    /** Alertmanager groups alerts, so one payload can hold thousands (one per domain). Only these are read in detail; the rest are counted. */
    private const DETAILED_ALERTS = 100;

    public function __invoke(Request $request): JsonResponse
    {
        $configured = (string) config('services.metrics.token');
        $tokenFile = config('services.metrics.token_file');
        if ($configured === '' && is_string($tokenFile) && is_readable($tokenFile)) {
            $configured = trim((string) file_get_contents($tokenFile));
        }
        abort_if($configured === '' || ! hash_equals($configured, (string) $request->bearerToken()), 404);
        // Alertmanager repeats a group on its own schedule; an identical payload inside a minute adds nothing.
        if (! Cache::add('notifications:alert:'.sha1($request->getContent()), 1, 60)) {
            return response()->json(['data' => ['queued' => 0, 'duplicate' => true]]);
        }
        $request->headers->set('Accept', 'application/json');
        $payload = $request->validate([
            'status' => ['required', 'in:firing,resolved'],
            'alerts' => ['required', 'array', 'min:1', 'max:20000'],
            'alerts.*.status' => ['required', 'in:firing,resolved'],
            'alerts.*.labels' => ['required', 'array', 'max:40'],
            'alerts.*.labels.alertname' => ['required', 'string', 'max:200'],
            'alerts.*.annotations' => ['sometimes', 'array', 'max:40'],
        ]);
        $total = count($payload['alerts']);
        $alerts = collect(array_slice($payload['alerts'], 0, self::DETAILED_ALERTS));
        if ($alerts->every(fn (array $alert): bool => $alert['labels']['alertname'] === 'Watchdog')) {
            Cache::put('notifications:watchdog_received_at', now()->toIso8601String(), now()->addDay());

            return response()->json(['data' => ['queued' => 0, 'watchdog' => true]]);
        }
        $alerts = $alerts->reject(fn (array $alert): bool => $alert['labels']['alertname'] === 'Watchdog')->values();
        $state = $payload['status'] === 'resolved' ? 'Resolved' : 'Firing';
        $names = $alerts->pluck('labels.alertname')->unique()->values();
        $title = "{$state}: ".$names->take(3)->implode(', ').($names->count() > 3 ? ' and '.($names->count() - 3).' more' : '').($total > 1 ? " ({$total} alerts)" : '');
        $body = $alerts->take(10)->map(fn (array $alert): string => '- '.mb_substr((string) ($alert['annotations']['summary'] ?? $alert['annotations']['description'] ?? $alert['labels']['alertname']), 0, 200))->implode("\n");
        $queued = EventPublisher::publish('alert.firing', null, $title, $body);

        return response()->json(['data' => ['queued' => $queued]]);
    }
}
