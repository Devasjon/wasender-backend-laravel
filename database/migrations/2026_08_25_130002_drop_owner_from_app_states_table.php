<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 2 — see 2026_08_25_130001_add_user_id_to_app_states_table.php.
     * Only safe to run once every environment's owner="default" row (if any)
     * has been exported via Settings → Export Data, since this drops the
     * column that keyed it. app_states.user_id is now required.
     */
    public function up(): void
    {
        Schema::table('app_states', function (Blueprint $table) {
            $table->dropUnique(['owner']);
            $table->dropColumn('owner');
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('app_states', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
            $table->string('owner')->unique()->default('default');
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }
};
