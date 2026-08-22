<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScanReceiptTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.paybill.access_token' => 'test-token']);
        config(['services.anthropic.key' => 'test-anthropic-key']);
    }

    private function fakeAnthropicResponse(array $data): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [
                    ['type' => 'text', 'text' => json_encode($data)],
                ],
            ], 200),
        ]);
    }

    public function test_it_rejects_requests_without_a_token(): void
    {
        $file = UploadedFile::fake()->image('receipt.jpg');

        $this->postJson('/api/scan-receipt', ['image' => $file])->assertStatus(401);
    }

    public function test_it_extracts_receipt_fields_from_the_model_response(): void
    {
        $this->fakeAnthropicResponse([
            'merchant' => 'Tenaga Nasional Berhad',
            'amount' => 168.20,
            'date' => '2026-08-24',
            'category' => 'utilities',
            'isPaid' => false,
        ]);

        $file = UploadedFile::fake()->image('receipt.jpg');

        $response = $this->withHeader('Authorization', 'Bearer test-token')
            ->postJson('/api/scan-receipt', ['image' => $file]);

        $response->assertOk()->assertJson([
            'ok' => true,
            'data' => [
                'merchant' => 'Tenaga Nasional Berhad',
                'amount' => 168.20,
                'date' => '2026-08-24',
                'category' => 'utilities',
                'isPaid' => false,
            ],
        ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->hasHeader('x-api-key', 'test-anthropic-key');
        });
    }

    public function test_it_accepts_a_pdf_invoice_and_sends_it_as_a_document_block(): void
    {
        $this->fakeAnthropicResponse([
            'merchant' => 'Astro Malaysia',
            'amount' => 99.90,
            'date' => '2026-09-01',
            'category' => 'subscription',
            'isPaid' => false,
        ]);

        $file = UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf');

        $this->withHeader('Authorization', 'Bearer test-token')
            ->postJson('/api/scan-receipt', ['image' => $file])
            ->assertOk()
            ->assertJsonPath('data.merchant', 'Astro Malaysia');

        Http::assertSent(function ($request) {
            return $request['messages'][0]['content'][0]['type'] === 'document'
                && $request['messages'][0]['content'][0]['source']['media_type'] === 'application/pdf';
        });
    }

    public function test_it_falls_back_to_other_for_an_unrecognized_category(): void
    {
        $this->fakeAnthropicResponse([
            'merchant' => 'Some Shop',
            'amount' => 25,
            'date' => null,
            'category' => 'groceries',
            'isPaid' => true,
        ]);

        $file = UploadedFile::fake()->image('receipt.jpg');

        $this->withHeader('Authorization', 'Bearer test-token')
            ->postJson('/api/scan-receipt', ['image' => $file])
            ->assertOk()
            ->assertJsonPath('data.category', 'other');
    }

    public function test_it_returns_422_when_the_model_cannot_read_the_image(): void
    {
        $this->fakeAnthropicResponse(['error' => 'Image is too blurry to read.']);

        $file = UploadedFile::fake()->image('receipt.jpg');

        $this->withHeader('Authorization', 'Bearer test-token')
            ->postJson('/api/scan-receipt', ['image' => $file])
            ->assertStatus(422);
    }

    public function test_it_rejects_a_missing_image(): void
    {
        $this->withHeader('Authorization', 'Bearer test-token')
            ->postJson('/api/scan-receipt', [])
            ->assertStatus(422);
    }

    public function test_it_returns_500_when_api_key_is_not_configured(): void
    {
        config(['services.anthropic.key' => null]);

        $file = UploadedFile::fake()->image('receipt.jpg');

        $this->withHeader('Authorization', 'Bearer test-token')
            ->postJson('/api/scan-receipt', ['image' => $file])
            ->assertStatus(500);
    }
}
