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

        $this->dropUniqueIndexIfPresent('vendors', 'name');
        $this->addUniqueIndexIfMissing('vendors', 'vendor_code');
        $this->dropUniqueIndexIfPresent('customers', 'name');
        $this->addUniqueIndexIfMissing('customers', 'customer_code');
    }

    public function down(): void
    {
        $this->dropUniqueIndexIfPresent('vendors', 'vendor_code');
        $this->addUniqueIndexIfMissing('vendors', 'name');
        $this->dropUniqueIndexIfPresent('customers', 'customer_code');
        $this->addUniqueIndexIfMissing('customers', 'name');
    }

    private function dropUniqueIndexIfPresent(string $tableName, string $column): void
    {
        $indexName = $this->uniqueIndexName($tableName, $column);
        if (! $indexName) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($indexName) {
            $table->dropUnique($indexName);
        });
    }

    private function addUniqueIndexIfMissing(string $tableName, string $column): void
    {
        if ($this->uniqueIndexName($tableName, $column)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($column) {
            $table->unique($column);
        });
    }

    private function uniqueIndexName(string $tableName, string $column): ?string
    {
        foreach (Schema::getIndexes($tableName) as $index) {
            if (($index['unique'] ?? false) && ($index['columns'] ?? []) === [$column]) {
                return $index['name'];
            }
        }

        return null;
    }
};
