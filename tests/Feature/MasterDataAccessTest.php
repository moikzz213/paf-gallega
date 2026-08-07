<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MasterDataAccessTest extends TestCase
{
    use RefreshDatabase;

    /** Every entity MasterDataDefinition exposes. Note business-units is hyphenated in the URL. */
    private const ENTITIES = ['vendors', 'customers', 'business-units', 'departments', 'locations'];

    private function user(string $role): User
    {
        return User::create([
            'name' => ucfirst($role).' '.uniqid(),
            'email' => $role.uniqid().'@t.local',
            'password' => 'password',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    public static function entityProvider(): array
    {
        return array_map(fn ($entity) => [$entity], self::ENTITIES);
    }

    #[DataProvider('entityProvider')]
    public function test_finance_can_run_the_full_lifecycle_on_every_entity(string $entity): void
    {
        $finance = $this->user(User::ROLE_FINANCE);
        $this->actingAs($finance);

        $this->getJson("/api/master-data/{$entity}")->assertOk();

        $created = $this->postJson("/api/master-data/{$entity}", ['name' => 'Alpha Entry', 'is_active' => true])
            ->assertCreated()
            ->json();

        $this->putJson("/api/master-data/{$entity}/{$created['id']}", ['name' => 'Alpha Renamed', 'is_active' => true])
            ->assertOk();

        $this->getJson("/api/master-data/{$entity}/template")->assertOk();

        $this->deleteJson("/api/master-data/{$entity}/{$created['id']}")->assertSuccessful();
    }

    #[DataProvider('entityProvider')]
    public function test_admin_keeps_the_same_access(string $entity): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN));

        $this->getJson("/api/master-data/{$entity}")->assertOk();
        $this->postJson("/api/master-data/{$entity}", ['name' => 'Admin Entry', 'is_active' => true])->assertCreated();
    }

    /** Opening this up to finance must not open it to everyone. */
    public function test_requesters_and_approvers_remain_blocked(): void
    {
        foreach ([User::ROLE_REQUESTER, User::ROLE_APPROVER] as $role) {
            $user = $this->user($role);

            foreach (self::ENTITIES as $entity) {
                $this->actingAs($user)->getJson("/api/master-data/{$entity}")->assertForbidden();
                $this->actingAs($user)->postJson("/api/master-data/{$entity}", ['name' => 'Nope'])->assertForbidden();
                $this->actingAs($user)->putJson("/api/master-data/{$entity}/1", ['name' => 'Nope'])->assertForbidden();
                $this->actingAs($user)->deleteJson("/api/master-data/{$entity}/1")->assertForbidden();
                $this->actingAs($user)->postJson("/api/master-data/{$entity}/import")->assertForbidden();
            }
        }
    }

    public function test_guests_remain_blocked(): void
    {
        $this->getJson('/api/master-data/vendors')->assertUnauthorized();
        $this->postJson('/api/master-data/vendors', ['name' => 'Nope'])->assertUnauthorized();
    }

    /** Finance gains master data only — the admin-only areas stay closed to them. */
    public function test_finance_still_cannot_reach_admin_only_areas(): void
    {
        $this->actingAs($this->user(User::ROLE_FINANCE));

        $this->getJson('/api/users')->assertForbidden();
        $this->getJson('/api/audit-logs')->assertForbidden();
        $this->getJson('/api/approval-levels')->assertForbidden();
    }

    public function test_finance_reaches_the_import_endpoint_rather_than_being_refused(): void
    {
        $this->actingAs($this->user(User::ROLE_FINANCE));

        // No file attached, so validation rejects it — a 422 proves authorization passed,
        // which is what this test is about.
        $this->postJson('/api/master-data/vendors/import')
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }
}
