<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The invoice form only ever asked for a currency per line item — the header currency was left at
 * its 'AED' default, so a EUR invoice was logged and displayed as AED. The controller now derives
 * the header from the lines; this brings the rows already in the table into line with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('invoices')->orderBy('id')->chunkById(200, function ($invoices) {
            foreach ($invoices as $invoice) {
                $currencies = DB::table('invoice_items')
                    ->where('invoice_id', $invoice->id)
                    ->distinct()
                    ->pluck('currency')
                    ->filter()
                    ->values();

                // Leave mixed-currency invoices alone — there is no single right answer for them,
                // and the header amount is a sum of the lines, so it needs a human decision.
                if ($currencies->count() !== 1 || $currencies->first() === $invoice->currency) {
                    continue;
                }

                DB::table('invoices')->where('id', $invoice->id)->update(['currency' => $currencies->first()]);
            }
        });
    }

    public function down(): void
    {
        // No going back: the pre-migration value was a default, not a recorded choice.
    }
};
