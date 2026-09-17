<?php

namespace Tests\Feature;

use App\Models\ApprovalLevel;
use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestApproval;
use App\Models\User;
use App\Services\PaymentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Finance often raises the payment request itself, so the people who sign those off are Finance
 * group members rather than the requester's own manager. A Finance user becomes selectable as an
 * approver when an admin gives them an approval level — and nobody, whatever their role, approves a
 * request they raised themselves.
 */
class FinanceApproverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        ApprovalLevel::create(['level' => 1, 'name' => 'Group L1', 'min_amount' => 0, 'is_active' => true]);
        ApprovalLevel::create(['level' => 2, 'name' => 'Group L2', 'min_amount' => 0, 'is_active' => true]);
    }

    private function user(string $role, ?int $level = null): User
    {
        return User::create([
            'name' => ucfirst($role).' '.uniqid(),
            'email' => $role.uniqid().'@t.local',
            'password' => 'password',
            'role' => $role,
            'approval_level' => $level,
            'is_active' => true,
        ]);
    }

    private function postedInvoice(User $owner, float $total = 5000): Invoice
    {
        $invoice = Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => 'Vendor', 'invoice_no' => 'INV-'.uniqid(),
            'invoice_date' => now()->toDateString(),
            'currency' => 'AED', 'amount' => $total, 'tax_amount' => 0, 'total_amount' => $total,
            'business_unit' => 'GIL', 'department' => 'Warehouse', 'location' => 'Head Office',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => Invoice::STATUS_POSTED, 'payment_status' => Invoice::PAY_NOT_INITIATED,
            'submitted_by' => $owner->id, 'submitted_at' => now(),
        ]);

        $invoice->items()->create([
            'sort_order' => 0, 'currency' => 'AED', 'amount' => $total,
            'tax_amount' => 0, 'total_amount' => $total,
        ]);

        return $invoice;
    }

    public function test_can_approve_covers_approvers_admins_and_finance_with_a_level(): void
    {
        $this->assertTrue($this->user(User::ROLE_APPROVER, 1)->canApprove());
        $this->assertTrue($this->user(User::ROLE_ADMIN)->canApprove());
        $this->assertTrue($this->user(User::ROLE_FINANCE, 1)->canApprove());

        $this->assertFalse($this->user(User::ROLE_FINANCE)->canApprove());
        $this->assertFalse($this->user(User::ROLE_REQUESTER)->canApprove());
    }

    public function test_the_approvers_list_includes_finance_with_a_level_and_excludes_finance_without(): void
    {
        $nominated = $this->user(User::ROLE_FINANCE, 1);
        $plainFinance = $this->user(User::ROLE_FINANCE);
        $inactive = $this->user(User::ROLE_FINANCE, 2);
        $inactive->update(['is_active' => false]);

        $ids = collect($this->actingAs($this->user(User::ROLE_ADMIN))->getJson('/api/approvers')->assertOk()->json())
            ->pluck('id');

        $this->assertTrue($ids->contains($nominated->id));
        $this->assertFalse($ids->contains($plainFinance->id));
        $this->assertFalse($ids->contains($inactive->id));
    }

    public function test_a_finance_user_with_a_level_can_be_assigned_to_a_chain_and_approve_it(): void
    {
        $creator = $this->user(User::ROLE_FINANCE);
        $l1 = $this->user(User::ROLE_FINANCE, 1);
        $l2 = $this->user(User::ROLE_FINANCE, 2);
        $invoice = $this->postedInvoice($this->user(User::ROLE_REQUESTER));

        $pr = $this->actingAs($creator)->postJson('/api/payment-requests', [
            'invoice_ids' => [$invoice->id],
            'approvers' => [1 => $l1->id, 2 => $l2->id],
        ])->assertCreated()->json();

        // Both Finance approvers see it in their own queue, and neither sees the other's stage.
        $this->assertSame([$pr['id']], collect($this->actingAs($l1)->getJson('/api/payment-requests/pending')->assertOk()->json('data'))->pluck('id')->all());
        $this->assertSame([], $this->actingAs($l2)->getJson('/api/payment-requests/pending')->assertOk()->json('data'));

        $this->actingAs($l1)->postJson("/api/payment-requests/{$pr['id']}/approve", [])->assertOk();
        $this->actingAs($l2)->postJson("/api/payment-requests/{$pr['id']}/approve", [])->assertOk();

        $this->assertSame(PaymentRequest::STATUS_APPROVED, PaymentRequest::find($pr['id'])->status);
        $this->assertSame(Invoice::PAY_APPROVED, $invoice->refresh()->payment_status);
    }

    public function test_a_finance_user_without_a_level_is_rejected_by_chain_validation(): void
    {
        $creator = $this->user(User::ROLE_FINANCE);
        $invoice = $this->postedInvoice($this->user(User::ROLE_REQUESTER));

        $this->actingAs($creator)->postJson('/api/payment-requests', [
            'invoice_ids' => [$invoice->id],
            'approvers' => [1 => $this->user(User::ROLE_FINANCE)->id, 2 => $this->user(User::ROLE_APPROVER, 2)->id],
        ])->assertStatus(422)->assertJsonValidationErrors('approvers.1');
    }

    public function test_a_finance_user_without_a_level_has_no_approval_queue(): void
    {
        $this->actingAs($this->user(User::ROLE_FINANCE))
            ->getJson('/api/payment-requests/pending')
            ->assertForbidden();
    }

    public function test_a_finance_user_with_a_level_can_be_a_level_default_approver(): void
    {
        $finance = $this->user(User::ROLE_FINANCE, 1);

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->putJson('/api/approval-levels/'.ApprovalLevel::where('level', 1)->first()->id, [
                'level' => 1, 'name' => 'Group L1', 'min_amount' => 0,
                'default_approver_id' => $finance->id, 'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('default_approver_id', $finance->id);
    }

    public function test_a_finance_user_without_a_level_cannot_be_a_level_default_approver(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->putJson('/api/approval-levels/'.ApprovalLevel::where('level', 1)->first()->id, [
                'level' => 1, 'name' => 'Group L1', 'min_amount' => 0,
                'default_approver_id' => $this->user(User::ROLE_FINANCE)->id, 'is_active' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('default_approver_id');
    }

    public function test_the_creator_cannot_be_put_on_the_chain_of_their_own_request(): void
    {
        $creator = $this->user(User::ROLE_FINANCE, 1);
        $invoice = $this->postedInvoice($this->user(User::ROLE_REQUESTER));

        $this->actingAs($creator)->postJson('/api/payment-requests', [
            'invoice_ids' => [$invoice->id],
            'approvers' => [1 => $creator->id, 2 => $this->user(User::ROLE_APPROVER, 2)->id],
        ])->assertStatus(422)->assertJsonValidationErrors('approvers');

        $this->assertSame(0, PaymentRequest::count());
        $this->assertSame(Invoice::PAY_NOT_INITIATED, $invoice->refresh()->payment_status);
    }

    public function test_the_creator_cannot_approve_their_own_request_even_on_an_existing_chain(): void
    {
        $creator = $this->user(User::ROLE_FINANCE, 1);
        $invoice = $this->postedInvoice($this->user(User::ROLE_REQUESTER));

        // Built past the service so it mimics a chain created before the rule existed.
        $pr = PaymentRequest::create([
            'reference_no' => PaymentRequest::nextReferenceNo(),
            'created_by' => $creator->id,
            'status' => PaymentRequest::STATUS_IN_APPROVAL,
            'current_stage' => 1,
            'total_amount' => 5000,
            'sent_at' => now(),
        ]);
        PaymentRequestApproval::create([
            'payment_request_id' => $pr->id, 'sequence' => 1, 'level' => 1, 'label' => 'Group L1',
            'approver_id' => $creator->id, 'status' => PaymentRequestApproval::STATUS_PENDING,
        ]);
        $invoice->update(['payment_request_id' => $pr->id, 'payment_status' => Invoice::PAY_IN_APPROVAL]);

        $this->actingAs($creator)->postJson("/api/payment-requests/{$pr->id}/approve", [])->assertForbidden();
        $this->actingAs($creator)->postJson("/api/payment-requests/{$pr->id}/reject", ['comments' => 'mine'])->assertForbidden();

        $this->assertSame(PaymentRequest::STATUS_IN_APPROVAL, $pr->refresh()->status);
    }

    public function test_an_admin_cannot_approve_a_request_they_created_either(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $approver = $this->user(User::ROLE_APPROVER, 1);
        $invoice = $this->postedInvoice($this->user(User::ROLE_REQUESTER));

        $pr = app(PaymentRequestService::class)->create([$invoice->id], $admin, [
            ['approver_id' => $approver->id, 'label' => 'Group L1', 'level' => 1, 'is_adhoc' => false],
        ]);

        // The admin override still applies to everyone else's requests, just not their own.
        $this->actingAs($admin)->postJson("/api/payment-requests/{$pr->id}/approve", [])->assertForbidden();
        $this->actingAs($approver)->postJson("/api/payment-requests/{$pr->id}/approve", [])->assertOk();
    }

    public function test_the_public_link_also_refuses_a_self_approval(): void
    {
        $creator = $this->user(User::ROLE_FINANCE, 1);
        $invoice = $this->postedInvoice($this->user(User::ROLE_REQUESTER));

        $pr = PaymentRequest::create([
            'reference_no' => PaymentRequest::nextReferenceNo(),
            'created_by' => $creator->id,
            'status' => PaymentRequest::STATUS_IN_APPROVAL,
            'current_stage' => 1,
            'total_amount' => 5000,
            'sent_at' => now(),
        ]);
        $approval = PaymentRequestApproval::create([
            'payment_request_id' => $pr->id, 'sequence' => 1, 'level' => 1, 'label' => 'Group L1',
            'approver_id' => $creator->id, 'status' => PaymentRequestApproval::STATUS_PENDING,
        ]);
        $invoice->update(['payment_request_id' => $pr->id, 'payment_status' => Invoice::PAY_IN_APPROVAL]);

        $this->post("/prf/view/{$pr->id}/{$approval->view_token}/approve")
            ->assertRedirect(route('payment-request.public', ['id' => $pr->id, 'token' => $approval->view_token]));

        $this->assertSame(PaymentRequestApproval::STATUS_PENDING, $approval->refresh()->status);
        $this->assertSame(PaymentRequest::STATUS_IN_APPROVAL, $pr->refresh()->status);
    }

    public function test_an_admin_can_still_set_a_level_on_a_finance_user(): void
    {
        $finance = $this->user(User::ROLE_FINANCE);

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->putJson("/api/users/{$finance->id}", [
                'name' => $finance->name, 'email' => $finance->email,
                'role' => User::ROLE_FINANCE, 'approval_level' => 2, 'is_active' => true,
            ])
            ->assertOk();

        $this->assertSame(2, $finance->refresh()->approval_level);
        $this->assertTrue($finance->canApprove());
    }

    public function test_the_service_rejects_a_self_approval_stage_directly(): void
    {
        $creator = $this->user(User::ROLE_FINANCE, 1);
        $invoice = $this->postedInvoice($this->user(User::ROLE_REQUESTER));

        $this->expectException(ValidationException::class);

        app(PaymentRequestService::class)->create([$invoice->id], $creator, [
            ['approver_id' => $creator->id, 'label' => 'Group L1', 'level' => 1, 'is_adhoc' => false],
        ]);
    }
}
