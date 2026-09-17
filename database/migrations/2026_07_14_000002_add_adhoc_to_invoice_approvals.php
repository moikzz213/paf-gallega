<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_approvals', function (Blueprint $table) {
            // Marks a stage added ad-hoc for this request (beyond the amount-based levels).
            $table->boolean('is_adhoc')->default(false)->after('level_name');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_approvals', function (Blueprint $table) {
            $table->dropColumn('is_adhoc');
        });
    }
};
