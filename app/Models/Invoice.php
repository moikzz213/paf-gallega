<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_SCHEDULED,
        self::STATUS_PAID,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'reference_no', 'vendor_name', 'vendor_email', 'vendor_trn',
        'invoice_no', 'invoice_date', 'due_date', 'currency',
        'amount', 'tax_amount', 'total_amount',
        'category', 'department', 'cost_center', 'payment_method',
        'priority', 'description', 'status', 'current_level',
        'submitted_by', 'submitted_at', 'approved_at', 'rejected_at',
        'rejection_reason', 'scheduled_date', 'paid_at', 'payment_reference', 'paid_by',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'scheduled_date' => 'date:Y-m-d',
            'amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function payer()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function documents()
    {
        return $this->hasMany(InvoiceDocument::class);
    }

    public function approvals()
    {
        return $this->hasMany(InvoiceApproval::class)->orderBy('level');
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
            // approvers see invoices that have (or had) an approval row at their level, plus their own
            return $query->where(function (Builder $q) use ($user) {
                $q->where('submitted_by', $user->id)
                    ->orWhereHas('approvals', fn (Builder $a) => $a->where('level', $user->approval_level));
            });
        }

        return $query->where('submitted_by', $user->id);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_REJECTED], true);
    }

    public static function nextReferenceNo(): string
    {
        $year = now()->year;
        $prefix = "PAF-{$year}-";
        $last = static::where('reference_no', 'like', "{$prefix}%")
            ->orderByDesc('id')
            ->value('reference_no');
        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }
}
