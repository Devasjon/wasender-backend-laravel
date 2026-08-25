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
 * browser anywhere. Reads the same synced app state /api/state serves
 * (AppState, owner="default"), so it only has anything to check once Cloud
 * Sync has been enabled and used at least once from the app.
 *
 * Dedupe: reminder_logs has a unique (bill_id, event, sent_date) — running
 * this command more than once on the same day never double-sends for the
 * same bill+event. Once a bill is marked paid (or its due date changes) on
 * any device, that change reaches this command on its next run via the same
 * synced state, so reminders naturally stop.
 */
class SendBillReminders extends Command
{
    protected $signature = 'bills:send-reminders';

    protected $description = 'Send WhatsApp reminders for bills due soon or overdue';

    private const DUE_SOON_WINDOW_DAYS = 3;

    public function handle(WasenderMessenger $messenger): int
    {
        $state = AppState::where('owner', 'default')->first();

        if (! $state) {
            $this->info('No synced app state yet — nothing to check.');

            return self::SUCCESS;
        }

        $data = $state->data ?? [];
        $whatsapp = $data['whatsapp'] ?? [];

        if (empty($whatsapp['enabled'])) {
            $this->info('WhatsApp notifications are disabled in Paybill Settings — skipping.');

            return self::SUCCESS;
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

            $alreadySent = ReminderLog::where('bill_id', $bill['id'])
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
                'bill_id' => $bill['id'],
                'event' => $event,
                'sent_date' => $today,
                'status' => $result['ok'] ? 'sent' : 'failed',
                'detail' => $result['ok'] ? null : json_encode($result['body']),
            ]);

            if ($result['ok']) {
                $sentCount++;
                $this->line("Sent {$event} reminder for \"{$bill['name']}\" to {$to}");
            } else {
                $this->warn("Failed to send {$event} reminder for \"{$bill['name']}\": ".json_encode($result['body']));
            }
        }

        $this->info("Done. {$sentCount} reminder(s) sent.");

        return self::SUCCESS;
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
