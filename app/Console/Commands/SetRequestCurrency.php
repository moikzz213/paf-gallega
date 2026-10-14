<?php

namespace App\Console\Commands;

use App\Models\Currency;
use App\Models\PaymentRequest;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

/**
 * Set a payment request's currency by hand, once Finance has confirmed it — for the requests
 * `paf:repair-request-currencies` could not prove and left blank. One request at a time, a currency
 * from the master list only, and the change recorded in the request's history with who confirmed it.
 *
 * CR: ai/change-requests/fix-wrong-currency-on-payment-requests-and-dashboard-totals.md
 */
class SetRequestCurrency extends Command
{
    protected $signature = 'paf:set-request-currency
        {reference : The payment request reference, e.g. PAF-2026-00009}
        {currency : The currency Finance confirmed, e.g. USD}
        {--confirmed-by= : Who in Finance confirmed it (recorded in the history)}';

    protected $description = 'Set the confirmed currency of one payment request';

    public function handle(): int
    {
        $pr = PaymentRequest::where('reference_no', $this->argument('reference'))->first();
        $code = strtoupper(trim((string) $this->argument('currency')));
        $confirmedBy = trim((string) $this->option('confirmed-by'));

        if (! $pr) {
            $this->error("No payment request {$this->argument('reference')}.");

            return Command::FAILURE;
        }

        if (! Currency::where('name', $code)->exists()) {
            $this->error("{$code} is not in the currency master list.");

            return Command::FAILURE;
        }

        if ($confirmedBy === '') {
            $this->error('Say who confirmed it: --confirmed-by="Name, Finance".');

            return Command::FAILURE;
        }

        // Invoices still on the request are the evidence; a currency that contradicts them is a
        // mistake, not a correction.
        $held = $pr->invoices()->pluck('currency')->filter()->unique();
        if ($held->isNotEmpty() && ! $held->contains($code)) {
            $this->error("{$pr->reference_no} holds invoices in {$held->implode(', ')}, not {$code}.");

            return Command::FAILURE;
        }

        $old = $pr->getRawOriginal('currency');

        $pr->forceFill(['currency' => $code])->saveQuietly();

        AuditLogger::log(
            'currency_recorded',
            "Currency of {$pr->reference_no} set to {$code}".($old ? " (was {$old})" : '').", confirmed by {$confirmedBy}.",
            null, ['currency' => $old], ['currency' => $code], $pr,
        );

        $this->info("{$pr->reference_no} is now recorded in {$code}.");

        return Command::SUCCESS;
    }
}
