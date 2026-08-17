<?php

namespace Tests\Feature;

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
 * Finance/admin can fix an invoice a payment request is already holding, with no further approval —
 * but only where the fix cannot invalidate the approvals that request already carries. Anything
 * that changes what gets paid (currency, a higher total) needs the invoice released first.
 *
 * Modelled on the local PRF-2026-00009 case: two approved invoices, one of which is wrong.
 */
class InvoiceInPlaceCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private PaymentRequest $pr;

    private Invoice $wrong;

    private Invoice $fine;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        BusinessUnit::create(['name' => 'GGL', 'is_active' => true]);
        Department::create(['name' => 'Freight Forwarding', 'is_active' => true]);
        Location::create(['name' => 'Dubai-Jafza', 'is_active' => true]);
        Currency::firstOrCreate(['name' => 'AED']);
        Currency::firstOrCreate(['name' => 'USD']);
        Vendor::create(['name' => 'Atlas Maritime Shipping', 'vendor_code' => 'VEN2071', 'is_active' => true]);

        $requester = $this->user(User::ROLE_REQUESTER);

        $this->pr = PaymentRequest::create([
            'reference_no' => PaymentRequest::nextReferenceNo(),
            'created_by' => $requester->id,
            'status' => PaymentRequest::STATUS_APPROVED,
            'total_amount' => 30992.85,
            'sent_at' => now()->subDays(3),
            'approved_at' => now()->subDay(),
        ]);

        foreach ([1 => 'Department Manager', 2 => 'Finance Director'] as $sequence => $label) {
            PaymentRequestApproval::create([
                'payment_request_id' => $this->pr->id, 'sequence' => $sequence, 'level' => $sequence,
                'label' => $label, 'approver_id' => $this->user(User::ROLE_APPROVER)->id,
                'status' => PaymentRequestApproval::STATUS_APPROVED, 'acted_at' => now()->subDay(),
            ]);
        }

        $this->wrong = $this->invoice($requester, 'INV-10732', 26696.25, '5139283648');
        $this->fine = $this->invoice($requester, 'INV-65479', 4296.60, '5139283649');
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

    private function invoice(User $owner, string $invoiceNo, float $total, string $erpDoc): Invoice
    {
        $invoice = Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => 'Atlas Maritime Shipping', 'invoice_no' => $invoiceNo,
            'invoice_date' => now()->subDays(20)->toDateString(),
            'currency' => 'AED', 'amount' => $total, 'tax_amount' => 0, 'total_amount' => $total,
            'business_unit' => 'GGL', 'department' => 'Freight Forwarding', 'location' => 'Dubai-Jafza',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => Invoice::STATUS_POSTED, 'payment_status' => Invoice::PAY_APPROVED,
            'erp_doc_no' => $erpDoc, 'posting_date' => now()->subDays(4)->toDateString(),
            'submitted_by' => $owner->id, 'submitted_at' => now()->subDays(10),
            'payment_request_id' => $this->pr->id,
        ]);

        $invoice->items()->create([
            'sort_order' => 0, 'job_no' => 'SSE00005216', 'currency' => 'AED',
            'amount' => $total, 'tax_amount' => 0, 'total_amount' => $total,
        ]);

        return $invoice;
    }

    /** @param  array<string, mixed>  $line */
    private function payload(Invoice $invoice, array $line = []): array
    {
        return [
            'vendor_id' => Vendor::first()->id,
            'invoice_no' => $invoice->invoice_no,
            'invoice_date' => $invoice->invoice_date->toDateString(),
            'business_unit' => 'GGL',
            'department' => 'Freight Forwarding',
            'location' => 'Dubai-Jafza',
            'payment_method' => 'bank_transfer',
            'priority' => 'normal',
            'items' => [array_merge(
                ['job_no' => 'SSE00005216', 'currency' => 'AED', 'amount' => $invoice->total_amount, 'tax_amount' => 0],
                $line,
            )],
        ];
    }

    public function test_finance_can_correct_details_in_place_without_re_approval(): void
    {
        $this->actingAs($this->user(User::ROLE_FINANCE))
            ->post("/api/invoices/{$this->wrong->id}", $this->payload($this->wrong, [
                'job_no' => 'SSE00005217',
                'description' => 'Corrected job reference',
            ]))
            ->assertOk();

        $this->wrong->refresh();
        $this->assertSame('SSE00005217', $this->wrong->items->first()->job_no);
        // Still held by the same request, which is still approved and payable.
        $this->assertSame($this->pr->id, $this->wrong->payment_request_id);
        $this->assertSame(Invoice::PAY_APPROVED, $this->wrong->payment_status);
        $this->assertSame(PaymentRequest::STATUS_APPROVED, $this->pr->refresh()->status);
    }

    public function test_a_currency_change_in_place_is_refused(): void
    {
        $this->actingAs($this->user(User::ROLE_FINANCE))
            ->post("/api/invoices/{$this->wrong->id}", $this->payload($this->wrong, ['currency' => 'USD']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');

        $this->assertSame('AED', $this->wrong->refresh()->currency);
    }

    public function test_raising_the_total_in_place_is_refused(): void
    {
        $this->actingAs($this->user(User::ROLE_FINANCE))
            ->post("/api/invoices/{$this->wrong->id}", $this->payload($this->wrong, ['amount' => 30000]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');

        $this->assertSame('26696.25', $this->wrong->refresh()->total_amount);
        $this->assertSame('30992.85', $this->pr->refresh()->total_amount);
    }

    public function test_lowering_the_total_in_place_is_allowed_and_the_request_total_follows(): void
    {
        $this->actingAs($this->user(User::ROLE_FINANCE))
            ->post("/api/invoices/{$this->wrong->id}", $this->payload($this->wrong, ['amount' => 20000]))
            ->assertOk();

        $this->assertSame('20000.00', $this->wrong->refresh()->total_amount);
        // 20,000.00 + 4,296.60
        $this->assertSame('24296.60', $this->pr->refresh()->total_amount);
    }

    public function test_a_requester_still_cannot_touch_an_invoice_in_a_payment_request(): void
    {
        $owner = User::find($this->wrong->submitted_by);

        $this->actingAs($owner)
            ->post("/api/invoices/{$this->wrong->id}", $this->payload($this->wrong, ['job_no' => 'NOPE']))
            ->assertStatus(422);

        $this->assertSame('SSE00005216', $this->wrong->refresh()->items->first()->job_no);
    }

    public function test_a_paid_invoice_cannot_be_corrected_in_place(): void
    {
        $this->pr->update(['status' => PaymentRequest::STATUS_PAID, 'paid_at' => now()]);
        $this->wrong->update(['payment_status' => Invoice::PAY_PAID]);

        $this->actingAs($this->user(User::ROLE_FINANCE))
            ->post("/api/invoices/{$this->wrong->id}", $this->payload($this->wrong, ['job_no' => 'NOPE']))
            ->assertStatus(422);
    }

    public function test_releasing_one_invoice_leaves_the_rest_of_the_request_approved(): void
    {
        $finance = $this->user(User::ROLE_FINANCE);

        $this->actingAs($finance)
            ->postJson("/api/invoices/{$this->wrong->id}/release", ['reason' => 'Currency must be USD'])
            ->assertOk();

        $this->wrong->refresh();
        $this->assertSame(Invoice::PAY_NOT_INITIATED, $this->wrong->payment_status);
        $this->assertNull($this->wrong->payment_request_id);

        // The other invoice keeps its approvals and stays payable; the total drops to just its own.
        $this->fine->refresh();
        $this->assertSame($this->pr->id, $this->fine->payment_request_id);
        $this->assertSame(Invoice::PAY_APPROVED, $this->fine->payment_status);
        $this->assertSame(PaymentRequest::STATUS_APPROVED, $this->pr->refresh()->status);
        $this->assertSame('4296.60', $this->pr->total_amount);

        // Released, posted and outside a cycle: it can be queried and corrected again.
        $this->actingAs($finance)
            ->postJson("/api/invoices/{$this->wrong->id}/query", ['finance_remarks' => 'change currency from AED to USD'])
            ->assertOk();
        $this->assertTrue($this->wrong->refresh()->isEditable());
    }

    public function test_releasing_the_last_invoice_withdraws_the_request(): void
    {
        $finance = $this->user(User::ROLE_FINANCE);

        foreach ([$this->wrong, $this->fine] as $invoice) {
            $this->actingAs($finance)
                ->postJson("/api/invoices/{$invoice->id}/release", ['reason' => 'Rebuilding this request'])
                ->assertOk();
        }

        $this->pr->refresh();
        $this->assertSame(PaymentRequest::STATUS_WITHDRAWN, $this->pr->status);
        $this->assertSame('0.00', $this->pr->total_amount);
        $this->assertSame($finance->id, $this->pr->withdrawn_by);
    }

    public function test_release_requires_a_reason_and_is_finance_admin_only(): void
    {
        $this->actingAs($this->user(User::ROLE_FINANCE))
            ->postJson("/api/invoices/{$this->wrong->id}/release", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        foreach ([User::ROLE_REQUESTER, User::ROLE_APPROVER] as $role) {
            $this->actingAs($this->user($role))
                ->postJson("/api/invoices/{$this->wrong->id}/release", ['reason' => 'Let me out'])
                ->assertForbidden();
        }

        $this->assertSame($this->pr->id, $this->wrong->refresh()->payment_request_id);
    }

    public function test_an_invoice_outside_a_payment_request_cannot_be_released(): void
    {
        $this->wrong->update(['payment_status' => Invoice::PAY_NOT_INITIATED, 'payment_request_id' => null]);

        $this->actingAs($this->user(User::ROLE_FINANCE))
            ->postJson("/api/invoices/{$this->wrong->id}/release", ['reason' => 'Nothing to release'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoice');
    }
}
