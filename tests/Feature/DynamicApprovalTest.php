<?php

namespace Tests\Feature;

use App\Models\ApprovalLevel;
use App\Models\Invoice;
use App\Models\User;
use App\Services\ApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DynamicApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function requester(): User
    {
        return User::create([
            'name' => 'Req', 'email' => 'req@t.local', 'password' => 'password',
            'role' => User::ROLE_REQUESTER, 'is_active' => true,
        ]);
    }

    private function approver(int $level, string $email): User
    {
        return User::create([
            'name' => "App{$level}", 'email' => $email, 'password' => 'password',
            'role' => User::ROLE_APPROVER, 'approval_level' => $level, 'is_active' => true,
        ]);
    }

    private function levels(): void
    {
        ApprovalLevel::create(['level' => 1, 'name' => 'Manager', 'min_amount' => 0, 'is_active' => true]);
        ApprovalLevel::create(['level' => 2, 'name' => 'Director', 'min_amount' => 10000, 'is_active' => true]);
    }

    private function draft(User $owner, float $total): Invoice
    {
        return Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => 'Vendor', 'invoice_no' => 'INV-1', 'invoice_date' => now()->toDateString(),
            'currency' => 'AED', 'amount' => $total, 'tax_amount' => 0, 'total_amount' => $total,
            'category' => 'Services', 'department' => 'Finance', 'payment_method' => 'bank_transfer',
            'priority' => 'normal', 'status' => Invoice::STATUS_DRAFT, 'submitted_by' => $owner->id,
        ]);
    }

    public function test_submit_assigns_approvers_and_appends_adhoc_stages(): void
    {
        $this->levels();
        $req = $this->requester();
        $a1 = $this->approver(1, 'a1@t.local');
        $a2 = $this->approver(2, 'a2@t.local');
        $extra = $this->approver(1, 'legal@t.local');

        $invoice = $this->draft($req, 20000);

        app(ApprovalService::class)->submit(
            $invoice,
            [1 => $a1->id, 2 => $a2->id],
            [['approver_id' => $extra->id, 'label' => 'Legal review']],
        );

        $stages = $invoice->approvals()->orderBy('level')->get();

        $this->assertCount(3, $stages);
        $this->assertSame([$a1->id, $a2->id, $extra->id], $stages->pluck('approver_id')->all());
        $this->assertTrue($stages->last()->is_adhoc);
        $this->assertSame('Legal review', $stages->last()->level_name);
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->refresh()->status);
        $this->assertSame(1, $invoice->current_level);
    }

    public function test_only_assigned_approver_can_act_and_chain_advances(): void
    {
        $this->levels();
        $req = $this->requester();
        $a1 = $this->approver(1, 'a1@t.local');
        $a2 = $this->approver(2, 'a2@t.local');
        $invoice = $this->draft($req, 20000);

        app(ApprovalService::class)->submit($invoice, [1 => $a1->id, 2 => $a2->id]);

        // Wrong approver (assigned to level 2) cannot act on level 1.
        $this->actingAs($a2)->postJson("/api/invoices/{$invoice->id}/approve")->assertForbidden();

        // Assigned approver advances the chain.
        $this->actingAs($a1)->postJson("/api/invoices/{$invoice->id}/approve")->assertOk();
        $this->assertSame(2, $invoice->refresh()->current_level);

        // Final approver completes it.
        $this->actingAs($a2)->postJson("/api/invoices/{$invoice->id}/approve")->assertOk();
        $this->assertSame(Invoice::STATUS_APPROVED, $invoice->refresh()->status);
    }

    public function test_submit_falls_back_to_level_default_approver(): void
    {
        $req = $this->requester();
        $a1 = $this->approver(1, 'a1@t.local');
        ApprovalLevel::create(['level' => 1, 'name' => 'Manager', 'min_amount' => 0, 'default_approver_id' => $a1->id, 'is_active' => true]);

        $invoice = $this->draft($req, 500);

        // No explicit assignment — should use the level's default approver.
        app(ApprovalService::class)->submit($invoice);

        $this->assertSame($a1->id, $invoice->approvals()->where('level', 1)->value('approver_id'));
    }

    public function test_submit_endpoint_rejects_non_approver_as_assignee(): void
    {
        $this->levels();
        $req = $this->requester();
        $plainUser = User::create([
            'name' => 'Plain', 'email' => 'plain@t.local', 'password' => 'password',
            'role' => User::ROLE_REQUESTER, 'is_active' => true,
        ]);
        $invoice = $this->draft($req, 500);

        $this->actingAs($req)
            ->postJson("/api/invoices/{$invoice->id}/submit", ['approvers' => [1 => $plainUser->id]])
            ->assertStatus(422);
    }
}
