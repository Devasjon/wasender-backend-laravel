<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Completes a password reset started by ForgotPasswordController. On
 * success, every existing token for the user is revoked — a device with an
 * old session shouldn't stay logged in after the password changes.
 */
class ResetPasswordController extends Controller
{
    public function reset(Request $request, OtpService $otpService): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'code' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $result = $otpService->verify($validated['email'], 'reset', $validated['code']);

        if (! $result['ok']) {
            return response()->json(['error' => $result['error']], 422);
        }

        $user = User::where('email', $validated['email'])->first();

        if (! $user) {
            return response()->json(['error' => 'invalid_or_expired'], 422);
        }

        $user->update(['password' => Hash::make($validated['password'])]);
        $user->tokens()->delete();

        return response()->json(['ok' => true]);
    }
}
