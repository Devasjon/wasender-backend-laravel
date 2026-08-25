<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends transactional email via the Resend HTTP API, keeping RESEND_API_KEY
 * server-side. Used by OtpService to deliver registration/reset codes — same
 * {ok, status, body} shape and Http::withToken(...) style as
 * WasenderMessenger, kept consistent on purpose.
 */
class ResendMailer
{
    /**
     * @return array{ok: bool, status: int, body: array|string|null}
     */
    public function send(string $to, string $subject, string $html): array
    {
        $apiKey = config('services.resend.key');

        if (! $apiKey) {
            Log::error('RESEND_API_KEY is not configured.');

            return ['ok' => false, 'status' => 500, 'body' => 'RESEND_API_KEY is not set in .env.'];
        }

        $from = config('services.resend.from');

        if (! $from) {
            Log::error('RESEND_FROM_EMAIL is not configured.');

            return ['ok' => false, 'status' => 500, 'body' => 'RESEND_FROM_EMAIL is not set in .env.'];
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(15)
                ->post('https://api.resend.com/emails', [
                    'from' => $from,
                    'to' => [$to],
                    'subject' => $subject,
                    'html' => $html,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Resend API call failed', ['message' => $e->getMessage()]);

            return ['ok' => false, 'status' => 502, 'body' => $e->getMessage()];
        }

        return $this->toResult($response);
    }

    private function toResult(Response $response): array
    {
        if ($response->failed()) {
            Log::warning('Resend send failed', [
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
