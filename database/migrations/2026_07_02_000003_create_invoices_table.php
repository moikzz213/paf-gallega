<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no')->unique(); // PAF-2026-00001
            $table->string('vendor_name');
            $table->string('vendor_email')->nullable();
            $table->string('vendor_trn')->nullable(); // tax registration number
            $table->string('invoice_no');
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->string('currency', 3)->default('AED');
            $table->decimal('amount', 15, 2);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2);
            $table->string('category');
            $table->string('department');
            $table->string('cost_center')->nullable();
            $table->string('payment_method')->default('bank_transfer'); // bank_transfer | cheque | cash | card
            $table->string('priority')->default('normal'); // low | normal | high | urgent
            $table->text('description')->nullable();

            // lifecycle
            $table->string('status')->default('draft'); // draft | pending_approval | approved | rejected | scheduled | paid | cancelled
            $table->unsignedTinyInteger('current_level')->nullable(); // approval level currently pending
            $table->foreignId('submitted_by')->constrained('users');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->date('scheduled_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users');

            $table->timestamps();
            $table->index(['status', 'current_level']);
            $table->index('department');
            $table->index('vendor_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
