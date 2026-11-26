<?php

namespace Tests\Feature;

use App\Mail\PaymentRequestSubmitted;
use App\Models\ApprovalLevel;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestApproval;
use App\Models\User;
use App\Services\PaymentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * An approver on leave is skipped — recorded, with a required reason — and the request goes to the
 * next approver. Either when Finance builds the chain, or on a request already waiting on them.
 *
 * CR: ai/change-requests/route-payment-approvals-past-an-approver-on-leave.md
 */
class ApproverOnLeaveTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $admin;

    private User $l1;

    private User $l2;

    private User $l3;

    protected function setUp(): void
    {
        parent::setUp();

        ApprovalLevel::create(['level' => 1, 'name' => 'Manager', 'min_amount' => 0, 'is_active' => true]);
        ApprovalLevel::create(['level' => 2, 'name' => 'Head of Finance', 'min_amount' => 10000, 'is_active' => true]);
        ApprovalLevel::create(['level' => 3, 'name' => 'MD', 'min_amount' => 250000, 'is_active' => true]);

        $this->finance = $this->user(User::ROLE_FINANCE);
        $this->admin = $this->user(User::ROLE_ADMIN);
        $this->l1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $this->l2 = $this->user(User::ROLE_APPROVER, ['approval_level' => 2]);
        $this->l3 = $this->user(User::ROLE_APPROVER, ['approval_level' => 3]);
    }

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

    private function invoice(float $amount = 300000): Invoice
    {
        return Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => 'Acme Trading', 'invoice_no' => 'SUP-'.uniqid(), 'invoice_date' => now()->toDateString(),
            'currency' => 'AED', 'amount' => $amount, 'tax_amount' => 0, 'total_amount' => $amount,
            'business_unit' => 'GIL', 'department' => 'Warehouse', 'location' => 'Head Office',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => Invoice::STATUS_POSTED, 'payment_status' => Invoice::PAY_NOT_INITIATED,
            'submitted_by' => $this->user(User::ROLE_REQUESTER)->id, 'submitted_at' => now(),
        ]);
    }

    private function createRequest(array $onLeave = [])
    {
        return $this->actingAs($this->finance)->postJson('/api/payment-requests', [
            'invoice_ids' => [$this->invoice()->id],
            'approvers' => [1 => $this->l1->id, 2 => $this->l2->id, 3 => $this->l3->id],
            'on_leave' => $onLeave,
        ]);
    }

    // ── Marked on leave when the chain is built ────────────────────────────────────────────────

    public function test_a_level_marked_on_leave_is_skipped_and_the_request_starts_with_the_next_approver(): void
    {
        Mail::fake();

        $this->createRequest([1 => 'Annual leave until 10 Dec'])->assertCreated();

        $pr = PaymentRequest::first();
        $stage1 = $pr->approvals()->where('sequence', 1)->first();

        $this->assertSame(PaymentRequestApproval::STATUS_SKIPPED, $stage1->status);
        $this->assertSame($this->l1->id, $stage1->approver_id, 'the absent approver is recorded');
        $this->assertSame('Annual leave until 10 Dec', $stage1->skip_reason);
        $this->assertSame($this->finance->id, $stage1->skipped_by);
        $this->assertNotNull($stage1->acted_at);

        $this->assertSame(2, $pr->current_stage);
        $this->assertSame($this->l2->id, $pr->currentApproval()->approver_id);

        Mail::assertQueued(PaymentRequestSubmitted::class, fn ($m) => $m->hasTo($this->l2->email));
        Mail::assertNotQueued(PaymentRequestSubmitted::class, fn ($m) => $m->hasTo($this->l1->email));

        $this->assertTrue(AuditLog::where('action', 'approver_on_leave')->where('payment_request_id', $pr->id)->exists());
    }

    public function test_the_approval_then_completes_without_the_skipped_level(): void
    {
        Mail::fake();
        $this->createRequest([2 => 'Sick leave'])->assertCreated();
        $pr = PaymentRequest::first();
        $service = app(PaymentRequestService::class);

        $service->approve($pr, $this->l1);
        $this->assertSame(3, $pr->refresh()->current_stage, 'L2 on leave — straight from L1 to L3');

        $service->approve($pr, $this->l3);
        $this->assertSame(PaymentRequest::STATUS_APPROVED, $pr->refresh()->status);
        $this->assertSame(PaymentRequestApproval::STATUS_SKIPPED, $pr->approvals()->where('sequence', 2)->value('status'));
    }

    public function test_a_reason_is_required_for_a_level_marked_on_leave(): void
    {
        $this->createRequest([1 => ''])->assertStatus(422)->assertJsonValidationErrors('on_leave.1');
        $this->assertSame(0, PaymentRequest::count());
    }

    public function test_every_approver_on_leave_is_refused(): void
    {
        $this->createRequest([1 => 'Away', 2 => 'Away', 3 => 'Away'])
            ->assertStatus(422)->assertJsonValidationErrors('on_leave');

        $this->assertSame(0, PaymentRequest::count());
        $this->assertSame(Invoice::PAY_NOT_INITIATED, Invoice::first()->payment_status);
    }

    // ── Marked on leave on a request already waiting ───────────────────────────────────────────

    public function test_finance_marks_the_current_approver_on_leave_and_the_next_approver_is_emailed(): void
    {
        $this->createRequest()->assertCreated();
        $pr = PaymentRequest::first();
        Mail::fake();

        $this->actingAs($this->finance)
            ->postJson("/api/payment-requests/{$pr->id}/on-leave", ['reason' => 'On leave this week'])
            ->assertOk();

        $pr->refresh();
        $this->assertSame(2, $pr->current_stage);
        $this->assertSame(PaymentRequest::STATUS_IN_APPROVAL, $pr->status);

        $stage1 = $pr->approvals()->where('sequence', 1)->first();
        $this->assertSame(PaymentRequestApproval::STATUS_SKIPPED, $stage1->status);
        $this->assertSame('On leave this week', $stage1->skip_reason);
        $this->assertSame($this->finance->id, $stage1->skipped_by);

        Mail::assertQueued(PaymentRequestSubmitted::class, fn ($m) => $m->hasTo($this->l2->email));
        $this->assertTrue(AuditLog::where('action', 'approver_on_leave')->where('payment_request_id', $pr->id)->exists());
    }

    public function test_an_admin_may_mark_on_leave_too(): void
    {
        Mail::fake();
        $this->createRequest()->assertCreated();
        $pr = PaymentRequest::first();

        $this->actingAs($this->admin)
            ->postJson("/api/payment-requests/{$pr->id}/on-leave", ['reason' => 'Travelling'])
            ->assertOk();

        $this->assertSame(2, $pr->refresh()->current_stage);
    }

    public function test_approvers_and_requesters_cannot_mark_on_leave(): void
    {
        Mail::fake();
        $this->createRequest()->assertCreated();
        $pr = PaymentRequest::first();

        foreach ([$this->l1, $this->l2, $this->user(User::ROLE_REQUESTER)] as $user) {
            $this->actingAs($user)
                ->postJson("/api/payment-requests/{$pr->id}/on-leave", ['reason' => 'Away'])
                ->assertForbidden();
        }

        $this->assertSame(1, $pr->refresh()->current_stage);
    }

    public function test_a_reason_is_required_on_a_waiting_request(): void
    {
        Mail::fake();
        $this->createRequest()->assertCreated();
        $pr = PaymentRequest::first();

        $this->actingAs($this->finance)
            ->postJson("/api/payment-requests/{$pr->id}/on-leave", ['reason' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->assertSame(1, $pr->refresh()->current_stage);
    }

    public function test_the_last_approver_cannot_be_skipped(): void
    {
        Mail::fake();
        $this->createRequest()->assertCreated();
        $pr = PaymentRequest::first();
        $service = app(PaymentRequestService::class);
        $service->approve($pr, $this->l1);
        $service->approve($pr->refresh(), $this->l2);
        $this->assertSame(3, $pr->refresh()->current_stage);

        $this->actingAs($this->finance)
            ->postJson("/api/payment-requests/{$pr->id}/on-leave", ['reason' => 'Away'])
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->assertSame(PaymentRequestApproval::STATUS_PENDING, $pr->approvals()->where('sequence', 3)->value('status'));
        $this->assertSame(PaymentRequest::STATUS_IN_APPROVAL, $pr->refresh()->status);
    }

    public function test_a_request_on_hold_for_a_query_cannot_be_skipped(): void
    {
        Mail::fake();
        $this->createRequest()->assertCreated();
        $pr = PaymentRequest::first();
        app(PaymentRequestService::class)->query($pr, $this->l1, 'Where is the PO?');

        $this->actingAs($this->finance)
            ->postJson("/api/payment-requests/{$pr->id}/on-leave", ['reason' => 'Away'])
            ->assertStatus(422)->assertJsonValidationErrors('status');

        $this->assertSame(1, $pr->refresh()->current_stage);
    }

    public function test_the_skipped_approvers_link_becomes_view_only(): void
    {
        Mail::fake();
        $this->createRequest()->assertCreated();
        $pr = PaymentRequest::first();
        $token = $pr->approvals()->where('sequence', 1)->value('view_token');

        $this->actingAs($this->finance)
            ->postJson("/api/payment-requests/{$pr->id}/on-leave", ['reason' => 'Away'])
            ->assertOk();

        auth()->logout();
        $this->post("/prf/view/{$pr->id}/{$token}/approve");

        $this->assertSame(2, $pr->refresh()->current_stage, 'the absent approver can no longer approve');
        $this->assertSame(PaymentRequestApproval::STATUS_SKIPPED, $pr->approvals()->where('sequence', 1)->value('status'));
    }

    public function test_the_paf_records_the_skip_and_never_as_a_signature(): void
    {
        Mail::fake();
        $this->createRequest([1 => 'Annual leave until 10 Dec'])->assertCreated();
        $pr = PaymentRequest::first();

        $html = view('pdf.payment-request', [
            'paymentRequest' => $pr->load('invoices.items', 'approvals.approver', 'approvals.skipper', 'approvals.approvalLevel', 'creator'),
            'supplierCodes' => collect(),
            'approvalLevels' => ApprovalLevel::where('is_active', true)->with('defaultApprover')->get(),
        ])->render();

        $this->assertStringContainsString('APPROVER ON LEAVE', $html);
        $this->assertStringContainsString('Annual leave until 10 Dec', $html);
        $this->assertStringContainsString('On Leave — Not Signed', $html);
    }

    public function test_the_emailed_approval_page_shows_the_skip(): void
    {
        Mail::fake();
        $this->createRequest([1 => 'Annual leave until 10 Dec'])->assertCreated();
        $pr = PaymentRequest::first();
        $token = $pr->approvals()->where('sequence', 2)->value('view_token');

        auth()->logout();
        $this->get("/prf/view/{$pr->id}/{$token}")
            ->assertOk()
            ->assertSee('Skipped — on leave')
            ->assertSee('Annual leave until 10 Dec');
    }
}
