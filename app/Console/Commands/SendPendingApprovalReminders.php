<?php

namespace App\Console\Commands;

use App\Mail\PaymentRequestReminder;
use App\Models\PaymentRequest;
use App\Services\Notifier;
use Illuminate\Console\Command;

class SendPendingApprovalReminders extends Command
{
    protected $signature = 'prf:send-reminders';

    protected $description = 'Send daily email reminders for payment requests awaiting approval';

    public function handle(): int
    {
        $pendingPRs = PaymentRequest::where('status', PaymentRequest::STATUS_IN_APPROVAL)
            ->where(function ($query) {
                $query->whereNull('last_reminder_sent_at')
                    ->orWhere('last_reminder_sent_at', '<', now()->startOfDay());
            })
            ->with('approvals.approver', 'creator', 'invoices.submitter', 'invoices.items.customer')
            ->get();

        $sentCount = 0;
        $failed = 0;

        foreach ($pendingPRs as $pr) {
            $approval = $pr->currentApproval();

            if (! $approval || ! $approval->approver || ! $approval->approver->email) {
                continue;
            }

            // One unreachable approver must not stop the rest of the run, and only a reminder that
            // was actually accepted should stamp last_reminder_sent_at — otherwise a failed send
            // would suppress tomorrow's attempt too.
            if (! Notifier::send($approval->approver->email, new PaymentRequestReminder($pr), "reminder for {$pr->reference_no}")) {
                $failed++;

                continue;
            }

            $pr->update(['last_reminder_sent_at' => now()]);
            $sentCount++;
        }

        $this->info("Sent {$sentCount} reminder(s) for pending payment requests.");

        if ($failed > 0) {
            $this->warn("{$failed} reminder(s) could not be sent — see the log. They will be retried on the next run.");
        }

        return Command::SUCCESS;
    }
}
