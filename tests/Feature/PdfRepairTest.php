<?php

namespace Tests\Feature;

use App\Services\PdfMergeService;
use App\Services\PdfRepairService;
use Illuminate\Support\Facades\Log;
use setasign\Fpdi\Fpdi;
use Tests\TestCase;

/**
 * Repairing a vendor PDF that FPDI's free parser will not read.
 *
 * The happy path matters, but the fallbacks matter more: this runs on a document that has *already*
 * failed, so every way the repair can itself fail has to land back on the behaviour that predates
 * it — the document listed as a link, the form still generated. A missing binary is the likeliest
 * of those in practice, because it is what an environment nobody installed qpdf on looks like.
 *
 * CR: ai/change-requests/repair-unmergeable-pdf-attachments-with-qpdf.md
 */
class PdfRepairTest extends TestCase
{
    /** @var array<int, string> */
    private array $temp = [];

    protected function tearDown(): void
    {
        foreach ($this->temp as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'paf-test-');
        file_put_contents($path, $contents);
        $this->temp[] = $path;

        return $path;
    }

    /** A PDF FPDI cannot read: truncated after the header. */
    private function unreadablePdf(): string
    {
        return $this->tempFile("%PDF-1.6\n%\xE2\xE3\xCF\xD3\nthis is not a usable document");
    }

    private function readablePdf(string $text = 'Attachment'): string
    {
        return $this->tempFile($this->createMinimalPdf($text));
    }

    private function attachment(string $path, string $name = 'vendor-invoice.pdf'): array
    {
        return [
            'path' => $path,
            'name' => $name,
            'mime_type' => 'application/pdf',
            'url' => 'https://paf.test/api/documents/1/download',
            'size' => 1024,
            'group' => 'INV-2026-00001 - Al Noor Logistics',
        ];
    }

    // ------------------------------------------------------------- the repair

    public function test_a_document_that_only_reads_after_repair_is_merged_inline(): void
    {
        $good = $this->readablePdf('Repaired attachment');

        // Stands in for qpdf: the contract is "hand back a path FPDI can read, or null". Whether
        // qpdf can rewrite a given file is qpdf's business, not this codebase's.
        $this->swap(PdfRepairService::class, new class($good) extends PdfRepairService
        {
            public function __construct(private string $good) {}

            public function enabled(): bool
            {
                return true;
            }

            public function repair(string $path): ?string
            {
                return $this->good;
            }

            public function cleanUp(): void {}
        });

        $merged = app(PdfMergeService::class)->mergePdfs(
            $this->createMinimalPdf('Main Document'),
            [$this->attachment($this->unreadablePdf())],
        );

        $this->assertStringStartsWith('%PDF', $merged);
        $this->assertStringNotContainsString(
            '/URI (https://paf.test/api/documents/1/download)',
            $merged,
            'A document merged after repair must not also be listed as a link.',
        );
    }

    public function test_a_document_that_merges_first_time_is_never_repaired(): void
    {
        $this->swap(PdfRepairService::class, new class extends PdfRepairService
        {
            public function repair(string $path): ?string
            {
                throw new \LogicException('repair must not be attempted for a document that already merges');
            }

            public function cleanUp(): void {}
        });

        $merged = app(PdfMergeService::class)->mergePdfs(
            $this->createMinimalPdf('Main Document'),
            [$this->attachment($this->readablePdf())],
        );

        $this->assertStringStartsWith('%PDF', $merged);
    }

    // ---------------------------------------------------------- the fallbacks

    public function test_an_unrepairable_document_still_reaches_the_link_page(): void
    {
        $this->swap(PdfRepairService::class, new class extends PdfRepairService
        {
            public function repair(string $path): ?string
            {
                return null;
            }

            public function cleanUp(): void {}
        });

        $merged = app(PdfMergeService::class)->mergePdfs(
            $this->createMinimalPdf('Main Document'),
            [$this->attachment($this->unreadablePdf())],
        );

        $this->assertStringStartsWith('%PDF', $merged);
        $this->assertStringContainsString('/URI (https://paf.test/api/documents/1/download)', $merged);
    }

    public function test_a_repair_that_produces_another_unreadable_file_falls_back(): void
    {
        $stillBad = $this->unreadablePdf();

        $this->swap(PdfRepairService::class, new class($stillBad) extends PdfRepairService
        {
            public function __construct(private string $stillBad) {}

            public function repair(string $path): ?string
            {
                return $this->stillBad;
            }

            public function cleanUp(): void {}
        });

        $merged = app(PdfMergeService::class)->mergePdfs(
            $this->createMinimalPdf('Main Document'),
            [$this->attachment($this->unreadablePdf())],
        );

        $this->assertStringContainsString('/URI (https://paf.test/api/documents/1/download)', $merged);
    }

    /** What an environment where nobody installed qpdf actually looks like. */
    public function test_a_missing_binary_falls_back_and_says_so_once(): void
    {
        config(['paf.pdf_repair.enabled' => true, 'paf.pdf_repair.binary' => 'qpdf-that-is-not-installed']);
        Log::spy();

        $repairer = new PdfRepairService;

        $this->assertFalse($repairer->available());
        $this->assertNull($repairer->repair($this->unreadablePdf()));
        $this->assertNull($repairer->repair($this->unreadablePdf()));

        // Once, not once per attachment: a missing install is one problem, however many files hit it.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'PDF repair is unavailable'))
            ->once();
    }

    public function test_the_whole_form_still_generates_when_the_binary_is_missing(): void
    {
        config(['paf.pdf_repair.enabled' => true, 'paf.pdf_repair.binary' => 'qpdf-that-is-not-installed']);

        $merged = app(PdfMergeService::class)->mergePdfs(
            $this->createMinimalPdf('Main Document'),
            [$this->attachment($this->unreadablePdf())],
        );

        $this->assertStringStartsWith('%PDF', $merged);
        $this->assertStringContainsString('/URI (https://paf.test/api/documents/1/download)', $merged);
    }

    public function test_the_repair_can_be_switched_off(): void
    {
        config(['paf.pdf_repair.enabled' => false]);

        $repairer = new PdfRepairService;

        $this->assertFalse($repairer->enabled());
        $this->assertNull($repairer->repair($this->unreadablePdf()));
    }

    public function test_a_file_that_is_not_on_disk_is_not_repaired(): void
    {
        config(['paf.pdf_repair.enabled' => true]);

        $this->assertNull((new PdfRepairService)->repair('/nonexistent/vendor-invoice.pdf'));
    }

    // ------------------------------------------------------------- housekeeping

    public function test_working_copies_are_deleted(): void
    {
        // `php` stands in for the converter: it exists on every machine that runs this suite, so the
        // probe passes and a working copy is genuinely reserved — which is the thing being cleaned.
        config(['paf.pdf_repair.enabled' => true, 'paf.pdf_repair.binary' => PHP_BINARY]);

        $repairer = new PdfRepairService;
        $repairer->repair($this->unreadablePdf());

        $reserved = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'paf-repair-*') ?: [];
        $repairer->cleanUp();

        $this->assertSame(
            [],
            array_values(array_filter($reserved, 'is_file')),
            'Repair working copies must not outlive the merge.',
        );
    }

    /**
     * The paths handed to the converter carry vendor-supplied filenames. They are passed as an
     * argument list rather than a shell string, so a name like `x"; rm -rf /; echo "` is a name.
     */
    public function test_a_hostile_filename_is_never_executed(): void
    {
        config(['paf.pdf_repair.enabled' => true, 'paf.pdf_repair.binary' => 'qpdf-that-is-not-installed']);

        $canary = sys_get_temp_dir().DIRECTORY_SEPARATOR.'paf-canary-'.uniqid();
        $hostile = $this->tempFile('%PDF-1.4 not a real document');

        // The binary does not exist, so nothing runs — but nothing must run *from the name* either.
        $merged = app(PdfMergeService::class)->mergePdfs(
            $this->createMinimalPdf('Main Document'),
            [$this->attachment($hostile, "invoice\"; echo pwned > {$canary}; echo \".pdf")],
        );

        $this->assertStringStartsWith('%PDF', $merged);
        $this->assertFileDoesNotExist($canary);
    }

    private function createMinimalPdf(string $text): string
    {
        $pdf = new Fpdi;
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->Cell(0, 10, $text);

        return $pdf->Output('S');
    }
}
