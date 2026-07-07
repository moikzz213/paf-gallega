<?php

namespace App\Http\Controllers;

use App\Models\ApprovalLevel;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApprovalLevelController extends Controller
{
    public function index()
    {
        return ApprovalLevel::orderBy('level')->get();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $level = ApprovalLevel::create($data);

        AuditLogger::log('approval_level_created', "Approval level {$level->level} ({$level->name}) created, threshold {$level->min_amount}");

        return response()->json($level, 201);
    }

    public function update(Request $request, ApprovalLevel $approvalLevel)
    {
        $data = $this->validated($request, $approvalLevel);

        $old = $approvalLevel->only(['name', 'min_amount', 'is_active']);
        $approvalLevel->update($data);

        AuditLogger::log('approval_level_updated', "Approval level {$approvalLevel->level} updated", null, $old, $approvalLevel->only(['name', 'min_amount', 'is_active']));

        return response()->json($approvalLevel);
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
            'is_active' => ['boolean'],
        ]);
    }
}
