<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendWhatsAppTest extends TestCase
{
    public function test_it_forwards_a_message_to_wasenderapi(): void
    {
        config(['services.wasender.key' => 'test-session-api-key']);

        Http::fake([
            'www.wasenderapi.com/*' => Http::response([
                'success' => true,
                'data' => ['msgId' => 100000, 'jid' => '+60123456789', 'status' => 'in_progress'],
            ], 200),
        ]);

        $response = $this->postJson('/api/send-whatsapp', [
            'to' => '+60123456789',
            'text' => 'Ujian',
        ]);

        $response->assertOk()->assertJson(['ok' => true]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://www.wasenderapi.com/api/send-message'
                && $request->hasHeader('Authorization', 'Bearer test-session-api-key')
                && $request['to'] === '+60123456789'
                && $request['text'] === 'Ujian';
        });
    }

    public function test_it_rejects_missing_fields(): void
    {
        $response = $this->postJson('/api/send-whatsapp', ['to' => '+60123456789']);

        $response->assertStatus(422);
    }

    public function test_it_returns_500_when_api_key_is_not_configured(): void
    {
        config(['services.wasender.key' => null]);

        $response = $this->postJson('/api/send-whatsapp', [
            'to' => '+60123456789',
            'text' => 'Ujian',
        ]);

        $response->assertStatus(500);
    }

    public function test_it_rejects_requests_with_a_wrong_shared_secret_when_one_is_configured(): void
    {
        config(['services.wasender.shared_secret' => 'correct-secret']);

        $response = $this->postJson('/api/send-whatsapp?key=wrong-secret', [
            'to' => '+60123456789',
            'text' => 'Ujian',
        ]);

        $response->assertStatus(401);
    }
}
