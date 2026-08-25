<?php

namespace App\Services;

use App\Models\Otp;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Generates, emails (via ResendMailer), and verifies 6-digit one-time codes
 * for both registration verification and password reset — see the `otps`
 * table (purpose column distinguishes the two flows).
 */
class OtpService
{
    private const EXPIRY_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    public function __construct(private ResendMailer $mailer)
    {
    }

    /**
     * @return array{ok: bool, status: int, body: array|string|null}
     */
    public function generateAndSend(string $email, string $purpose): array
    {
        Otp::where('email', $email)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = (string) random_int(100000, 999999);

        Otp::create([
            'email' => $email,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(self::EXPIRY_MINUTES),
        ]);

        // Also written under 'testing' so feature tests can recover the
        // plaintext code (it's otherwise only ever stored hashed) without
        // brute-forcing the hash.
        if (app()->environment(['local', 'testing'])) {
            Log::info("OTP for {$email} ({$purpose}): {$code}");
        }

        [$subject, $html] = $this->template($purpose, $code);

        return $this->mailer->send($email, $subject, $html);
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public function verify(string $email, string $purpose, string $code): array
    {
        $otp = Otp::where('email', $email)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if (! $otp || $otp->expires_at->isPast()) {
            return ['ok' => false, 'error' => 'invalid_or_expired'];
        }

        if ($otp->attempts >= self::MAX_ATTEMPTS) {
            return ['ok' => false, 'error' => 'too_many_attempts'];
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');

            return ['ok' => false, 'error' => 'invalid_or_expired'];
        }

        $otp->update(['consumed_at' => now()]);

        return ['ok' => true];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function template(string $purpose, string $code): array
    {
        if ($purpose === 'reset') {
            return [
                'Reset your Paybill password',
                "<p>Your password reset code is:</p><h2>{$code}</h2><p>This code expires in ".self::EXPIRY_MINUTES.' minutes. If you did not request this, you can ignore this email.</p>',
            ];
        }

        return [
            'Verify your Paybill account',
            "<p>Your verification code is:</p><h2>{$code}</h2><p>This code expires in ".self::EXPIRY_MINUTES.' minutes.</p>',
        ];
    }
}
