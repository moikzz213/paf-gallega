<?php

namespace App\Console\Commands;

use App\Mail\PaymentRequestQueryRaised;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestQuery;
use App\Services\Notifier;
use App\Services\PaymentRequestService;
use Illuminate\Console\Command;

/**
 * Every two days, remind the people who must answer an open Approval Query that it is still waiting:
 * the invoice submitter(s) and the Finance user who raised the request, in one BCC email. Approvers
 * are not reminded — they cannot act until the request is resubmitted.
 *
 * Runs daily; a query is due when its last reminder (or, before the first, the query itself) fell on
 * a day before yesterday. A query raised on Monday is reminded on Wednesday, then Friday, and so on,
 * whatever time of day it was raised.
 *
 * CR: ai/change-requests/replace-reject-with-query-in-payment-approval.md
 */
class SendApprovalQueryReminders extends Command
{
    protected $signature = 'prf:send-query-reminders';

    protected $description = 'Remind the submitters and Finance every two days about unanswered approval queries';

    public function handle(PaymentRequestService $service): int
    {
        $cutoff = now()->subDay()->startOfDay();

        $due = PaymentRequestQuery::query()
            ->whereNull('resubmitted_at')
            ->whereHas('paymentRequest', fn ($q) => $q->where('status', PaymentRequest::STATUS_QUERIED))
            ->where(fn ($q) => $q
                ->where(fn ($first) => $first->whereNull('last_reminder_sent_at')->where('raised_at', '<', $cutoff))
                ->orWhere('last_reminder_sent_at', '<', $cutoff))
            ->with('paymentRequest')
            ->get();

        $sent = 0;
        $failed = 0;

        foreach ($due as $query) {
            $pr = $query->paymentRequest;

            // Only a reminder that was accepted stamps the query, so a failed send is retried tomorrow
            // rather than waiting another two days.
            if (! Notifier::sendBcc(
                $service->queryReminderRecipients($pr)->pluck('email'),
                new PaymentRequestQueryRaised($pr, $query, reminder: true),
                "approval query reminder for {$pr->reference_no}",
            )) {
                $failed++;

                continue;
            }

            $query->update(['last_reminder_sent_at' => now()]);
            $sent++;
        }

        $this->info("Sent {$sent} reminder(s) for unanswered approval queries.");

        if ($failed > 0) {
            $this->warn("{$failed} reminder(s) could not be sent — see the log. They will be retried on the next run.");
        }

        return Command::SUCCESS;
    }
}
