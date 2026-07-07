<?php

namespace App\Services;

use App\Models\ApprovalLevel;
use App\Models\Invoice;
use App\Models\InvoiceApproval;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovalService
{
    /**
     * Submit a draft (or rejected) invoice into the approval workflow.
     * Builds one pending approval row per required level based on amount thresholds.
     */
    public function submit(Invoice $invoice): Invoice
    {
        if (! $invoice->isEditable()) {
            throw ValidationException::withMessages(['status' => 'Only draft or rejected requests can be submitted.']);
        }

        $levels = ApprovalLevel::requiredFor((float) $invoice->total_amount);

        if ($levels->isEmpty()) {
            throw ValidationException::withMessages(['status' => 'No active approval levels are configured. Contact the administrator.']);
        }

        return DB::transaction(function () use ($invoice, $levels) {
            // resubmission after rejection starts a fresh cycle
            $invoice->approvals()->delete();

            foreach ($levels as $level) {
                InvoiceApproval::create([
                    'invoice_id' => $invoice->id,
                    'level' => $level->level,
                    'level_name' => $level->name,
                    'status' => InvoiceApproval::STATUS_PENDING,
                ]);
            }

            $invoice->update([
                'status' => Invoice::STATUS_PENDING,
                'current_level' => $levels->first()->level,
                'submitted_at' => now(),
                'approved_at' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ]);

            AuditLogger::log('submitted', "Request {$invoice->reference_no} submitted for approval (levels: {$levels->pluck('level')->implode(', ')})", $invoice);

            return $invoice->refresh();
        });
    }

    public function approve(Invoice $invoice, User $approver, ?string $comments = null): Invoice
    {
        $this->assertActionable($invoice, $approver);

        return DB::transaction(function () use ($invoice, $approver, $comments) {
            $invoice->approvals()
                ->where('level', $invoice->current_level)
                ->update([
                    'status' => InvoiceApproval::STATUS_APPROVED,
                    'approver_id' => $approver->id,
                    'comments' => $comments,
                    'acted_at' => now(),
                ]);

            $next = $invoice->approvals()
                ->where('status', InvoiceApproval::STATUS_PENDING)
                ->orderBy('level')
                ->first();

            if ($next) {
                $invoice->update(['current_level' => $next->level]);
                AuditLogger::log('approved', "Level {$approver->approval_level} approved {$invoice->reference_no}; moved to level {$next->level}", $invoice);
            } else {
                $invoice->update([
                    'status' => Invoice::STATUS_APPROVED,
                    'current_level' => null,
                    'approved_at' => now(),
                ]);
                AuditLogger::log('approved', "Final approval granted for {$invoice->reference_no}", $invoice);
            }

            return $invoice->refresh();
        });
    }

    public function reject(Invoice $invoice, User $approver, string $comments): Invoice
    {
        $this->assertActionable($invoice, $approver);

        return DB::transaction(function () use ($invoice, $approver, $comments) {
            $invoice->approvals()
                ->where('level', $invoice->current_level)
                ->update([
                    'status' => InvoiceApproval::STATUS_REJECTED,
                    'approver_id' => $approver->id,
                    'comments' => $comments,
                    'acted_at' => now(),
                ]);

            $invoice->update([
                'status' => Invoice::STATUS_REJECTED,
                'current_level' => null,
                'rejected_at' => now(),
                'rejection_reason' => $comments,
            ]);

            AuditLogger::log('rejected', "Request {$invoice->reference_no} rejected at level {$approver->approval_level}", $invoice);

            return $invoice->refresh();
        });
    }

    private function assertActionable(Invoice $invoice, User $approver): void
    {
        if ($invoice->status !== Invoice::STATUS_PENDING) {
            throw ValidationException::withMessages(['status' => 'This request is not pending approval.']);
        }

        $canAct = $approver->isAdmin()
            || ($approver->isApprover() && $approver->approval_level === $invoice->current_level);

        if (! $canAct) {
            abort(403, 'You are not the approver for the current level of this request.');
        }
    }
}
