<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Starts registration: creates (or, if the email exists but was never
 * verified, updates) a User row and emails a 6-digit OTP via OtpService.
 * The account isn't usable until VerifyOtpController confirms the code.
 */
class RegisterController extends Controller
{
    public function register(Request $request, OtpService $otpService): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $existing = User::where('email', $validated['email'])->first();

        if ($existing && $existing->email_verified_at !== null) {
            return response()->json([
                'error' => 'This email is already registered. Try logging in instead.',
            ], 422);
        }

        if ($existing) {
            $existing->update([
                'name' => $validated['name'],
                'password' => Hash::make($validated['password']),
            ]);
        } else {
            User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);
        }

        $otpService->generateAndSend($validated['email'], 'register');

        return response()->json([
            'ok' => true,
            'message' => 'Check your email for a verification code.',
        ]);
    }
}
