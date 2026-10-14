<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    // Intake lifecycle (the Invoice Log)
    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_POSTED = 'posted';

    public const STATUS_QUERY = 'query_raised';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_POSTED,
        self::STATUS_QUERY,
        self::STATUS_CANCELLED,
    ];

    // Where the invoice sits in the payment cycle
    public const PAY_NOT_INITIATED = 'not_initiated';

    public const PAY_IN_APPROVAL = 'in_approval';

    public const PAY_APPROVED = 'approved_for_payment';

    public const PAY_PAID = 'paid';

    public const PAYMENT_STATUSES = [
        self::PAY_NOT_INITIATED,
        self::PAY_IN_APPROVAL,
        self::PAY_APPROVED,
        self::PAY_PAID,
    ];

    protected $fillable = [
        'reference_no', 'vendor_name', 'vendor_id',
        'invoice_no', 'invoice_no_exempt', 'invoice_date', 'due_date', 'currency',
        'amount', 'tax_amount', 'total_amount',
        'business_unit', 'department', 'location', 'payment_method',
        'priority', 'is_advance_payment', 'description', 'status', 'submitted_by', 'submitted_at',
        'posting_date', 'erp_doc_no', 'finance_remarks', 'posted_by', 'posted_at',
        'payment_status', 'payment_request_id', 'subject_to_document_checklist',
    ];

    protected function casts(): array
    {
        return [
            'is_advance_payment' => 'boolean',
            'invoice_no_exempt' => 'boolean',
            'subject_to_document_checklist' => 'boolean',
            'invoice_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'posting_date' => 'date:Y-m-d',
            'amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function poster()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function paymentRequest()
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    /**
     * The payment requests this invoice was on that were rejected, oldest first.
     *
     * Separate from `paymentRequest`, which only ever holds the request the invoice is on *now* —
     * rejection clears it, and re-submission overwrites it. See InvoiceRejection.
     */
    public function rejections()
    {
        return $this->hasMany(InvoiceRejection::class)->oldest('rejected_at');
    }

    public function documents()
    {
        return $this->hasMany(InvoiceDocument::class);
    }

    /**
     * Whether a department files its documents against the checklist (Freight Forwarding). Static so
     * the form's department can be tested before the invoice exists or before a change is saved.
     *
     * CR: ai/change-requests/add-freight-forwarding-document-checklist-to-invoice-submission.md
     */
    public static function usesDocumentChecklist(?string $department): bool
    {
        return $department !== null
            && strcasecmp(trim($department), (string) config('paf.document_checklist.department')) === 0;
    }

    /** The most documents an invoice in this department may hold. */
    public static function maxDocumentsFor(?string $department): int
    {
        return self::usesDocumentChecklist($department)
            ? (int) config('paf.document_checklist.max_documents')
            : (int) config('paf.max_documents');
    }

    /**
     * The required checklist items that hold no document, as their labels. Counts what is already
     * stored and the types arriving with this save. Empty for any other department, and for an
     * invoice submitted before the checklist existed — those are exempt, so correcting one is never
     * blocked by a rule it was not submitted under.
     *
     * @param  array<int, string|null>  $incomingTypes
     * @return array<int, string>
     */
    public static function missingChecklistItems(?self $invoice, ?string $department, array $incomingTypes): array
    {
        if (! self::usesDocumentChecklist($department)) {
            return [];
        }

        if ($invoice && ! $invoice->subject_to_document_checklist) {
            return [];
        }

        $present = collect($incomingTypes)
            ->merge($invoice ? $invoice->documents()->pluck('document_type') : [])
            ->filter()
            ->unique();

        $types = config('paf.document_checklist.types');

        return collect(config('paf.document_checklist.required'))
            ->reject(fn ($type) => $present->contains($type))
            ->map(fn ($type) => $types[$type] ?? $type)
            ->values()
            ->all();
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order');
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class)->latest('created_at');
    }

    /**
     * Every query Finance has raised on this invoice, oldest first. The invoice itself keeps only
     * the latest query text in `finance_remarks`, so the history lives in the audit log.
     */
    public function queryLogs()
    {
        return $this->hasMany(AuditLog::class)
            ->where('action', 'query_raised')
            ->oldest('created_at');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->canViewAllInvoices()) {
            return $query;
        }

        // Everyone else sees what is shared with them, and approvers also the invoices in a PRF routed
        // to them.
        return $query->where(function (Builder $q) use ($user) {
            $q->sharedWith($user);

            if ($user->isApprover()) {
                $q->orWhereHas('paymentRequest.approvals', fn (Builder $a) => $a->where('approver_id', $user->id));
            }
        });
    }

    /**
     * Submitted by the user, by a colleague an admin granted them (User::viewableColleagues), or —
     * for a requester — by anyone in their own department (User::departmentColleagueIds), the
     * department being the submitter's, not the one picked on the invoice. Read-only: no write
     * check consults this.
     *
     * CR: ai/change-requests/show-paf-invoices-to-all-users-in-the-same-department.md
     */
    public function scopeSharedWith(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('submitted_by', $user->id)
            ->orWhereIn('submitted_by', $user->viewableColleagueIds())
            ->when($user->departmentColleagueIds(), fn (Builder $q, $department) => $q->orWhereIn('submitted_by', $department)));
    }

    /** Editable while it is still in the log and not yet in a payment cycle. */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_SUBMITTED, self::STATUS_QUERY], true)
            && $this->payment_status === self::PAY_NOT_INITIATED;
    }

    /**
     * Posted to the ERP but not yet in a payment cycle, so Finance/admin may correct it directly
     * rather than querying it back to the submitter. Nobody has approved anything yet, so no field
     * is locked; the invoice stays posted under its ERP document. Includes invoices returned from a
     * rejected, withdrawn or released request, which come back posted. See InvoiceController::update.
     *
     * CR: ai/change-requests/allow-finance-to-correct-posted-invoices-before-payment.md
     */
    public function isCorrectableAfterPosting(): bool
    {
        return $this->status === self::STATUS_POSTED
            && $this->payment_status === self::PAY_NOT_INITIATED;
    }

    /**
     * Held by a payment request that has not been paid, so Finance/admin may still correct it in
     * place — within limits that cannot invalidate the approvals it already carries. See
     * InvoiceController::assertInPlaceCorrection.
     */
    public function isCorrectableInPlace(): bool
    {
        return in_array($this->payment_status, [self::PAY_IN_APPROVAL, self::PAY_APPROVED], true)
            && $this->status !== self::STATUS_CANCELLED;
    }

    /**
     * The submitter's own correction window: narrower than Finance's. Open only while the payment
     * request is still in approval — including while it is on hold for an approval query, which is
     * often what asks for the correction — and closed for good once the chain has signed. After
     * that, a change goes through Finance. The limits are Finance's plus a locked vendor; see
     * InvoiceController::update.
     */
    public function isCorrectableBySubmitter(): bool
    {
        return $this->payment_status === self::PAY_IN_APPROVAL
            && $this->status !== self::STATUS_CANCELLED
            && in_array($this->paymentRequest?->status, [PaymentRequest::STATUS_IN_APPROVAL, PaymentRequest::STATUS_QUERIED], true);
    }

    /**
     * Open to a supporting document even though the record itself is closed to change.
     *
     * An advance is raised and paid *before* the vendor's final tax invoice exists, so the document
     * that completes the record turns up after the payment is done — the point at which everything
     * else is deliberately frozen. Attaching evidence alters nothing anyone approved, so this is
     * allowed where a correction is not; `isCorrectableInPlace` stays exactly as it was, and the
     * upload route ignores every field but the files (see InvoiceController::uploadDocuments).
     *
     * Advance payments only. An ordinary invoice has its documents before it is paid, and widening
     * this to all of them would reopen closed records for no stated need.
     */
    public function acceptsLateDocuments(): bool
    {
        return $this->is_advance_payment
            && $this->status !== self::STATUS_CANCELLED
            && in_array($this->payment_status, [self::PAY_IN_APPROVAL, self::PAY_APPROVED, self::PAY_PAID], true);
    }

    /**
     * Past the point where documents are simply deleted (isEditable), but not yet paid: a document
     * may still be *removed* — hidden everywhere, file kept, audited — by its uploader or an admin.
     * Covers correction after posting, correction in place, and late uploads on an advance until it
     * is paid. See DocumentController::destroy.
     *
     * CR: ai/change-requests/allow-removal-of-attachments-uploaded-by-finance.md
     */
    public function acceptsDocumentRemoval(): bool
    {
        return $this->status === self::STATUS_POSTED
            && $this->payment_status !== self::PAY_PAID;
    }

    /** Eligible to be pulled into a new payment request. */
    public function isPayable(): bool
    {
        return $this->payment_status === self::PAY_NOT_INITIATED
            && in_array($this->status, [self::STATUS_POSTED, self::STATUS_SUBMITTED], true);
    }

    public static function nextReferenceNo(): string
    {
        $year = now()->year;
        $prefix = "INV-{$year}-";

        // Matched on the year rather than the prefix so numbering carries on across the historical
        // PAF- references instead of restarting at 1 alongside them. PAF- now belongs to the
        // Payment Approval Form (PaymentRequest), which is the document that name describes.
        $last = static::where('reference_no', 'like', "%-{$year}-%")
            ->orderByDesc('id')
            ->value('reference_no');

        // Read from the last separator, so the sequence survives any future prefix change.
        $seq = $last ? ((int) substr($last, strrpos($last, '-') + 1)) + 1 : 1;

        return $prefix.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }
}
