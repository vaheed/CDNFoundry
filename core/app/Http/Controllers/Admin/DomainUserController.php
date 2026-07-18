<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class DomainUserController extends Controller
{
    public function index(Domain $domain): AnonymousResourceCollection
    {
        return UserResource::collection($domain->users()->orderBy('users.id')->cursorPaginate(50));
    }

    public function store(Request $request, Domain $domain): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('type', UserType::User->value)->whereNull('disabled_at')],
        ]);
        $attached = $domain->users()->syncWithoutDetaching([$validated['user_id']]);
        if ($attached['attached'] !== []) {
            AuditLog::record($request->user(), 'domain.user_assigned', $domain, ['user_id' => $validated['user_id']], $request->ip());
        }

        return response()->json(['data' => UserResource::make(User::findOrFail($validated['user_id']))], $attached['attached'] === [] ? 200 : 201);
    }

    public function destroy(Request $request, Domain $domain, User $user): JsonResponse
    {
        if ($domain->users()->detach($user->getKey()) > 0) {
            AuditLog::record($request->user(), 'domain.user_unassigned', $domain, ['user_id' => $user->getKey()], $request->ip());
        }

        return response()->json(null, 204);
    }
}
