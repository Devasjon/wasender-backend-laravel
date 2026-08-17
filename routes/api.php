<?php

use App\Http\Controllers\Api\SendWhatsAppController;
use Illuminate\Support\Facades\Route;

// Called by Paybill (Settings → Backend endpoint URL) whenever a bill's
// status changes. See app/Http/Controllers/Api/SendWhatsAppController.php.
Route::post('/send-whatsapp', [SendWhatsAppController::class, 'send']);
