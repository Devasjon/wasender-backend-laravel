<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends a WhatsApp message via WasenderAPI, keeping the Session API Key
 * server-side. Shared by SendWhatsAppController (frontend-triggered sends)
 * and the bills:send-reminders scheduled command (backend-triggered
 * due-soon/overdue reminders) so both use identical request/error handling.
 */
class WasenderMessenger
{
    /**
     * @return array{ok: bool, status: int, body: array|string|null}
     */
    public function send(string $to, string $text): array
    {
        $apiKey = config('services.wasender.key');

        if (! $apiKey) {
            Log::error('WASENDER_API_KEY is not configured.');

            return ['ok' => false, 'status' => 500, 'body' => 'WASENDER_API_KEY is not set in .env.'];
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(15)
                ->post('https://www.wasenderapi.com/api/send-message', [
                    'to' => $to,
                    'text' => $text,
                ]);
        } catch (\Throwable $e) {
            Log::warning('WasenderAPI call failed', ['message' => $e->getMessage()]);

            return ['ok' => false, 'status' => 502, 'body' => $e->getMessage()];
        }

        return $this->toResult($response);
    }

    private function toResult(Response $response): array
    {
        if ($response->failed()) {
            Log::warning('WasenderAPI send failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [
                'ok' => false,
                'status' => $response->status() ?: 502,
                'body' => $response->json() ?? $response->body(),
            ];
        }

        return ['ok' => true, 'status' => 200, 'body' => $response->json()];
    }
}
