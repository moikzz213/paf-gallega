<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Named, read-only exceptions to role-based visibility: the viewer may see the invoices a colleague
 * submitted and the payment requests holding them, but gains no right to change either. Maintained
 * by an admin on the user screen; see User::viewableSubmitterIds and the two visibleTo scopes.
 *
 * Additive only — no existing table or row is touched. Every user starts with no grants.
 *
 * CR: ai/change-requests/grant-view-only-access-to-paf-records-of-nominated-colleagues.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_view_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('viewer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('colleague_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['viewer_id', 'colleague_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_view_grants');
    }
};
