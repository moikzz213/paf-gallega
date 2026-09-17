<?php

namespace Tests\Feature;

use App\Models\ApprovalLevel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A level's default approver is pre-filled onto every chain at that level, so it must be someone
 * assigned to that level — the same membership rule the chain builder enforces. Before this, the
 * admin form offered every approver, so L4 could be defaulted to an L1 user who would then be
 * rejected when a chain was built.
 */
class ApprovalLevelDefaultApproverTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->user(User::ROLE_ADMIN);
        ApprovalLevel::create(['level' => 1, 'name' => 'Department Manager', 'min_amount' => 0, 'is_active' => true]);
        ApprovalLevel::create(['level' => 4, 'name' => 'Finance Manager', 'min_amount' => 5000, 'is_active' => true]);
    }

    private function user(string $role, ?int $level = null): User
    {
        return User::create([
            'name' => ucfirst($role).' L'.($level ?? '-').' '.uniqid(),
            'email' => $role.uniqid().'@t.local',
            'password' => 'password',
            'role' => $role,
            'approval_level' => $level,
            'is_active' => true,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function payload(ApprovalLevel $level, array $overrides = []): array
    {
        return array_merge([
            'level' => $level->level,
            'name' => $level->name,
            'min_amount' => $level->min_amount,
            'is_active' => true,
        ], $overrides);
    }

    public function test_a_user_assigned_to_the_level_is_accepted(): void
    {
        $level4 = ApprovalLevel::where('level', 4)->first();
        $approver = $this->user(User::ROLE_APPROVER, 4);

        $this->actingAs($this->admin)
            ->putJson("/api/approval-levels/{$level4->id}", $this->payload($level4, ['default_approver_id' => $approver->id]))
            ->assertOk()
            ->assertJsonPath('default_approver_id', $approver->id);
    }

    public function test_a_user_from_another_level_is_rejected(): void
    {
        $level4 = ApprovalLevel::where('level', 4)->first();
        $l1 = $this->user(User::ROLE_APPROVER, 1);

        $this->actingAs($this->admin)
            ->putJson("/api/approval-levels/{$level4->id}", $this->payload($level4, ['default_approver_id' => $l1->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('default_approver_id');

        $this->assertNull($level4->refresh()->default_approver_id);
    }

    public function test_an_admin_without_an_approval_level_is_rejected(): void
    {
        $level4 = ApprovalLevel::where('level', 4)->first();

        $this->actingAs($this->admin)
            ->putJson("/api/approval-levels/{$level4->id}", $this->payload($level4, ['default_approver_id' => $this->admin->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('default_approver_id');
    }

    public function test_a_finance_user_assigned_to_the_level_is_accepted(): void
    {
        $level4 = ApprovalLevel::where('level', 4)->first();
        $finance = $this->user(User::ROLE_FINANCE, 4);

        $this->actingAs($this->admin)
            ->putJson("/api/approval-levels/{$level4->id}", $this->payload($level4, ['default_approver_id' => $finance->id]))
            ->assertOk();
    }

    public function test_a_requester_is_rejected_even_with_a_level_set(): void
    {
        $level4 = ApprovalLevel::where('level', 4)->first();
        $requester = $this->user(User::ROLE_REQUESTER, 4);

        $this->actingAs($this->admin)
            ->putJson("/api/approval-levels/{$level4->id}", $this->payload($level4, ['default_approver_id' => $requester->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('default_approver_id');
    }

    public function test_creating_a_level_applies_the_same_rule(): void
    {
        $l1 = $this->user(User::ROLE_APPROVER, 1);
        $l5 = $this->user(User::ROLE_APPROVER, 5);

        $this->actingAs($this->admin)->postJson('/api/approval-levels', [
            'level' => 5, 'name' => 'Head of Finance', 'min_amount' => 50000,
            'default_approver_id' => $l1->id, 'is_active' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('default_approver_id');

        $this->actingAs($this->admin)->postJson('/api/approval-levels', [
            'level' => 5, 'name' => 'Head of Finance', 'min_amount' => 50000,
            'default_approver_id' => $l5->id, 'is_active' => true,
        ])->assertCreated();
    }

    /** A level saved before this rule stays editable without being forced to reassign it first. */
    public function test_an_existing_mismatched_default_can_be_kept_while_editing_other_fields(): void
    {
        $level4 = ApprovalLevel::where('level', 4)->first();
        $l1 = $this->user(User::ROLE_APPROVER, 1);
        $level4->update(['default_approver_id' => $l1->id]); // legacy state, set past validation

        $this->actingAs($this->admin)
            ->putJson("/api/approval-levels/{$level4->id}", $this->payload($level4, [
                'name' => 'Finance Manager (renamed)',
                'default_approver_id' => $l1->id,
            ]))
            ->assertOk();

        $this->assertSame('Finance Manager (renamed)', $level4->refresh()->name);

        // Switching it to a different mismatched user is still refused.
        $otherL1 = $this->user(User::ROLE_APPROVER, 1);
        $this->actingAs($this->admin)
            ->putJson("/api/approval-levels/{$level4->id}", $this->payload($level4, ['default_approver_id' => $otherL1->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('default_approver_id');
    }

    public function test_the_default_can_still_be_cleared(): void
    {
        $level4 = ApprovalLevel::where('level', 4)->first();
        $level4->update(['default_approver_id' => $this->user(User::ROLE_APPROVER, 4)->id]);

        $this->actingAs($this->admin)
            ->putJson("/api/approval-levels/{$level4->id}", $this->payload($level4, ['default_approver_id' => null]))
            ->assertOk();

        $this->assertNull($level4->refresh()->default_approver_id);
    }
}
