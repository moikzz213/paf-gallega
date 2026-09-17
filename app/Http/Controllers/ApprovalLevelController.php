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
            // The default is pre-filled onto every chain at this level, so it has to be someone who
            // may approve *at this level* — the same membership rule the chain builder enforces
            // (PaymentRequestController::assertApproverBelongsToLevel).
            'default_approver_id' => [
                'nullable', 'integer',
                $this->belongsToLevel((int) $request->input('level'), $level?->default_approver_id),
            ],
            'is_active' => ['boolean'],
        ]);
    }

    /**
     * A default approver must satisfy User::canApprove() and be assigned to this level.
     *
     * `$keepId` is the level's current default: a level saved before this rule (or before the user's
     * level changed) can still be renamed or deactivated without being forced to reassign it first.
     */
    private function belongsToLevel(int $levelNumber, ?int $keepId): callable
    {
        return function (string $attribute, mixed $value, callable $fail) use ($levelNumber, $keepId): void {
            if ($value === null || (int) $value === (int) $keepId) {
                return;
            }

            $user = User::find($value);

            if (! $user || ! $user->is_active || ! $user->canApprove()) {
                $fail('The default approver must be an active approver, admin, or a finance user with an approval level.');

                return;
            }

            if ((int) $user->approval_level !== $levelNumber) {
                $fail("{$user->name} is not assigned to level {$levelNumber}. Choose a user assigned to this level, or change their approval level first.");
            }
        };
    }
}
