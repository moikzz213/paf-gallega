<?php

use App\Console\Commands\SendApprovalQueryReminders;
use App\Console\Commands\SendPendingApprovalReminders;
use App\Console\Commands\SendQueryRaisedReport;
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

/*
 * Unanswered approval queries, every two days per query (the command works out which are due), to
 * the invoice submitters and the Finance user who raised the request. Same 09:00 slot and log.
 */
Schedule::command(SendApprovalQueryReminders::class)
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

/*
 * The weekly Query Raised Report: each department's open Finance queries to its Department Head(s),
 * then a summary of what was sent to Finance and admins. Fridays 08:00 Dubai time by default; the
 * day, time and on/off switch are in config('paf.query_raised_report'). Same scheduler and log.
 *
 * CR: ai/change-requests/schedule-weekly-query-raised-report-email-to-department-heads.md
 */
if (config('paf.query_raised_report.enabled')) {
    Schedule::command(SendQueryRaisedReport::class)
        ->weeklyOn((int) config('paf.query_raised_report.day'), (string) config('paf.query_raised_report.time'))
        ->timezone((string) config('paf.query_raised_report.timezone'))
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/scheduler.log'));
}
