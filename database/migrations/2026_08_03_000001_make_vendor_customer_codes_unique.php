<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicateVendorCode = DB::table('vendors')
            ->select('vendor_code')
            ->whereNotNull('vendor_code')
            ->where('vendor_code', '<>', '')
            ->groupBy('vendor_code')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        $duplicateCustomerCode = DB::table('customers')
            ->select('customer_code')
            ->whereNotNull('customer_code')
            ->where('customer_code', '<>', '')
            ->groupBy('customer_code')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicateVendorCode || $duplicateCustomerCode) {
            throw new RuntimeException('Duplicate vendor or customer codes must be resolved before applying code uniqueness constraints.');
        }

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropUnique('vendors_name_unique');
            $table->unique('vendor_code');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique('customers_name_unique');
            $table->unique('customer_code');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropUnique('vendors_vendor_code_unique');
            $table->unique('name');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique('customers_customer_code_unique');
            $table->unique('name');
        });
    }
};
