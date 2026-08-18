<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stores Paybill's full app state (bills, debts, settings, notification
     * log) as a single JSON blob, keyed by a device-agnostic "owner" string.
     * This is deliberately not a normalized relational schema — Paybill is
     * a single-user app that already has a JSON export/import feature
     * (src/lib/storage.ts on the frontend); this table just makes that same
     * JSON blob fetchable/savable from any device instead of living only in
     * one browser's memory. See app/Http/Controllers/Api/AppStateController.php.
     */
    public function up(): void
    {
        Schema::create('app_states', function (Blueprint $table) {
            $table->id();
            // Single-user app: this is always "default" today. Kept as a
            // column (unique) rather than hardcoding a single row so this
            // can grow into multi-user storage later without a schema change.
            $table->string('owner')->unique()->default('default');
            $table->json('data');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_states');
    }
};
