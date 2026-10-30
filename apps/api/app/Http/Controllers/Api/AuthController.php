<?php

namespace App\Http\Controllers\Api;

use App\Domain\GhanaPhone;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Sign-in with phone/email + password, then a staff PIN for quick till switching (SEC-ROLE-003).
 * Owner and manager must also pass TOTP MFA (B16 tier 1) — enforced by the `mfa` middleware.
 */
class AuthController extends Controller
{
    public function login(Request $r): JsonResponse
    {
        $data = $r->validate(['login' => 'required|string|max:120', 'password' => 'required|string|max:200', 'device_id' => 'nullable|string|size:26']);
        $phone = GhanaPhone::tryParse($data['login']);
        $user = User::query()->where('active', true)
            ->where(fn ($q) => $q->where('email', $data['login'])->when($phone, fn ($q) => $q->orWhere('phone_e164', $phone->e164)))
            ->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            AuditLogger::record('login.failed', 'login attempt for '.AuditLogger::redact($data['login']));

            return response()->json(['error' => ['code' => 'invalid_credentials', 'message' => 'Wrong login or password.']], 422);
        }
        $token = $user->createToken('device:'.($data['device_id'] ?? 'web'), ['*'], now()->addHours(12));
        AuditLogger::record('login', $user->name, $user->id);

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => $user->only('id', 'name', 'role', 'bundles'),
            'mfa_required' => in_array($user->role, ['owner', 'manager'], true),
            'mfa_enrolled' => $user->two_factor_confirmed_at !== null,
        ]);
    }

    /** Verify a staff PIN (till switching on a shared shop device). */
    public function pin(Request $r): JsonResponse
    {
        $data = $r->validate(['user_id' => 'required|integer', 'pin' => 'required|digits_between:4,6']);
        $key = 'pin:'.$data['user_id'].'|'.$r->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['error' => ['code' => 'locked', 'message' => 'Too many wrong PINs. Wait 5 minutes.']], 429);
        }
        $user = User::where('active', true)->find($data['user_id']);
        if (! $user || ! $user->pin_hash || ! Hash::check($data['pin'], $user->pin_hash)) {
            RateLimiter::hit($key, 300);

            return response()->json(['error' => ['code' => 'wrong_pin', 'message' => 'Wrong PIN.']], 422);
        }
        RateLimiter::clear($key);

        return response()->json(['user' => $user->only('id', 'name', 'role', 'bundles')]);
    }

    public function me(Request $r): JsonResponse
    {
        return response()->json(['user' => $r->user()->only('id', 'name', 'role', 'bundles')]);
    }

    public function logout(Request $r): JsonResponse
    {
        $r->user()->currentAccessToken()?->delete();
        AuditLogger::record('logout', $r->user()->name, $r->user()->id);

        return response()->json(['ok' => true]);
    }
}
