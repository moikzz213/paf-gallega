<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An advance payment carries a different commercial risk from an ordinary payable: the money
     * leaves before the business has what it paid for. Until now that distinction lived only in
     * whatever the submitter typed in the description, so advances could not be listed, counted or
     * reconciled. Indexed because the Invoice Log filters on it.
     *
     * Existing invoices default to false. They are not backfilled: nothing on record reliably
     * identifies which of them were advances, and a guessed flag would be worse than none.
     *
     * CR: ai/change-requests/flag-advance-payment-invoices-and-allow-final-invoice-upload.md
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('is_advance_payment')->default(false)->index()->after('priority');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('is_advance_payment');
        });
    }
};
