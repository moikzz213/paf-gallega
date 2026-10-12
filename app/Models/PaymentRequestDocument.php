<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

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
    // Removal hides, never destroys — see the 2026_10_09 migration and removeBy().
    use SoftDeletes;

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

    /** The uploader, or an admin. Kept with the document so both removal routes agree. */
    public function isRemovableBy(User $user): bool
    {
        return $user->isAdmin() || $this->uploaded_by === $user->id;
    }

    /**
     * Take the document off the record without destroying it: hidden from every screen, the PAF,
     * the public links and downloads, while the file stays in storage for audit.
     */
    public function removeBy(User $user): void
    {
        $this->forceFill(['removed_by' => $user->id])->save();
        $this->delete();
    }
}
