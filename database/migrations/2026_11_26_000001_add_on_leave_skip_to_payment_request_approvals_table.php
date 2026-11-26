<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An approver on leave: their stage is recorded as `skipped` (the `status` column is a plain
 * string, so no change there), with the reason and who marked it. `acted_at` holds when.
 * Additive only — no existing row is touched.
 *
 * `skipped_by` is an indexed user id **without** a foreign-key constraint. Adding a constraint makes
 * MySQL rebuild the table and re-check every existing foreign key, and the working database holds
 * approval rows whose payment request no longer exists (known-issues), so the rebuild is refused.
 * Those rows are not this change's to delete. The service only ever writes the acting user's id.
 *
 * Each column is added only if missing, so a run interrupted after adding them can be re-run.
 *
 * CR: ai/change-requests/route-payment-approvals-past-an-approver-on-leave.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_request_approvals', function (Blueprint $table) {
            if (! Schema::hasColumn('payment_request_approvals', 'skip_reason')) {
                $table->text('skip_reason')->nullable()->after('comments');
            }
            if (! Schema::hasColumn('payment_request_approvals', 'skipped_by')) {
                $table->unsignedBigInteger('skipped_by')->nullable()->after('skip_reason');
            }
        });

        Schema::table('payment_request_approvals', function (Blueprint $table) {
            $table->index('skipped_by');
        });
    }

    public function down(): void
    {
        Schema::table('payment_request_approvals', function (Blueprint $table) {
            $table->dropIndex(['skipped_by']);
            $table->dropColumn(['skipped_by', 'skip_reason']);
        });
    }
};
