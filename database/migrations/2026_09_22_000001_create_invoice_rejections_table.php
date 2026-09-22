<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remembers that an invoice was on a payment request that got rejected.
 *
 * Rejection returns the invoices to Finance by clearing `invoices.payment_request_id`, and that one
 * column is the only link the report has — so a rejected PAF, its reason and its approver comments
 * vanished from the report the moment it was rejected. Re-initiating the corrected invoices then
 * overwrites the column with the new request, so simply keeping it would not have helped either:
 * the history has to live somewhere the current request cannot overwrite.
 *
 * One row per (invoice, rejected request). Both sides cascade: this is a record *of* those two
 * things, worth nothing once either is gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_rejections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_request_id')->constrained()->cascadeOnDelete();
            $table->timestamp('rejected_at');
            $table->timestamps();

            // A request is rejected once, so an invoice can appear against it once.
            $table->unique(['invoice_id', 'payment_request_id']);
            $table->index('rejected_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_rejections');
    }
};
