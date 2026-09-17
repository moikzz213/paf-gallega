<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Approval thresholds are amounts in the base currency (AED), but invoices are raised in whatever
 * currency the vendor bills in. Without a rate the two were compared as bare numbers, so USD 36,000
 * was measured against a 50,000 threshold as if it were AED 36,000 and skipped the level it should
 * have reached. This gives every currency a rate into the base currency.
 *
 * Left null on purpose for everything but the base currency: a currency with no rate cannot be
 * converted, and the chain builder refuses to route it rather than guessing 1:1 and under-routing
 * again. An admin fills the rates in under Master Data → Currencies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            // Units of base currency per 1 unit of this currency, e.g. USD -> 3.672500 AED.
            // Six decimal places so thin rates (JPY, IDR) survive the round trip.
            $table->decimal('exchange_rate', 15, 6)->nullable()->after('name');
        });

        DB::table('currencies')
            ->where('name', config('paf.base_currency', 'AED'))
            ->update(['exchange_rate' => 1]);
    }

    public function down(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            $table->dropColumn('exchange_rate');
        });
    }
};
