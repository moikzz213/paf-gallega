<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::query()->with('viewableColleagues:id,name');

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

        $user = DB::transaction(function () use ($data) {
            $user = User::create(Arr::except($data, 'viewable_colleague_ids'));
            $this->syncViewAccess($user, $data['viewable_colleague_ids'] ?? []);

            return $user;
        });

        AuditLogger::log('user_created', "User {$user->name} ({$user->email}) created with role {$user->role}", null, null, $this->auditValues($user));

        return response()->json($user->load('viewableColleagues:id,name'), 201);
    }

    public function update(Request $request, User $user)
    {
        $data = $this->validated($request, $user);

        if (empty($data['password'])) {
            unset($data['password']);
        }

        $old = $this->auditValues($user);

        DB::transaction(function () use ($user, $data) {
            $user->update(Arr::except($data, 'viewable_colleague_ids'));

            // Only when sent, so a client that predates the field cannot wipe someone's grants.
            if (array_key_exists('viewable_colleague_ids', $data)) {
                $this->syncViewAccess($user, $data['viewable_colleague_ids'] ?? []);
            }
        });

        AuditLogger::log('user_updated', "User {$user->name} updated", null, $old, $this->auditValues($user));

        return response()->json($user->load('viewableColleagues:id,name'));
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
            // Read-only access to these colleagues' invoices and payment requests. Inactive users stay
            // selectable: their past records are what a manager may still need to look up.
            'viewable_colleague_ids' => ['sometimes', 'nullable', 'array'],
            'viewable_colleague_ids.*' => [
                'integer', 'distinct', Rule::exists('users', 'id'),
                Rule::notIn(array_filter([$user?->id])),
            ],
        ]);
    }

    /**
     * Replace the colleagues whose PAF records this user may view.
     *
     * CR: ai/change-requests/grant-view-only-access-to-paf-records-of-nominated-colleagues.md
     */
    private function syncViewAccess(User $user, array $colleagueIds): void
    {
        $user->viewableColleagues()->sync($colleagueIds);
        $user->unsetRelation('viewableColleagues');
    }

    /**
     * What the audit trail records about a user. The view grants are recorded by name, because the
     * question an access review asks is "who could see whose records, and since when?".
     */
    private function auditValues(User $user): array
    {
        return [
            ...$user->only(['role', 'approval_level', 'department', 'is_active']),
            'can_view_records_of' => $user->viewableColleagues()->orderBy('name')->pluck('name')->all(),
        ];
    }
}
