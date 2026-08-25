<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Resends the registration OTP (RegisterController's flow only — password
 * reset codes are resent by simply calling ForgotPasswordController again,
 * since OtpService.generateAndSend already invalidates any prior unconsumed
 * code for that email+purpose).
 *
 * Always returns the same generic response regardless of whether the email
 * exists or is already verified, to avoid leaking account existence.
 */
class ResendOtpController extends Controller
{
    public function resend(Request $request, OtpService $otpService): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if ($user && $user->email_verified_at === null) {
            $otpService->generateAndSend($validated['email'], 'register');
        }

        return response()->json([
            'ok' => true,
            'message' => 'If that account needs verifying, a new code has been sent.',
        ]);
    }
}
