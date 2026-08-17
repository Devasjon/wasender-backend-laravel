<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Bridges Paybill's frontend to WasenderAPI.
 *
 * The WasenderAPI Session API Key is a secret and must never be embedded in
 * the browser app — it lives only in this server's .env file. Paybill's
 * Settings page just needs to know this endpoint's URL; it POSTs
 * { to, text, event, billName } here and this controller forwards the
 * message to WasenderAPI using the key kept server-side.
 */
class SendWhatsAppController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        // Optional shared-secret check. If WASENDER_SHARED_SECRET is set in
        // .env, require it as a ?key=... query parameter on the URL Paybill
        // calls (Settings → Backend endpoint URL →
        // https://your-forge-domain.com/api/send-whatsapp?key=...). This
        // stops anyone who finds the URL from sending WhatsApp messages
        // through your session. Leave WASENDER_SHARED_SECRET unset in .env
        // to skip this check.
        $sharedSecret = config('services.wasender.shared_secret');
        if ($sharedSecret && $request->query('key') !== $sharedSecret) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            $validated = $request->validate([
                'to' => ['required', 'string'],
                'text' => ['required', 'string'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'error' => 'Request body must include \'to\' and \'text\'.',
                'details' => $e->errors(),
            ], 422);
        }

        $apiKey = config('services.wasender.key');

        if (! $apiKey) {
            Log::error('WASENDER_API_KEY is not configured.');

            return response()->json([
                'error' => 'Server misconfigured: WASENDER_API_KEY is not set in .env.',
            ], 500);
        }

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout(15)
            ->post('https://www.wasenderapi.com/api/send-message', [
                'to' => $validated['to'],
                'text' => $validated['text'],
            ]);

        if ($response->failed()) {
            Log::warning('WasenderAPI send failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return response()->json([
                'error' => 'WasenderAPI error',
                'detail' => $response->json() ?? $response->body(),
            ], $response->status() ?: 502);
        }

        return response()->json([
            'ok' => true,
            'wasender' => $response->json(),
        ]);
    }
}
