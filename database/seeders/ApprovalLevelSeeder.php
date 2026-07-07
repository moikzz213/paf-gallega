<?php

namespace Database\Seeders;

use App\Models\ApprovalLevel;
use Illuminate\Database\Seeder;

class ApprovalLevelSeeder extends Seeder
{
    public function run(): void
    {
        $levels = [
            ['level' => 1, 'name' => 'Department Manager', 'min_amount' => 0],
            ['level' => 2, 'name' => 'Finance Director', 'min_amount' => 10000],
            ['level' => 3, 'name' => 'CFO', 'min_amount' => 50000],
        ];

        foreach ($levels as $level) {
            ApprovalLevel::updateOrCreate(['level' => $level['level']], $level);
        }
    }
}
