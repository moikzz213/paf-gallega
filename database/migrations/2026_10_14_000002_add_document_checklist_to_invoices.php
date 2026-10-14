<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Freight Forwarding's supporting-document checklist.
 *
 * `invoice_documents.document_type` is the checklist item a file was filed under (a key of
 * config('paf.document_checklist.types')), and `document_label` the name the user gave an "Other"
 * file. Both are null on every document that predates the checklist, which reads as "Unclassified".
 *
 * `invoices.subject_to_document_checklist` is set on every invoice submitted from now on. It is what
 * exempts the invoices already on record from the required items: they default to false here, so
 * correcting one of them is never blocked by a rule they were not submitted under. No existing row
 * is rewritten.
 *
 * CR: ai/change-requests/add-freight-forwarding-document-checklist-to-invoice-submission.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_documents', function (Blueprint $table) {
            $table->string('document_type', 50)->nullable()->after('invoice_id');
            $table->string('document_label', 150)->nullable()->after('document_type');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('subject_to_document_checklist')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('invoice_documents', function (Blueprint $table) {
            $table->dropColumn(['document_type', 'document_label']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('subject_to_document_checklist');
        });
    }
};
