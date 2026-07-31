<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::query();

        if ($q = trim((string) $request->input('q'))) {
            $query->where(fn ($sub) => $sub
                ->where('name', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%"));
        }

        if ($role = $request->input('role')) {
            $query->where('role', $role);
        }

        return $query->orderBy('name')->paginate((int) $request->input('per_page', 15));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $user = User::create($data);

        AuditLogger::log('user_created', "User {$user->name} ({$user->email}) created with role {$user->role}");

        return response()->json($user, 201);
    }

    public function update(Request $request, User $user)
    {
        $data = $this->validated($request, $user);

        if (empty($data['password'])) {
            unset($data['password']);
        }

        $old = $user->only(['role', 'approval_level', 'department', 'is_active']);
        $user->update($data);

        AuditLogger::log('user_updated', "User {$user->name} updated", null, $old, $user->only(['role', 'approval_level', 'department', 'is_active']));

        return response()->json($user);
    }

    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8'],
            'role' => ['required', Rule::in(User::ROLES)],
            'approval_level' => ['nullable', 'integer', 'min:1', 'max:10', 'required_if:role,approver'],
            'department' => ['nullable', 'string', Rule::exists('departments', 'name')->where('is_active', true)],
            'job_title' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);
    }
}
