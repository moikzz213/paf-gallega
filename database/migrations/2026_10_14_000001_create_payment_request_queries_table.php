<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An approver's question on a payment request, and the answer that sent it back to them.
 *
 * Replaces rejection as the way an approver stops a request: the request is put on hold at the
 * approver's stage (`payment_requests.status = queried`) instead of being ended, and resumes at the
 * same stage when it is resubmitted. One row per query, so a request queried twice keeps both
 * exchanges. A row is open while `resubmitted_at` is null.
 *
 * `last_reminder_sent_at` drives the two-day reminder to the people who have to answer — see
 * SendApprovalQueryReminders.
 *
 * CR: ai/change-requests/replace-reject-with-query-in-payment-approval.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_request_queries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_request_approval_id')->constrained()->cascadeOnDelete();
            // Snapshotted from the stage, so the record reads the same if the chain is ever edited.
            $table->unsignedInteger('sequence');
            $table->string('stage_label');
            $table->foreignId('raised_by')->constrained('users');
            $table->text('question');
            $table->timestamp('raised_at');
            $table->foreignId('resubmitted_by')->nullable()->constrained('users');
            $table->text('response')->nullable();
            $table->timestamp('resubmitted_at')->nullable();
            $table->timestamp('last_reminder_sent_at')->nullable();
            $table->timestamps();

            $table->index(['payment_request_id', 'resubmitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_request_queries');
    }
};
