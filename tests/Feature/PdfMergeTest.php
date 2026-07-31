<?php

namespace Tests\Feature;

use App\Services\PdfMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PdfMergeTest extends TestCase
{
    use RefreshDatabase;

    public function test_merge_service_can_merge_pdfs(): void
    {
        // Create a minimal valid PDF as the main document
        $mainPdf = $this->createMinimalPdf('Main Document');

        // Create a test image file
        $testImage = tempnam(sys_get_temp_dir(), 'test_');
        $this->createTestImage($testImage);

        $attachments = [
            [
                'path' => $testImage,
                'name' => 'test-image.jpg',
                'mime_type' => 'image/jpeg',
            ],
        ];

        $mergeService = new PdfMergeService();
        $mergedPdf = $mergeService->mergePdfs($mainPdf, $attachments);

        $this->assertNotEmpty($mergedPdf);
        $this->assertStringStartsWith('%PDF', $mergedPdf);

        unlink($testImage);
    }

    /**
     * Non-mergeable documents (Excel, Word, ...) are offered as download links. The links must be
     * emitted by FPDF, because FPDI's importPage() copies page content only and silently drops
     * link annotations — a link rendered in the dompdf view would look right but do nothing.
     */
    public function test_non_mergeable_documents_produce_real_link_annotations(): void
    {
        $merged = (new PdfMergeService())->mergePdfs($this->createMinimalPdf('Main Document'), [], [
            [
                'name' => 'ledger.xlsx',
                'url' => 'https://paf.test/api/documents/9/download',
                'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'size' => 2048,
                'group' => 'INV-2026-00001 - Al Noor Logistics',
            ],
        ]);

        $this->assertStringStartsWith('%PDF', $merged);
        $this->assertStringContainsString('/Annots', $merged, 'links page carries no annotation array');
        $this->assertStringContainsString('/URI (https://paf.test/api/documents/9/download)', $merged);
    }

    public function test_excel_and_word_are_not_treated_as_mergeable(): void
    {
        $this->assertNotContains('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', PdfMergeService::MERGEABLE_MIMES);
        $this->assertNotContains('application/vnd.openxmlformats-officedocument.wordprocessingml.document', PdfMergeService::MERGEABLE_MIMES);
        $this->assertContains('application/pdf', PdfMergeService::MERGEABLE_MIMES);
        $this->assertContains('image/png', PdfMergeService::MERGEABLE_MIMES);
    }

    /**
     * FPDI cannot parse an encrypted PDF at all (CrossReferenceException::ENCRYPTED), and such a
     * file usually also denies page assembly in its /P permissions. Rather than failing the export
     * or emitting a dead-end error page, the attachment degrades to a download link.
     */
    public function test_encrypted_pdf_falls_back_to_a_download_link(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'enc_');
        file_put_contents($path, $this->createEncryptedPdf());

        $merged = (new PdfMergeService())->mergePdfs($this->createMinimalPdf('Main Document'), [[
            'path' => $path,
            'name' => 'LPO - IT Assets - PRF 20266_encrypted_.pdf',
            'url' => 'https://paf.test/api/documents/42/download',
            'mime_type' => 'application/pdf',
            'size' => filesize($path),
            'group' => 'INV-2026-00031 - Jawed WH Operations',
        ]]);

        unlink($path);

        $this->assertStringStartsWith('%PDF', $merged);
        // Deferred to the links page: a merged PDF would carry no annotation at all.
        $this->assertStringContainsString('/Annots', $merged);
        $this->assertStringContainsString('/URI', $merged);
        $this->assertStringContainsString('https://paf.test/api/documents/42/download', $merged);
        $this->assertStringNotContainsString('Could not process this attachment', $merged);
    }

    public function test_merge_service_handles_missing_files(): void
    {
        $mainPdf = $this->createMinimalPdf('Main Document');

        $attachments = [
            [
                'path' => '/nonexistent/file.jpg',
                'name' => 'missing-file.jpg',
                'mime_type' => 'image/jpeg',
            ],
        ];

        $mergeService = new PdfMergeService();
        $mergedPdf = $mergeService->mergePdfs($mainPdf, $attachments);

        $this->assertNotEmpty($mergedPdf);
        $this->assertStringStartsWith('%PDF', $mergedPdf);
    }

    private function createMinimalPdf(string $text): string
    {
        $pdf = <<<'EOF'
%PDF-1.4
1 0 obj
<< /Type /Catalog /Pages 2 0 R >>
endobj
2 0 obj
<< /Type /Pages /Kids [3 0 R] /Count 1 >>
endobj
3 0 obj
<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>
endobj
4 0 obj
<< >>
stream
BT
/F1 12 Tf
50 750 Td
(Test PDF) Tj
ET
endstream
endobj
5 0 obj
<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>
endobj
xref
0 6
0000000000 65535 f
0000000009 00000 n
0000000058 00000 n
0000000115 00000 n
0000000229 00000 n
0000000334 00000 n
trailer
<< /Size 6 /Root 1 0 R >>
startxref
412
%%EOF
EOF;

        return $pdf;
    }

    /**
     * Minimal PDF whose trailer carries /Encrypt, which is what FPDI checks before it will read a
     * document. Built with computed xref offsets so the parser gets far enough to see the entry.
     */
    private function createEncryptedPdf(): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>',
            4 => '<< /Filter /Standard /V 1 /R 2 /O (0123456789abcdef) /U (0123456789abcdef) /P -1292 >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $startxref = strlen($pdf);
        $size = count($objects) + 1;

        // Every xref entry must be exactly 20 bytes.
        $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size {$size} /Root 1 0 R /Encrypt 4 0 R >>\nstartxref\n{$startxref}\n%%EOF\n";

        return $pdf;
    }

    private function createTestImage(string $path): void
    {
        $image = imagecreate(200, 200);
        $white = imagecolorallocate($image, 255, 255, 255);
        $blue = imagecolorallocate($image, 0, 0, 255);
        imagefill($image, 0, 0, $white);
        imagestring($image, 3, 50, 100, 'Test', $blue);
        imagejpeg($image, $path);
        imagedestroy($image);
    }
}
