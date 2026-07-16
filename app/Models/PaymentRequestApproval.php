<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PaymentRequestApproval extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'payment_request_id', 'view_token', 'sequence', 'level', 'label', 'is_adhoc',
        'status', 'approver_id', 'comments', 'acted_at',
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
}
