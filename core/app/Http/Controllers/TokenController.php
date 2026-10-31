<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

class TokenController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tokens = $request->user()->tokens()->latest('id')->cursorPaginate(50);

        return response()->json($tokens->through(fn (ApiToken $token): array => [
            'id' => $token->id, 'name' => $token->name, 'token_last_six' => $token->token_last_six, 'abilities' => $token->abilities, 'expires_at' => $token->expires_at?->toIso8601String(), 'domain_ids' => $token->scopedDomainIds() ?? [], 'last_used_at' => $token->last_used_at?->toIso8601String(), 'created_at' => $token->created_at?->toIso8601String(),
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['sometimes', 'array', 'min:1', 'max:'.count(ApiToken::ABILITIES)],
            'abilities.*' => ['string', Rule::in(ApiToken::ABILITIES), 'distinct'],
            'expires_in_days' => ['sometimes', 'integer', 'min:1', 'max:'.ApiToken::MAX_LIFETIME_DAYS],
            'domain_ids' => ['sometimes', 'array', 'max:'.ApiToken::MAX_DOMAINS],
            'domain_ids.*' => ['integer', 'distinct'],
        ]);
        $abilities = $validated['abilities'] ?? ['*'];
        if (in_array('*', $abilities, true)) {
            $abilities = ['*'];
        }
        $domainIds = array_map('intval', $validated['domain_ids'] ?? []);
        foreach (Domain::query()->whereKey($domainIds)->get() as $domain) {
            Gate::authorize('view', $domain);
        }
        abort_unless(Domain::query()->whereKey($domainIds)->count() === count($domainIds), 422, 'Every domain in domain_ids must exist and be yours.');
        $expiresAt = now()->addDays($validated['expires_in_days'] ?? ApiToken::DEFAULT_LIFETIME_DAYS);
        $created = DB::transaction(function () use ($request, $validated, $abilities, $domainIds, $expiresAt): NewAccessToken {
            $created = $request->user()->createTokenWithinLimit($validated['name'], 'name', $abilities, $expiresAt, $domainIds);
            AuditLog::record($request->user(), 'token.created', $request->user(), ['token_id' => $created->accessToken->id], $request->ip());

            return $created;
        });

        return response()->json(['data' => ['id' => $created->accessToken->id, 'name' => $validated['name'], 'abilities' => $abilities, 'domain_ids' => $domainIds, 'expires_at' => $expiresAt->toIso8601String(), 'token' => $created->plainTextToken]], 201);
    }

    public function destroy(Request $request, PersonalAccessToken $token): JsonResponse
    {
        abort_unless($token->tokenable_id === $request->user()->getKey() && $token->tokenable_type === $request->user()->getMorphClass(), 404);
        $id = $token->id;
        $token->delete();
        AuditLog::record($request->user(), 'token.revoked', $request->user(), ['token_id' => $id], $request->ip());

        return response()->json(['data' => ['revoked' => true]]);
    }
}
