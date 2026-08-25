<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppStateTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        // Sanctum's guard caches the resolved user for the lifetime of the
        // test process — without this, a second request in the same test
        // using a different user's token would still authenticate as
        // whichever user was resolved first.
        $this->app->make('auth')->forgetGuards();

        return $user->createToken('test')->plainTextToken;
    }

    public function test_it_rejects_requests_without_a_token(): void
    {
        $this->getJson('/api/state')->assertStatus(401);
    }

    public function test_it_rejects_requests_with_an_invalid_token(): void
    {
        $this->withHeader('Authorization', 'Bearer wrong-token')
            ->getJson('/api/state')
            ->assertStatus(401);
    }

    public function test_it_returns_null_data_when_nothing_has_been_synced_yet(): void
    {
        $user = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/state')
            ->assertOk()
            ->assertJson(['data' => null, 'updatedAt' => null]);
    }

    public function test_it_saves_and_returns_the_full_app_state(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);
        $payload = ['bills' => [['id' => '1', 'name' => 'Bil Elektrik']], 'debts' => []];

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/state', ['data' => $payload])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/state')
            ->assertOk()
            ->assertJsonPath('data.bills.0.name', 'Bil Elektrik');

        $this->assertDatabaseCount('app_states', 1);
    }

    public function test_saving_again_overwrites_rather_than_duplicates(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/state', ['data' => ['bills' => []]])
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/state', ['data' => ['bills' => [['id' => 'x']]]])
            ->assertOk();

        $this->assertDatabaseCount('app_states', 1);
    }

    public function test_it_rejects_a_missing_data_field(): void
    {
        $user = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->putJson('/api/state', [])
            ->assertStatus(422);
    }

    public function test_two_users_cannot_see_or_overwrite_each_others_state(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($userA))
            ->putJson('/api/state', ['data' => ['bills' => [['id' => 'a-bill']]]])
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($userB))
            ->putJson('/api/state', ['data' => ['bills' => [['id' => 'b-bill']]]])
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($userA))
            ->getJson('/api/state')
            ->assertOk()
            ->assertJsonPath('data.bills.0.id', 'a-bill');

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($userB))
            ->getJson('/api/state')
            ->assertOk()
            ->assertJsonPath('data.bills.0.id', 'b-bill');

        $this->assertDatabaseCount('app_states', 2);
    }
}
