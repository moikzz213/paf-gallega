<?php

use App\Console\Commands\SendPendingApprovalReminders;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Daily approval reminders, 09:00 in config('app.timezone') (Asia/Dubai).
 *
 * Only advances when something calls `schedule:run` every minute (cron, or Task Scheduler on
 * Windows) — see ai/deployment.md. The output is appended to a log so it is possible to tell that
 * the job ran at all, and how many reminders went out; without it, a scheduler that was never
 * configured looks identical to a quiet day.
 */
Schedule::command(SendPendingApprovalReminders::class)
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler.log'));
