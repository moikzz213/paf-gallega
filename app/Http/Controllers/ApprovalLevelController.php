<?php

namespace App\Http\Controllers;

use App\Models\ApprovalLevel;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApprovalLevelController extends Controller
{
    public function index()
    {
        return ApprovalLevel::with('defaultApprover:id,name')->orderBy('level')->get();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $level = ApprovalLevel::create($data);

        AuditLogger::log('approval_level_created', "Approval level {$level->level} ({$level->name}) created, threshold {$level->min_amount}");

        return response()->json($level->load('defaultApprover:id,name'), 201);
    }

    public function update(Request $request, ApprovalLevel $approvalLevel)
    {
        $data = $this->validated($request, $approvalLevel);

        $old = $approvalLevel->only(['name', 'min_amount', 'default_approver_id', 'is_active']);
        $approvalLevel->update($data);

        AuditLogger::log('approval_level_updated', "Approval level {$approvalLevel->level} updated", null, $old, $approvalLevel->only(['name', 'min_amount', 'default_approver_id', 'is_active']));

        return response()->json($approvalLevel->load('defaultApprover:id,name'));
    }

    public function destroy(ApprovalLevel $approvalLevel)
    {
        AuditLogger::log('approval_level_deleted', "Approval level {$approvalLevel->level} ({$approvalLevel->name}) deleted");
        $approvalLevel->delete();

        return response()->json(['message' => 'Deleted']);
    }

    private function validated(Request $request, ?ApprovalLevel $level = null): array
    {
        return $request->validate([
            'level' => ['required', 'integer', 'min:1', 'max:10', Rule::unique('approval_levels')->ignore($level)],
            'name' => ['required', 'string', 'max:100'],
            'min_amount' => ['required', 'numeric', 'min:0'],
            'default_approver_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q->where('is_active', true)
                    ->whereIn('role', [User::ROLE_APPROVER, User::ROLE_ADMIN])),
            ],
            'is_active' => ['boolean'],
        ]);
    }
}
