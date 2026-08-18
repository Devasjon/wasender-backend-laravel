<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.paybill.access_token' => 'test-token']);
    }

    public function test_it_rejects_requests_without_a_token(): void
    {
        $this->getJson('/api/state')->assertStatus(401);
    }

    public function test_it_rejects_requests_with_the_wrong_token(): void
    {
        $this->withHeader('Authorization', 'Bearer wrong-token')
            ->getJson('/api/state')
            ->assertStatus(401);
    }

    public function test_it_returns_null_data_when_nothing_has_been_synced_yet(): void
    {
        $this->withHeader('Authorization', 'Bearer test-token')
            ->getJson('/api/state')
            ->assertOk()
            ->assertJson(['data' => null, 'updatedAt' => null]);
    }

    public function test_it_saves_and_returns_the_full_app_state(): void
    {
        $payload = ['bills' => [['id' => '1', 'name' => 'Bil Elektrik']], 'debts' => []];

        $this->withHeader('Authorization', 'Bearer test-token')
            ->putJson('/api/state', ['data' => $payload])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->withHeader('Authorization', 'Bearer test-token')
            ->getJson('/api/state')
            ->assertOk()
            ->assertJsonPath('data.bills.0.name', 'Bil Elektrik');

        $this->assertDatabaseCount('app_states', 1);
    }

    public function test_saving_again_overwrites_rather_than_duplicates(): void
    {
        $this->withHeader('Authorization', 'Bearer test-token')
            ->putJson('/api/state', ['data' => ['bills' => []]])
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer test-token')
            ->putJson('/api/state', ['data' => ['bills' => [['id' => 'x']]]])
            ->assertOk();

        $this->assertDatabaseCount('app_states', 1);
    }

    public function test_it_rejects_a_missing_data_field(): void
    {
        $this->withHeader('Authorization', 'Bearer test-token')
            ->putJson('/api/state', [])
            ->assertStatus(422);
    }

    public function test_it_returns_500_when_no_access_token_is_configured(): void
    {
        config(['services.paybill.access_token' => null]);

        $this->getJson('/api/state')->assertStatus(500);
    }
}
