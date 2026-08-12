<?php

namespace Tests\Feature;

use App\Models\ApprovalLevel;
use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DynamicApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, array $extra = []): User
    {
        return User::create([
            'name' => ucfirst($role).' '.uniqid(),
            'email' => $role.uniqid().'@t.local',
            'password' => 'password',
            'role' => $role,
            'is_active' => true,
            ...$extra,
        ]);
    }

    private function levels(): void
    {
        ApprovalLevel::create(['level' => 1, 'name' => 'Manager', 'min_amount' => 0, 'is_active' => true]);
        ApprovalLevel::create(['level' => 2, 'name' => 'Director', 'min_amount' => 10000, 'is_active' => true]);
    }

    private function postedInvoice(User $owner, float $total, string $currency = 'AED'): Invoice
    {
        return Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => 'Vendor', 'invoice_no' => 'INV-'.uniqid(), 'invoice_date' => now()->toDateString(),
            'currency' => $currency, 'amount' => $total, 'tax_amount' => 0, 'total_amount' => $total,
            'business_unit' => 'GIL', 'department' => 'Warehouse', 'location' => 'Head Office', 'payment_method' => 'bank_transfer',
            'priority' => 'normal', 'status' => Invoice::STATUS_POSTED, 'payment_status' => Invoice::PAY_NOT_INITIATED,
            'submitted_by' => $owner->id, 'submitted_at' => now(),
        ]);
    }

    public function test_finance_posts_a_submitted_invoice(): void
    {
        $finance = $this->user(User::ROLE_FINANCE);
        $requester = $this->user(User::ROLE_REQUESTER);
        $invoice = Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => 'V', 'invoice_no' => 'INV-1', 'invoice_date' => now()->toDateString(),
            'currency' => 'AED', 'amount' => 500, 'tax_amount' => 0, 'total_amount' => 500,
            'business_unit' => 'GIL', 'department' => 'Warehouse', 'location' => 'Head Office', 'payment_method' => 'bank_transfer',
            'priority' => 'normal', 'status' => Invoice::STATUS_SUBMITTED, 'payment_status' => Invoice::PAY_NOT_INITIATED,
            'submitted_by' => $requester->id, 'submitted_at' => now(),
        ]);

        $this->actingAs($finance)
            ->postJson("/api/invoices/{$invoice->id}/post", ['erp_doc_no' => '5100999'])
            ->assertOk();

        $this->assertSame(Invoice::STATUS_POSTED, $invoice->refresh()->status);
        $this->assertSame('5100999', $invoice->erp_doc_no);
    }

    public function test_create_payment_request_builds_chain_and_reserves_invoices(): void
    {
        $this->levels();
        $finance = $this->user(User::ROLE_FINANCE);
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $a2 = $this->user(User::ROLE_APPROVER, ['approval_level' => 2]);
        $inv = $this->postedInvoice($this->user(User::ROLE_REQUESTER), 20000);

        $res = $this->actingAs($finance)->postJson('/api/payment-requests', [
            'invoice_ids' => [$inv->id],
            'approvers' => [1 => $a1->id, 2 => $a2->id],
        ])->assertCreated();

        $pr = PaymentRequest::first();
        $this->assertSame(PaymentRequest::STATUS_IN_APPROVAL, $pr->status);
        $this->assertCount(2, $pr->approvals);
        $this->assertSame([$a1->id, $a2->id], $pr->approvals->pluck('approver_id')->all());
        $this->assertSame(Invoice::PAY_IN_APPROVAL, $inv->refresh()->payment_status);
        $this->assertSame($pr->id, $inv->payment_request_id);
    }

    public function test_only_assigned_approver_advances_the_chain(): void
    {
        $this->levels();
        $finance = $this->user(User::ROLE_FINANCE);
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $a2 = $this->user(User::ROLE_APPROVER, ['approval_level' => 2]);
        $inv = $this->postedInvoice($this->user(User::ROLE_REQUESTER), 20000);

        $this->actingAs($finance)->postJson('/api/payment-requests', [
            'invoice_ids' => [$inv->id],
            'approvers' => [1 => $a1->id, 2 => $a2->id],
        ])->assertCreated();
        $pr = PaymentRequest::first();

        // wrong approver (stage 2) can't act first
        $this->actingAs($a2)->postJson("/api/payment-requests/{$pr->id}/approve")->assertForbidden();

        $this->actingAs($a1)->postJson("/api/payment-requests/{$pr->id}/approve")->assertOk();
        $this->assertSame(2, $pr->refresh()->current_stage);

        $this->actingAs($a2)->postJson("/api/payment-requests/{$pr->id}/approve")->assertOk();
        $this->assertSame(PaymentRequest::STATUS_APPROVED, $pr->refresh()->status);
        $this->assertSame(Invoice::PAY_APPROVED, $inv->refresh()->payment_status);
    }

    public function test_reject_returns_invoices_to_the_pool(): void
    {
        $this->levels();
        $finance = $this->user(User::ROLE_FINANCE);
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $inv = $this->postedInvoice($this->user(User::ROLE_REQUESTER), 500); // only level 1

        $this->actingAs($finance)->postJson('/api/payment-requests', [
            'invoice_ids' => [$inv->id],
            'approvers' => [1 => $a1->id],
        ])->assertCreated();
        $pr = PaymentRequest::first();

        $this->actingAs($a1)->postJson("/api/payment-requests/{$pr->id}/reject", ['comments' => 'No PO'])->assertOk();

        $this->assertSame(PaymentRequest::STATUS_REJECTED, $pr->refresh()->status);
        $inv->refresh();
        $this->assertSame(Invoice::PAY_NOT_INITIATED, $inv->payment_status);
        $this->assertNull($inv->payment_request_id);
    }

    public function test_default_approver_prefills_when_not_specified(): void
    {
        $finance = $this->user(User::ROLE_FINANCE);
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        ApprovalLevel::create(['level' => 1, 'name' => 'Manager', 'min_amount' => 0, 'default_approver_id' => $a1->id, 'is_active' => true]);
        $inv = $this->postedInvoice($this->user(User::ROLE_REQUESTER), 500);

        $this->actingAs($finance)->postJson('/api/payment-requests', ['invoice_ids' => [$inv->id]])->assertCreated();

        $this->assertSame($a1->id, PaymentRequest::first()->approvals()->first()->approver_id);
    }

    public function test_approver_from_another_level_is_rejected(): void
    {
        $this->levels();
        $finance = $this->user(User::ROLE_FINANCE);
        $a2 = $this->user(User::ROLE_APPROVER, ['approval_level' => 2]);
        $inv = $this->postedInvoice($this->user(User::ROLE_REQUESTER), 20000);

        $this->actingAs($finance)->postJson('/api/payment-requests', [
            'invoice_ids' => [$inv->id],
            'approvers' => [1 => $a2->id, 2 => $a2->id], // L2 user cannot fill level 1
        ])->assertStatus(422)->assertJsonValidationErrors('approvers.1');

        $this->assertSame(0, PaymentRequest::count());
        $this->assertSame(Invoice::PAY_NOT_INITIATED, $inv->refresh()->payment_status);

        // the level's own default approver stays allowed even without a matching level
        $admin = $this->user(User::ROLE_ADMIN);
        ApprovalLevel::where('level', 1)->update(['default_approver_id' => $admin->id]);

        $this->actingAs($finance)->postJson('/api/payment-requests', [
            'invoice_ids' => [$inv->id],
            'approvers' => [1 => $admin->id, 2 => $a2->id],
        ])->assertCreated();

        $this->assertSame([$admin->id, $a2->id], PaymentRequest::first()->approvals->pluck('approver_id')->all());
    }

    public function test_payment_request_cannot_mix_currencies(): void
    {
        $this->levels();
        $finance = $this->user(User::ROLE_FINANCE);
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $requester = $this->user(User::ROLE_REQUESTER);
        $aed = $this->postedInvoice($requester, 500);
        $eur = $this->postedInvoice($requester, 400, 'EUR');

        $this->actingAs($finance)->postJson('/api/payment-requests', [
            'invoice_ids' => [$aed->id, $eur->id],
            'approvers' => [1 => $a1->id],
        ])->assertStatus(422)->assertJsonValidationErrors('invoices');

        $this->assertSame(0, PaymentRequest::count());
        $this->assertSame(Invoice::PAY_NOT_INITIATED, $eur->refresh()->payment_status);

        // one currency at a time is fine, and the request reports that currency
        $this->actingAs($finance)->postJson('/api/payment-requests', [
            'invoice_ids' => [$eur->id],
            'approvers' => [1 => $a1->id],
        ])->assertCreated();

        $this->assertSame('EUR', PaymentRequest::first()->currency);
    }

    public function test_paid_invoice_cannot_be_reused(): void
    {
        $this->levels();
        $finance = $this->user(User::ROLE_FINANCE);
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $inv = $this->postedInvoice($this->user(User::ROLE_REQUESTER), 500);
        $inv->update(['payment_status' => Invoice::PAY_IN_APPROVAL]);

        $this->actingAs($finance)->postJson('/api/payment-requests', [
            'invoice_ids' => [$inv->id],
            'approvers' => [1 => $a1->id],
        ])->assertStatus(422);
    }
}
