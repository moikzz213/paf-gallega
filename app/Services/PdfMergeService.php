<?php

namespace App\Services;

use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

class PdfMergeService
{
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
     * @return string  The merged PDF content
     */
    public function mergePdfs(string $mainPdfContent, array $attachments, array $links = []): string
    {
        $pdf = new Fpdi();
        $pageCount = 0;

        // Add main PDF first
        try {
            $mainPageCount = $this->addPdfToPdf($pdf, $mainPdfContent, null);
            $pageCount += $mainPageCount;
        } catch (\Exception $e) {
            // If main PDF fails, we still want to continue
            \Log::error('Failed to process main PDF: '.$e->getMessage());
        }

        // Add each attachment
        foreach ($attachments as $attachment) {
            if (!is_file($attachment['path'])) {
                continue;
            }

            $isPdf = $attachment['mime_type'] === 'application/pdf';

            if ($isPdf) {
                try {
                    $attachmentPageCount = $this->addPdfToPdf($pdf, file_get_contents($attachment['path']), $attachment['name']);
                    $pageCount += $attachmentPageCount;
                } catch (\Exception $e) {
                    \Log::error("Failed to merge PDF attachment {$attachment['name']}: ".$e->getMessage());
                    $this->addErrorPage($pdf, $attachment['name']);
                }
            } elseif (in_array($attachment['mime_type'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'])) {
                try {
                    $this->addImagePage($pdf, $attachment['path'], $attachment['name'], $attachment['mime_type']);
                } catch (\Exception $e) {
                    \Log::error("Failed to add image attachment {$attachment['name']}: ".$e->getMessage());
                    $this->addErrorPage($pdf, $attachment['name']);
                }
            }
        }

        // Anything that cannot be rendered inline is listed on a final page. This page must be
        // drawn by FPDF rather than dompdf: FPDI's importPage() copies page content only, so a
        // link annotation coming from the dompdf view would be dropped by the merge above.
        if (! empty($links)) {
            $this->addLinksPage($pdf, $links);
        }

        return $pdf->Output('S');
    }

    /**
     * Add a PDF file's pages to the main PDF.
     */
    private function addPdfToPdf(Fpdi $pdf, string $pdfContent, ?string $title): int
    {
        try {
            $pageCount = $pdf->setSourceFile(StreamReader::createByString($pdfContent));

            for ($i = 1; $i <= $pageCount; $i++) {
                $templateId = $pdf->importPage($i);
                $size = $pdf->getTemplateSize($templateId);

                $orientation = $size['width'] > $size['height'] ? 'L' : 'P';
                $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                $pdf->useTemplate($templateId);
            }

            return $pageCount;
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to process PDF: ".$e->getMessage());
        }
    }

    /**
     * Add an image as a page to the PDF.
     */
    private function addImagePage(Fpdi $pdf, string $imagePath, string $title, string $mimeType): void
    {
        $pdf->AddPage();

        list($width, $height) = getimagesize($imagePath);
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
        $pdf->Cell(0, 5, $this->text('These file types cannot be displayed inside a PDF. Click a name to download it.'), 0, 1);
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

            $name = $this->text($link['name']);
            $pdf->SetFont('Helvetica', 'U', 10);
            $pdf->SetTextColor(0, 102, 204);
            // Width is measured from the string so the clickable area matches the visible text.
            $pdf->Cell($pdf->GetStringWidth($name) + 1, 6, $name, 0, 1, 'L', false, $link['url']);

            $pdf->SetFont('Helvetica', '', 7.5);
            $pdf->SetTextColor(102, 102, 102);
            $pdf->Cell(0, 4, $this->text($link['mime_type'].'  |  '.round(($link['size'] ?? 0) / 1024, 1).' KB'), 0, 1);
            $pdf->Ln(1);
        }

        $pdf->SetTextColor(0, 0, 0);
    }

    /** FPDF core fonts are cp1252-encoded, so UTF-8 filenames must be transliterated. */
    private function text(string $value): string
    {
        return @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $value) ?: $value;
    }

    /**
     * Add an error page for failed attachments.
     */
    private function addErrorPage(Fpdi $pdf, string $title): void
    {
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'Attachment: '.$title, 0, 1);
        $pdf->SetFont('Helvetica', 'I', 10);
        $pdf->SetTextColor(200, 0, 0);
        $pdf->Cell(0, 10, 'Error: Could not process this attachment', 0, 1);
        $pdf->SetTextColor(0, 0, 0);
    }
}
