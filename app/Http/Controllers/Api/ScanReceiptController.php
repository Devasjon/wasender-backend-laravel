<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Reads a photo or PDF of a receipt or invoice (Paybill's Settings → Scan
 * Receipt — either snapped with the camera or imported from a file/folder)
 * and extracts the fields needed to prefill a bill: merchant name, amount,
 * date, a best-guess category, and whether it looks already paid (a receipt)
 * or still owing (an invoice). Uses Claude's vision API server-side so the
 * Anthropic API key never reaches the browser — same pattern as
 * SendWhatsAppController keeping WASENDER_API_KEY secret.
 *
 * Protected by the same 'paybill.token' middleware as /api/state (see
 * EnsurePaybillAccessToken) — no separate secret to configure.
 */
class ScanReceiptController extends Controller
{
    private const ALLOWED_CATEGORIES = [
        'utilities', 'housing', 'creditCard', 'subscription', 'insurance', 'loan', 'other',
    ];

    public function scan(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'image' => ['required', 'file', 'mimes:jpeg,png,jpg,gif,webp,pdf', 'max:8192'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'error' => "Request must include an 'image' file — photo or PDF (max 8MB).",
                'details' => $e->errors(),
            ], 422);
        }

        $apiKey = config('services.anthropic.key');

        if (! $apiKey) {
            Log::error('ANTHROPIC_API_KEY is not configured.');

            return response()->json([
                'error' => 'Server misconfigured: ANTHROPIC_API_KEY is not set in .env.',
            ], 500);
        }

        $file = $validated['image'];
        $base64 = base64_encode(file_get_contents($file->getRealPath()));
        $mediaType = $file->getMimeType() ?: 'image/jpeg';
        $isPdf = $mediaType === 'application/pdf';
        $model = config('services.anthropic.model', 'claude-sonnet-5');

        $prompt = <<<'PROMPT'
            You are reading a photo or document of a receipt or invoice for a personal bill-tracking app.
            Extract these fields and reply with ONLY a single JSON object — no markdown
            fences, no explanation, just the JSON:

            {
              "merchant": string (the business/biller name, e.g. "Tenaga Nasional Berhad"),
              "amount": number (the total amount payable/paid, in the currency shown, digits only, no currency symbol),
              "date": string or null (the transaction or due date, formatted YYYY-MM-DD; use the due date if this looks like an unpaid invoice, otherwise the transaction/paid date; null if you truly cannot find a date),
              "category": one of exactly these strings: "utilities", "housing", "creditCard", "subscription", "insurance", "loan", "other",
              "isPaid": boolean (true if this looks like a receipt for a completed payment, false if it looks like an unpaid/outstanding invoice or bill)
            }

            If you cannot read the image at all, reply with {"error": "reason here"} instead.
            PROMPT;

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])
                ->timeout(30)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $model,
                    'max_tokens' => 1024,
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => [
                                [
                                    'type' => $isPdf ? 'document' : 'image',
                                    'source' => [
                                        'type' => 'base64',
                                        'media_type' => $mediaType,
                                        'data' => $base64,
                                    ],
                                ],
                                [
                                    'type' => 'text',
                                    'text' => $prompt,
                                ],
                            ],
                        ],
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::warning('Anthropic API call failed', ['message' => $e->getMessage()]);

            return response()->json(['error' => 'Could not reach the scanning service.'], 502);
        }

        if ($response->failed()) {
            Log::warning('Anthropic API returned an error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return response()->json([
                'error' => 'Scanning service error',
                'detail' => $response->json() ?? $response->body(),
            ], $response->status() ?: 502);
        }

        $text = $response->json('content.0.text', '');
        $cleaned = trim(preg_replace('/^```(?:json)?|```$/m', '', $text));
        $parsed = json_decode($cleaned, true);

        if (! is_array($parsed) || isset($parsed['error'])) {
            return response()->json([
                'error' => $parsed['error'] ?? 'Could not read the receipt — try a clearer photo.',
            ], 422);
        }

        $amount = is_numeric($parsed['amount'] ?? null) ? (float) $parsed['amount'] : null;

        if (! isset($parsed['merchant']) || $amount === null) {
            return response()->json([
                'error' => 'Could not find a merchant name and amount on this receipt.',
            ], 422);
        }

        $category = in_array($parsed['category'] ?? null, self::ALLOWED_CATEGORIES, true)
            ? $parsed['category']
            : 'other';

        return response()->json([
            'ok' => true,
            'data' => [
                'merchant' => (string) $parsed['merchant'],
                'amount' => $amount,
                'date' => $parsed['date'] ?? null,
                'category' => $category,
                'isPaid' => (bool) ($parsed['isPaid'] ?? false),
            ],
        ]);
    }
}
