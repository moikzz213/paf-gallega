<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A fully approved request could only move forward to paid. When a problem surfaced between
 * approval and payment (a wrong currency, say) the invoices were stranded: not editable, not
 * cancellable, and not rejectable, because rejection only applies while the request is still in
 * approval. Withdrawal is that missing exit, recorded separately from an approver's rejection so
 * the two are not confused in the history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->timestamp('withdrawn_at')->nullable()->after('rejection_reason');
            $table->foreignId('withdrawn_by')->nullable()->after('withdrawn_at')->constrained('users');
            $table->text('withdrawal_reason')->nullable()->after('withdrawn_by');
        });
    }

    public function down(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->dropForeign(['withdrawn_by']);
            $table->dropColumn(['withdrawn_at', 'withdrawn_by', 'withdrawal_reason']);
        });
    }
};
