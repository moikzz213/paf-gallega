<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add invoice-log / ERP-posting fields and the payment cycle linkage.
        Schema::table('invoices', function (Blueprint $table) {
            $table->date('posting_date')->nullable()->after('status');       // date posted in ERP
            $table->string('erp_doc_no')->nullable()->after('posting_date'); // ERP document number
            $table->text('finance_remarks')->nullable()->after('erp_doc_no'); // remarks / query text
            $table->foreignId('posted_by')->nullable()->after('finance_remarks')->constrained('users');
            $table->timestamp('posted_at')->nullable()->after('posted_by');
            $table->string('payment_status')->default('not_initiated')->after('posted_at');
            // not_initiated | in_approval | approved_for_payment | paid
            $table->foreignId('payment_request_id')->nullable()->after('payment_status')
                ->constrained('payment_requests')->nullOnDelete();
        });

        // Retire the per-invoice approval / payment columns — that lifecycle now lives on payment_requests.
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['status', 'current_level']);
            $table->dropConstrainedForeignId('paid_by');
            $table->dropColumn([
                'current_level', 'approved_at', 'rejected_at', 'rejection_reason',
                'scheduled_date', 'paid_at', 'payment_reference',
            ]);
            $table->index(['status', 'payment_status']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['status', 'payment_status']);
            $table->dropConstrainedForeignId('payment_request_id');
            $table->dropConstrainedForeignId('posted_by');
            $table->dropColumn(['posting_date', 'erp_doc_no', 'finance_remarks', 'posted_at', 'payment_status']);

            $table->unsignedTinyInteger('current_level')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->date('scheduled_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users');
            $table->index(['status', 'current_level']);
        });
    }
};
