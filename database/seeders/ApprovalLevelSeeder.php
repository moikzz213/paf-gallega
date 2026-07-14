<?php

namespace Database\Seeders;

use App\Models\ApprovalLevel;
use App\Models\User;
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

        // Pre-fill each level's default approver with the seeded approver at that level, if present.
        $approvers = User::where('role', User::ROLE_APPROVER)->get()->keyBy('approval_level');

        foreach ($levels as $level) {
            $level['default_approver_id'] = $approvers[$level['level']]->id ?? null;
            ApprovalLevel::updateOrCreate(['level' => $level['level']], $level);
        }
    }
}
