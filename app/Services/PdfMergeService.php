<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use setasign\Fpdi\PdfParser\StreamReader;

class PdfMergeService
{
    public function __construct(private PdfRepairService $repairer) {}

    /** Mime types this service can render into the merged document. */
    public const MERGEABLE_MIMES = [
        'application/pdf',
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
    ];

    /**
     * Merge multiple PDF files into a single PDF.
     *
     * @param  string  $mainPdfContent  The main PDF content (binary)
     * @param  array  $attachments  Array of file paths to merge
     * @param  array  $links  Non-mergeable documents to list as clickable links
     *                        (['name', 'url', 'mime_type', 'size', 'group'])
     * @return string The merged PDF content
     */
    public function mergePdfs(string $mainPdfContent, array $attachments, array $links = []): string
    {
        $pdf = new Fpdi;

        try {
            $this->addPdfToPdf($pdf, $mainPdfContent);
        } catch (\Throwable $e) {
            Log::error('Failed to process the payment request sheet: '.$e->getMessage());
        }

        // An attachment that cannot be rendered is not a dead end: it falls through to the link
        // list below, so the approver can still reach the original file.
        $deferred = [];

        foreach ($attachments as $attachment) {
            if (! is_file($attachment['path'])) {
                Log::warning("Attachment missing on disk: {$attachment['path']}");

                continue;
            }

            try {
                if ($attachment['mime_type'] === 'application/pdf') {
                    $this->addPdfToPdf($pdf, file_get_contents($attachment['path']));
                } else {
                    $this->addImagePage($pdf, $attachment['path']);
                }
            } catch (\Throwable $e) {
                // The free FPDI parser refuses permissions-only encryption and the object streams
                // PDF 1.5+ writes — between them, most of what vendor accounting systems produce.
                // qpdf rewrites either into a form it can read, so the second attempt succeeds where
                // the first could not. A document that merged first time never reaches here.
                if ($attachment['mime_type'] === 'application/pdf' && $repaired = $this->repairer->repair($attachment['path'])) {
                    try {
                        $this->addPdfToPdf($pdf, file_get_contents($repaired));

                        Log::info("Repaired attachment '{$attachment['name']}' and merged it into the PRF PDF", [
                            'reason_first_attempt_failed' => $e->getMessage(),
                        ]);

                        continue;
                    } catch (\Throwable $second) {
                        // Rewritten and still unreadable. Reported against the original failure,
                        // which is the one that explains the document.
                        Log::warning("Repair did not make '{$attachment['name']}' mergeable", [
                            'reason' => $second->getMessage(),
                        ]);
                    }
                }

                Log::warning("Could not render attachment '{$attachment['name']}' into the PRF PDF", [
                    'mime_type' => $attachment['mime_type'],
                    'reason' => $e->getMessage(),
                ]);

                $deferred[] = $attachment + ['note' => $this->reasonFor($e)];
            }
        }

        // Anything not rendered inline is listed on a final page. This page must be drawn by FPDF
        // rather than dompdf: FPDI's importPage() copies page content only, so a link annotation
        // coming from the dompdf view would be dropped by the merge above.
        $listed = array_merge($links, $deferred);

        if ($listed) {
            $this->addLinksPage($pdf, $listed);
        }

        $output = $pdf->Output('S');

        // The repaired copies exist only to be read into the document above; nothing survives the
        // request. Done here rather than left to the destructor so a long-lived container does not
        // hold them, and so a failure below cannot strand them.
        $this->repairer->cleanUp();

        return $output;
    }

    /** Short, approver-readable explanation of why a file could not be rendered inline. */
    private function reasonFor(\Throwable $e): string
    {
        if ($e->getCode() === CrossReferenceException::ENCRYPTED) {
            // Usually permissions-only encryption: opens fine in a viewer, but the document
            // denies page assembly, and FPDI cannot parse encrypted PDFs at all.
            return 'password-protected PDF - could not be merged';
        }

        return 'could not be merged';
    }

    /**
     * Append every page of a PDF, preserving each page's own size and orientation.
     * Exceptions propagate unwrapped so the caller can inspect the FPDI error code.
     */
    private function addPdfToPdf(Fpdi $pdf, string $pdfContent): int
    {
        $pageCount = $pdf->setSourceFile(StreamReader::createByString($pdfContent));

        for ($i = 1; $i <= $pageCount; $i++) {
            $templateId = $pdf->importPage($i);
            $size = $pdf->getTemplateSize($templateId);

            $orientation = $size['width'] > $size['height'] ? 'L' : 'P';
            $pdf->AddPage($orientation, [$size['width'], $size['height']]);
            $pdf->useTemplate($templateId);
        }

        return $pageCount;
    }

    /**
     * Add an image as a page to the PDF.
     */
    private function addImagePage(Fpdi $pdf, string $imagePath): void
    {
        $pdf->AddPage();

        [$width, $height] = getimagesize($imagePath);
        $pageWidth = $pdf->GetPageWidth() - 20;
        $pageHeight = $pdf->GetPageHeight() - 10;

        // Calculate aspect ratio
        $imageRatio = $width / $height;
        $pageRatio = $pageWidth / $pageHeight;

        if ($imageRatio > $pageRatio) {
            $newWidth = $pageWidth;
            $newHeight = $pageWidth / $imageRatio;
        } else {
            $newHeight = $pageHeight;
            $newWidth = $pageHeight * $imageRatio;
        }

        // Center the image vertically
        $y = ($pdf->GetPageHeight() - $newHeight) / 2;
        $x = ($pdf->GetPageWidth() - $newWidth) / 2;

        $pdf->Image($imagePath, $x, $y, $newWidth, $newHeight);
    }

    /**
     * List documents that cannot be rendered inline (Excel, Word, archives, ...) as
     * clickable download links. Cell()'s $link argument emits a real /URI annotation.
     */
    private function addLinksPage(Fpdi $pdf, array $links): void
    {
        $pdf->AddPage('L');

        $pdf->SetFont('Helvetica', 'B', 13);
        $pdf->SetTextColor(17, 17, 17);
        $pdf->Cell(0, 8, $this->text('Additional Documents ('.count($links).')'), 'B', 1);
        $pdf->Ln(3);

        $pdf->SetFont('Helvetica', 'I', 8);
        $pdf->SetTextColor(102, 102, 102);
        $pdf->Cell(0, 5, $this->text('These files could not be displayed inside this PDF. Click a name to download it.'), 0, 1);
        $pdf->Ln(3);

        $group = null;
        foreach ($links as $link) {
            if (($link['group'] ?? null) !== $group) {
                $group = $link['group'] ?? null;
                if ($group) {
                    $pdf->Ln(2);
                    $pdf->SetFont('Helvetica', 'B', 9);
                    $pdf->SetTextColor(17, 17, 17);
                    $pdf->Cell(0, 6, $this->text($group), 0, 1);
                }
            }

            $pdf->SetFont('Helvetica', 'U', 10);
            $pdf->SetTextColor(0, 102, 204);
            // Write() wraps at the right margin and links each line it lays down, so long
            // filenames stay on the page instead of overflowing past the MediaBox.
            $pdf->Write(5, $this->text($link['name']), $link['url'] ?? '');
            $pdf->Ln(6);

            $meta = $link['mime_type'].'  |  '.round(($link['size'] ?? 0) / 1024, 1).' KB';
            if (! empty($link['note'])) {
                $meta .= '  |  '.$link['note'];
            }

            $pdf->SetFont('Helvetica', '', 7.5);
            $pdf->SetTextColor(102, 102, 102);
            $pdf->Cell(0, 4, $this->text($meta), 0, 1);
            $pdf->Ln(1);
        }

        $pdf->SetTextColor(0, 0, 0);
    }

    /** FPDF core fonts are cp1252-encoded, so UTF-8 filenames must be transliterated. */
    private function text(string $value): string
    {
        return @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $value) ?: $value;
    }
}
