<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Confirms the registration OTP sent by RegisterController, marks the user
 * verified, and immediately issues a Sanctum token so the frontend can skip
 * straight to a logged-in session (no separate login step after verifying).
 */
class VerifyOtpController extends Controller
{
    public function verify(Request $request, OtpService $otpService): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'code' => ['required', 'string'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user) {
            return response()->json(['error' => 'invalid_or_expired'], 422);
        }

        $result = $otpService->verify($validated['email'], 'register', $validated['code']);

        if (! $result['ok']) {
            return response()->json(['error' => $result['error']], 422);
        }

        if ($user->email_verified_at === null) {
            // email_verified_at is deliberately not in User's Fillable list
            // (it must never be settable via ordinary mass-assigned request
            // input) — forceFill is the correct way for this
            // system-controlled field.
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $token = $user->createToken('paybill')->plainTextToken;

        return response()->json([
            'ok' => true,
            'token' => $token,
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ]);
    }
}
