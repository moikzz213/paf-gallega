<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no')->unique(); // PRF-2026-00001
            $table->foreignId('created_by')->constrained('users'); // finance user who initiated
            $table->string('status')->default('draft'); // draft | in_approval | approved | rejected | paid
            $table->unsignedInteger('current_stage')->nullable(); // sequence of the stage awaiting action
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_requests');
    }
};
