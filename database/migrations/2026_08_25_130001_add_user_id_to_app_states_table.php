<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 1 of moving app_states off the single hardcoded owner="default"
     * row to real per-user storage. Added nullable/additive on purpose — see
     * 2026_08_25_130002_drop_owner_from_app_states_table.php for step 2,
     * which only runs after any existing owner="default" data has been
     * exported (Settings → Export Data) so it isn't silently orphaned.
     */
    public function up(): void
    {
        Schema::table('app_states', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('app_states', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
