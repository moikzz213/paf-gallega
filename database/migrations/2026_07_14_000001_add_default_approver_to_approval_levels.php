<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_levels', function (Blueprint $table) {
            // Default approver pre-filled onto each request's chain for this level.
            $table->foreignId('default_approver_id')->nullable()->after('min_amount')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('approval_levels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_approver_id');
        });
    }
};
