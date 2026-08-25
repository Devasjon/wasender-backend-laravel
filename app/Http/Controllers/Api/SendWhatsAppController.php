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
 * frontend POSTs { to, text, event, billName } here and this controller
 * forwards the message to WasenderAPI using the key kept server-side.
 * Protected by auth:sanctum — see routes/api.php.
 */
class SendWhatsAppController extends Controller
{
    public function send(Request $request, WasenderMessenger $messenger): JsonResponse
    {
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
