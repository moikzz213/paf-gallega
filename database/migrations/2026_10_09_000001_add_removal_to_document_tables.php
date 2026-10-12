<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documents attached after posting can be removed until payment, but are never destroyed: removal
 * hides the row everywhere (SoftDeletes — screens, PAF, public links, downloads) and keeps the file,
 * because approvers may already have relied on it. `removed_by` says who; the audit trail says why.
 *
 * Adds nullable columns only — no existing row is rewritten.
 *
 * CR: ai/change-requests/allow-removal-of-attachments-uploaded-by-finance.md
 */
return new class extends Migration
{
    private const TABLES = ['invoice_documents', 'payment_request_documents'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->softDeletes();
                $table->foreignId('removed_by')->nullable()->after('deleted_at')->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropConstrainedForeignId('removed_by');
                $table->dropSoftDeletes();
            });
        }
    }
};
