<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Now that app_states is per-user, bills:send-reminders checks every
     * user's synced state instead of a single global row — reminder_logs
     * needs user_id too so its dedupe key can't collide across two users'
     * independently client-generated bill ids.
     */
    public function up(): void
    {
        // Disposable dedupe/audit rows only (see the create-table migration's
        // doc comment) — safe to clear rather than attempt to backfill an
        // owner they never recorded.
        \Illuminate\Support\Facades\DB::table('reminder_logs')->delete();

        Schema::table('reminder_logs', function (Blueprint $table) {
            $table->foreignId('user_id')->after('id')->constrained()->cascadeOnDelete();
            $table->dropUnique(['bill_id', 'event', 'sent_date']);
            $table->unique(['user_id', 'bill_id', 'event', 'sent_date']);
        });
    }

    public function down(): void
    {
        Schema::table('reminder_logs', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'bill_id', 'event', 'sent_date']);
            $table->unique(['bill_id', 'event', 'sent_date']);
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
