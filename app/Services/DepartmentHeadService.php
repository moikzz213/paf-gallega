<?php

namespace App\Services;

use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Nominating the Department Head(s) who receive a department's weekly Query Raised Report.
 *
 * Admin-only: the nomination decides who is sent the department's invoice details by email, which
 * is an access decision, so Finance — who otherwise maintain master data — cannot make it. Heads
 * must be active users, so the report can never be addressed to a mistyped or outside mailbox.
 *
 * CR: ai/change-requests/schedule-weekly-query-raised-report-email-to-department-heads.md
 */
class DepartmentHeadService
{
    /**
     * Replace a department's Heads, recording the change by name when it is one. Callers validate
     * that $userIds are active users (see rules()).
     *
     * @param  array<int, int>  $userIds
     */
    public function sync(Department $department, array $userIds): void
    {
        $before = $department->heads()->pluck('name')->all();

        $department->heads()->sync(array_values(array_unique(array_map('intval', $userIds))));
        $department->unsetRelation('heads');

        $after = $department->heads()->pluck('name')->all();

        if ($before === $after) {
            return;
        }

        AuditLogger::log(
            'department_heads_updated',
            "Department Heads of \"{$department->name}\" set to: ".($after ? implode(', ', $after) : 'none'),
            oldValues: ['heads' => $before],
            newValues: ['heads' => $after],
        );
    }

    /** Validation rules for a `head_ids` array of active users. */
    public static function rules(): array
    {
        return [
            'head_ids' => ['sometimes', 'nullable', 'array'],
            'head_ids.*' => [
                'integer', 'distinct',
                Rule::exists('users', 'id')->where('is_active', true),
            ],
        ];
    }

    /** Refuse a nomination from anyone but an admin. */
    public static function authorize(?User $user): void
    {
        if (! $user?->isAdmin()) {
            abort(403, 'Only an administrator can nominate Department Heads.');
        }
    }

    /**
     * Resolve a spreadsheet cell of comma- or semicolon-separated email addresses to active users.
     * Returns their ids, and the addresses that are not an active user's.
     *
     * @return array{ids: array<int, int>, unknown: array<int, string>}
     */
    public static function resolveEmails(?string $cell): array
    {
        $emails = self::splitEmails($cell);

        if ($emails->isEmpty()) {
            return ['ids' => [], 'unknown' => []];
        }

        $users = User::query()
            ->where('is_active', true)
            ->whereIn(DB::raw('lower(email)'), $emails->all())
            ->get(['id', 'email']);

        return [
            'ids' => $users->pluck('id')->all(),
            'unknown' => $emails->diff($users->map(fn (User $u) => mb_strtolower($u->email)))->values()->all(),
        ];
    }

    /** @return Collection<int, string> */
    public static function splitEmails(?string $cell): Collection
    {
        return collect(preg_split('/[,;\s]+/', (string) $cell))
            ->map(fn ($e) => mb_strtolower(trim($e)))
            ->filter()
            ->unique()
            ->values();
    }
}
