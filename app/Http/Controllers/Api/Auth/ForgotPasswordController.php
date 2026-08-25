<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sends a password-reset OTP. Always returns the same generic response
 * whether or not the email exists, so the endpoint can't be used to
 * enumerate registered accounts. Calling this again for the same email
 * simply issues a fresh code (OtpService invalidates the previous one).
 */
class ForgotPasswordController extends Controller
{
    public function request(Request $request, OtpService $otpService): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if ($user) {
            $otpService->generateAndSend($validated['email'], 'reset');
        }

        return response()->json([
            'ok' => true,
            'message' => 'If that email is registered, a reset code has been sent.',
        ]);
    }
}
