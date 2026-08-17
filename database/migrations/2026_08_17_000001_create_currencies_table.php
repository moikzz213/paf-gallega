<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Currencies become master data: the Invoice Log dropdown now reads this table instead of the
 * hard-coded config list. The table is seeded from that same config list plus every code already
 * recorded on an invoice or invoice line, so existing invoices stay editable after the switch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // the 3-letter code stored on invoices, e.g. AED
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $codes = collect(config('paf.currencies'))
            ->merge(DB::table('invoices')->distinct()->pluck('currency'))
            ->merge(DB::table('invoice_items')->distinct()->pluck('currency'))
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $now = now();
        foreach ($codes as $code) {
            DB::table('currencies')->insert([
                'name' => $code,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
