<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_request_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_request_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');           // position in the chain (1..N)
            $table->unsignedTinyInteger('level')->nullable(); // source approval level, null when ad-hoc
            $table->string('label');                        // role / stage name
            $table->boolean('is_adhoc')->default(false);
            $table->string('status')->default('pending');   // pending | approved | rejected
            $table->foreignId('approver_id')->nullable()->constrained('users');
            $table->text('comments')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->timestamps();
            $table->unique(['payment_request_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_request_approvals');
    }
};
