<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PaymentRequestApproval extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /**
     * The approver was on leave: the stage was passed over, not signed. `approver_id` stays the
     * absent approver, `skipped_by` is who marked it, `skip_reason` why, `acted_at` when.
     */
    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'payment_request_id', 'view_token', 'sequence', 'level', 'label', 'is_adhoc',
        'status', 'approver_id', 'comments', 'skip_reason', 'skipped_by', 'acted_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'level' => 'integer',
            'is_adhoc' => 'boolean',
            'acted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PaymentRequestApproval $approval) {
            if (empty($approval->view_token)) {
                $approval->view_token = Str::random(64);
            }
        });
    }

    public function paymentRequest()
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function skipper()
    {
        return $this->belongsTo(User::class, 'skipped_by');
    }

    public function approvalLevel()
    {
        return $this->belongsTo(ApprovalLevel::class, 'level', 'level');
    }
}
