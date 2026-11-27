<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Support\AnalyticsStore;
use App\Support\AttackEpisodes;
use App\Support\SecurityAlertData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SecurityEpisodeController extends Controller
{
    /** Attack episodes of the last 24 hours (or the range given inside the 7-day event retention), newest first. */
    public function index(Request $request, Domain $domain, AnalyticsStore $store): JsonResponse
    {
        Gate::authorize('view', $domain);
        $request->merge(['from' => $request->query('from') ?? now('UTC')->subDay()->addMinute()->toIso8601String()]);
        $range = $store->range($request, true);

        return response()->json(['data' => AttackEpisodes::describe($domain, $range, $store), 'meta' => [
            ...$store->metadata($range), 'retention_days' => 7, 'maximum_episodes' => AnalyticsStore::EPISODES_LISTED,
            'minimum_blocked' => AttackEpisodes::MINIMUM_BLOCKED, 'quiet_minutes' => SecurityAlertData::EPISODE_QUIET_MINUTES,
        ]]);
    }

    /** The client keys that were blocked most in the range; a key is the keyed hash of one client, never its address. */
    public function clientKeys(Request $request, Domain $domain, AnalyticsStore $store): JsonResponse
    {
        Gate::authorize('view', $domain);
        $request->merge(['from' => $request->query('from') ?? now('UTC')->subDay()->addMinute()->toIso8601String()]);
        $range = $store->range($request, true);

        return response()->json(['data' => $store->topBlocked($domain, $range, 'client_key', 20), 'meta' => [...$store->metadata($range), 'retention_days' => 7, 'maximum_rows' => 20]]);
    }

    /**
     * Client keys that were blocked and then solved a challenge: candidates for a rule that is too broad. They are candidates, not
     * proof. The bot and reputation breakdowns are not offered because the cell does not log those signals.
     */
    public function falsePositiveCandidates(Request $request, Domain $domain, AnalyticsStore $store): JsonResponse
    {
        Gate::authorize('view', $domain);
        $request->merge(['from' => $request->query('from') ?? now('UTC')->subDay()->addMinute()->toIso8601String()]);
        $range = $store->range($request, true);

        return response()->json(['data' => $store->falsePositiveCandidates($domain, $range), 'meta' => [...$store->metadata($range), 'retention_days' => 7, 'maximum_rows' => AnalyticsStore::CANDIDATES_LISTED,
            'unavailable' => ['bot_breakdown' => 'The cell does not log bot signals.', 'reputation_breakdown' => 'Reputation runs in shadow mode and is not logged.']]]);
    }
}
