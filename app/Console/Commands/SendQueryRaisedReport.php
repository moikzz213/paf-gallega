<?php

namespace App\Console\Commands;

use App\Mail\QueryRaisedReportSummary;
use App\Mail\QueryRaisedWeeklyReport;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Notifier;
use App\Services\QueryRaisedReportService;
use Illuminate\Console\Command;

/**
 * The weekly Query Raised Report. Each department with open Finance queries gets one email, to its
 * Department Head(s) in BCC; a department with none gets nothing. Afterwards Finance and admins get
 * one summary of what was sent, including the departments nobody received — no Head nominated, or
 * a department name on the invoices that is not in master data — so a gap is never silent.
 *
 * Scheduled in routes/console.php (Fridays 08:00 Dubai time by default, see config paf.query_raised_report).
 * Safe to run by hand for testing or to catch up after an outage: it only reads, and sends.
 *
 * CR: ai/change-requests/schedule-weekly-query-raised-report-email-to-department-heads.md
 */
class SendQueryRaisedReport extends Command
{
    protected $signature = 'paf:send-query-raised-report';

    protected $description = 'Email each Department Head the open Finance queries on their department\'s invoices, then send Finance a summary';

    public function handle(QueryRaisedReportService $report): int
    {
        $reportDate = now()->format('d M Y, h:i A');

        $sent = [];
        $withoutHead = [];
        $unmatched = [];

        foreach ($report->groups() as $group) {
            $count = count($group['rows']);

            if (! $group['department']) {
                $unmatched[] = ['department' => $group['name'], 'count' => $count];

                continue;
            }

            if ($group['recipients']->isEmpty()) {
                $withoutHead[] = ['department' => $group['name'], 'count' => $count];

                continue;
            }

            $ok = Notifier::sendBcc(
                $group['recipients']->pluck('email'),
                new QueryRaisedWeeklyReport($group['name'], $group['rows'], $group['totals'], $reportDate),
                "weekly query raised report for {$group['name']}",
            );

            $names = $group['recipients']->pluck('name')->all();
            $sent[] = ['department' => $group['name'], 'recipients' => $names, 'count' => $count, 'sent' => $ok];

            if ($ok) {
                AuditLogger::log(
                    'query_raised_report_sent',
                    "Weekly Query Raised Report for \"{$group['name']}\" ({$count} open ".($count === 1 ? 'query' : 'queries').') sent to '.implode(', ', $names),
                );
            }
        }

        $summaryRecipients = User::query()
            ->where('is_active', true)
            ->whereIn('role', [User::ROLE_FINANCE, User::ROLE_ADMIN])
            ->pluck('email');

        Notifier::sendBcc(
            $summaryRecipients,
            new QueryRaisedReportSummary($sent, $withoutHead, $unmatched, $reportDate),
            'weekly query raised report summary',
        );

        $delivered = count(array_filter($sent, fn ($line) => $line['sent']));
        $this->info("Sent the Query Raised Report to {$delivered} department(s).");

        if ($failed = count($sent) - $delivered) {
            $this->warn("{$failed} department report(s) could not be sent — see the log. Re-run the command once mail is working.");
        }

        if ($withoutHead || $unmatched) {
            $this->warn((count($withoutHead) + count($unmatched)).' department(s) with open queries had nobody to send to — see the summary email.');
        }

        return Command::SUCCESS;
    }
}
