<?php

namespace App\Console\Commands;

use App\Models\AppState;
use App\Models\ReminderLog;
use App\Services\WasenderMessenger;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Runs once a day (see routes/console.php) and sends WhatsApp "due soon" /
 * "overdue" reminders for unpaid bills — without needing Paybill open in a
 * browser anywhere. Checks every user's synced app state in turn (one
 * AppState row per user_id, see AppStateController), so it only has
 * anything to check for a given user once they've enabled Cloud Sync and
 * synced at least once.
 *
 * Dedupe: reminder_logs has a unique (user_id, bill_id, event, sent_date) —
 * running this command more than once on the same day never double-sends
 * for the same user's bill+event. Once a bill is marked paid (or its due
 * date changes) on any device, that change reaches this command on its next
 * run via the same synced state, so reminders naturally stop.
 */
class SendBillReminders extends Command
{
    protected $signature = 'bills:send-reminders';

    protected $description = 'Send WhatsApp reminders for bills due soon or overdue';

    private const DUE_SOON_WINDOW_DAYS = 3;

    public function handle(WasenderMessenger $messenger): int
    {
        $states = AppState::whereNotNull('user_id')->get();

        if ($states->isEmpty()) {
            $this->info('No synced app state yet — nothing to check.');

            return self::SUCCESS;
        }

        $sentCount = 0;

        foreach ($states as $state) {
            $sentCount += $this->processState($state, $messenger);
        }

        $this->info("Done. {$sentCount} reminder(s) sent.");

        return self::SUCCESS;
    }

    private function processState(AppState $state, WasenderMessenger $messenger): int
    {
        $data = $state->data ?? [];
        $whatsapp = $data['whatsapp'] ?? [];

        if (empty($whatsapp['enabled'])) {
            return 0;
        }

        $lang = $data['lang'] ?? 'en';
        $bills = $data['bills'] ?? [];
        $today = Carbon::today();
        $sentCount = 0;

        foreach ($bills as $bill) {
            if (! empty($bill['paid'])) {
                continue;
            }

            $dueDate = Carbon::parse($bill['dueDate']);
            $daysUntil = $today->diffInDays($dueDate, false);

            $event = match (true) {
                $daysUntil < 0 => 'billOverdue',
                $daysUntil <= self::DUE_SOON_WINDOW_DAYS => 'billDueSoon',
                default => null,
            };

            if ($event === null) {
                continue;
            }

            $alreadySent = ReminderLog::where('user_id', $state->user_id)
                ->where('bill_id', $bill['id'])
                ->where('event', $event)
                ->whereDate('sent_date', $today)
                ->exists();

            if ($alreadySent) {
                continue;
            }

            $to = $bill['whatsappNumber'] ?? $whatsapp['defaultNumber'] ?? null;

            if (empty($to)) {
                continue;
            }

            $text = $this->buildMessage($event, $bill, $lang);
            $result = $messenger->send($to, $text);

            ReminderLog::create([
                'user_id' => $state->user_id,
                'bill_id' => $bill['id'],
                'event' => $event,
                'sent_date' => $today,
                'status' => $result['ok'] ? 'sent' : 'failed',
                'detail' => $result['ok'] ? null : json_encode($result['body']),
            ]);

            if ($result['ok']) {
                $sentCount++;
                $this->line("Sent {$event} reminder for \"{$bill['name']}\" to {$to} (user #{$state->user_id})");
            } else {
                $this->warn("Failed to send {$event} reminder for \"{$bill['name']}\" (user #{$state->user_id}): ".json_encode($result['body']));
            }
        }

        return $sentCount;
    }

    private function buildMessage(string $event, array $bill, string $lang): string
    {
        $amount = 'RM '.number_format((float) $bill['amount'], 2);
        $name = $bill['name'];
        $dueDate = $bill['dueDate'];

        $templates = [
            'billDueSoon' => [
                'en' => "Reminder: {$name} ({$amount}) is due soon on {$dueDate}.",
                'ms' => "Peringatan: {$name} ({$amount}) akan tamat tempoh pada {$dueDate}.",
            ],
            'billOverdue' => [
                'en' => "Overdue: {$name} ({$amount}) was due {$dueDate}. Please settle it soon to avoid late charges.",
                'ms' => "Tertunggak: {$name} ({$amount}) sepatutnya dibayar pada {$dueDate}. Sila selesaikan segera untuk elak caj lewat.",
            ],
        ];

        return $templates[$event][$lang] ?? $templates[$event]['en'];
    }
}
