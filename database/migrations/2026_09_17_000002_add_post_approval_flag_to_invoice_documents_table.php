<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A document attached to an advance after its payment request was approved did not exist when
     * the approvers signed. Recorded at upload rather than inferred from timestamps later, so the
     * question "what was in front of the approver?" always has an answer on the record itself.
     *
     * CR: ai/change-requests/flag-advance-payment-invoices-and-allow-final-invoice-upload.md
     */
    public function up(): void
    {
        Schema::table('invoice_documents', function (Blueprint $table) {
            $table->boolean('uploaded_after_approval')->default(false)->after('uploaded_by');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_documents', function (Blueprint $table) {
            $table->dropColumn('uploaded_after_approval');
        });
    }
};
