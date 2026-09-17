<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UserSeeder::class,          // users first so levels can reference default approvers
            ApprovalLevelSeeder::class,
            MasterDataSeeder::class,    // vendors, business units, departments, locations
            DemoDataSeeder::class,
        ]);
    }
}
