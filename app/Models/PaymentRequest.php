<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PaymentRequest extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_IN_APPROVAL = 'in_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /** Pulled back by Finance after full approval but before payment — see PaymentRequestService::withdraw. */
    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUS_PAID = 'paid';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_IN_APPROVAL,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_WITHDRAWN,
        self::STATUS_PAID,
    ];

    protected $fillable = [
        'reference_no', 'view_token', 'created_by', 'status', 'current_stage', 'total_amount',
        'sent_at', 'approved_at', 'rejected_at', 'rejection_reason',
        'withdrawn_at', 'withdrawn_by', 'withdrawal_reason',
        'paid_at', 'payment_reference', 'paid_by', 'last_reminder_sent_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (PaymentRequest $pr) {
            if (empty($pr->view_token)) {
                $pr->view_token = Str::random(64);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'current_stage' => 'integer',
            'total_amount' => 'decimal:2',
            'sent_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'paid_at' => 'datetime',
            'last_reminder_sent_at' => 'datetime',
        ];
    }

    /**
     * A request stores no currency of its own — it carries the one its invoices are in.
     * Creation refuses to mix currencies, so this is a single code for anything created since;
     * older mixed requests are labelled rather than mislabelled with one of their currencies.
     */
    public function getCurrencyAttribute(): string
    {
        $codes = $this->invoices->pluck('currency')->filter()->unique();

        if ($codes->isEmpty()) {
            return '';
        }

        return $codes->count() === 1 ? $codes->first() : 'MULTI-CURRENCY';
    }

    /**
     * The vendor a request is for, as it should be named to a reader.
     *
     * A request groups invoices and is not constrained to one vendor, so a set spanning several is
     * labelled rather than mislabelled with the first of them.
     */
    public function getVendorLabelAttribute(): string
    {
        $names = $this->invoices->pluck('vendor_name')->filter()->unique()->values();

        return match (true) {
            $names->isEmpty() => 'Vendor not recorded',
            $names->count() === 1 => (string) $names->first(),
            default => $names->first().' + '.($names->count() - 1).' more',
        };
    }

    /**
     * What the request is paying for, in one line.
     *
     * Line descriptions are mandatory from the PAF Enhancements change, so a request raised since
     * then always has one to show; the invoice-level description and then the reference are the
     * fallbacks for the records that predate it.
     */
    public function getPurposeAttribute(): string
    {
        foreach ($this->invoices as $invoice) {
            foreach ($invoice->items as $item) {
                if (trim((string) $item->description) !== '') {
                    return trim($item->description);
                }
            }

            if (trim((string) $invoice->description) !== '') {
                return trim($invoice->description);
            }
        }

        return 'Payment request';
    }

    /**
     * The identifying line an approver reads in an email subject: what it is for, who it pays, how
     * much, and which PAF — the order the business asked for, most identifying detail first so it
     * survives truncation on a phone.
     *
     * Capped because mail clients cut a subject at around 70-80 characters; the purpose is what gets
     * shortened, since the vendor, amount and reference are each meaningless in part.
     */
    public function subjectSummary(int $purposeLimit = 60): string
    {
        $this->loadMissing('invoices.items');

        return implode(' - ', [
            Str::limit($this->purpose, $purposeLimit),
            $this->vendor_label,
            Money::format($this->total_amount, $this->currency),
            $this->reference_no,
        ]);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payer()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function withdrawer()
    {
        return $this->belongsTo(User::class, 'withdrawn_by');
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function approvals()
    {
        return $this->hasMany(PaymentRequestApproval::class)->orderBy('sequence');
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class)->latest('created_at');
    }

    /** Documents attached to the request as a whole — see documentsVisibleTo before showing them. */
    public function documents()
    {
        return $this->hasMany(PaymentRequestDocument::class);
    }

    /**
     * Corrections an invoice's submitter made while this request was in approval. Read from the
     * audit trail rather than stored as a flag: the trail is the record of what changed, and keying
     * it by request means a corrected invoice that later moves to another request does not carry
     * the mark with it. Shown to approvers on the request, the approval page and the PAF.
     */
    public function corrections()
    {
        return $this->hasMany(AuditLog::class)
            ->where('action', 'corrected_during_approval')
            ->oldest('created_at');
    }

    /**
     * May see the documents attached to the request as a whole.
     *
     * Narrower than seeing the request: a submitter can open a request that holds one of their
     * invoices, but the request may group invoices from other submitters and departments, and a
     * document covering the whole payment can show figures for all of them. So Finance, admins and
     * the approvers on this request's chain only.
     */
    public function documentsVisibleTo(User $user): bool
    {
        return $user->isAdmin() || $user->isFinance()
            || $this->approvals()->where('approver_id', $user->id)->exists();
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin() || $user->isFinance()) {
            return $query;
        }

        // Approvers see the PRFs routed to them, plus any holding an invoice by a colleague an admin
        // has granted them view access to (read-only — see User::viewableColleagues).
        if ($user->isApprover()) {
            return $query->where(fn (Builder $q) => $q
                ->whereHas('approvals', fn (Builder $a) => $a->where('approver_id', $user->id))
                ->orWhereHas('invoices', fn (Builder $i) => $i->whereIn('submitted_by', $user->viewableColleagueIds())));
        }

        // Requesters can track PRFs holding an invoice shared with them — their own, a granted
        // colleague's, or their department's (Invoice::scopeSharedWith).
        return $query->whereHas('invoices', fn (Builder $i) => $i->sharedWith($user));
    }

    /**
     * Keep total_amount in step with the invoices still attached. Member invoices can be corrected
     * downwards or released, and the total is what the approval thresholds were measured against.
     */
    public function syncTotal(): void
    {
        $this->update(['total_amount' => $this->invoices()->sum('total_amount')]);
    }

    /** The approval stage currently awaiting action. */
    public function currentApproval()
    {
        return $this->approvals()->where('sequence', $this->current_stage)->first();
    }

    public static function nextReferenceNo(): string
    {
        $year = now()->year;
        $prefix = "PAF-{$year}-";

        // Matched on the year rather than the prefix so numbering carries on across the historical
        // PRF- references instead of restarting at 1 alongside them. Ordered by id because a mixed
        // set of prefixes does not sort by sequence.
        $last = static::where('reference_no', 'like', "%-{$year}-%")
            ->orderByDesc('id')
            ->value('reference_no');

        // Read from the last separator, so the sequence survives any future prefix change.
        $seq = $last ? ((int) substr($last, strrpos($last, '-') + 1)) + 1 : 1;

        return $prefix.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }
}
