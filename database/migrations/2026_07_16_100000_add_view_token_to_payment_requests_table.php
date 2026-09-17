<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->string('view_token', 64)->nullable()->after('reference_no');
        });

        // Backfill existing rows with unique tokens
        foreach (DB::table('payment_requests')->pluck('id') as $id) {
            DB::table('payment_requests')
                ->where('id', $id)
                ->update(['view_token' => Str::random(64)]);
        }

        Schema::table('payment_requests', function (Blueprint $table) {
            $table->string('view_token', 64)->nullable(false)->unique()->change();
        });
    }

    public function down(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->dropColumn('view_token');
        });
    }
};
