<?php

namespace App\Services;

use App\Mail\PaymentRequestApproved;
use App\Mail\PaymentRequestSubmitted;
use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestApproval;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentRequestService
{
    /**
     * Create a payment request from posted invoices and route it into the approval chain.
     *
     * @param  array<int>  $invoiceIds
     * @param  array<int, array{approver_id: int, label: string, level: int|null, is_adhoc: bool}>  $stages  ordered chain
     */
    /**
     * A request covers one currency only.
     *
     * `total_amount` is a plain sum of the invoices and the approval thresholds are measured
     * against it, so a mixed set is nonsense on both counts. Public and static because the chain has
     * to be built before `create` runs, and building it converts each invoice into the base currency
     * — a mixed set should be turned away as a mixed set, not as whichever currency lacks a rate.
     */
    public static function assertSingleCurrency($invoices): void
    {
        $currencies = collect($invoices)->pluck('currency')->filter()->unique();

        if ($currencies->count() > 1) {
            throw ValidationException::withMessages([
                'invoices' => 'All invoices in a payment request must share one currency (selected: '.$currencies->implode(', ').').',
            ]);
        }
    }

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

        self::assertSingleCurrency($invoices);

        $stages = array_values(array_filter($stages, fn ($s) => ! empty($s['approver_id'])));
        if (empty($stages)) {
            throw ValidationException::withMessages(['approvers' => 'Add at least one approver to the chain.']);
        }

        // Finance raises payment requests and can also be nominated as an approver, so the two roles
        // can land on the same person. Nobody signs off their own request — every payment keeps a
        // second pair of eyes.
        if (in_array($creator->id, array_map(fn ($s) => (int) $s['approver_id'], $stages), true)) {
            throw ValidationException::withMessages([
                'approvers' => 'You cannot be an approver on a payment request you are creating — assign someone else to that stage.',
            ]);
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

        Notifier::send(
            $pr->currentApproval()?->approver?->email,
            new PaymentRequestSubmitted($pr),
            "{$pr->reference_no} sent for approval",
        );

        return $pr;
    }

    public function approve(PaymentRequest $pr, User $actor, ?string $comments = null): PaymentRequest
    {
        $this->assertActionable($pr, $actor);

        $finalized = false;
        $next = null;

        $pr = DB::transaction(function () use ($pr, $actor, $comments, &$finalized, &$next) {
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
                $finalized = true;
            }

            return $pr->refresh();
        });

        // Sent after the transaction commits, so a mail failure cannot roll back a recorded
        // approval and no mail goes out for a state change that did not persist.
        if ($finalized) {
            $this->notifyApproved($pr);
        } else {
            $this->notifyNextApprover($pr, $next);
        }

        return $pr;
    }

    /**
     * Tell the approver who now owns the request that it is waiting on them. Without this, a chain
     * advanced silently and later approvers only learned of it by opening the app.
     */
    public function notifyNextApprover(PaymentRequest $pr, ?PaymentRequestApproval $next): void
    {
        Notifier::send(
            $next?->approver?->email,
            new PaymentRequestSubmitted($pr),
            "stage {$next?->sequence} of {$pr->reference_no} routed for approval",
        );
    }

    /**
     * Notify the requestor(s) that a payment request has been fully approved —
     * the PRF creator plus everyone who submitted one of its invoices.
     */
    public function notifyApproved(PaymentRequest $pr): void
    {
        $pr->loadMissing('creator', 'invoices.submitter');

        $emails = collect([$pr->creator?->email])
            ->merge($pr->invoices->map(fn ($inv) => $inv->submitter?->email))
            ->filter()
            ->unique()
            ->values();

        foreach ($emails as $email) {
            Notifier::send($email, new PaymentRequestApproved($pr), "{$pr->reference_no} fully approved");
        }
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

    /**
     * Pull a fully approved request back out of the payment cycle, returning its invoices to the
     * Invoice Log so they can be corrected and re-approved.
     *
     * Rejection is the equivalent exit while a request is still in approval, but it stops being
     * available the moment the last stage approves — which left an approved-but-unpaid request with
     * no way back. Withdrawal does not reuse the approvals: the corrected invoices go through a
     * fresh chain, because a correction that changes the amount (or its currency) changes which
     * thresholds apply, and the recorded approvals only ever covered the old figure.
     */
    public function withdraw(PaymentRequest $pr, User $actor, string $reason): PaymentRequest
    {
        if ($pr->status === PaymentRequest::STATUS_IN_APPROVAL) {
            throw ValidationException::withMessages([
                'status' => 'This request is still in approval — reject it instead, which returns its invoices the same way.',
            ]);
        }

        if ($pr->status !== PaymentRequest::STATUS_APPROVED) {
            throw ValidationException::withMessages([
                'status' => 'Only a fully approved request that has not been paid can be withdrawn.',
            ]);
        }

        return DB::transaction(function () use ($pr, $actor, $reason) {
            // Read the invoices before detaching them — afterwards the relation is empty.
            $references = $pr->invoices()->pluck('reference_no')->implode(', ');

            $pr->update([
                'status' => PaymentRequest::STATUS_WITHDRAWN,
                'current_stage' => null,
                'withdrawn_at' => now(),
                'withdrawn_by' => $actor->id,
                'withdrawal_reason' => $reason,
            ]);

            $pr->invoices()->update([
                'payment_status' => Invoice::PAY_NOT_INITIATED,
                'payment_request_id' => null,
            ]);

            AuditLogger::log(
                'withdrawn',
                "Payment request {$pr->reference_no} withdrawn after approval: {$reason}. Invoices returned to Finance: {$references}.",
                null, null, null, $pr,
            );

            return $pr->refresh();
        });
    }

    /**
     * Return a single invoice to the Invoice Log, leaving the rest of its payment request intact.
     *
     * Withdrawal is the right move when the whole request is wrong; this is for the common case
     * where one invoice in a request needs correcting and the others are fine — they keep their
     * approvals and stay payable. Safe because the total only ever falls, and
     * `ApprovalLevel::requiredFor` filters on `min_amount`, so a smaller total requires a subset of
     * the levels that already approved the larger one.
     */
    public function releaseInvoice(Invoice $invoice, User $actor, string $reason): PaymentRequest
    {
        $pr = $invoice->paymentRequest;

        if (! $pr) {
            throw ValidationException::withMessages([
                'invoice' => 'This invoice is not held by a payment request.',
            ]);
        }

        if ($pr->status === PaymentRequest::STATUS_PAID || $invoice->payment_status === Invoice::PAY_PAID) {
            throw ValidationException::withMessages([
                'status' => 'A paid payment request cannot be changed.',
            ]);
        }

        if (! in_array($pr->status, [PaymentRequest::STATUS_IN_APPROVAL, PaymentRequest::STATUS_APPROVED], true)) {
            throw ValidationException::withMessages([
                'status' => 'Only an in-approval or approved payment request can have an invoice released.',
            ]);
        }

        return DB::transaction(function () use ($pr, $invoice, $actor, $reason) {
            $invoice->update([
                'payment_status' => Invoice::PAY_NOT_INITIATED,
                'payment_request_id' => null,
            ]);

            $remaining = $pr->invoices()->count();

            if ($remaining === 0) {
                // An approved request with nothing left to pay is void, not payable.
                $pr->update([
                    'status' => PaymentRequest::STATUS_WITHDRAWN,
                    'current_stage' => null,
                    'total_amount' => 0,
                    'withdrawn_at' => now(),
                    'withdrawn_by' => $actor->id,
                    'withdrawal_reason' => "Last invoice released: {$reason}",
                ]);
            } else {
                $pr->syncTotal();
            }

            AuditLogger::log(
                'invoice_released',
                "Invoice {$invoice->reference_no} released from {$pr->reference_no} and returned to the Invoice Log: {$reason}."
                    .($remaining === 0
                        ? " {$pr->reference_no} held nothing else and was withdrawn."
                        : " {$remaining} invoice(s) remain, total now {$pr->refresh()->total_amount}."),
                $invoice, null, null, $pr,
            );

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

        // Creation refuses to put the creator on the chain; this closes the same door at action time,
        // for chains built before that rule and for the admin override below.
        if ($pr->created_by === $actor->id) {
            abort(403, 'You created this payment request, so you cannot approve or reject it.');
        }

        $stage = $pr->approvals()->where('sequence', $pr->current_stage)->first();

        $canAct = $actor->isAdmin() || ($stage && $stage->approver_id === $actor->id);

        if (! $canAct) {
            abort(403, 'You are not the assigned approver for the current stage of this payment request.');
        }
    }
}
