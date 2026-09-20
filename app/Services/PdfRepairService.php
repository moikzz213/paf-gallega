<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Rewrites a PDF that FPDI cannot read into one that it can.
 *
 * FPDI's free parser refuses two things that vendor invoices carry constantly: permissions-only
 * encryption (the file opens without a password in any viewer, but the parser will not touch it) and
 * the object/cross-reference streams that PDF 1.5+ uses. `qpdf` converts either into the plain,
 * uncompressed form the parser understands — a format change, not a re-render, so text stays text.
 *
 * Used only where the ordinary merge has already failed (see `PdfMergeService::mergePdfs`), so a
 * document that works today never takes this path. Every failure here — no binary, a bad exit code,
 * a timeout, a password-protected file — returns null, and the caller falls back to listing the
 * document as a link exactly as it does now. The worst outcome is the behaviour before this existed.
 *
 * Nothing is written back to storage: the repaired copy lives in the system temp area for the
 * duration of the merge and is deleted by `cleanUp()`.
 *
 * CR: ai/change-requests/repair-unmergeable-pdf-attachments-with-qpdf.md
 */
class PdfRepairService
{
    /** @var array<int, string> Temp files this instance created, deleted by cleanUp(). */
    private array $scratch = [];

    /** Memoised: whether the converter answered a probe. Null until first asked. */
    private ?bool $available = null;

    public function enabled(): bool
    {
        return (bool) config('paf.pdf_repair.enabled');
    }

    /**
     * Whether the converter is actually installed.
     *
     * Probed rather than inferred from a failed conversion: a missing binary surfaces differently on
     * each platform — an exception here, exit 127 on POSIX, exit 1 or 9009 on Windows — and an
     * environment nobody installed qpdf on is the likeliest failure of the lot. Asked once per
     * instance, and reported once, so a missed installation is discoverable without a line per file.
     */
    public function available(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        $binary = (string) config('paf.pdf_repair.binary');
        $probe = new Process([$binary, '--version']);
        $probe->setTimeout(10);

        try {
            $probe->run();
            $this->available = $probe->isSuccessful();
        } catch (\Throwable $e) {
            $this->available = false;
        }

        if (! $this->available) {
            Log::warning('PDF repair is unavailable; attachments that cannot be merged will be listed as links', [
                'binary' => $binary,
                'hint' => 'Install qpdf, or set PAF_QPDF_PATH to its full path.',
            ]);
        }

        return $this->available;
    }

    /**
     * A repaired copy of $path, or null when it cannot be produced.
     *
     * Null is not an error condition — it is the signal to fall back.
     */
    public function repair(string $path): ?string
    {
        if (! $this->enabled() || ! is_file($path) || ! $this->available()) {
            return null;
        }

        $binary = (string) config('paf.pdf_repair.binary');

        // tempnam creates the file, so the name is ours and cannot be taken between now and the
        // write. qpdf overwrites it; the extension is irrelevant to both qpdf and FPDI.
        $target = tempnam(sys_get_temp_dir(), 'paf-repair-');

        if ($target === false) {
            return null;
        }

        $this->scratch[] = $target;

        // An argument list, never a shell string: these paths carry vendor-supplied filenames, and
        // nothing in one may ever be read as part of the command.
        $process = new Process([
            $binary,
            // Strips permissions-only encryption. A file that needs a password to open fails here
            // rather than being guessed at, and falls back like any other unreadable document.
            '--decrypt',
            // The two things the free parser cannot follow.
            '--stream-data=uncompress',
            '--object-streams=disable',
            // Warnings still produce a usable file (qpdf exits 3); the exit-code check below is
            // what decides, so there is no need for them on stderr.
            '--no-warn',
            $path,
            $target,
        ]);

        $process->setTimeout((float) config('paf.pdf_repair.timeout'));

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            Log::warning('PDF repair timed out; falling back to the link list', [
                'file' => basename($path),
                'timeout' => config('paf.pdf_repair.timeout'),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::warning('PDF repair failed to run; falling back to the link list', [
                'file' => basename($path),
                'reason' => $e->getMessage(),
            ]);

            return null;
        }

        // qpdf exits 3 on warnings and still writes a usable file; only 0 and 3 are worth reading.
        if (! in_array($process->getExitCode(), [0, 3], true) || ! is_file($target) || filesize($target) === 0) {
            Log::warning('PDF repair did not produce a usable file; falling back to the link list', [
                'file' => basename($path),
                'exit_code' => $process->getExitCode(),
                'reason' => trim($process->getErrorOutput()) ?: 'no output',
            ]);

            return null;
        }

        return $target;
    }

    /** Remove every working copy this instance made. Safe to call more than once. */
    public function cleanUp(): void
    {
        foreach ($this->scratch as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        $this->scratch = [];
    }

    public function __destruct()
    {
        $this->cleanUp();
    }
}
