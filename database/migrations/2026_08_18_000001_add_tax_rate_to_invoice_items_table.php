<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tax is entered as a percentage of the line amount, not as a cash figure, so the rate is what the
 * form captures and `tax_amount` becomes derived (amount x rate / 100, rounded to the currency).
 *
 * Existing lines only recorded the cash amount, so the rate is recovered from it where it can be:
 * a line of 1,000 with 50 tax was 5%. Lines with no tax stay at 0, and a rate that cannot be
 * expressed (no amount to divide by) is left at 0 with the recorded tax_amount untouched — the
 * money already agreed on the invoice is never rewritten by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('tax_rate', 5, 2)->default(0)->after('amount');
        });

        $this->backfillRates();
    }

    /** Split out from up() so the recovery itself can be exercised by a test. */
    public function backfillRates(): void
    {
        DB::table('invoice_items')
            ->select('id', 'amount', 'tax_amount')
            ->where('tax_amount', '>', 0)
            ->where('amount', '>', 0)
            ->orderBy('id')
            ->chunk(500, function ($items) {
                foreach ($items as $item) {
                    $rate = round(((float) $item->tax_amount / (float) $item->amount) * 100, 2);

                    // Anything beyond a plausible tax rate is data we should not reinterpret.
                    if ($rate <= 0 || $rate > 100) {
                        continue;
                    }

                    DB::table('invoice_items')->where('id', $item->id)->update(['tax_rate' => $rate]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('tax_rate');
        });
    }
};
