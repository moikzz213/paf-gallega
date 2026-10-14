<?php

namespace App\Services;

use App\Mail\PaymentRequestApproved;
use App\Mail\PaymentRequestQueryRaised;
use App\Mail\PaymentRequestSubmitted;
use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestApproval;
use App\Models\PaymentRequestDocument;
use App\Models\PaymentRequestQuery;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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

    /**
     * A request has to ask for more than zero.
     *
     * An invoice may net below zero — a credit note that arrived on its own — but a payment request
     * is where money leaves, so the floor lifted off the invoice lives here. It is structural rather
     * than a policy preference: `ApprovalLevel::requiredFor` filters on `min_amount` (default 0), so
     * a request at or below zero matches no level at all, could be routed to nobody, and has nothing
     * to pay. A credit is settled by being selected alongside the charge it offsets.
     *
     * Public and static for the same reason as `assertSingleCurrency`: the chain is built before
     * `create` runs, and building it on a non-positive total finds no levels, which would surface as
     * a missing approver instead of the netting problem it actually is.
     */
    public static function assertPayableTotal($invoices): void
    {
        $invoices = collect($invoices);
        $total = round($invoices->sum(fn ($invoice) => (float) $invoice->total_amount), 2);

        if ($total > 0) {
            return;
        }

        $currency = $invoices->pluck('currency')->filter()->first() ?? '';
        $credits = $invoices->filter(fn ($invoice) => (float) $invoice->total_amount < 0);

        throw ValidationException::withMessages([
            'invoices' => trim("The selected invoices come to {$currency} ".number_format($total, 2))
                .', so nothing would be paid. '
                .($credits->isNotEmpty()
                    ? 'A credit note is settled against a charge — also select the invoice(s) that '
                        .$credits->pluck('reference_no')->implode(', ')
                        .' offsets, so the request comes to more than zero.'
                    : 'Select at least one invoice with an amount owed.'),
        ]);
    }

    /**
     * Apply Finance's "this one is a credit note" marks to a selection, in memory.
     *
     * Invoices already on record carry credit notes entered as **positive** amounts, because the
     * system used to refuse a negative one — so grouping such an invoice added it to the request
     * instead of deducting it. Marking it corrects the sign, which is the truth of the document: it
     * always was a credit note. Correcting rather than flagging is what keeps everything else
     * honest — the approval routing, the request total, `syncTotal`, the release and correction
     * guards, the PDF, the emails and the reports all read `total_amount`, and none of them has to
     * know this mark ever existed.
     *
     * Mutates the models without saving, so the caller can build the chain on the netted figure;
     * `create` persists the same flip inside its transaction, so a request that is then refused
     * never leaves a rewritten invoice behind.
     *
     * @param  array<int>  $creditIds  ids of invoices Finance marked as credit notes
     */
    public static function applyCreditMarks($invoices, array $creditIds): void
    {
        if (empty($creditIds)) {
            return;
        }

        $creditIds = array_map('intval', $creditIds);
        $marked = collect($invoices)->whereIn('id', $creditIds);

        if ($unknown = array_diff($creditIds, $marked->pluck('id')->all())) {
            throw ValidationException::withMessages([
                'credit_invoice_ids' => 'A credit-note mark refers to an invoice that is not in this selection ('.implode(', ', $unknown).').',
            ]);
        }

        // A negative invoice is already deducted as it stands, so there is no sign to correct —
        // marking it would turn a credit note back into a charge. The screen only offers the mark on
        // a positive invoice; this closes the same door on the server.
        $already = $marked->filter(fn ($invoice) => (float) $invoice->total_amount < 0);
        if ($already->isNotEmpty()) {
            throw ValidationException::withMessages([
                'credit_invoice_ids' => 'Already recorded as a credit note and deducted as it stands, so it cannot be marked again: '
                    .$already->pluck('reference_no')->implode(', ').'.',
            ]);
        }

        foreach ($marked as $invoice) {
            $invoice->amount = -(float) $invoice->amount;
            $invoice->tax_amount = -(float) $invoice->tax_amount;
            $invoice->total_amount = -(float) $invoice->total_amount;
        }
    }

    /**
     * Persist a credit-note mark. The invoice and its lines change sign together, so the header
     * stays the sum of its lines, and the correction is logged with the figures it replaced — the
     * original amounts are recoverable from the audit trail.
     */
    private function persistCreditMark(Invoice $invoice, PaymentRequest $pr): void
    {
        $old = [
            'amount' => $invoice->getOriginal('amount'),
            'tax_amount' => $invoice->getOriginal('tax_amount'),
            'total_amount' => $invoice->getOriginal('total_amount'),
        ];

        $invoice->save();

        foreach ($invoice->items as $item) {
            $item->update([
                'amount' => -(float) $item->amount,
                'tax_amount' => -(float) $item->tax_amount,
                'total_amount' => -(float) $item->total_amount,
            ]);
        }

        AuditLogger::log(
            'credit_note_marked',
            "Invoice {$invoice->reference_no} marked as a credit note when {$pr->reference_no} was raised: recorded as "
                ."{$invoice->currency} ".number_format((float) $old['total_amount'], 2).', corrected to '
                .number_format((float) $invoice->total_amount, 2)
                .' so it is deducted from the request instead of added to it.',
            $invoice, $old, $invoice->only(['amount', 'tax_amount', 'total_amount']), $pr,
        );
    }

    /**
     * @param  array<int>  $creditIds  ids of invoices Finance marked as credit notes (see applyCreditMarks)
     * @param  array<int, UploadedFile>  $documents  supporting documents for the request as a whole
     */
    public function create(array $invoiceIds, User $creator, array $stages, array $creditIds = [], array $documents = []): PaymentRequest
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

        // Ahead of both checks below, so the currency and the payable total are measured on what the
        // request will actually ask for once the marked invoices are deducted.
        self::applyCreditMarks($invoices, $creditIds);

        self::assertSingleCurrency($invoices);
        self::assertPayableTotal($invoices);

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

        $pr = DB::transaction(function () use ($invoices, $creator, $stages, $creditIds, $documents) {
            $pr = PaymentRequest::create([
                'reference_no' => PaymentRequest::nextReferenceNo(),
                'created_by' => $creator->id,
                'status' => PaymentRequest::STATUS_IN_APPROVAL,
                'current_stage' => 1,
                'total_amount' => $invoices->sum('total_amount'),
                // Recorded, not derived: it has to survive the invoices leaving the request.
                'currency' => $invoices->pluck('currency')->filter()->first(),
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

            // Inside the transaction, so a failure anywhere in creation leaves the invoice with the
            // sign it had. Before the reservation below, which is a query-builder update and would
            // otherwise be overwritten by the model save.
            foreach ($invoices->whereIn('id', array_map('intval', $creditIds)) as $invoice) {
                $this->persistCreditMark($invoice, $pr);
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

            // Last in the transaction: the files are the one part a rollback cannot undo, so they are
            // written only once everything else has succeeded (and cleaned up if this step fails).
            $this->attachDocuments($pr, $documents, $creator);

            return $pr->refresh();
        });

        $this->notifyNextApprover($pr, $pr->currentApproval());

        return $pr;
    }

    /**
     * Attach supporting documents to the request as a whole — evidence for the payment rather than
     * for one invoice, merged into the PAF alongside the invoices' own documents.
     *
     * Open while the request is in approval (or on hold for an approval query), approved or paid (a
     * rejected or withdrawn request is finished with). A document arriving once the chain has signed was not in front of the
     * approvers, and is recorded as such, the same way a late invoice document is.
     *
     * If storing any file fails, the files already written by this call are removed before the error
     * propagates, so a refused request leaves nothing behind on disk.
     *
     * @param  array<int, UploadedFile>  $files
     */
    public function attachDocuments(PaymentRequest $pr, array $files, User $uploader): void
    {
        if (! $files) {
            return;
        }

        if (! in_array($pr->status, [PaymentRequest::STATUS_IN_APPROVAL, PaymentRequest::STATUS_QUERIED, PaymentRequest::STATUS_APPROVED, PaymentRequest::STATUS_PAID], true)) {
            throw ValidationException::withMessages([
                'documents' => "Documents can no longer be attached to {$pr->reference_no} — it is {$pr->status}.",
            ]);
        }

        $existing = $pr->documents()->count();
        $max = (int) config('paf.max_documents');
        if ($existing + count($files) > $max) {
            throw ValidationException::withMessages([
                'documents' => "{$pr->reference_no} already holds {$existing} document(s); the limit is {$max}.",
            ]);
        }

        // A document added to answer an approval query is in front of the approvers when the request
        // comes back to them, so it is not late.
        $late = ! in_array($pr->status, [PaymentRequest::STATUS_IN_APPROVAL, PaymentRequest::STATUS_QUERIED], true);
        $stored = [];

        try {
            foreach ($files as $file) {
                $path = $file->store("payment-requests/{$pr->id}", 'local');
                $stored[] = $path;

                $pr->documents()->create([
                    'uploaded_by' => $uploader->id,
                    'uploaded_after_approval' => $late,
                    'original_name' => $file->getClientOriginalName(),
                    'file_path' => $path,
                    'mime_type' => $file->getClientMimeType(),
                    'size' => $file->getSize(),
                ]);

                AuditLogger::log(
                    $late ? 'request_document_uploaded_after_approval' : 'request_document_uploaded',
                    "Document '{$file->getClientOriginalName()}' attached to {$pr->reference_no}"
                        .($late ? ' after approval' : ''),
                    null, null, null, $pr,
                );
            }
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($stored);

            throw $e;
        }
    }

    /**
     * Take a request-level document off the request until it is paid — hidden from the page, the
     * PAF and the approvers' links, but never destroyed: approvers may already have relied on it,
     * so the file is kept and the request's history records the removal.
     *
     * CR: ai/change-requests/allow-removal-of-attachments-uploaded-by-finance.md
     */
    public function removeDocument(PaymentRequestDocument $document, User $actor): void
    {
        $pr = $document->paymentRequest;

        if (! $document->isRemovableBy($actor)) {
            abort(403, 'Only the person who uploaded this document, or an administrator, can remove it.');
        }

        if (! in_array($pr->status, [PaymentRequest::STATUS_IN_APPROVAL, PaymentRequest::STATUS_QUERIED, PaymentRequest::STATUS_APPROVED], true)) {
            throw ValidationException::withMessages([
                'document' => "Documents can no longer be removed from {$pr->reference_no} — it is {$pr->status}.",
            ]);
        }

        DB::transaction(function () use ($document, $pr, $actor) {
            $document->removeBy($actor);

            AuditLogger::log(
                'request_document_removed',
                "Document '{$document->original_name}' removed from {$pr->reference_no} (file retained)",
                null, null, ['document_id' => $document->id, 'original_name' => $document->original_name], $pr,
            );
        });
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
        // The stage goes with the mailable. It used to be re-derived at render time from
        // `current_stage`, which a queued job reads long after the chain has moved — and once that
        // is null, the approval link cannot be built and the whole notification is lost without
        // anyone noticing. See PaymentRequestSubmitted.
        if (! $next?->view_token) {
            Log::warning("Notification skipped ({$pr->reference_no} routed for approval): the stage has no approval token to link to.");

            return;
        }

        Notifier::send(
            $next->approver?->email,
            new PaymentRequestSubmitted($pr, $next),
            "stage {$next->sequence} of {$pr->reference_no} routed for approval",
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

    /**
     * An approver asks a question instead of deciding: the request goes on hold at their stage.
     *
     * This is what replaced rejection. Nothing is ended — the invoices stay on the request, the
     * approvals already given stand, and the stage stays pending with its approver — so once the
     * query is answered the request carries on from here rather than starting over. While it is
     * `queried`, `assertActionable` turns away every approval, on both routes.
     *
     * CR: ai/change-requests/replace-reject-with-query-in-payment-approval.md
     */
    public function query(PaymentRequest $pr, User $actor, string $question): PaymentRequest
    {
        $this->assertActionable($pr, $actor);

        [$pr, $query] = DB::transaction(function () use ($pr, $actor, $question) {
            $stage = $pr->currentApproval();

            $query = $pr->queries()->create([
                'payment_request_approval_id' => $stage->id,
                'sequence' => $stage->sequence,
                'stage_label' => $stage->label,
                'raised_by' => $actor->id,
                'question' => $question,
                'raised_at' => now(),
            ]);

            $pr->update(['status' => PaymentRequest::STATUS_QUERIED]);

            AuditLogger::log(
                'approval_query_raised',
                "Approval query raised on {$pr->reference_no} at stage {$stage->sequence} ({$stage->label}) by {$actor->name}: {$question}",
                null, null, null, $pr,
            );

            return [$pr->refresh(), $query];
        });

        // After the commit, like every other notice: a mail failure cannot undo a recorded query.
        $this->notifyQueryRaised($pr, $query);

        return $pr;
    }

    /**
     * Answer the open query and send the request back to the approver who asked.
     *
     * The stage they asked from is still pending and still `current_stage`, so it simply becomes
     * actionable again; the approvals before it were never touched. Any correction made while the
     * request was on hold went through the in-approval correction rules, which cannot raise the total
     * or change the currency, so those approvals still cover what is being paid.
     */
    public function resubmit(PaymentRequest $pr, User $actor, string $response): PaymentRequest
    {
        if ($pr->status !== PaymentRequest::STATUS_QUERIED) {
            throw ValidationException::withMessages(['status' => 'This payment request has no open approval query to answer.']);
        }

        if (! $pr->canBeResubmittedBy($actor)) {
            abort(403, 'Only the invoice submitter, Finance or an administrator can answer this approval query.');
        }

        $pr = DB::transaction(function () use ($pr, $actor, $response) {
            $query = $pr->openQuery();

            $query?->update([
                'resubmitted_by' => $actor->id,
                'response' => $response,
                'resubmitted_at' => now(),
            ]);

            // The approver is told below, so the daily reminder starts counting from now.
            $pr->update([
                'status' => PaymentRequest::STATUS_IN_APPROVAL,
                'last_reminder_sent_at' => now(),
            ]);

            AuditLogger::log(
                'approval_query_resubmitted',
                "Approval query on {$pr->reference_no} answered by {$actor->name} and resubmitted to stage {$pr->current_stage}"
                    .($query ? " ({$query->stage_label})" : '').": {$response}",
                null, null, null, $pr,
            );

            return $pr->refresh();
        });

        $this->notifyNextApprover($pr, $pr->currentApproval());

        return $pr;
    }

    /**
     * One email for the whole query, every recipient in BCC. Who gets it depends on the stage that
     * asked — see queryRecipients.
     */
    public function notifyQueryRaised(PaymentRequest $pr, PaymentRequestQuery $query): bool
    {
        return Notifier::sendBcc(
            $this->queryRecipients($pr, $query)->pluck('email'),
            new PaymentRequestQueryRaised($pr, $query),
            "approval query on {$pr->reference_no}",
        );
    }

    /**
     * Who is told that a query was raised, by the position of the stage that asked:
     *
     *  - always: the invoice submitter(s) and the Finance user who raised the request;
     *  - stage 2 and later: every approver before that stage;
     *  - stage 4 and later: the approver at that stage as well.
     *
     * "Stage" is the position in this request's chain, ad-hoc stages included. A person who
     * qualifies twice is listed once, and deactivated accounts are left out (and logged).
     *
     * @return Collection<int, User>
     */
    public function queryRecipients(PaymentRequest $pr, PaymentRequestQuery $query)
    {
        $pr->load('creator', 'invoices.submitter', 'approvals.approver');

        $stage = $query->sequence;

        $users = $this->answerers($pr)
            ->merge($stage >= 2
                ? $pr->approvals->where('sequence', '<', $stage)->map(fn ($a) => $a->approver)
                : [])
            ->merge($stage >= 4
                ? $pr->approvals->where('sequence', $stage)->map(fn ($a) => $a->approver)
                : []);

        return $this->activeRecipients($users, "approval query on {$pr->reference_no}");
    }

    /**
     * The people who can answer a query and are expected to: the invoice submitter(s) and the Finance
     * user who raised the request. They are the only ones reminded while it is unanswered.
     *
     * @return Collection<int, User>
     */
    public function queryReminderRecipients(PaymentRequest $pr)
    {
        $pr->load('creator', 'invoices.submitter');

        return $this->activeRecipients($this->answerers($pr), "approval query reminder for {$pr->reference_no}");
    }

    private function answerers(PaymentRequest $pr)
    {
        return collect([$pr->creator])->merge($pr->invoices->map(fn ($inv) => $inv->submitter));
    }

    private function activeRecipients($users, string $context)
    {
        $users = collect($users)->filter()->unique('id')->values();

        foreach ($users->reject(fn (User $u) => $u->is_active) as $inactive) {
            Log::info("Notification recipient left out ({$context}): {$inactive->name} is deactivated.");
        }

        return $users->filter(fn (User $u) => $u->is_active)->values();
    }

    /**
     * Pull a fully approved request back out of the payment cycle, returning its invoices to the
     * Invoice Log so they can be corrected and re-approved.
     *
     * While a request is still in approval (or on hold for an approval query) the exit is releasing
     * its invoices — releasing the last one withdraws the request. Approvers no longer reject, so
     * there is no whole-request exit before approval. Withdrawal does not reuse the approvals: the corrected invoices go through a
     * fresh chain, because a correction that changes the amount (or its currency) changes which
     * thresholds apply, and the recorded approvals only ever covered the old figure.
     */
    public function withdraw(PaymentRequest $pr, User $actor, string $reason): PaymentRequest
    {
        if (in_array($pr->status, [PaymentRequest::STATUS_IN_APPROVAL, PaymentRequest::STATUS_QUERIED], true)) {
            throw ValidationException::withMessages([
                'status' => 'This request is still in approval — release its invoices instead. Releasing the last one withdraws the request.',
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
     *
     * That "only ever falls" rests on every invoice total being positive. Vendor credit notes are
     * captured as negative lines, so the guard below asserts it rather than assuming it: releasing
     * an invoice worth nothing or less would *raise* what the request still asks for, past the
     * figure its recorded approvals cover.
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

        if (! in_array($pr->status, [PaymentRequest::STATUS_IN_APPROVAL, PaymentRequest::STATUS_QUERIED, PaymentRequest::STATUS_APPROVED], true)) {
            throw ValidationException::withMessages([
                'status' => 'Only an in-approval, queried or approved payment request can have an invoice released.',
            ]);
        }

        if ((float) $invoice->total_amount <= 0 && $pr->invoices()->count() > 1) {
            throw ValidationException::withMessages([
                'invoice' => "Nothing is owed on {$invoice->reference_no} ({$invoice->currency} {$invoice->total_amount}), so releasing it would raise what {$pr->reference_no} still asks for — above the figure its approvals cover. Withdraw {$pr->reference_no} instead, then send the invoices that are still needed for approval again.",
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

    /**
     * Correct the payment reference on a paid request.
     *
     * The reference is the transfer or cheque number, typed by hand when the payment is recorded, and
     * it is what reconciles a PAF payment to the bank statement. Until now it could only ever be set
     * once: `markPaid` refuses a request that is not `approved`, so one already paid can never pass
     * through it again, and nothing else writes the field. A typo was therefore permanent.
     *
     * Only the person who recorded the payment may correct it — they had the bank record in front of
     * them — or an admin, which is the way through once that person has left. Correcting is not
     * re-paying: `paid_by`, `paid_at` and the `paid` status stand, because they are facts about what
     * happened; the correction is logged as its own event with the value it replaced.
     *
     * No uniqueness rule: one transfer legitimately settles several requests, so two of them sharing
     * a reference is normal rather than a mistake.
     */
    public function updatePaymentReference(PaymentRequest $pr, User $actor, string $reference): PaymentRequest
    {
        if ($pr->status !== PaymentRequest::STATUS_PAID) {
            throw ValidationException::withMessages([
                'status' => 'Only a paid payment request has a payment reference to correct.',
            ]);
        }

        if ($pr->paid_by !== $actor->id && ! $actor->isAdmin()) {
            $payer = $pr->payer?->name ?? 'whoever recorded the payment';
            abort(403, "This payment was recorded by {$payer}, so only they can correct its reference. Ask them, or an administrator, to make the correction.");
        }

        $old = $pr->payment_reference;

        $pr->update(['payment_reference' => $reference]);

        AuditLogger::log(
            'payment_reference_corrected',
            "Payment reference corrected on {$pr->reference_no}: {$old} → {$reference}. Recorded as paid by "
                .($pr->payer?->name ?? 'unknown')
                .($pr->paid_at ? ' on '.$pr->paid_at->format('Y-m-d') : '')
                .', which the correction does not change.',
            null, ['payment_reference' => $old], ['payment_reference' => $reference], $pr,
        );

        return $pr->refresh();
    }

    private function assertActionable(PaymentRequest $pr, User $actor): void
    {
        // A queried request is on hold for everyone, the approver who asked included, until the
        // submitter, Finance or an admin answers and resubmits it.
        if ($pr->status === PaymentRequest::STATUS_QUERIED) {
            throw ValidationException::withMessages(['status' => 'This payment request is on hold for an approval query. It can be approved once the query is answered and the request is resubmitted.']);
        }

        if ($pr->status !== PaymentRequest::STATUS_IN_APPROVAL) {
            throw ValidationException::withMessages(['status' => 'This payment request is not awaiting approval.']);
        }

        // Creation refuses to put the creator on the chain; this closes the same door at action time,
        // for chains built before that rule and for the admin override below.
        if ($pr->created_by === $actor->id) {
            abort(403, 'You created this payment request, so you cannot approve or query it.');
        }

        $stage = $pr->approvals()->where('sequence', $pr->current_stage)->first();

        $canAct = $actor->isAdmin() || ($stage && $stage->approver_id === $actor->id);

        if (! $canAct) {
            abort(403, 'You are not the assigned approver for the current stage of this payment request.');
        }
    }
}
