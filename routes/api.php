<?php

use App\Http\Controllers\Api\AppStateController;
use App\Http\Controllers\Api\ScanReceiptController;
use App\Http\Controllers\Api\SendWhatsAppController;
use Illuminate\Support\Facades\Route;

// Called by Paybill (Settings → Backend endpoint URL) whenever a bill's
// status changes. See app/Http/Controllers/Api/SendWhatsAppController.php.
Route::post('/send-whatsapp', [SendWhatsAppController::class, 'send']);

// Cloud sync for Paybill's full app state (bills, debts, settings), and
// receipt/invoice scanning — both need the same shared access token, so both
// sit behind 'paybill.token'.
// See app/Http/Controllers/Api/AppStateController.php and ScanReceiptController.php.
Route::middleware('paybill.token')->group(function () {
    Route::get('/state', [AppStateController::class, 'show']);
    Route::put('/state', [AppStateController::class, 'store']);
    Route::post('/scan-receipt', [ScanReceiptController::class, 'scan']);
});
