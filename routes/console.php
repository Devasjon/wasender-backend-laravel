<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Checks synced bills for due-soon/overdue status and sends WhatsApp
// reminders — see app/Console/Commands/SendBillReminders.php. Requires
// Forge's "Scheduler" to be enabled on this site (runs `php artisan
// schedule:run` every minute; this command itself only actually does
// anything once a day).
Schedule::command('bills:send-reminders')->dailyAt('09:00');
