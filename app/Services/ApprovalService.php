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
     *
     * Builds one pending approval row per required level (by amount threshold). The approver for
     * each level is taken from $assignments (level => user id), falling back to the level's
     * configured default approver. Extra ad-hoc stages can be appended after the required levels.
     *
     * @param  array<int|string, int|null>  $assignments  map of approval level => approver user id
     * @param  array<int, array{approver_id: int, label?: string|null}>  $adhoc  extra stages, in order
     */
    public function submit(Invoice $invoice, array $assignments = [], array $adhoc = []): Invoice
    {
        if (! $invoice->isEditable()) {
            throw ValidationException::withMessages(['status' => 'Only draft or rejected requests can be submitted.']);
        }

        $levels = ApprovalLevel::requiredFor((float) $invoice->total_amount);

        if ($levels->isEmpty()) {
            throw ValidationException::withMessages(['status' => 'No active approval levels are configured. Contact the administrator.']);
        }

        return DB::transaction(function () use ($invoice, $levels, $assignments, $adhoc) {
            // resubmission after rejection starts a fresh cycle
            $invoice->approvals()->delete();

            foreach ($levels as $level) {
                $approverId = $assignments[$level->level] ?? $level->default_approver_id;

                InvoiceApproval::create([
                    'invoice_id' => $invoice->id,
                    'level' => $level->level,
                    'level_name' => $level->name,
                    'is_adhoc' => false,
                    'approver_id' => $approverId ? (int) $approverId : null,
                    'status' => InvoiceApproval::STATUS_PENDING,
                ]);
            }

            // Ad-hoc stages are appended after every configured level, in the given order.
            $nextLevel = ((int) $levels->max('level')) + 1;
            foreach ($adhoc as $stage) {
                $approverId = $stage['approver_id'] ?? null;
                if (! $approverId) {
                    continue;
                }

                InvoiceApproval::create([
                    'invoice_id' => $invoice->id,
                    'level' => $nextLevel,
                    'level_name' => trim((string) ($stage['label'] ?? '')) ?: 'Additional approver',
                    'is_adhoc' => true,
                    'approver_id' => (int) $approverId,
                    'status' => InvoiceApproval::STATUS_PENDING,
                ]);
                $nextLevel++;
            }

            $stages = $invoice->approvals()->orderBy('level')->get();

            $invoice->update([
                'status' => Invoice::STATUS_PENDING,
                'current_level' => $stages->first()->level,
                'submitted_at' => now(),
                'approved_at' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ]);

            AuditLogger::log('submitted', "Request {$invoice->reference_no} submitted for approval ({$stages->count()} stage(s))", $invoice);

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

        $stage = $invoice->approvals()->where('level', $invoice->current_level)->first();

        $canAct = $approver->isAdmin()
            // the approver assigned to the current stage
            || ($stage && $stage->approver_id === $approver->id)
            // legacy / unassigned fallback: any approver whose level matches
            || ($stage && $stage->approver_id === null
                && $approver->isApprover() && $approver->approval_level === $invoice->current_level);

        if (! $canAct) {
            abort(403, 'You are not the assigned approver for the current stage of this request.');
        }
    }
}
