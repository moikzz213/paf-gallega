<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('vendor_id')
                ->nullable()
                ->after('vendor_name')
                ->constrained()
                ->nullOnDelete();
        });

        $uniqueVendors = DB::table('vendors')
            ->selectRaw('MIN(id) AS id, name')
            ->groupBy('name')
            ->havingRaw('COUNT(*) = 1')
            ->get();

        foreach ($uniqueVendors as $vendor) {
            DB::table('invoices')
                ->whereNull('vendor_id')
                ->where('vendor_name', $vendor->name)
                ->update(['vendor_id' => $vendor->id]);
        }
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_id');
        });
    }
};
