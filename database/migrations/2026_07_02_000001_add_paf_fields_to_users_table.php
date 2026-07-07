<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('requester')->after('password'); // admin | requester | approver | finance
            $table->unsignedTinyInteger('approval_level')->nullable()->after('role'); // only for approvers
            $table->string('department')->nullable()->after('approval_level');
            $table->string('job_title')->nullable()->after('department');
            $table->boolean('is_active')->default(true)->after('job_title');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'approval_level', 'department', 'job_title', 'is_active']);
        });
    }
};
