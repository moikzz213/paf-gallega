<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Vendor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Clears the way for the per-vendor invoice number rule, without destroying anything.
 *
 * Production could not simply switch the rule on. Two things were in the way, and neither could be
 * solved by deleting or cancelling records:
 *
 *   1. Petty cash floats, staff reimbursements and fuel claims have no vendor invoice number, so
 *      submitters type a word in the field — `petty cash` ten times over for one account, `na`
 *      seven times, `august` five. Different amounts, different months, genuinely different
 *      transactions. These accounts are marked as expense accounts and leave the rule's scope.
 *   2. Duplicates that were already paid or sitting on a live payment request. Cancelling those
 *      would rewrite payment history, so instead the later copies are grandfathered — kept exactly
 *      as they are, but no longer holding the number. The earliest invoice of each group keeps it
 *      and goes on refusing anything new that collides.
 *
 * Reports what it would do and changes nothing unless `--apply` is given. Run it after
 * `paf:check-invoice-duplicates`, and before migrating.
 *
 * One group it will not touch: copies that all carry the identical amount. That is what a
 * double-billing looks like, and grandfathering it would bless the very thing the rule exists to
 * catch — while leaving any unpaid copy still payable. Production held three copies of one
 * AED 752,171.90 invoice, one paid and two waiting. Those are listed for Finance to cancel or
 * correct, and the migration stays blocked until they do.
 *
 * Grandfathered groups stay visible to `paf:check-invoice-duplicates` afterwards: grandfathering
 * unblocks a deployment, it does not settle what the figures mean.
 *
 * CR: ai/change-requests/enforce-unique-invoice-number-per-vendor.md
 */
class PrepareInvoiceNoUniqueness extends Command
{
    protected $signature = 'paf:prepare-invoice-no-uniqueness
        {--apply : write the changes; without this the command only reports}
        {--pattern=* : vendor name fragments treated as expense accounts (default: "petty cash", "reimbursement")}';

    protected $description = 'Mark expense accounts and grandfather historic duplicates so invoice numbers can be made unique per vendor';

    private const DEFAULT_PATTERNS = ['petty cash', 'reimbursement'];

    private int $unresolved = 0;

    private const EXPRESSION = "CASE WHEN status = 'cancelled' OR invoice_no_exempt = 1 THEN NULL ELSE LOWER(TRIM(invoice_no)) END";

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $patterns = $this->option('pattern') ?: self::DEFAULT_PATTERNS;

        $this->newLine();
        $this->line('<comment>Preparing invoice numbers for per-vendor uniqueness</comment>');
        $this->line($apply
            ? '  <fg=yellow>APPLY — changes will be written.</>'
            : '  Dry run. Nothing is written. Re-run with --apply to commit.');
        $this->newLine();

        DB::beginTransaction();

        try {
            $vendors = $this->markExpenseAccounts($patterns);
            $grandfathered = $this->grandfatherRemainingDuplicates();

            $this->line('  <options=bold>Summary</>');
            $this->line("    Vendors marked as expense accounts: {$vendors['vendors']}");
            $this->line("    Their invoices taken out of the rule: {$vendors['invoices']}");
            $this->line("    Historic duplicate copies grandfathered: {$grandfathered}");
            $this->newLine();

            if ($this->unresolved > 0) {
                $this->line("    <fg=red>Numbers needing a Finance decision first: {$this->unresolved}</>");
                $this->newLine();
            }

            if ($apply) {
                DB::commit();
                $this->info('  Applied. Run `php artisan paf:check-invoice-duplicates` to confirm, then migrate.');
            } else {
                DB::rollBack();
                $this->line('  Rolled back (dry run). Re-run with --apply to commit.');
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->newLine();
            $this->error('  Nothing was changed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $patterns
     * @return array{vendors: int, invoices: int}
     */
    private function markExpenseAccounts(array $patterns): array
    {
        $this->line('  <options=bold>1. Expense accounts</>');
        $this->line('     Matching vendor names against: '.implode(', ', array_map(fn ($p) => "\"{$p}\"", $patterns)));

        $query = Vendor::where('is_expense_account', false)->where(function ($outer) use ($patterns) {
            foreach ($patterns as $pattern) {
                $outer->orWhere('name', 'like', '%'.$pattern.'%');
            }
        });

        $vendors = $query->get(['id', 'name']);

        if ($vendors->isEmpty()) {
            $this->line('     Nothing to mark.');
            $this->newLine();

            return ['vendors' => 0, 'invoices' => 0];
        }

        foreach ($vendors->take(10) as $vendor) {
            $this->line("     <fg=gray>+</> {$vendor->name}");
        }

        if ($vendors->count() > 10) {
            $this->line('     <fg=gray>  … and '.($vendors->count() - 10).' more</>');
        }

        Vendor::whereIn('id', $vendors->pluck('id'))->update(['is_expense_account' => true]);

        // Their existing invoices carry the old value, and the index reads the invoice, not the
        // vendor — so without this the historic petty cash rows would still collide with each other.
        $invoices = Invoice::whereIn('vendor_id', $vendors->pluck('id'))
            ->where('invoice_no_exempt', false)
            ->update(['invoice_no_exempt' => true]);

        $this->line("     {$vendors->count()} vendors, {$invoices} of their invoices taken out of the rule.");
        $this->newLine();

        return ['vendors' => $vendors->count(), 'invoices' => $invoices];
    }

    /**
     * Whatever still collides after the expense accounts have gone is a real vendor with a real
     * number recorded twice. The earliest keeps it; the rest are preserved but stop holding it.
     */
    private function grandfatherRemainingDuplicates(): int
    {
        $this->line('  <options=bold>2. Historic duplicates on real vendors</>');

        $groups = DB::table('invoices')
            ->selectRaw('vendor_id, '.self::EXPRESSION.' AS invoice_no_key, COUNT(*) AS occurrences')
            ->whereNotNull('vendor_id')
            ->whereRaw(self::EXPRESSION.' IS NOT NULL')
            ->groupByRaw('vendor_id, '.self::EXPRESSION)
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($groups->isEmpty()) {
            $this->line('     None left — nothing to grandfather.');
            $this->newLine();

            return 0;
        }

        $total = 0;
        $unresolved = 0;

        foreach ($groups as $group) {
            $invoices = Invoice::where('vendor_id', $group->vendor_id)
                ->whereRaw(self::EXPRESSION.' = ?', [$group->invoice_no_key])
                ->orderBy('id')
                ->get(['id', 'reference_no', 'vendor_name', 'invoice_no', 'payment_status', 'total_amount', 'currency']);

            $keeper = $invoices->first();
            $rest = $invoices->slice(1);

            $sameAmount = $invoices->pluck('total_amount')->unique()->count() === 1;

            // Identical amounts under one number is what a double-billing looks like, and
            // grandfathering it would quietly bless the thing the rule exists to catch — worse,
            // it would leave any unpaid copy still payable. Production had three copies of one
            // AED 752,171.90 invoice, one paid and two waiting. Finance decide these, not this
            // command: cancel a true duplicate, or correct the number if the documents differ.
            if ($sameAmount) {
                $this->line("     <fg=red>{$keeper->vendor_name} — {$group->invoice_no_key} ×{$group->occurrences} — NOT grandfathered</>");
                $this->line("       <fg=gray>every copy carries {$keeper->total_amount} {$keeper->currency}, which is what a double-billing looks like.</>");

                foreach ($invoices as $invoice) {
                    $action = $invoice->payment_status === Invoice::PAY_NOT_INITIATED
                        ? 'can be cancelled'
                        : 'in a payment cycle';
                    $this->line("       {$invoice->reference_no} ({$invoice->payment_status}) — {$action}");
                }

                $this->line('       <fg=yellow>Cancel the duplicate, or correct its number, then re-run.</>');
                $unresolved++;

                continue;
            }

            $this->line("     {$keeper->vendor_name} — {$group->invoice_no_key} ×{$group->occurrences}");
            $this->line("       <fg=gray>keeps the number:</> {$keeper->reference_no}");

            foreach ($rest as $invoice) {
                $this->line("       <fg=gray>grandfathered:</>    {$invoice->reference_no} ({$invoice->payment_status})");
            }

            Invoice::whereIn('id', $rest->pluck('id'))->update(['invoice_no_exempt' => true]);
            $total += $rest->count();
        }

        $this->newLine();
        $this->line("     {$total} copies grandfathered across {$groups->count()} numbers.");
        $this->line('     <fg=yellow>These remain visible to `paf:check-invoice-duplicates`; grandfathering unblocks</>');
        $this->line('     <fg=yellow>the deployment, it does not settle whether any of them was billed twice.</>');

        if ($unresolved > 0) {
            $this->newLine();
            $this->line("     <fg=red>{$unresolved} number(s) left for Finance — see above. The migration stays blocked</>");
            $this->line('     <fg=red>until they are cancelled or corrected, which is the point of the rule.</>');
        }

        $this->newLine();

        $this->unresolved = $unresolved;

        return $total;
    }
}
