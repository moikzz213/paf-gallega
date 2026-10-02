<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A supporting document attached to a payment request as a whole, rather than to one of its
 * invoices. Merged into the PAF alongside the invoices' own documents.
 *
 * Narrower visibility than an invoice document: one request can group invoices from several
 * submitters and departments, so these are shown only to Finance, admins and the request's
 * approvers — see PaymentRequest::documentsVisibleTo.
 */
class PaymentRequestDocument extends Model
{
    protected $fillable = [
        'payment_request_id', 'uploaded_by', 'uploaded_after_approval',
        'original_name', 'file_path', 'mime_type', 'size',
    ];

    protected function casts(): array
    {
        return ['uploaded_after_approval' => 'boolean'];
    }

    public function paymentRequest()
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
