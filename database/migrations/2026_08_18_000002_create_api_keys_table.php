<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credentials for machine callers of the export API — a spreadsheet or BI tool refreshing the
 * invoice report, which cannot hold a session cookie.
 *
 * Each key belongs to a user and carries **that user's visibility**: the export runs through
 * `Invoice::scopeVisibleTo($key->user)`, so a key can never read more than the person it was issued
 * for. Only a hash of the secret is stored, so a leaked database does not hand over working
 * credentials, and a key can be deactivated without touching the user's own login.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('key')->unique();
            $table->string('secret_hash');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
