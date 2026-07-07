<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('level');
            $table->string('level_name');
            $table->string('status')->default('pending'); // pending | approved | rejected
            $table->foreignId('approver_id')->nullable()->constrained('users');
            $table->text('comments')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->timestamps();
            $table->unique(['invoice_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_approvals');
    }
};
