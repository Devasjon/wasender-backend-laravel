<?php

use App\Http\Controllers\Api\AppStateController;
use App\Http\Controllers\Api\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\MeController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\Auth\ResendOtpController;
use App\Http\Controllers\Api\Auth\ResetPasswordController;
use App\Http\Controllers\Api\Auth\VerifyOtpController;
use App\Http\Controllers\Api\ScanReceiptController;
use App\Http\Controllers\Api\SendWhatsAppController;
use Illuminate\Support\Facades\Route;

// Account creation, verification, login, and password reset — see
// app/Http/Controllers/Api/Auth/*. Each has its own named rate limiter
// (registered in AppServiceProvider::boot()) since these are brute-force
// targets and the default api throttle (60/min) is far too loose.
Route::prefix('auth')->group(function () {
    Route::post('/register', [RegisterController::class, 'register'])->middleware('throttle:auth-register');
    Route::post('/verify-otp', [VerifyOtpController::class, 'verify'])->middleware('throttle:auth-otp-verify');
    Route::post('/resend-otp', [ResendOtpController::class, 'resend'])->middleware('throttle:auth-otp-resend');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:auth-login');
    Route::post('/forgot-password', [ForgotPasswordController::class, 'request'])->middleware('throttle:auth-otp-resend');
    Route::post('/reset-password', [ResetPasswordController::class, 'reset'])->middleware('throttle:auth-otp-verify');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [MeController::class, 'show']);
        Route::post('/logout', [LogoutController::class, 'logout']);
    });
});

// Cloud sync for Paybill's full app state (bills, debts, settings), receipt
// scanning, and the WhatsApp bridge — all gated behind a logged-in user's
// Sanctum token now. See app/Http/Controllers/Api/AppStateController.php,
// ScanReceiptController.php, and SendWhatsAppController.php.
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/state', [AppStateController::class, 'show']);
    Route::put('/state', [AppStateController::class, 'store']);
    Route::post('/scan-receipt', [ScanReceiptController::class, 'scan']);
    Route::post('/send-whatsapp', [SendWhatsAppController::class, 'send']);
});
