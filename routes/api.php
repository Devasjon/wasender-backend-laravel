<?php

use App\Http\Controllers\Api\AppStateController;
use App\Http\Controllers\Api\SendWhatsAppController;
use Illuminate\Support\Facades\Route;

// Called by Paybill (Settings → Backend endpoint URL) whenever a bill's
// status changes. See app/Http/Controllers/Api/SendWhatsAppController.php.
Route::post('/send-whatsapp', [SendWhatsAppController::class, 'send']);

// Cloud sync for Paybill's full app state (bills, debts, settings) — lets
// the same data show up on every device instead of staying in one browser.
// See app/Http/Controllers/Api/AppStateController.php.
Route::middleware('paybill.token')->group(function () {
    Route::get('/state', [AppStateController::class, 'show']);
    Route::put('/state', [AppStateController::class, 'store']);
});
