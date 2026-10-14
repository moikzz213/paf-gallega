<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A payment request records the currency it was raised in.
 *
 * It used to borrow it from the invoices it held, so once they left — the request rejected,
 * withdrawn, or its last invoice released — it had none, and every screen and the PAF showed AED.
 * Set at creation from now on. Existing rows stay null here; `paf:repair-request-currencies`
 * fills the ones its history can prove, and the rest stay blank until Finance confirms them.
 *
 * CR: ai/change-requests/fix-wrong-currency-on-payment-requests-and-dashboard-totals.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->string('currency', 10)->nullable()->after('total_amount');
        });
    }

    public function down(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
