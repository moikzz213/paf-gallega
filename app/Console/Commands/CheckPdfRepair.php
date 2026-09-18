<?php

namespace App\Console\Commands;

use App\Services\PdfRepairService;
use Illuminate\Console\Command;
use setasign\Fpdi\Fpdi;

/**
 * Answers "will PDF attachment repair actually work on this server?" without deploying and hoping.
 *
 * Written for shared hosting, where the honest answer is often no: `proc_open` is commonly in
 * `disable_functions`, and without it no external program can be run at all — which is checked here
 * before anything else, because it is the one failure no amount of configuration can fix.
 *
 * CR: ai/change-requests/repair-unmergeable-pdf-attachments-with-qpdf.md
 */
class CheckPdfRepair extends Command
{
    protected $signature = 'paf:check-pdf-repair';

    protected $description = 'Report whether PDF attachment repair can run on this server, and why not if it cannot';

    public function handle(PdfRepairService $repairer): int
    {
        $this->newLine();
        $this->line('<comment>PDF attachment repair — environment check</comment>');
        $this->newLine();

        // 1. The switch.
        $enabled = $repairer->enabled();
        $this->state('Feature enabled (PAF_PDF_REPAIR)', $enabled, $enabled ? 'true' : 'false — repair is switched off');

        // 2. The blocker that cannot be configured around. Symfony Process needs proc_open, and
        //    shared hosts routinely disable it; nothing below matters if this is missing.
        $procOpen = function_exists('proc_open');
        $this->state('proc_open available', $procOpen, $procOpen
            ? 'yes'
            : 'NO — disabled by PHP (disable_functions='.(ini_get('disable_functions') ?: 'unset').')');

        if (! $procOpen) {
            $this->newLine();
            $this->error('This server cannot run external programs, so qpdf cannot be used at all.');
            $this->line('  Attachments that FPDI cannot read will keep falling back to the link page.');
            $this->line('  Ask the host whether proc_open can be enabled; if not, this approach is not viable here.');
            $this->newLine();

            return self::FAILURE;
        }

        // 3. The binary.
        $binary = (string) config('paf.pdf_repair.binary');
        $available = $repairer->available();
        $this->state('qpdf reachable', $available, $available ? $binary : $binary.' — not found or not executable');

        if (! $available) {
            $this->newLine();
            $this->error('qpdf was not found.');
            $this->line('  Install it, or upload a copy and point PAF_QPDF_PATH at the binary.');
            $this->line('  On Windows the DLLs must sit beside qpdf.exe; on Linux the file needs the execute bit.');
            $this->newLine();

            return self::FAILURE;
        }

        // 4. Run it for real. This proves the pipeline — process spawned, file written, output
        //    readable — which is what an environment check can honestly establish. Whether qpdf can
        //    rewrite one particular vendor invoice is a question only that invoice can answer.
        $this->line('  Running a live conversion…');
        $sample = $this->sampleDocument();
        $repaired = $repairer->repair($sample);

        $worked = $repaired !== null && $this->fpdiCanRead($repaired);
        $this->state('Live qpdf conversion', $worked, $worked
            ? 'succeeded — FPDI can read the result'
            : 'failed — see storage/logs/laravel.log for the reason');

        $repairer->cleanUp();
        @unlink($sample);

        $this->newLine();

        if (! $worked) {
            $this->error('qpdf is present but the repair did not produce a usable file.');
            $this->newLine();

            return self::FAILURE;
        }

        $this->info('PDF attachment repair is working on this server.');
        $this->newLine();

        return self::SUCCESS;
    }

    private function state(string $label, bool $ok, string $detail): void
    {
        $this->line(sprintf(
            '  %s %-38s %s',
            $ok ? '<info>[ok]</info>  ' : '<error>[fail]</error>',
            $label,
            $detail,
        ));
    }

    /** An ordinary PDF. qpdf rewriting it proves the pipeline works; the content is irrelevant. */
    private function sampleDocument(): string
    {
        $pdf = new Fpdi;
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->Cell(0, 10, 'Environment check');

        $path = tempnam(sys_get_temp_dir(), 'paf-check-');
        file_put_contents($path, $pdf->Output('S'));

        return $path;
    }

    private function fpdiCanRead(string $path): bool
    {
        try {
            $probe = new Fpdi;
            $probe->setSourceFile($path);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
