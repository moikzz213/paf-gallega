<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['name' => 'System Admin', 'email' => 'admin@paf.local', 'role' => User::ROLE_ADMIN, 'department' => 'IT', 'job_title' => 'System Administrator'],
            ['name' => 'Rachel Cruz', 'email' => 'requester@paf.local', 'role' => User::ROLE_REQUESTER, 'department' => 'Procurement', 'job_title' => 'Procurement Officer'],
            ['name' => 'Daniel Reyes', 'email' => 'requester2@paf.local', 'role' => User::ROLE_REQUESTER, 'department' => 'Operations', 'job_title' => 'Operations Coordinator'],
            ['name' => 'Liam Fernandez', 'email' => 'approver1@paf.local', 'role' => User::ROLE_APPROVER, 'approval_level' => 1, 'department' => 'Operations', 'job_title' => 'Department Manager'],
            ['name' => 'Fatima Hassan', 'email' => 'approver2@paf.local', 'role' => User::ROLE_APPROVER, 'approval_level' => 2, 'department' => 'Finance', 'job_title' => 'Finance Director'],
            ['name' => 'Omar Khalid', 'email' => 'approver3@paf.local', 'role' => User::ROLE_APPROVER, 'approval_level' => 3, 'department' => 'Finance', 'job_title' => 'Chief Financial Officer'],
            ['name' => 'Grace Tan', 'email' => 'finance@paf.local', 'role' => User::ROLE_FINANCE, 'department' => 'Finance', 'job_title' => 'AP Accountant'],
        ];

        foreach ($users as $user) {
            User::updateOrCreate(
                ['email' => $user['email']],
                [...$user, 'password' => 'password', 'is_active' => true]
            );
        }
    }
}
