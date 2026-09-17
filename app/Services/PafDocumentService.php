<?php

namespace App\Services;

use App\Models\ApprovalLevel;
use App\Models\PaymentRequest;
use App\Models\Vendor;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Builds the PAF sheet for a payment request, with its supporting documents merged in.
 *
 * The document is produced on demand rather than stored at creation: it is available from the
 * moment the request is raised (PAF Enhancements, item 3), and because it is rendered fresh it
 * always shows the approval progress reached so far rather than a snapshot taken before anyone
 * signed. Anything produced before full approval is watermarked by the view — see
 * `resources/views/pdf/payment-request.blade.php`.
 */
class PafDocumentService
{
    public function __construct(private PdfMergeService $merger) {}

    /** The merged PAF as raw PDF bytes. */
    public function render(PaymentRequest $paymentRequest): string
    {
        $paymentRequest->load([
            'creator:id,name', 'payer:id,name',
            'invoices.submitter:id,name', 'invoices.poster:id,name', 'invoices.documents',
            'invoices.vendor:id,name,vendor_code',
            'invoices.items',
            'approvals.approver:id,name', 'approvals.approvalLevel:level,min_amount',
        ]);

        // Supplier code comes from the vendor master, keyed by the invoice's vendor name.
        $supplierCodes = Vendor::whereIn('name', $paymentRequest->invoices->pluck('vendor_name')->filter()->unique())
            ->get(['name', 'vendor_code'])
            ->groupBy('name')
            ->map(fn ($vendors) => $vendors->count() === 1 ? $vendors->first()->vendor_code : null);

        // Every active approval level, so the PDF can show reached (required) and
        // not-reached (still displayed) approvers regardless of this PRF's amount.
        $approvalLevels = ApprovalLevel::where('is_active', true)
            // job_title feeds the "not reached" rows on the PDF, which show the level's default approver.
            ->with('defaultApprover:id,name,job_title')
            ->orderBy('level')
            ->get();

        $pdf = Pdf::loadView('pdf.payment-request', [
            'paymentRequest' => $paymentRequest,
            'supplierCodes' => $supplierCodes,
            'approvalLevels' => $approvalLevels,
        ])
            ->setPaper('a4', 'landscape')
            ->setOption('isRemoteEnabled', true)
            ->setOption('isHtml5ParserEnabled', true);

        $mainPdfContent = $pdf->output();

        // PDFs and images are rendered into the document; everything else (Excel, Word, ...)
        // can only be offered as a download link on a trailing page.
        $attachments = [];
        $links = [];
        foreach ($paymentRequest->invoices as $invoice) {
            foreach ($invoice->documents as $document) {
                $path = storage_path('app/private/'.$document->file_path);
                if (! is_file($path)) {
                    continue;
                }

                $entry = [
                    'name' => $document->original_name,
                    'url' => url("/api/documents/{$document->id}/download"),
                    'mime_type' => $document->mime_type,
                    'size' => $document->size,
                    'group' => "{$invoice->reference_no} - {$invoice->vendor_name}",
                ];

                if (in_array($document->mime_type, PdfMergeService::MERGEABLE_MIMES, true)) {
                    // Carries the link fields too: a PDF that turns out to be encrypted or
                    // damaged falls back to the download list instead of failing the export.
                    $attachments[] = $entry + ['path' => $path];
                } else {
                    $links[] = $entry;
                }
            }
        }

        if ($attachments || $links) {
            return $this->merger->mergePdfs($mainPdfContent, $attachments, $links);
        }

        return $mainPdfContent;
    }

    /** The filename the PAF is offered under. */
    public function filename(PaymentRequest $paymentRequest): string
    {
        return "{$paymentRequest->reference_no}.pdf";
    }
}
