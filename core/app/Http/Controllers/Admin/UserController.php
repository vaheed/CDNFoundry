<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\DomainResource;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection(User::query()->orderBy('id')->cursorPaginate(50));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = User::query()->create($request->validated());
        AuditLog::record($request->user(), 'user.created', $user, ['type' => $user->type->value], $request->ip());

        return UserResource::make($user)->response()->setStatusCode(201);
    }

    public function show(User $user): UserResource
    {
        return UserResource::make($user);
    }

    public function domains(User $user): AnonymousResourceCollection
    {
        return DomainResource::collection($user->domains()->orderBy('domains.id')->cursorPaginate(50));
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        abort_if($user->is($request->user()) && $request->input('type') === 'user', 422, 'You cannot remove your own administrator access.');
        $user->update($request->validated());
        AuditLog::record($request->user(), 'user.updated', $user, ['fields' => array_keys($request->validated())], $request->ip());

        return UserResource::make($user->refresh());
    }

    public function disable(Request $request, User $user): UserResource
    {
        abort_if($user->is($request->user()), 422, 'You cannot disable your own account.');
        DB::transaction(function () use ($request, $user): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());
            if (! $locked->isDisabled()) {
                $locked->forceFill(['disabled_at' => now()])->save();
                $locked->tokens()->delete();
                AuditLog::record($request->user(), 'user.disabled', $locked, [], $request->ip());
            }
        });

        return UserResource::make($user->refresh());
    }

    public function enable(Request $request, User $user): UserResource
    {
        if ($user->isDisabled()) {
            $user->forceFill(['disabled_at' => null])->save();
            AuditLog::record($request->user(), 'user.enabled', $user, [], $request->ip());
        }

        return UserResource::make($user->refresh());
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_if($user->is($request->user()), 422, 'You cannot delete your own account.');
        DB::transaction(function () use ($request, $user): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());
            abort_if($locked->tokens()->exists(), 409, 'Revoke all tokens before deleting this user.');
            AuditLog::record($request->user(), 'user.deleted', $locked, ['email' => $locked->email], $request->ip());
            $locked->delete();
        });

        return response()->json(null, 204);
    }
}
