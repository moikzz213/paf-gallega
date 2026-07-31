<?php

namespace App\Console\Commands;

use App\Mail\PaymentRequestReminder;
use App\Models\PaymentRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

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

        foreach ($pendingPRs as $pr) {
            $approval = $pr->currentApproval();

            if (! $approval || ! $approval->approver || ! $approval->approver->email) {
                continue;
            }

            Mail::to($approval->approver->email)->send(
                new PaymentRequestReminder($pr)
            );

            $pr->update(['last_reminder_sent_at' => now()]);
            $sentCount++;
        }

        $this->info("Sent {$sentCount} reminder(s) for pending payment requests.");

        return Command::SUCCESS;
    }
}
