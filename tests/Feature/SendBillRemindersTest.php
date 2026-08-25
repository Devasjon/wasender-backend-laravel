<?php

namespace Tests\Feature;

use App\Models\AppState;
use App\Models\ReminderLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendBillRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.wasender.key' => 'test-session-api-key']);
        Http::fake([
            'www.wasenderapi.com/*' => Http::response(['success' => true], 200),
        ]);
    }

    private function seedState(array $bills, array $whatsappOverrides = []): void
    {
        AppState::create([
            'owner' => 'default',
            'data' => [
                'bills' => $bills,
                'lang' => 'en',
                'whatsapp' => array_merge([
                    'enabled' => true,
                    'defaultNumber' => '+60123456789',
                    'backendUrl' => '',
                ], $whatsappOverrides),
            ],
        ]);
    }

    public function test_it_does_nothing_when_no_state_has_synced_yet(): void
    {
        $this->artisan('bills:send-reminders')->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertDatabaseCount('reminder_logs', 0);
    }

    public function test_it_skips_everything_when_whatsapp_is_disabled(): void
    {
        $this->seedState(
            [['id' => 'b1', 'name' => 'Bil Elektrik', 'amount' => 100, 'dueDate' => now()->toDateString(), 'paid' => false]],
            ['enabled' => false]
        );

        $this->artisan('bills:send-reminders')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_it_sends_a_due_soon_reminder_for_a_bill_due_within_three_days(): void
    {
        $this->seedState([[
            'id' => 'b1', 'name' => 'Bil Elektrik', 'amount' => 168.20,
            'dueDate' => now()->addDays(2)->toDateString(), 'paid' => false,
        ]]);

        $this->artisan('bills:send-reminders')->assertExitCode(0);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://www.wasenderapi.com/api/send-message'
                && $request['to'] === '+60123456789'
                && str_contains($request['text'], 'Bil Elektrik')
                && str_contains($request['text'], 'due soon');
        });

        $this->assertDatabaseHas('reminder_logs', [
            'bill_id' => 'b1',
            'event' => 'billDueSoon',
            'status' => 'sent',
        ]);
    }

    public function test_it_sends_an_overdue_reminder_for_a_past_due_bill(): void
    {
        $this->seedState([[
            'id' => 'b2', 'name' => 'Kad Kredit', 'amount' => 500,
            'dueDate' => now()->subDays(3)->toDateString(), 'paid' => false,
        ]]);

        $this->artisan('bills:send-reminders')->assertExitCode(0);

        Http::assertSent(fn ($request) => str_contains($request['text'], 'Overdue'));
        $this->assertDatabaseHas('reminder_logs', ['bill_id' => 'b2', 'event' => 'billOverdue']);
    }

    public function test_it_skips_a_bill_that_is_already_paid(): void
    {
        $this->seedState([[
            'id' => 'b3', 'name' => 'Paid Bill', 'amount' => 50,
            'dueDate' => now()->subDays(1)->toDateString(), 'paid' => true,
        ]]);

        $this->artisan('bills:send-reminders')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_it_skips_a_bill_not_yet_within_the_due_soon_window(): void
    {
        $this->seedState([[
            'id' => 'b4', 'name' => 'Future Bill', 'amount' => 50,
            'dueDate' => now()->addDays(10)->toDateString(), 'paid' => false,
        ]]);

        $this->artisan('bills:send-reminders')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_it_does_not_double_send_on_a_second_run_the_same_day(): void
    {
        $this->seedState([[
            'id' => 'b5', 'name' => 'Bil Air', 'amount' => 40,
            'dueDate' => now()->toDateString(), 'paid' => false,
        ]]);

        $this->artisan('bills:send-reminders')->assertExitCode(0);
        $this->artisan('bills:send-reminders')->assertExitCode(0);

        Http::assertSentCount(1);
        $this->assertDatabaseCount('reminder_logs', 1);
    }

    public function test_it_prefers_the_bills_own_whatsapp_number_over_the_default(): void
    {
        $this->seedState([[
            'id' => 'b6', 'name' => 'Personal Loan', 'amount' => 300,
            'dueDate' => now()->toDateString(), 'paid' => false,
            'whatsappNumber' => '+60199998888',
        ]]);

        $this->artisan('bills:send-reminders')->assertExitCode(0);

        Http::assertSent(fn ($request) => $request['to'] === '+60199998888');
    }
}
