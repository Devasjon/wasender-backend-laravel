<?php

namespace Tests\Feature;

use App\Models\Otp;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.resend.key' => 'test-resend-key']);
        config(['services.resend.from' => 'noreply@example.com']);

        Http::fake([
            'api.resend.com/*' => Http::response(['id' => 'email-id'], 200),
        ]);
    }

    /**
     * OTP codes are only ever stored hashed. OtpService also logs the
     * plaintext under the 'testing' environment (same debug convenience it
     * offers for 'local') specifically so tests can recover it this way
     * instead of brute-forcing the hash.
     */
    private function generateAndCaptureOtp(string $email, string $purpose): string
    {
        Log::spy();

        app(OtpService::class)->generateAndSend($email, $purpose);

        $captured = null;
        Log::shouldHaveReceived('info')->withArgs(function (string $message) use (&$captured) {
            $captured = $message;

            return true;
        });

        preg_match('/(\d{6})$/', (string) $captured, $matches);
        $this->assertNotEmpty($matches, 'Could not capture OTP code from log output.');

        return $matches[1];
    }

    public function test_registration_sends_an_otp_and_creates_an_unverified_user(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Haven',
            'email' => 'haven@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertOk()->assertJson(['ok' => true]);

        $user = User::where('email', 'haven@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.resend.com/emails');
        $this->assertDatabaseHas('otps', ['email' => 'haven@example.com', 'purpose' => 'register']);
    }

    public function test_duplicate_registration_of_a_verified_email_is_rejected(): void
    {
        User::factory()->create([
            'email' => 'taken@example.com',
            'email_verified_at' => now(),
        ]);

        $this->postJson('/api/auth/register', [
            'name' => 'Someone',
            'email' => 'taken@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(422);
    }

    public function test_re_registering_an_unverified_email_is_idempotent(): void
    {
        $user = User::factory()->create([
            'email' => 'pending@example.com',
            'email_verified_at' => null,
        ]);

        $this->postJson('/api/auth/register', [
            'name' => 'Updated Name',
            'email' => 'pending@example.com',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk();

        $this->assertDatabaseCount('users', 1);
        $this->assertSame('Updated Name', $user->fresh()->name);
    }

    public function test_verify_otp_with_the_correct_code_verifies_the_user_and_returns_a_token(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $code = $this->generateAndCaptureOtp($user->email, 'register');

        $response = $this->postJson('/api/auth/verify-otp', [
            'email' => $user->email,
            'code' => $code,
        ]);

        $response->assertOk()->assertJsonStructure(['ok', 'token', 'user' => ['id', 'name', 'email']]);
        $this->assertNotNull($user->fresh()->email_verified_at);

        $token = $response->json('token');
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    public function test_verify_otp_rejects_a_wrong_code(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $this->generateAndCaptureOtp($user->email, 'register');

        $this->postJson('/api/auth/verify-otp', [
            'email' => $user->email,
            'code' => '000000',
        ])->assertStatus(422)->assertJson(['error' => 'invalid_or_expired']);

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_verify_otp_rejects_an_expired_code(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $code = $this->generateAndCaptureOtp($user->email, 'register');

        Otp::where('email', $user->email)->where('purpose', 'register')
            ->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/auth/verify-otp', [
            'email' => $user->email,
            'code' => $code,
        ])->assertStatus(422)->assertJson(['error' => 'invalid_or_expired']);
    }

    public function test_verify_otp_locks_after_five_wrong_attempts(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $code = $this->generateAndCaptureOtp($user->email, 'register');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/verify-otp', [
                'email' => $user->email,
                'code' => '111111',
            ])->assertStatus(422)->assertJson(['error' => 'invalid_or_expired']);
        }

        // Even the correct code is now rejected — locked out.
        $this->postJson('/api/auth/verify-otp', [
            'email' => $user->email,
            'code' => $code,
        ])->assertStatus(422)->assertJson(['error' => 'too_many_attempts']);
    }

    public function test_resend_otp_gives_a_generic_response_whether_or_not_the_email_exists(): void
    {
        $known = User::factory()->create(['email_verified_at' => null]);

        $this->postJson('/api/auth/resend-otp', ['email' => $known->email])
            ->assertOk()->assertJson(['ok' => true]);

        $this->postJson('/api/auth/resend-otp', ['email' => 'nobody@example.com'])
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseCount('otps', 1);
    }

    public function test_login_rejects_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'x@example.com',
            'password' => Hash::make('correct-password'),
            'email_verified_at' => now(),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'x@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(401)->assertJson(['error' => 'Invalid email or password.']);
    }

    public function test_login_rejects_unknown_email_with_the_same_generic_error(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'whatever123',
        ])->assertStatus(401)->assertJson(['error' => 'Invalid email or password.']);
    }

    public function test_login_rejects_an_unverified_account(): void
    {
        User::factory()->create([
            'email' => 'unverified@example.com',
            'password' => Hash::make('password123'),
            'email_verified_at' => null,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'unverified@example.com',
            'password' => 'password123',
        ])->assertStatus(403)->assertJson(['error' => 'email_not_verified']);
    }

    public function test_login_succeeds_and_returns_a_usable_token(): void
    {
        User::factory()->create([
            'email' => 'ok@example.com',
            'password' => Hash::make('password123'),
            'email_verified_at' => now(),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'ok@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk()->assertJsonStructure(['ok', 'token', 'user' => ['id', 'name', 'email']]);

        $token = $response->json('token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'ok@example.com');
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/logout')
            ->assertOk();

        // Sanctum's guard caches the resolved user for the lifetime of the
        // test process — without this, the revoked token above would still
        // resolve to the same (stale, cached) user on the next request.
        $this->app->make('auth')->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertStatus(401);
    }

    public function test_forgot_password_does_not_leak_whether_an_email_exists(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk()->assertJson(['ok' => true]);

        Http::assertNothingSent();
    }

    public function test_forgot_password_sends_a_code_for_a_real_account(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();

        Http::assertSent(fn ($request) => $request->url() === 'https://api.resend.com/emails');
        $this->assertDatabaseHas('otps', ['email' => $user->email, 'purpose' => 'reset']);
    }

    public function test_reset_password_rejects_a_wrong_code(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->generateAndCaptureOtp($user->email, 'reset');

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'code' => '000000',
            'password' => 'brandnewpass123',
            'password_confirmation' => 'brandnewpass123',
        ])->assertStatus(422);
    }

    public function test_reset_password_with_a_correct_code_changes_the_password_and_revokes_tokens(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => Hash::make('old-password'),
        ]);
        $oldToken = $user->createToken('old-session')->plainTextToken;

        $code = $this->generateAndCaptureOtp($user->email, 'reset');

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'code' => $code,
            'password' => 'brandnewpass123',
            'password_confirmation' => 'brandnewpass123',
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertTrue(Hash::check('brandnewpass123', $user->fresh()->password));

        $this->withHeader('Authorization', 'Bearer '.$oldToken)
            ->getJson('/api/auth/me')
            ->assertStatus(401);
    }
}
