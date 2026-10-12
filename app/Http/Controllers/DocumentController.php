<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceDocument;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function download(Request $request, InvoiceDocument $document)
    {
        $this->authorizeAccess($request, $document);

        abort_unless(Storage::disk('local')->exists($document->file_path), 404, 'File not found on disk.');

        return Storage::disk('local')->download($document->file_path, $document->original_name);
    }

    public function destroy(Request $request, InvoiceDocument $document)
    {
        $user = $request->user();
        $invoice = $document->invoice;

        abort_unless($document->isRemovableBy($user), 403, 'Only the person who uploaded this document, or an administrator, can remove it.');

        // Still editable: nobody downstream has relied on it yet, so it is deleted outright, as before.
        if ($invoice->isEditable()) {
            Storage::disk('local')->delete($document->file_path);
            AuditLogger::log('document_deleted', "Document '{$document->original_name}' removed from {$invoice->reference_no}", $invoice);
            $document->forceDelete();

            return response()->json(['message' => 'Deleted']);
        }

        // Posted and not yet paid: Finance, approvers or the PAF may have seen it, so it is removed
        // from view but the file is kept, and the history says who removed what.
        if (! $invoice->acceptsDocumentRemoval()) {
            abort(422, $invoice->payment_status === Invoice::PAY_PAID
                ? 'This invoice has been paid, so its documents can no longer be removed.'
                : 'Documents on this invoice can no longer be removed.');
        }

        $document->removeBy($user);
        AuditLogger::log(
            'document_removed',
            "Document '{$document->original_name}' removed from {$invoice->reference_no} (file retained)",
            $invoice, null, ['document_id' => $document->id, 'original_name' => $document->original_name],
            $invoice->paymentRequest,
        );

        return response()->json(['message' => 'Removed']);
    }

    private function authorizeAccess(Request $request, InvoiceDocument $document): void
    {
        // The download route sits outside the `auth` group, so a guest has to be turned away here.
        abort_unless($request->user(), 401);

        // Whoever may see the invoice may open its documents — the same rule as the Invoice Log.
        abort_unless(Invoice::whereKey($document->invoice_id)->visibleTo($request->user())->exists(), 403);
    }
}
