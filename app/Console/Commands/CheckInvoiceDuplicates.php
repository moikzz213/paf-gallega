<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Support\InvoiceDuplicates;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Answers "what is actually in the way?" before the per-vendor invoice number rule is switched on,
 * and afterwards, "what did we agree to live with?".
 *
 * The migration refuses to build the index over data that already breaks it, because a unique index
 * failing on its own produces a driver error naming neither the vendor nor the invoices involved.
 * This reports the same set in a form Finance can act on, split three ways:
 *
 *   - **Blocking** — two or more live copies still holding the number. The migration will refuse
 *     until these are cancelled or grandfathered.
 *   - **Grandfathered** — copies already paid or in a payment cycle when the rule arrived, kept
 *     exactly as they are and no longer holding the number. Still listed, deliberately: they
 *     unblocked the deployment, they did not settle whether anything was billed twice.
 *   - **Expense accounts** — petty cash, reimbursements and fuel claims, which have no vendor
 *     invoice number at all. Counted, not itemised; repetition there is expected, not a fault.
 *
 * Strictly read-only. It changes nothing and is safe to run against production.
 *
 * CR: ai/change-requests/enforce-unique-invoice-number-per-vendor.md
 */
class CheckInvoiceDuplicates extends Command
{
    protected $signature = 'paf:check-invoice-duplicates {--csv= : write the full listing to this file}';

    protected $description = 'Report vendor invoice numbers recorded more than once, and whether each blocks the uniqueness rule';

    public function handle(): int
    {
        $this->newLine();
        $this->line('<comment>Duplicate vendor invoice numbers</comment>');
        $this->newLine();

        // Exemption deliberately not honoured, so grandfathered history stays reported here even
        // though the index ignores it. See App\Support\InvoiceDuplicates for why the grouping is
        // shaped the way it is.
        $groups = InvoiceDuplicates::groups(honourExemption: false)->get();

        $expenseGroups = (clone $groups)->filter(fn ($g) => $this->vendorIsExpenseAccount($g->vendor_id));
        $groups = $groups->reject(fn ($g) => $this->vendorIsExpenseAccount($g->vendor_id));

        $this->line('  Invoices examined: '.Invoice::count());
        $this->line('  Not linked to a vendor (outside the rule): '.Invoice::whereNull('vendor_id')->count());
        $this->line('  Repeated numbers on expense accounts (expected, out of scope): '.$expenseGroups->count());
        $this->newLine();

        if ($groups->isEmpty()) {
            $this->info('  No duplicates on ordinary vendors. The rule can be switched on as it stands.');
            $this->newLine();

            return self::SUCCESS;
        }

        $rows = [];
        $blocking = 0;

        foreach ($groups as $group) {
            $invoices = Invoice::with('paymentRequest:id,reference_no,status')
                ->where('vendor_id', $group->vendor_id)
                ->whereRaw(InvoiceDuplicates::expression(false).' = ?', [$group->invoice_no_key])
                ->orderBy('id')
                ->get();

            $live = $invoices->where('invoice_no_exempt', false);
            $isBlocking = $live->count() > 1;
            $blocking += $isBlocking ? 1 : 0;

            $sameAmount = $invoices->pluck('total_amount')->unique()->count() === 1;
            $vendor = $invoices->first()?->vendor_name ?? "vendor #{$group->vendor_id}";

            $label = $isBlocking
                ? '<fg=red>BLOCKING</>'
                : '<fg=gray>grandfathered</>';
            $money = $sameAmount
                ? ' <fg=red>[same amount — review as a possible double-billing]</>'
                : '';

            $this->line("  <options=bold>{$vendor}</> — invoice no. {$group->invoice_no_key} ×{$group->occurrences}  {$label}{$money}");

            foreach ($invoices as $invoice) {
                $verdict = match (true) {
                    (bool) $invoice->invoice_no_exempt => 'exempt (grandfathered)',
                    $invoice->payment_status === Invoice::PAY_PAID => 'PAID — cannot cancel',
                    $invoice->payment_status !== Invoice::PAY_NOT_INITIATED => 'in a payment cycle',
                    default => 'can be cancelled',
                };

                $this->line(sprintf(
                    '    <fg=gray>%s</>  %-12s %-21s %12s %-4s PAF %-18s %s',
                    $invoice->reference_no,
                    $invoice->status,
                    $invoice->payment_status,
                    $invoice->total_amount,
                    $invoice->currency,
                    $invoice->paymentRequest?->reference_no ?? '—',
                    $verdict,
                ));

                $rows[] = [
                    $vendor, $invoice->invoice_no, $invoice->reference_no, $invoice->status,
                    $invoice->payment_status, $invoice->paymentRequest?->reference_no ?? '',
                    $invoice->total_amount, $invoice->currency,
                    $isBlocking ? 'blocking' : 'grandfathered',
                    $sameAmount ? 'same amount' : 'differing amounts',
                    $verdict,
                ];
            }

            $this->newLine();
        }

        $this->line('  <options=bold>Summary</>');
        $this->line("    Numbers repeated on ordinary vendors: {$groups->count()}");
        $this->line("    Of those, blocking the rule: {$blocking}");
        $this->newLine();

        if ($path = $this->option('csv')) {
            $this->writeCsv($path, $rows);
        }

        if ($blocking === 0) {
            $this->info('  Nothing blocks the rule. The remainder are grandfathered and kept for review.');
            $this->newLine();

            return self::SUCCESS;
        }

        $this->error('  The migration will refuse to run while these exist.');
        $this->line('  Cancel the duplicates that are not in a payment cycle, then run');
        $this->line('  `php artisan paf:prepare-invoice-no-uniqueness --apply` for the rest.');
        $this->newLine();

        return self::FAILURE;
    }

    private function vendorIsExpenseAccount(int $vendorId): bool
    {
        return (bool) DB::table('vendors')->where('id', $vendorId)->value('is_expense_account');
    }

    /** @param  array<int, array<int, mixed>>  $rows */
    private function writeCsv(string $path, array $rows): void
    {
        $handle = fopen($path, 'w');
        fputcsv($handle, [
            'vendor', 'invoice_no', 'reference_no', 'status', 'payment_status', 'payment_request',
            'total', 'currency', 'group_state', 'amounts', 'verdict',
        ]);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);
        $this->line("  Full listing written to {$path}");
        $this->newLine();
    }
}
