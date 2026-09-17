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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Invoices already on record carry credit notes entered as a **positive** amount, because the system
 * used to refuse a negative one. Grouping such an invoice added it to the payment request instead of
 * deducting it, so the request asked for more than was owed.
 *
 * Marking the invoice as a credit note when the request is raised corrects its sign — the invoice
 * always was a credit note. Correcting rather than flagging is the point: from then on it is an
 * ordinary credit-only invoice, so the routing, the request total, `syncTotal`, the release and
 * correction guards, the PDF and the reports are all right without knowing the mark existed.
 *
 * CR: ai/change-requests/mark-a-positive-invoice-as-a-credit-note-on-a-payment-request.md
 */
class PaymentRequestCreditMarkTest extends TestCase
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

    private function levels(): void
    {
        ApprovalLevel::create(['level' => 1, 'name' => 'Manager', 'min_amount' => 0, 'is_active' => true]);
        ApprovalLevel::create(['level' => 2, 'name' => 'Head of Finance', 'min_amount' => 50000, 'is_active' => true]);
        ApprovalLevel::create(['level' => 3, 'name' => 'MD', 'min_amount' => 250000, 'is_active' => true]);
    }

    /** An invoice as production holds it: a real amount, with tax, spread over lines. */
    private function postedInvoice(float $amount, float $tax = 0, string $currency = 'AED'): Invoice
    {
        $invoice = Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => $this->vendor->name, 'invoice_no' => 'INV-'.uniqid(),
            'invoice_date' => now()->toDateString(),
            'currency' => $currency, 'amount' => $amount, 'tax_amount' => $tax, 'total_amount' => $amount + $tax,
            'business_unit' => 'GGH', 'department' => 'Yard', 'location' => 'Dubai',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => Invoice::STATUS_POSTED, 'payment_status' => Invoice::PAY_NOT_INITIATED,
            'submitted_by' => $this->user(User::ROLE_REQUESTER)->id, 'submitted_at' => now(),
        ]);

        $invoice->items()->create([
            'sort_order' => 0, 'currency' => $currency, 'description' => 'Line',
            'amount' => $amount, 'tax_rate' => $amount ? round($tax / $amount * 100, 2) : 0,
            'tax_amount' => $tax, 'total_amount' => $amount + $tax,
        ]);

        return $invoice;
    }

    private function createRequest(array $payload, ?User $actor = null)
    {
        return $this->actingAs($actor ?? $this->user(User::ROLE_FINANCE))
            ->postJson('/api/payment-requests', $payload);
    }

    // ── Happy path ────────────────────────────────────────────────────────────────────────────

    /** The bug this fixes: a positive credit note used to be added to the request. */
    public function test_a_marked_invoice_is_deducted_from_the_request_instead_of_added(): void
    {
        $this->levels();
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);

        $charge = $this->postedInvoice(20000);
        $credit = $this->postedInvoice(5000);

        $this->createRequest([
            'invoice_ids' => [$charge->id, $credit->id],
            'credit_invoice_ids' => [$credit->id],
            'approvers' => [1 => $a1->id],
        ])->assertCreated();

        // Added it would be 25,000; deducted it is 15,000.
        $this->assertSame('15000.00', PaymentRequest::first()->total_amount);
    }

    /** The correction is real: the invoice and its lines both change sign. */
    public function test_marking_corrects_the_invoice_and_its_lines(): void
    {
        $this->levels();
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);

        $charge = $this->postedInvoice(20000);
        $credit = $this->postedInvoice(5000, 250);

        $this->createRequest([
            'invoice_ids' => [$charge->id, $credit->id],
            'credit_invoice_ids' => [$credit->id],
            'approvers' => [1 => $a1->id],
        ])->assertCreated();

        $credit->refresh();
        $this->assertSame('-5000.00', $credit->amount);
        $this->assertSame('-250.00', $credit->tax_amount);
        $this->assertSame('-5250.00', $credit->total_amount);

        // The header stays the sum of its lines, so nothing downstream can disagree with it.
        $this->assertSame(-5250.0, (float) $credit->items()->sum('total_amount'));
        $this->assertSame('-5000.00', $credit->items->first()->amount);
        $this->assertSame('-250.00', $credit->items->first()->tax_amount);
        // The tax rate describes the line, not its direction, so it is untouched.
        $this->assertSame('5.00', $credit->items->first()->tax_rate);
    }

    /** A corrected invoice is an ordinary credit-only invoice — `syncTotal` agrees with the request. */
    public function test_the_request_total_stays_the_sum_of_its_invoices(): void
    {
        $this->levels();
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);

        $charge = $this->postedInvoice(20000);
        $credit = $this->postedInvoice(5000);

        $this->createRequest([
            'invoice_ids' => [$charge->id, $credit->id],
            'credit_invoice_ids' => [$credit->id],
            'approvers' => [1 => $a1->id],
        ])->assertCreated();

        $pr = PaymentRequest::first();
        $this->assertSame(15000.0, (float) $pr->invoices()->sum('total_amount'));

        // The independent recompute lands on the same figure, so no later sync can move the total.
        $pr->syncTotal();
        $this->assertSame('15000.00', $pr->refresh()->total_amount);
    }

    /** Several marks in one request. */
    public function test_several_invoices_can_be_marked_at_once(): void
    {
        $this->levels();
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);

        $charge = $this->postedInvoice(30000);
        $c1 = $this->postedInvoice(5000);
        $c2 = $this->postedInvoice(4000);

        $this->createRequest([
            'invoice_ids' => [$charge->id, $c1->id, $c2->id],
            'credit_invoice_ids' => [$c1->id, $c2->id],
            'approvers' => [1 => $a1->id],
        ])->assertCreated();

        $this->assertSame('21000.00', PaymentRequest::first()->total_amount);
    }

    /** A marked invoice can sit alongside one already recorded as a credit. */
    public function test_a_mark_and_an_existing_credit_note_net_together(): void
    {
        $this->levels();
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);

        $charge = $this->postedInvoice(30000);
        $marked = $this->postedInvoice(5000);
        $already = $this->postedInvoice(-4000);

        $this->createRequest([
            'invoice_ids' => [$charge->id, $marked->id, $already->id],
            'credit_invoice_ids' => [$marked->id],
            'approvers' => [1 => $a1->id],
        ])->assertCreated();

        $this->assertSame('21000.00', PaymentRequest::first()->total_amount);
    }

    /** Nothing changes for a request with no marks at all. */
    public function test_an_unmarked_request_is_unchanged(): void
    {
        $this->levels();
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);

        $a = $this->postedInvoice(10000);
        $b = $this->postedInvoice(2500);

        $this->createRequest([
            'invoice_ids' => [$a->id, $b->id],
            'approvers' => [1 => $a1->id],
        ])->assertCreated();

        $this->assertSame('12500.00', PaymentRequest::first()->total_amount);
        $this->assertSame('10000.00', $a->refresh()->total_amount);
        $this->assertSame('2500.00', $b->refresh()->total_amount);
    }

    // ── Negative ──────────────────────────────────────────────────────────────────────────────

    /** An invoice already recorded as a credit note is deducted as it stands. */
    public function test_an_invoice_already_a_credit_note_cannot_be_marked(): void
    {
        $this->levels();
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);

        $charge = $this->postedInvoice(20000);
        $credit = $this->postedInvoice(-5000);

        $this->createRequest([
            'invoice_ids' => [$charge->id, $credit->id],
            'credit_invoice_ids' => [$credit->id],
            'approvers' => [1 => $a1->id],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('credit_invoice_ids');

        // Marking it would have turned a credit back into a charge — nothing moved.
        $this->assertSame('-5000.00', $credit->refresh()->total_amount);
        $this->assertSame(0, PaymentRequest::count());
    }

    /** A mark has to belong to the selection it is sent with. */
    public function test_a_mark_outside_the_selection_is_refused(): void
    {
        $this->levels();
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);

        $charge = $this->postedInvoice(20000);
        $elsewhere = $this->postedInvoice(5000);

        $this->createRequest([
            'invoice_ids' => [$charge->id],
            'credit_invoice_ids' => [$elsewhere->id],
            'approvers' => [1 => $a1->id],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('credit_invoice_ids');

        $this->assertSame('5000.00', $elsewhere->refresh()->total_amount);
    }

    /** Marking everything leaves nothing to pay, and the payable floor catches it. */
    public function test_marking_the_whole_selection_is_refused_as_nothing_payable(): void
    {
        $this->levels();
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);

        $one = $this->postedInvoice(20000);
        $two = $this->postedInvoice(5000);

        $this->createRequest([
            'invoice_ids' => [$one->id, $two->id],
            'credit_invoice_ids' => [$one->id, $two->id],
            'approvers' => [1 => $a1->id],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoices');

        $this->assertSame(0, PaymentRequest::count());
    }

    /** Marks net to zero — nothing would be paid. */
    public function test_a_mark_that_nets_the_request_to_zero_is_refused(): void
    {
        $this->levels();
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);

        $charge = $this->postedInvoice(5000);
        $credit = $this->postedInvoice(5000);

        $this->createRequest([
            'invoice_ids' => [$charge->id, $credit->id],
            'credit_invoice_ids' => [$credit->id],
            'approvers' => [1 => $a1->id],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoices');
    }

    /**
     * The critical one: a refused request must not leave a rewritten invoice behind. The mark is
     * applied in memory for validation and persisted only inside the creation transaction.
     */
    public function test_a_refused_request_leaves_the_invoice_untouched(): void
    {
        $this->levels();
        $finance = $this->user(User::ROLE_FINANCE);

        $charge = $this->postedInvoice(20000);
        $credit = $this->postedInvoice(5000);

        // No approvers, so creation fails after the marks have been applied in memory.
        $this->createRequest([
            'invoice_ids' => [$charge->id, $credit->id],
            'credit_invoice_ids' => [$credit->id],
        ], $finance)->assertStatus(422);

        $this->assertSame('5000.00', $credit->refresh()->total_amount);
        $this->assertSame(5000.0, (float) $credit->items()->sum('total_amount'));
        $this->assertNull($credit->payment_request_id);
        $this->assertSame(0, PaymentRequest::count());
    }

    /** A creator on their own chain is refused after the marks are applied — same guarantee. */
    public function test_a_request_refused_for_its_chain_leaves_the_invoice_untouched(): void
    {
        $this->levels();
        $finance = $this->user(User::ROLE_FINANCE, ['approval_level' => 1]);

        $charge = $this->postedInvoice(20000);
        $credit = $this->postedInvoice(5000);

        $this->createRequest([
            'invoice_ids' => [$charge->id, $credit->id],
            'credit_invoice_ids' => [$credit->id],
            'approvers' => [1 => $finance->id],
        ], $finance)
            ->assertStatus(422)
            ->assertJsonValidationErrors('approvers');

        $this->assertSame('5000.00', $credit->refresh()->total_amount);
        $this->assertSame(0, PaymentRequest::count());
    }

    // ── Security: the amount and who signs for it ─────────────────────────────────────────────

    /** Routing follows the deducted figure, which is what is actually paid. */
    public function test_routing_is_measured_on_the_deducted_total(): void
    {
        $this->levels();
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $a2 = $this->user(User::ROLE_APPROVER, ['approval_level' => 2]);
        $a3 = $this->user(User::ROLE_APPROVER, ['approval_level' => 3]);

        // Added, 500,000 + 450,000 would reach level 3. Deducted, it pays 50,000 and stops at 2.
        $charge = $this->postedInvoice(500000);
        $credit = $this->postedInvoice(450000);

        $this->createRequest([
            'invoice_ids' => [$charge->id, $credit->id],
            'credit_invoice_ids' => [$credit->id],
            'approvers' => [1 => $a1->id, 2 => $a2->id, 3 => $a3->id],
        ])->assertCreated();

        $pr = PaymentRequest::first();
        $this->assertSame('50000.00', $pr->total_amount);
        $this->assertSame([$a1->id, $a2->id], $pr->approvals->pluck('approver_id')->all());
    }

    /** A mark must never cost the chain a level the deducted figure still requires. */
    public function test_routing_never_omits_a_level_the_deducted_total_requires(): void
    {
        $this->levels();
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $a2 = $this->user(User::ROLE_APPROVER, ['approval_level' => 2]);

        $this->createRequest([
            'invoice_ids' => [$this->postedInvoice(500000)->id, $this->postedInvoice(300000)->id],
            'credit_invoice_ids' => [Invoice::latest('id')->first()->id],
            'approvers' => [1 => $a1->id, 2 => $a2->id],
        ])->assertCreated();

        $pr = PaymentRequest::first();
        $this->assertSame('200000.00', $pr->total_amount);
        $this->assertSame([$a1->id, $a2->id], $pr->approvals->pluck('approver_id')->all());
    }

    /** Only Finance and admin raise requests, so only they can mark. */
    public function test_marking_grants_no_new_access(): void
    {
        $this->levels();
        $charge = $this->postedInvoice(20000);
        $credit = $this->postedInvoice(5000);

        $this->createRequest([
            'invoice_ids' => [$charge->id, $credit->id],
            'credit_invoice_ids' => [$credit->id],
        ], $this->user(User::ROLE_REQUESTER))->assertForbidden();

        $this->assertSame('5000.00', $credit->refresh()->total_amount);
    }

    /** The correction has to be recoverable — the log keeps the figures it replaced. */
    public function test_the_correction_is_audited_with_the_original_figures(): void
    {
        $this->levels();
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);

        $charge = $this->postedInvoice(20000);
        $credit = $this->postedInvoice(5000, 250);

        $this->createRequest([
            'invoice_ids' => [$charge->id, $credit->id],
            'credit_invoice_ids' => [$credit->id],
            'approvers' => [1 => $a1->id],
        ])->assertCreated();

        $log = $credit->auditLogs()->where('action', 'credit_note_marked')->first();

        $this->assertNotNull($log, 'marking an invoice as a credit note must be audited');
        $this->assertSame('5250.00', $log->old_values['total_amount']);
        $this->assertSame(-5250.0, (float) $log->new_values['total_amount']);
        $this->assertStringContainsString('marked as a credit note', $log->description);
        $this->assertStringContainsString('AED 5,250.00', $log->description);
        // Tied to the request it was raised on, so a reviewer can see why it was corrected.
        $this->assertSame(PaymentRequest::first()->id, $log->payment_request_id);
    }

    /** Corrected means corrected: the invoice behaves as a credit-only invoice afterwards. */
    public function test_a_marked_invoice_behaves_as_a_credit_only_invoice_afterwards(): void
    {
        $this->levels();
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $finance = $this->user(User::ROLE_FINANCE);

        $charge = $this->postedInvoice(20000);
        $credit = $this->postedInvoice(5000);

        $this->createRequest([
            'invoice_ids' => [$charge->id, $credit->id],
            'credit_invoice_ids' => [$credit->id],
            'approvers' => [1 => $a1->id],
        ], $finance)->assertCreated();

        // Releasing it would raise what the request still asks for, so the existing guard refuses —
        // the guard reads `total_amount`, and knows nothing about the mark.
        $this->actingAs($finance)
            ->postJson("/api/invoices/{$credit->id}/release", ['reason' => 'Wrongly grouped'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoice');

        // And rejection returns it to the log as the credit note it now is.
        $this->actingAs($a1)
            ->postJson('/api/payment-requests/'.PaymentRequest::first()->id.'/reject', ['comments' => 'Wrong period'])
            ->assertOk();

        $this->assertSame('-5000.00', $credit->refresh()->total_amount);
        $this->assertTrue($credit->isPayable());
    }
}
