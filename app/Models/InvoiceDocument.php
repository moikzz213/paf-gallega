<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InvoiceDocument extends Model
{
    // Removal hides, never destroys — see the 2026_10_09 migration and removeBy().
    use SoftDeletes;

    protected $fillable = [
        'invoice_id', 'document_type', 'document_label', 'uploaded_by', 'uploaded_after_approval',
        'original_name', 'file_path', 'mime_type', 'size',
    ];

    /** The checklist item, readable, wherever a document is listed — see getTypeLabelAttribute. */
    protected $appends = ['type_label'];

    protected function casts(): array
    {
        return ['uploaded_after_approval' => 'boolean'];
    }

    /**
     * What the document is, as filed against the Freight Forwarding checklist: "Job Sheet", or
     * "Other — {the name the user gave it}". Null for a document filed without a type — any other
     * department's, or one that predates the checklist.
     */
    public function getTypeLabelAttribute(): ?string
    {
        if (! $this->document_type) {
            return null;
        }

        $label = config('paf.document_checklist.types')[$this->document_type] ?? $this->document_type;

        return $this->document_type === 'other' && $this->document_label
            ? "{$label} — {$this->document_label}"
            : $label;
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
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
