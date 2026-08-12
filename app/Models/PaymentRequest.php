<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PaymentRequest extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_IN_APPROVAL = 'in_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PAID = 'paid';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_IN_APPROVAL,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_PAID,
    ];

    protected $fillable = [
        'reference_no', 'view_token', 'created_by', 'status', 'current_stage', 'total_amount',
        'sent_at', 'approved_at', 'rejected_at', 'rejection_reason',
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

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payer()
    {
        return $this->belongsTo(User::class, 'paid_by');
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

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin() || $user->isFinance()) {
            return $query;
        }

        if ($user->isApprover()) {
            return $query->whereHas('approvals', fn (Builder $a) => $a->where('approver_id', $user->id));
        }

        // requesters can track PRFs that contain one of their invoices
        return $query->whereHas('invoices', fn (Builder $i) => $i->where('submitted_by', $user->id));
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
