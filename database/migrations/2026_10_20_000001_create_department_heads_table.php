<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Department Head(s) of each department: who receives that department's weekly Query Raised
 * Report. Nominated by an admin in Master Data; a department may have several Heads, and one person
 * may head several departments. Heads are existing users, never free-text addresses, so a
 * deactivated account stops receiving the report without anyone having to remember to remove it.
 *
 * Additive only — no existing table or row is touched. Every department starts with no Heads.
 *
 * CR: ai/change-requests/schedule-weekly-query-raised-report-email-to-department-heads.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_heads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['department_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_heads');
    }
};
