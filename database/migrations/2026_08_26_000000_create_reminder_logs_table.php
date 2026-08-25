<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per (bill, event, day) a scheduled reminder actually fired for
     * — see app/Console/Commands/SendBillReminders.php. Purely a dedupe/audit
     * log so the daily scheduled command never re-sends the same "due soon"
     * or "overdue" WhatsApp reminder twice in one day; it's independent of
     * app_states (the synced bill data itself), not a mirror of it.
     */
    public function up(): void
    {
        Schema::create('reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->string('bill_id');
            $table->string('event'); // "billDueSoon" | "billOverdue"
            $table->date('sent_date');
            $table->string('status'); // "sent" | "failed"
            $table->text('detail')->nullable();
            $table->timestamps();

            $table->unique(['bill_id', 'event', 'sent_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_logs');
    }
};
