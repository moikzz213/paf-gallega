<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A record that this invoice was on a payment request that got rejected.
 *
 * Written when the request is rejected, just before its invoices are detached and returned to
 * Finance. It is the report's only durable route back to the rejected PAF — `payment_request_id`
 * on the invoice is cleared by the rejection, and then reused by whatever request the corrected
 * invoice goes onto next.
 */
class InvoiceRejection extends Model
{
    protected $fillable = ['invoice_id', 'payment_request_id', 'rejected_at'];

    protected function casts(): array
    {
        return ['rejected_at' => 'datetime'];
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function paymentRequest()
    {
        return $this->belongsTo(PaymentRequest::class);
    }
}
