<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Named rate limiters for the auth endpoints (routes/api.php) — the
        // default api throttle (60/min) is far too loose for OTP/login
        // endpoints, which are brute-force targets.
        RateLimiter::for('auth-register', function (Request $request) {
            return Limit::perHour(5)->by($request->ip());
        });

        RateLimiter::for('auth-login', function (Request $request) {
            return [
                Limit::perMinute(10)->by($request->ip()),
                Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            ];
        });

        // Kept above OtpService's own 5-attempt lockout (App\Services\OtpService)
        // so that app-level lockout — which returns a clearer error — is what
        // normally fires; this is just the coarser outer guard.
        RateLimiter::for('auth-otp-verify', function (Request $request) {
            return Limit::perMinutes(10, 10)->by(strtolower((string) $request->input('email')));
        });

        RateLimiter::for('auth-otp-resend', function (Request $request) {
            return Limit::perMinutes(10, 3)->by(strtolower((string) $request->input('email')).'|'.$request->ip());
        });
    }
}
