<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BusinessUnit;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestApproval;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Reproduces the production dead end behind INV-2026-00054: a query was raised on an invoice that
 * was already inside a fully approved payment request, leaving it uneditable, uncancellable and
 * unrejectable. Covers the guard that prevents it and the withdrawal that recovers from it.
 */
class PaymentRequestWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        BusinessUnit::create(['name' => 'GGL', 'is_active' => true]);
        Department::create(['name' => 'Freight Forwarding', 'is_active' => true]);
        Location::create(['name' => 'Dubai-Jafza', 'is_active' => true]);
        Vendor::create(['name' => 'Atlas Maritime Shipping', 'vendor_code' => 'VEN2071', 'is_active' => true]);
    }

    private function user(string $role): User
    {
        return User::create([
            'name' => ucfirst($role).' '.uniqid(),
            'email' => $role.uniqid().'@t.local',
            'password' => 'password',
            'role' => $role,
            'is_active' => true,
            'approval_level' => $role === User::ROLE_APPROVER ? 1 : null,
        ]);
    }

    /** An invoice posted to the ERP and sitting inside a fully approved payment request. */
    private function approvedInvoice(User $requester, User $approver): Invoice
    {
        $invoice = Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => 'Atlas Maritime Shipping', 'invoice_no' => 'ATL-INV-0024514871',
            'invoice_date' => now()->subDays(20)->toDateString(),
            'currency' => 'AED', 'amount' => 4900, 'tax_amount' => 0, 'total_amount' => 4900,
            'business_unit' => 'GGL', 'department' => 'Freight Forwarding', 'location' => 'Dubai-Jafza',
            'payment_method' => 'bank_transfer', 'priority' => 'urgent',
            'status' => Invoice::STATUS_POSTED, 'payment_status' => Invoice::PAY_APPROVED,
            'erp_doc_no' => 'PINGGF00012347', 'posting_date' => now()->subDays(4)->toDateString(),
            'submitted_by' => $requester->id, 'submitted_at' => now()->subDays(10),
        ]);

        $invoice->items()->create([
            'sort_order' => 0, 'job_no' => 'SSE00005216', 'currency' => 'AED',
            'amount' => 4900, 'tax_amount' => 0, 'total_amount' => 4900,
        ]);

        $pr = PaymentRequest::create([
            'reference_no' => PaymentRequest::nextReferenceNo(),
            'created_by' => $requester->id,
            'status' => PaymentRequest::STATUS_APPROVED,
            'current_stage' => null,
            'total_amount' => 4900,
            'sent_at' => now()->subDays(3),
            'approved_at' => now()->subDay(),
        ]);

        PaymentRequestApproval::create([
            'payment_request_id' => $pr->id, 'sequence' => 1, 'level' => 1, 'label' => 'Finance Manager',
            'approver_id' => $approver->id, 'status' => PaymentRequestApproval::STATUS_APPROVED, 'acted_at' => now()->subDay(),
        ]);

        $invoice->update(['payment_request_id' => $pr->id]);

        return $invoice->refresh();
    }

    public function test_a_query_cannot_be_raised_on_an_invoice_already_in_a_payment_request(): void
    {
        $invoice = $this->approvedInvoice($this->user(User::ROLE_REQUESTER), $this->user(User::ROLE_APPROVER));

        $this->actingAs($this->user(User::ROLE_FINANCE))
            ->postJson("/api/invoices/{$invoice->id}/query", ['finance_remarks' => 'currency need to change from AED to USD'])
            ->assertStatus(422);

        $this->assertSame(Invoice::STATUS_POSTED, $invoice->refresh()->status);
    }

    public function test_a_posted_invoice_that_is_queried_cannot_be_posted_again(): void
    {
        $requester = $this->user(User::ROLE_REQUESTER);
        $invoice = $this->approvedInvoice($requester, $this->user(User::ROLE_APPROVER));
        // The state the incident left behind: queried while already posted and already approved.
        $invoice->update(['status' => Invoice::STATUS_QUERY, 'finance_remarks' => 'wrong currency']);

        $this->actingAs($this->user(User::ROLE_FINANCE))
            ->postJson("/api/invoices/{$invoice->id}/post", ['erp_doc_no' => 'PINGGF00099999'])
            ->assertStatus(422);

        $this->assertSame('PINGGF00012347', $invoice->refresh()->erp_doc_no);
    }

    public function test_finance_can_withdraw_an_approved_request_and_the_invoices_come_back(): void
    {
        $requester = $this->user(User::ROLE_REQUESTER);
        $invoice = $this->approvedInvoice($requester, $this->user(User::ROLE_APPROVER));
        $finance = $this->user(User::ROLE_FINANCE);

        $this->actingAs($finance)
            ->postJson("/api/payment-requests/{$invoice->payment_request_id}/withdraw", [
                'reason' => 'Currency must be USD, not AED — re-approval required.',
            ])
            ->assertOk()
            ->assertJsonPath('status', PaymentRequest::STATUS_WITHDRAWN);

        $pr = PaymentRequest::find($invoice->payment_request_id);
        $this->assertSame($finance->id, $pr->withdrawn_by);
        $this->assertNotNull($pr->withdrawn_at);
        $this->assertNull($pr->current_stage);

        $invoice->refresh();
        $this->assertSame(Invoice::PAY_NOT_INITIATED, $invoice->payment_status);
        $this->assertNull($invoice->payment_request_id);

        // The approval history survives, and the reference of every returned invoice is recorded.
        $this->assertSame(1, $pr->approvals()->where('status', PaymentRequestApproval::STATUS_APPROVED)->count());
        $log = AuditLog::where('action', 'withdrawn')->latest('id')->first();
        $this->assertStringContainsString($invoice->reference_no, $log->description);
    }

    public function test_a_withdrawn_request_can_no_longer_be_paid(): void
    {
        $invoice = $this->approvedInvoice($this->user(User::ROLE_REQUESTER), $this->user(User::ROLE_APPROVER));
        $finance = $this->user(User::ROLE_FINANCE);
        $prId = $invoice->payment_request_id;

        $this->actingAs($finance)
            ->postJson("/api/payment-requests/{$prId}/withdraw", ['reason' => 'Wrong currency'])
            ->assertOk();

        $this->actingAs($finance)
            ->postJson("/api/payment-requests/{$prId}/mark-paid", ['payment_reference' => 'TT-123'])
            ->assertStatus(422);

        $this->assertSame(PaymentRequest::STATUS_WITHDRAWN, PaymentRequest::find($prId)->status);
    }

    public function test_withdrawal_requires_a_reason_and_refuses_requests_that_are_not_approved(): void
    {
        $invoice = $this->approvedInvoice($this->user(User::ROLE_REQUESTER), $this->user(User::ROLE_APPROVER));
        $finance = $this->user(User::ROLE_FINANCE);
        $pr = PaymentRequest::find($invoice->payment_request_id);

        $this->actingAs($finance)
            ->postJson("/api/payment-requests/{$pr->id}/withdraw", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        // Still in approval: rejection is the exit, so withdrawal points the caller at it.
        $pr->update(['status' => PaymentRequest::STATUS_IN_APPROVAL, 'current_stage' => 1]);
        $this->actingAs($finance)
            ->postJson("/api/payment-requests/{$pr->id}/withdraw", ['reason' => 'Wrong currency'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $pr->update(['status' => PaymentRequest::STATUS_PAID, 'current_stage' => null]);
        $this->actingAs($finance)
            ->postJson("/api/payment-requests/{$pr->id}/withdraw", ['reason' => 'Wrong currency'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_only_finance_and_admin_can_withdraw(): void
    {
        $requester = $this->user(User::ROLE_REQUESTER);
        $approver = $this->user(User::ROLE_APPROVER);
        $invoice = $this->approvedInvoice($requester, $approver);
        $prId = $invoice->payment_request_id;

        foreach ([$requester, $approver] as $user) {
            $this->actingAs($user)
                ->postJson("/api/payment-requests/{$prId}/withdraw", ['reason' => 'Let me out'])
                ->assertForbidden();
        }

        $this->assertSame(PaymentRequest::STATUS_APPROVED, PaymentRequest::find($prId)->status);

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->postJson("/api/payment-requests/{$prId}/withdraw", ['reason' => 'Wrong currency'])
            ->assertOk();
    }

    /** The whole recovery route the incident needed, end to end. */
    public function test_withdraw_then_query_then_correct_the_currency_and_re_post(): void
    {
        Currency::firstOrCreate(['name' => 'USD']);
        $requester = $this->user(User::ROLE_REQUESTER);
        $finance = $this->user(User::ROLE_FINANCE);
        $invoice = $this->approvedInvoice($requester, $this->user(User::ROLE_APPROVER));

        $this->actingAs($finance)
            ->postJson("/api/payment-requests/{$invoice->payment_request_id}/withdraw", ['reason' => 'Currency must be USD'])
            ->assertOk();

        // Returned invoices are still `posted`, so the query is what makes them editable again.
        $this->actingAs($finance)
            ->postJson("/api/invoices/{$invoice->id}/query", ['finance_remarks' => 'currency need to change from AED to USD'])
            ->assertOk();
        $this->assertTrue($invoice->refresh()->isEditable());

        $this->actingAs($requester)->post("/api/invoices/{$invoice->id}", [
            'vendor_id' => Vendor::first()->id,
            'invoice_no' => 'ATL-INV-0024514871',
            'invoice_date' => now()->subDays(20)->toDateString(),
            'business_unit' => 'GGL',
            'department' => 'Freight Forwarding',
            'location' => 'Dubai-Jafza',
            'payment_method' => 'bank_transfer',
            'priority' => 'urgent',
            'items' => [
                ['job_no' => 'SSE00005216', 'currency' => 'USD', 'amount' => 4900, 'tax_rate' => 0],
            ],
        ])->assertOk()->assertJsonPath('currency', 'USD');

        // Correcting a query returns it to submitted, which is the state that may be re-posted.
        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_SUBMITTED, $invoice->status);

        $this->actingAs($finance)
            ->postJson("/api/invoices/{$invoice->id}/post", ['erp_doc_no' => 'PINGGF00012348'])
            ->assertOk();

        $invoice->refresh();
        $this->assertSame('USD', $invoice->currency);
        $this->assertSame('PINGGF00012348', $invoice->erp_doc_no);
        $this->assertTrue($invoice->isPayable());
    }
}
