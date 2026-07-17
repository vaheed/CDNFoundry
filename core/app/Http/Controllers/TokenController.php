<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class TokenController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tokens = $request->user()->tokens()->latest('id')->cursorPaginate(50);

        return response()->json($tokens->through(fn (PersonalAccessToken $token): array => [
            'id' => $token->id, 'name' => $token->name, 'token_last_six' => $token->token_last_six, 'last_used_at' => $token->last_used_at?->toIso8601String(), 'created_at' => $token->created_at?->toIso8601String(),
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $created = $request->user()->createToken($validated['name']);
        $created->accessToken->forceFill(['token_last_six' => substr($created->plainTextToken, -6)])->save();
        AuditLog::record($request->user(), 'token.created', $request->user(), ['token_id' => $created->accessToken->id], $request->ip());

        return response()->json(['data' => ['id' => $created->accessToken->id, 'name' => $validated['name'], 'token' => $created->plainTextToken]], 201);
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
