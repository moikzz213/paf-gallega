<?php

namespace App\Services;

use App\Mail\PaymentRequestSubmitted;
use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestApproval;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class PaymentRequestService
{
    /**
     * Create a payment request from posted invoices and route it into the approval chain.
     *
     * @param  array<int>  $invoiceIds
     * @param  array<int, array{approver_id: int, label: string, level: int|null, is_adhoc: bool}>  $stages  ordered chain
     */
    public function create(array $invoiceIds, User $creator, array $stages): PaymentRequest
    {
        $invoices = Invoice::whereIn('id', $invoiceIds)->get();

        if ($invoices->isEmpty()) {
            throw ValidationException::withMessages(['invoices' => 'Select at least one invoice.']);
        }

        $notPayable = $invoices->reject->isPayable();
        if ($notPayable->isNotEmpty()) {
            throw ValidationException::withMessages([
                'invoices' => 'Some invoices are not eligible for payment: '.$notPayable->pluck('reference_no')->implode(', '),
            ]);
        }

        $stages = array_values(array_filter($stages, fn ($s) => ! empty($s['approver_id'])));
        if (empty($stages)) {
            throw ValidationException::withMessages(['approvers' => 'Add at least one approver to the chain.']);
        }

        $pr = DB::transaction(function () use ($invoices, $creator, $stages) {
            $pr = PaymentRequest::create([
                'reference_no' => PaymentRequest::nextReferenceNo(),
                'created_by' => $creator->id,
                'status' => PaymentRequest::STATUS_IN_APPROVAL,
                'current_stage' => 1,
                'total_amount' => $invoices->sum('total_amount'),
                'sent_at' => now(),
                'last_reminder_sent_at' => now(),
            ]);

            foreach ($stages as $i => $stage) {
                PaymentRequestApproval::create([
                    'payment_request_id' => $pr->id,
                    'sequence' => $i + 1,
                    'level' => $stage['level'] ?? null,
                    'label' => trim((string) ($stage['label'] ?? '')) ?: 'Approver',
                    'is_adhoc' => (bool) ($stage['is_adhoc'] ?? false),
                    'approver_id' => (int) $stage['approver_id'],
                    'status' => PaymentRequestApproval::STATUS_PENDING,
                ]);
            }

            Invoice::whereIn('id', $invoices->pluck('id'))->update([
                'payment_request_id' => $pr->id,
                'payment_status' => Invoice::PAY_IN_APPROVAL,
            ]);

            AuditLogger::log(
                'payment_initiated',
                "Payment request {$pr->reference_no} created for {$invoices->count()} invoice(s) ({$invoices->sum('total_amount')}); routed to {$pr->currentApproval()?->approver?->name}",
                null, null, null, $pr,
            );

            return $pr->refresh();
        });

        $approver = $pr->currentApproval()?->approver;
        if ($approver && $approver->email) {
            Mail::to($approver->email)->send(new PaymentRequestSubmitted($pr));
        }

        return $pr;
    }

    public function approve(PaymentRequest $pr, User $actor, ?string $comments = null): PaymentRequest
    {
        $this->assertActionable($pr, $actor);

        return DB::transaction(function () use ($pr, $actor, $comments) {
            $pr->approvals()->where('sequence', $pr->current_stage)->update([
                'status' => PaymentRequestApproval::STATUS_APPROVED,
                'approver_id' => $actor->id,
                'comments' => $comments,
                'acted_at' => now(),
            ]);

            $next = $pr->approvals()
                ->where('status', PaymentRequestApproval::STATUS_PENDING)
                ->orderBy('sequence')
                ->first();

            if ($next) {
                $pr->update(['current_stage' => $next->sequence]);
                AuditLogger::log('approved', "Stage {$pr->current_stage} approved on {$pr->reference_no}; moved to {$next->label}", null, null, null, $pr);
            } else {
                $pr->update([
                    'status' => PaymentRequest::STATUS_APPROVED,
                    'current_stage' => null,
                    'approved_at' => now(),
                ]);
                $pr->invoices()->update(['payment_status' => Invoice::PAY_APPROVED]);
                AuditLogger::log('approved', "Payment request {$pr->reference_no} fully approved — released for payment", null, null, null, $pr);
            }

            return $pr->refresh();
        });
    }

    public function reject(PaymentRequest $pr, User $actor, string $comments): PaymentRequest
    {
        $this->assertActionable($pr, $actor);

        return DB::transaction(function () use ($pr, $actor, $comments) {
            $pr->approvals()->where('sequence', $pr->current_stage)->update([
                'status' => PaymentRequestApproval::STATUS_REJECTED,
                'approver_id' => $actor->id,
                'comments' => $comments,
                'acted_at' => now(),
            ]);

            $stage = $pr->current_stage;

            $pr->update([
                'status' => PaymentRequest::STATUS_REJECTED,
                'current_stage' => null,
                'rejected_at' => now(),
                'rejection_reason' => $comments,
            ]);

            // Return the invoices to the pool so Finance can re-initiate after correction.
            $pr->invoices()->update([
                'payment_status' => Invoice::PAY_NOT_INITIATED,
                'payment_request_id' => null,
            ]);

            AuditLogger::log('rejected', "Payment request {$pr->reference_no} rejected at stage {$stage}. Invoices returned to Finance.", null, null, null, $pr);

            return $pr->refresh();
        });
    }

    public function markPaid(PaymentRequest $pr, User $actor, string $reference): PaymentRequest
    {
        if ($pr->status !== PaymentRequest::STATUS_APPROVED) {
            throw ValidationException::withMessages(['status' => 'Only fully approved payment requests can be marked paid.']);
        }

        return DB::transaction(function () use ($pr, $actor, $reference) {
            $pr->update([
                'status' => PaymentRequest::STATUS_PAID,
                'paid_at' => now(),
                'payment_reference' => $reference,
                'paid_by' => $actor->id,
            ]);

            $pr->invoices()->update(['payment_status' => Invoice::PAY_PAID]);

            AuditLogger::log('paid', "Payment request {$pr->reference_no} marked paid (ref {$reference})", null, null, null, $pr);

            return $pr->refresh();
        });
    }

    private function assertActionable(PaymentRequest $pr, User $actor): void
    {
        if ($pr->status !== PaymentRequest::STATUS_IN_APPROVAL) {
            throw ValidationException::withMessages(['status' => 'This payment request is not awaiting approval.']);
        }

        $stage = $pr->approvals()->where('sequence', $pr->current_stage)->first();

        $canAct = $actor->isAdmin() || ($stage && $stage->approver_id === $actor->id);

        if (! $canAct) {
            abort(403, 'You are not the assigned approver for the current stage of this payment request.');
        }
    }
}
