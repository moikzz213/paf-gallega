<?php

namespace App\Http\Controllers;

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

        if ($document->uploaded_by !== $user->id && ! $user->isAdmin()) {
            abort(403);
        }

        if (! $invoice->isEditable()) {
            abort(422, 'Documents can only be removed while the request is editable.');
        }

        Storage::disk('local')->delete($document->file_path);
        AuditLogger::log('document_deleted', "Document '{$document->original_name}' removed from {$invoice->reference_no}", $invoice);
        $document->delete();

        return response()->json(['message' => 'Deleted']);
    }

    private function authorizeAccess(Request $request, InvoiceDocument $document): void
    {
        $user = $request->user();
        $invoice = $document->invoice;

        $visible = $user->canViewAllInvoices()
            || $invoice->submitted_by === $user->id
            || ($user->isApprover() && $invoice->paymentRequest
                && $invoice->paymentRequest->approvals()->where('approver_id', $user->id)->exists());

        abort_unless($visible, 403);
    }
}
