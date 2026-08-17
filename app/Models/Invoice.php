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
        'invoice_no', 'invoice_date', 'due_date', 'currency',
        'amount', 'tax_amount', 'total_amount',
        'business_unit', 'department', 'location', 'payment_method',
        'priority', 'description', 'status', 'submitted_by', 'submitted_at',
        'posting_date', 'erp_doc_no', 'finance_remarks', 'posted_by', 'posted_at',
        'payment_status', 'payment_request_id',
    ];

    protected function casts(): array
    {
        return [
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

    public function documents()
    {
        return $this->hasMany(InvoiceDocument::class);
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order');
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class)->latest('created_at');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->canViewAllInvoices()) {
            return $query;
        }

        if ($user->isApprover()) {
            // approvers see their own submissions plus invoices in a PRF routed to them
            return $query->where(function (Builder $q) use ($user) {
                $q->where('submitted_by', $user->id)
                    ->orWhereHas('paymentRequest.approvals', fn (Builder $a) => $a->where('approver_id', $user->id));
            });
        }

        return $query->where('submitted_by', $user->id);
    }

    /** Editable while it is still in the log and not yet in a payment cycle. */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_SUBMITTED, self::STATUS_QUERY], true)
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
