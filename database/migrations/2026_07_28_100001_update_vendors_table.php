<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('vendor_code')->nullable()->after('name');
            $table->decimal('credit_limit', 15, 2)->default(0)->after('vendor_code');
            $table->unsignedInteger('credit_days')->default(0)->after('credit_limit');
            $table->dropColumn(['email', 'trn']);
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('email')->nullable()->after('name');
            $table->string('trn', 50)->nullable()->after('email');
            $table->dropColumn(['vendor_code', 'credit_limit', 'credit_days']);
        });
    }
};
