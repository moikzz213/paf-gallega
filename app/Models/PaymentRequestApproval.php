<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentRequestApproval extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'payment_request_id', 'sequence', 'level', 'label', 'is_adhoc',
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

    public function paymentRequest()
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
