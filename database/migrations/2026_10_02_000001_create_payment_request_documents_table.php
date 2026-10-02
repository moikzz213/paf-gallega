<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supporting documents that belong to a payment request as a whole rather than to one invoice — a
 * vendor statement, a covering memo, a contract schedule. Until now these had nowhere to go and
 * travelled by email beside the request; stored here they are merged into the PAF the approvers sign.
 *
 * Same shape as `invoice_documents`, including `uploaded_after_approval`: Finance may add documents
 * after the chain has signed, and the record has to say that those were not in front of the
 * approvers. Additive only — no existing table or row is touched.
 *
 * CR: ai/change-requests/requester-corrections-during-approval-and-payment-request-attachments.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_request_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->boolean('uploaded_after_approval')->default(false);
            $table->string('original_name');
            $table->string('file_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_request_documents');
    }
};
