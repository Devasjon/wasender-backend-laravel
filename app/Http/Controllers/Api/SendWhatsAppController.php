<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WasenderMessenger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    public function send(Request $request, WasenderMessenger $messenger): JsonResponse
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

        $result = $messenger->send($validated['to'], $validated['text']);

        if (! $result['ok']) {
            $isConfigError = $result['status'] === 500;

            return response()->json(
                $isConfigError
                    ? ['error' => 'Server misconfigured: '.$result['body']]
                    : ['error' => 'WasenderAPI error', 'detail' => $result['body']],
                $result['status']
            );
        }

        return response()->json(['ok' => true, 'wasender' => $result['body']]);
    }
}
