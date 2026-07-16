<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->string('email'))->first();
        if ($user === null || ! Hash::check($request->string('password'), $user->password)) {
            throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
        }
        if ($user->isDisabled()) {
            return response()->json(['error' => ['code' => 'account_disabled', 'message' => 'This account is disabled.']], 403);
        }

        $token = $user->createToken($request->string('device_name')->value() ?: 'api')->plainTextToken;
        AuditLog::record($user, 'auth.login', $user, [], $request->ip());

        return response()->json(['data' => ['user' => UserResource::make($user), 'token' => $token]]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->currentAccessToken()?->delete();
        AuditLog::record($user, 'auth.logout', $user, [], $request->ip());

        return response()->json(['data' => ['logged_out' => true]]);
    }

    public function me(Request $request): UserResource
    {
        return UserResource::make($request->user());
    }

    public function update(Request $request): UserResource
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$request->user()->getKey()],
        ]);
        $request->user()->update($validated);
        AuditLog::record($request->user(), 'profile.updated', $request->user(), ['fields' => array_keys($validated)], $request->ip());

        return UserResource::make($request->user()->refresh());
    }

    public function password(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);
        $request->user()->forceFill(['password' => Hash::make($validated['password'])])->save();
        $request->user()->tokens()->whereKeyNot($request->user()->currentAccessToken()?->getKey())->delete();
        AuditLog::record($request->user(), 'profile.password_changed', $request->user(), [], $request->ip());

        return response()->json(['data' => ['password_changed' => true]]);
    }
}
