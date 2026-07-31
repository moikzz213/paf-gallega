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
