<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\InvoiceRejection;
use App\Models\PaymentRequest;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

/**
 * Record the currency of payment requests raised before requests stored their own.
 *
 * A request used to borrow its currency from the invoices it held, so one whose invoices have all
 * left (rejected, withdrawn, last invoice released) showed AED whatever it was raised in. This works
 * out each such request's currency from firm evidence only, in order:
 *
 *  1. the invoices it still holds;
 *  2. the invoices its rejection recorded (invoice_rejections);
 *  3. the invoices named in its own audit trail — linked entries, and the list a withdrawal writes.
 *
 * Anything it cannot prove — no record of the invoices, invoices in different currencies, or an
 * invoice whose currency has been changed since — is left blank for Finance to confirm and set with
 * `paf:set-request-currency`. Nothing is ever assumed.
 *
 * Previews by default. `--apply` writes, and touches only the new `currency` field; take a database
 * backup first (AGENTS.md, Database safety).
 *
 * CR: ai/change-requests/fix-wrong-currency-on-payment-requests-and-dashboard-totals.md
 */
class RepairRequestCurrencies extends Command
{
    protected $signature = 'paf:repair-request-currencies {--apply : Write the currencies found (default: preview only)}';

    protected $description = 'Record the currency of older payment requests from their history; leave unprovable ones blank';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $found = [];
        $blank = [];

        $requests = PaymentRequest::query()->whereNull('currency')->orderBy('id')->get();

        foreach ($requests as $pr) {
            [$currency, $source] = $this->evidence($pr);

            if ($currency === null) {
                $blank[] = [$pr->reference_no, $pr->status, $pr->getRawOriginal('total_amount'), $source];

                continue;
            }

            $found[] = [$pr->reference_no, $pr->status, $currency, $source];

            if ($apply) {
                $pr->forceFill(['currency' => $currency])->saveQuietly();
                AuditLogger::log(
                    'currency_recorded',
                    "Currency of {$pr->reference_no} recorded as {$currency} from its history ({$source}).",
                    null, ['currency' => null], ['currency' => $currency], $pr,
                );
            }
        }

        $this->info(($apply ? 'Recorded' : 'Would record').' a currency on '.count($found).' payment request(s):');
        $this->table(['Request', 'Status', 'Currency', 'Evidence'], $found);

        $this->warn(count($blank).' payment request(s) left blank — confirm with Finance, then set each with paf:set-request-currency:');
        $this->table(['Request', 'Status', 'Total', 'Why it cannot be proven'], $blank);

        if (! $apply) {
            $this->line('Preview only. Nothing was changed. Back up the database, then run again with --apply.');
        }

        return Command::SUCCESS;
    }

    /**
     * The request's currency and where it came from, or [null, why not].
     *
     * @return array{0: string|null, 1: string}
     */
    private function evidence(PaymentRequest $pr): array
    {
        $held = $pr->invoices()->pluck('currency')->filter()->unique();

        if ($held->count() === 1) {
            return [$held->first(), 'invoices on the request'];
        }

        if ($held->count() > 1) {
            // Predates the one-currency rule. Its invoices already label it MULTI-CURRENCY.
            return [null, 'holds invoices in '.$held->implode(', ').' — left to its invoices'];
        }

        $invoiceIds = InvoiceRejection::where('payment_request_id', $pr->id)->pluck('invoice_id')
            ->merge(AuditLog::where('payment_request_id', $pr->id)->whereNotNull('invoice_id')->pluck('invoice_id'))
            ->merge(Invoice::whereIn('reference_no', $this->referencesReturnedOnWithdrawal($pr))->pluck('id'))
            ->unique()
            ->values();

        if ($invoiceIds->isEmpty()) {
            return [null, 'no record of the invoices it held'];
        }

        // A currency changed after the invoice left the request would make its current currency
        // the wrong evidence, so any such change disqualifies the lot.
        $changed = AuditLog::whereIn('invoice_id', $invoiceIds)
            ->whereNotNull('old_values')
            ->get(['old_values', 'new_values'])
            ->contains(fn ($log) => isset($log->old_values['currency'], $log->new_values['currency'])
                && $log->old_values['currency'] !== $log->new_values['currency']);

        if ($changed) {
            return [null, "an invoice's currency has changed since"];
        }

        $currencies = Invoice::whereIn('id', $invoiceIds)->pluck('currency')->filter()->unique();

        return match ($currencies->count()) {
            1 => [$currencies->first(), InvoiceRejection::where('payment_request_id', $pr->id)->exists() ? 'rejection history' : 'audit trail'],
            0 => [null, 'its invoices carry no currency'],
            default => [null, 'its invoices were in '.$currencies->implode(', ')],
        };
    }

    /**
     * The invoice references a withdrawal listed — "Invoices returned to Finance: INV-…, INV-…." —
     * read from that list alone, so a request reference elsewhere in the text is never mistaken for
     * an invoice.
     *
     * @return array<int, string>
     */
    private function referencesReturnedOnWithdrawal(PaymentRequest $pr): array
    {
        $descriptions = AuditLog::where('payment_request_id', $pr->id)->where('action', 'withdrawn')->pluck('description');

        return $descriptions
            ->flatMap(function ($text) {
                if (! preg_match('/Invoices returned to Finance: (.+?)\.?$/', (string) $text, $m)) {
                    return [];
                }

                return array_map('trim', explode(',', $m[1]));
            })
            ->filter()
            ->values()
            ->all();
    }
}
