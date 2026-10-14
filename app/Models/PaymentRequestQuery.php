<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An approver's Approval Query on a payment request, and the response that resubmitted it.
 * Open while `resubmitted_at` is null. See PaymentRequestService::query / resubmit.
 *
 * CR: ai/change-requests/replace-reject-with-query-in-payment-approval.md
 */
class PaymentRequestQuery extends Model
{
    protected $fillable = [
        'payment_request_id', 'payment_request_approval_id', 'sequence', 'stage_label',
        'raised_by', 'question', 'raised_at',
        'resubmitted_by', 'response', 'resubmitted_at', 'last_reminder_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'raised_at' => 'datetime',
            'resubmitted_at' => 'datetime',
            'last_reminder_sent_at' => 'datetime',
        ];
    }

    public function paymentRequest()
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    public function approval()
    {
        return $this->belongsTo(PaymentRequestApproval::class, 'payment_request_approval_id');
    }

    public function raiser()
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function resubmitter()
    {
        return $this->belongsTo(User::class, 'resubmitted_by');
    }

    public function isOpen(): bool
    {
        return $this->resubmitted_at === null;
    }
}
