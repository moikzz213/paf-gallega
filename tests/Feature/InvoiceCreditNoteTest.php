<?php

namespace Tests\Feature;

use App\Models\ApprovalLevel;
use App\Models\BusinessUnit;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A vendor credit note is captured as a line with a negative amount, netted off the invoice by the
 * same sums that build the header totals. The figure that then goes for approval and payment is the
 * net one.
 *
 * The rule that makes this safe is that a *payment request* must still ask for more than zero: a
 * total of zero or less matches no `min_amount` at all, so it could never be routed, and there would
 * be nothing to pay. An invoice on its own may net below zero — a credit-only invoice, settled by
 * being grouped with the charge it offsets. See PaymentRequestCreditOnlyInvoiceTest.
 */
class InvoiceCreditNoteTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        BusinessUnit::create(['name' => 'GGH', 'is_active' => true]);
        Department::create(['name' => 'Yard', 'is_active' => true]);
        Location::create(['name' => 'Dubai', 'is_active' => true]);
        Currency::firstOrCreate(['name' => 'AED']);
        $this->vendor = Vendor::create(['name' => 'Al Noor Logistics', 'vendor_code' => 'ALN-1', 'is_active' => true]);
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

    /** @param  array<int, array<string, mixed>>  $items */
    private function payload(array $items): array
    {
        return [
            'vendor_id' => $this->vendor->id,
            'invoice_no' => 'INV-'.uniqid(),
            'invoice_date' => now()->toDateString(),
            'business_unit' => 'GGH',
            'department' => 'Yard',
            'location' => 'Dubai',
            'payment_method' => 'bank_transfer',
            'priority' => 'normal',
            'items' => array_map(fn ($item) => array_merge(['currency' => 'AED'], $item), $items),
        ];
    }

    private function levels(): void
    {
        ApprovalLevel::create(['level' => 1, 'name' => 'Manager', 'min_amount' => 0, 'is_active' => true]);
        ApprovalLevel::create(['level' => 2, 'name' => 'Head of Finance', 'min_amount' => 50000, 'is_active' => true]);
        ApprovalLevel::create(['level' => 3, 'name' => 'MD', 'min_amount' => 250000, 'is_active' => true]);
    }

    private function postedInvoice(User $owner, float $total, string $currency = 'AED'): Invoice
    {
        return Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => $this->vendor->name, 'invoice_no' => 'INV-'.uniqid(),
            'invoice_date' => now()->toDateString(),
            'currency' => $currency, 'amount' => $total, 'tax_amount' => 0, 'total_amount' => $total,
            'business_unit' => 'GGH', 'department' => 'Yard', 'location' => 'Dubai',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => Invoice::STATUS_POSTED, 'payment_status' => Invoice::PAY_NOT_INITIATED,
            'submitted_by' => $owner->id, 'submitted_at' => now(),
        ]);
    }

    // ── Happy path ────────────────────────────────────────────────────────────────────────────

    /** HP-01 */
    public function test_a_credit_note_line_is_deducted_from_the_invoice_total(): void
    {
        $res = $this->actingAs($this->user(User::ROLE_REQUESTER))
            ->postJson('/api/invoices', $this->payload([
                ['amount' => 10000, 'description' => 'Freight'],
                ['amount' => -1500, 'description' => 'Credit note CN-88'],
            ]))
            ->assertCreated();

        $res->assertJsonPath('amount', '8500.00')
            ->assertJsonPath('total_amount', '8500.00');

        // Both lines survive as themselves — the point of the change is that the credit stays visible.
        $items = Invoice::find($res->json('id'))->items;
        $this->assertSame(['10000.00', '-1500.00'], $items->pluck('amount')->all());
    }

    /** HP-03 — tax on a credit line is a reduction of the tax owed. */
    public function test_tax_on_a_credit_line_reduces_the_tax_owed(): void
    {
        $res = $this->actingAs($this->user(User::ROLE_REQUESTER))
            ->postJson('/api/invoices', $this->payload([
                ['amount' => 10000, 'tax_rate' => 5],
                ['amount' => -1000, 'tax_rate' => 5],
            ]))
            ->assertCreated();

        $invoice = Invoice::find($res->json('id'));

        $this->assertSame(['500.00', '-50.00'], $invoice->items->pluck('tax_amount')->all());
        $this->assertSame('9000.00', $invoice->amount);
        $this->assertSame('450.00', $invoice->tax_amount);
        $this->assertSame('9450.00', $invoice->total_amount);
    }

    /** HP-04 */
    public function test_a_credit_line_can_carry_no_tax_of_its_own(): void
    {
        $res = $this->actingAs($this->user(User::ROLE_REQUESTER))
            ->postJson('/api/invoices', $this->payload([
                ['amount' => 10000, 'tax_rate' => 5],
                ['amount' => -1000, 'tax_rate' => 0],
            ]))
            ->assertCreated();

        $res->assertJsonPath('tax_amount', '500.00')
            ->assertJsonPath('total_amount', '9500.00');
    }

    /** HP-05 */
    public function test_several_credit_lines_all_net_off(): void
    {
        $this->actingAs($this->user(User::ROLE_REQUESTER))
            ->postJson('/api/invoices', $this->payload([
                ['amount' => 20000],
                ['amount' => -1000],
                ['amount' => -2000],
                ['amount' => -500],
            ]))
            ->assertCreated()
            ->assertJsonPath('total_amount', '16500.00');
    }

    /** HP-06 and HP-07 */
    public function test_a_credit_line_can_be_added_and_removed_by_editing(): void
    {
        $requester = $this->user(User::ROLE_REQUESTER);
        $created = $this->actingAs($requester)
            ->postJson('/api/invoices', $this->payload([['amount' => 10000]]))
            ->assertCreated()
            ->json();

        $this->actingAs($requester)
            ->post("/api/invoices/{$created['id']}", $this->payload([
                ['amount' => 10000],
                ['amount' => -2000, 'description' => 'CN-90'],
            ]))
            ->assertOk()
            ->assertJsonPath('total_amount', '8000.00');

        $this->actingAs($requester)
            ->post("/api/invoices/{$created['id']}", $this->payload([['amount' => 10000]]))
            ->assertOk()
            ->assertJsonPath('total_amount', '10000.00');
    }

    /** HP-02 — the net figure is what the payment request asks for. */
    public function test_the_payment_request_total_is_the_net_of_the_credit_note(): void
    {
        $this->levels();
        $finance = $this->user(User::ROLE_FINANCE);
        $approver = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);

        $invoice = $this->postedInvoice($this->user(User::ROLE_REQUESTER), 10000);
        $invoice->items()->create([
            'sort_order' => 0, 'currency' => 'AED', 'amount' => 12000,
            'tax_rate' => 0, 'tax_amount' => 0, 'total_amount' => 12000,
        ]);
        $invoice->items()->create([
            'sort_order' => 1, 'currency' => 'AED', 'amount' => -2000,
            'tax_rate' => 0, 'tax_amount' => 0, 'total_amount' => -2000,
        ]);

        $this->actingAs($finance)->postJson('/api/payment-requests', [
            'invoice_ids' => [$invoice->id],
            'approvers' => [1 => $approver->id],
        ])->assertCreated();

        $this->assertSame('10000.00', PaymentRequest::first()->total_amount);
    }

    // ── Negative ──────────────────────────────────────────────────────────────────────────────

    /** NEG-01 */
    public function test_a_zero_line_is_rejected(): void
    {
        $this->actingAs($this->user(User::ROLE_REQUESTER))
            ->postJson('/api/invoices', $this->payload([['amount' => 10000], ['amount' => 0]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.1.amount');
    }

    /**
     * NEG-01 (credit-only CR) — an invoice that nets to exactly zero is neither a charge nor a
     * credit. Netting *below* zero is now accepted; see PaymentRequestCreditOnlyInvoiceTest.
     */
    public function test_an_invoice_that_nets_to_exactly_zero_is_rejected(): void
    {
        $this->actingAs($this->user(User::ROLE_REQUESTER))
            ->postJson('/api/invoices', $this->payload([['amount' => 5000], ['amount' => -5000]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');

        $this->assertSame(0, Invoice::count());
    }

    /** Tax can be what tips an invoice onto zero, so the rule measures the net total. */
    public function test_the_rule_is_measured_on_the_total_including_tax(): void
    {
        // Amounts net to zero, but the tax on each line nets to zero too — still nothing owed.
        $this->actingAs($this->user(User::ROLE_REQUESTER))
            ->postJson('/api/invoices', $this->payload([
                ['amount' => 5000, 'tax_rate' => 5],
                ['amount' => -5000, 'tax_rate' => 5],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');

        // A single fil either way is enough — the rule is "not zero", not "materially more".
        $this->actingAs($this->user(User::ROLE_REQUESTER))
            ->postJson('/api/invoices', $this->payload([
                ['amount' => 5000],
                ['amount' => -4999.99],
            ]))
            ->assertCreated()
            ->assertJsonPath('total_amount', '0.01');
    }

    /** NEG-05 */
    public function test_a_credit_line_is_bounded_by_the_same_size_limit_as_a_charge(): void
    {
        $this->actingAs($this->user(User::ROLE_REQUESTER))
            ->postJson('/api/invoices', $this->payload([
                ['amount' => 1000],
                ['amount' => -9999999999999],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.1.amount');
    }

    /** NEG-10 */
    public function test_a_credit_line_still_cannot_carry_a_negative_tax_rate(): void
    {
        $this->actingAs($this->user(User::ROLE_REQUESTER))
            ->postJson('/api/invoices', $this->payload([
                ['amount' => 10000],
                ['amount' => -1000, 'tax_rate' => -5],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.1.tax_rate');
    }

    /** NEG-09 */
    public function test_a_credit_line_cannot_be_in_a_different_currency_to_the_charge(): void
    {
        Currency::firstOrCreate(['name' => 'USD'], ['is_active' => true]);

        $this->actingAs($this->user(User::ROLE_REQUESTER))
            ->postJson('/api/invoices', $this->payload([
                ['amount' => 10000, 'currency' => 'AED'],
                ['amount' => -1000, 'currency' => 'USD'],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');
    }

    // ── Security: approval authority ──────────────────────────────────────────────────────────

    /**
     * SEC-01 — the whole point of the change: approval follows what is actually paid. Asserted
     * explicitly rather than left implicit, because it is also the route by which a credit note
     * lowers who has to sign.
     */
    public function test_routing_is_measured_on_the_net_total_not_the_gross(): void
    {
        $this->levels();
        $finance = $this->user(User::ROLE_FINANCE);
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $a2 = $this->user(User::ROLE_APPROVER, ['approval_level' => 2]);
        $a3 = $this->user(User::ROLE_APPROVER, ['approval_level' => 3]);

        // Gross 500,000 would reach level 3; net 50,000 reaches level 2 and no further.
        $invoice = $this->postedInvoice($this->user(User::ROLE_REQUESTER), 50000);

        $this->assertSame([1, 2], ApprovalLevel::requiredForInvoices([$invoice])->pluck('level')->all());

        $this->actingAs($finance)->postJson('/api/payment-requests', [
            'invoice_ids' => [$invoice->id],
            'approvers' => [1 => $a1->id, 2 => $a2->id, 3 => $a3->id],
        ])->assertCreated();

        // Level 3 is not on the chain, because 50,000 does not require it.
        $this->assertSame([$a1->id, $a2->id], PaymentRequest::first()->approvals->pluck('approver_id')->all());
    }

    /** SEC-02 — a credit must never cost the chain a level the net figure still requires. */
    public function test_routing_never_omits_a_level_the_net_total_requires(): void
    {
        $this->levels();
        $finance = $this->user(User::ROLE_FINANCE);
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $a2 = $this->user(User::ROLE_APPROVER, ['approval_level' => 2]);

        // 500,000 credited down to 200,000 — still over the 50,000 level.
        $invoice = $this->postedInvoice($this->user(User::ROLE_REQUESTER), 200000);

        $this->actingAs($finance)->postJson('/api/payment-requests', [
            'invoice_ids' => [$invoice->id],
            'approvers' => [1 => $a1->id, 2 => $a2->id],
        ])->assertCreated();

        $this->assertSame([$a1->id, $a2->id], PaymentRequest::first()->approvals->pluck('approver_id')->all());
    }

    /**
     * SEC-05 — releasing an invoice normally lowers what a request still asks for, which is why it
     * keeps its approvals. An invoice worth nothing or less would raise it instead, past the figure
     * those approvals cover, so the release is refused rather than silently allowed.
     */
    public function test_releasing_an_invoice_that_owes_nothing_is_refused(): void
    {
        $this->levels();
        $finance = $this->user(User::ROLE_FINANCE);
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);

        $keep = $this->postedInvoice($this->user(User::ROLE_REQUESTER), 10000);
        // A credit-only invoice, grouped with the charge it offsets — the request asks for 6,000,
        // which is what its approvals cover. Releasing the credit would push that back up to 10,000.
        $credited = $this->postedInvoice($this->user(User::ROLE_REQUESTER), -4000);

        $this->actingAs($finance)->postJson('/api/payment-requests', [
            'invoice_ids' => [$keep->id, $credited->id],
            'approvers' => [1 => $a1->id],
        ])->assertCreated();

        $this->assertSame('6000.00', PaymentRequest::first()->total_amount);

        $this->actingAs($finance)
            ->postJson("/api/invoices/{$credited->id}/release", ['reason' => 'Wrongly grouped'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoice');

        // Nothing moved: the invoice is still held and the request still asks for what it did.
        $this->assertNotNull($credited->refresh()->payment_request_id);
    }

    /** SEC-08 — the credit is the thing a reviewer needs to find in the log. */
    public function test_the_audit_log_records_what_was_credited_and_the_gross(): void
    {
        $res = $this->actingAs($this->user(User::ROLE_REQUESTER))
            ->postJson('/api/invoices', $this->payload([
                ['amount' => 10000],
                ['amount' => -1500],
            ]))
            ->assertCreated();

        $description = Invoice::find($res->json('id'))->auditLogs()->where('action', 'submitted')->value('description');

        $this->assertStringContainsString('AED 8500.00', $description);
        $this->assertStringContainsString('net of AED 1,500.00 credited on 1 line', $description);
        $this->assertStringContainsString('gross AED 10,000.00', $description);
    }

    /** REG-02 — an ordinary invoice's audit message is untouched. */
    public function test_an_invoice_without_a_credit_note_logs_exactly_as_before(): void
    {
        $res = $this->actingAs($this->user(User::ROLE_REQUESTER))
            ->postJson('/api/invoices', $this->payload([['amount' => 10000]]))
            ->assertCreated();

        $invoice = Invoice::find($res->json('id'));
        $description = $invoice->auditLogs()->where('action', 'submitted')->value('description');

        $this->assertSame(
            "Invoice {$invoice->reference_no} submitted to Finance for {$this->vendor->name} (AED 10000.00)",
            $description,
        );
    }

    // ── Presentation ──────────────────────────────────────────────────────────────────────────

    /** UAT-03 to UAT-05 — a credit has to be unmistakable, not merely present. */
    public function test_a_credit_is_rendered_in_accounting_style(): void
    {
        $this->assertSame('AED (1,500.00)', Money::format(-1500, 'AED'));
        $this->assertSame('AED 1,500.00', Money::format(1500, 'AED'));
        $this->assertSame('AED 0.00', Money::format(0, 'AED'));
        // A rounding tail is not a credit.
        $this->assertSame('AED 0.00', Money::format(-0.001, 'AED'));
        $this->assertSame('USD (12,345.68)', Money::format(-12345.678, 'USD'));
        $this->assertSame('AED 0.00', Money::format(null, null));
    }
}
