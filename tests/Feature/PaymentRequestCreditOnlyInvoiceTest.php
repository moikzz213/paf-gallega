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
 * A credit note that arrived on its own is recorded as a credit-only invoice: the whole invoice nets
 * below zero. It is settled by being grouped into a payment request alongside the charge it offsets.
 *
 * The floor that used to sit on the invoice now sits on the payment request, and it is structural
 * rather than a preference: `ApprovalLevel::requiredFor` filters on `min_amount` (default 0), so a
 * request at or below zero matches no level, can be routed to nobody, and has nothing to pay. Every
 * test here exists to prove that floor cannot be got around — at creation, on a correction, or on a
 * release.
 *
 * CR: ai/change-requests/allow-credit-only-invoices-netted-in-payment-requests.md
 */
class PaymentRequestCreditOnlyInvoiceTest extends TestCase
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

    private function postedInvoice(float $total, string $currency = 'AED'): Invoice
    {
        return Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => $this->vendor->name, 'invoice_no' => 'INV-'.uniqid(),
            'invoice_date' => now()->toDateString(),
            'currency' => $currency, 'amount' => $total, 'tax_amount' => 0, 'total_amount' => $total,
            'business_unit' => 'GGH', 'department' => 'Yard', 'location' => 'Dubai',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => Invoice::STATUS_POSTED, 'payment_status' => Invoice::PAY_NOT_INITIATED,
            'submitted_by' => $this->user(User::ROLE_REQUESTER)->id, 'submitted_at' => now(),
        ]);
    }

    // ── Happy path: recording a credit-only invoice ────────────────────────────────────────────

    /** HP-01, HP-02 */
    public function test_a_credit_only_invoice_can_be_submitted(): void
    {
        $single = $this->actingAs($this->user(User::ROLE_REQUESTER))
            ->postJson('/api/invoices', $this->payload([['amount' => -5000, 'description' => 'Credit note CN-88']]))
            ->assertCreated();

        $single->assertJsonPath('total_amount', '-5000.00');

        $this->actingAs($this->user(User::ROLE_REQUESTER))
            ->postJson('/api/invoices', $this->payload([['amount' => -3000], ['amount' => -2000]]))
            ->assertCreated()
            ->assertJsonPath('total_amount', '-5000.00');
    }

    /** HP-03 — a charge on the same document does not have to cover the credit. */
    public function test_an_invoice_may_net_below_zero_from_mixed_lines(): void
    {
        $this->actingAs($this->user(User::ROLE_REQUESTER))
            ->postJson('/api/invoices', $this->payload([['amount' => 1000], ['amount' => -6000]]))
            ->assertCreated()
            ->assertJsonPath('total_amount', '-5000.00');
    }

    /** HP-04 — tax stays signed, so the net does too. */
    public function test_tax_on_a_credit_only_invoice_is_a_reduction(): void
    {
        $this->actingAs($this->user(User::ROLE_REQUESTER))
            ->postJson('/api/invoices', $this->payload([['amount' => -1000, 'tax_rate' => 5]]))
            ->assertCreated()
            ->assertJsonPath('tax_amount', '-50.00')
            ->assertJsonPath('total_amount', '-1050.00');
    }

    /** HP-05 — a credit is only settleable if Finance can find it in the eligible pool. */
    public function test_a_credit_only_invoice_is_eligible_for_a_payment_request(): void
    {
        $credit = $this->postedInvoice(-5000);

        $this->actingAs($this->user(User::ROLE_FINANCE))
            ->getJson('/api/payment-requests/eligible')
            ->assertOk()
            ->assertJsonPath('data.0.id', $credit->id);
    }

    /** HP-12 — nothing traps an invoice on the credit side of zero. */
    public function test_a_credit_only_invoice_can_be_corrected_back_into_a_charge(): void
    {
        $requester = $this->user(User::ROLE_REQUESTER);

        $id = $this->actingAs($requester)
            ->postJson('/api/invoices', $this->payload([['amount' => -5000]]))
            ->assertCreated()
            ->json('id');

        $this->actingAs($requester)
            ->postJson("/api/invoices/{$id}", $this->payload([['amount' => 5000]]))
            ->assertOk()
            ->assertJsonPath('total_amount', '5000.00');
    }

    // ── Happy path: settling it in a payment request ──────────────────────────────────────────

    /** HP-06, HP-07 — the credit is settled by being grouped with the charge it offsets. */
    public function test_a_credit_is_settled_by_grouping_it_with_the_charge(): void
    {
        $this->levels();
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);

        $charge = $this->postedInvoice(20000);
        $credit = $this->postedInvoice(-5000);

        $this->actingAs($this->user(User::ROLE_FINANCE))->postJson('/api/payment-requests', [
            'invoice_ids' => [$charge->id, $credit->id],
            'approvers' => [1 => $a1->id],
        ])->assertCreated();

        $pr = PaymentRequest::first();
        $this->assertSame('15000.00', $pr->total_amount);
        $this->assertSame(PaymentRequest::STATUS_IN_APPROVAL, $pr->status);
        // Both documents are held by the request, so the credit is visibly consumed by this payment.
        $this->assertSame(Invoice::PAY_IN_APPROVAL, $credit->refresh()->payment_status);
    }

    /** HP-09, HP-10 — one credit against several charges, and several credits against one. */
    public function test_credits_and_charges_net_across_the_whole_selection(): void
    {
        $this->levels();
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $finance = $this->user(User::ROLE_FINANCE);

        $ids = collect([8000, 9000, -5000])->map(fn ($t) => $this->postedInvoice($t)->id)->all();

        $this->actingAs($finance)->postJson('/api/payment-requests', [
            'invoice_ids' => $ids,
            'approvers' => [1 => $a1->id],
        ])->assertCreated();

        $this->assertSame('12000.00', PaymentRequest::first()->total_amount);

        $more = collect([30000, -5000, -4000])->map(fn ($t) => $this->postedInvoice($t)->id)->all();

        $this->actingAs($finance)->postJson('/api/payment-requests', [
            'invoice_ids' => $more,
            'approvers' => [1 => $a1->id],
        ])->assertCreated();

        $this->assertSame('21000.00', PaymentRequest::latest('id')->first()->total_amount);
    }

    /** HP-08 — the whole cycle, on the net figure throughout. */
    public function test_a_netted_request_can_be_approved_and_paid(): void
    {
        $this->levels();
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $finance = $this->user(User::ROLE_FINANCE);

        $charge = $this->postedInvoice(20000);
        $credit = $this->postedInvoice(-5000);

        $this->actingAs($finance)->postJson('/api/payment-requests', [
            'invoice_ids' => [$charge->id, $credit->id],
            'approvers' => [1 => $a1->id],
        ])->assertCreated();

        $pr = PaymentRequest::first();

        $this->actingAs($a1)->postJson("/api/payment-requests/{$pr->id}/approve")->assertOk();
        $this->actingAs($finance)
            ->postJson("/api/payment-requests/{$pr->id}/mark-paid", ['payment_reference' => 'TT-9001'])
            ->assertOk();

        $this->assertSame(PaymentRequest::STATUS_PAID, $pr->refresh()->status);
        $this->assertSame('15000.00', $pr->total_amount);
        // The credit is settled: it is marked paid alongside the charge it reduced.
        $this->assertSame(Invoice::PAY_PAID, $credit->refresh()->payment_status);
    }

    /** HP-14 — a correction that lowers a netted request but leaves it payable is fine. */
    public function test_a_correction_that_leaves_the_request_payable_is_accepted(): void
    {
        $this->levels();
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $finance = $this->user(User::ROLE_FINANCE);

        $charge = $this->postedInvoice(20000);
        $credit = $this->postedInvoice(-5000);

        $this->actingAs($finance)->postJson('/api/payment-requests', [
            'invoice_ids' => [$charge->id, $credit->id],
            'approvers' => [1 => $a1->id],
        ])->assertCreated();

        $this->actingAs($finance)
            ->postJson("/api/invoices/{$charge->id}", $this->payload([['amount' => 10000]]))
            ->assertOk();

        $this->assertSame('5000.00', PaymentRequest::first()->total_amount);
    }

    /** HP-15 — releasing a charge is still fine while the remainder stays positive. */
    public function test_a_charge_can_be_released_while_the_request_stays_payable(): void
    {
        $this->levels();
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $finance = $this->user(User::ROLE_FINANCE);

        $keep = $this->postedInvoice(20000);
        $spare = $this->postedInvoice(9000);
        $credit = $this->postedInvoice(-5000);

        $this->actingAs($finance)->postJson('/api/payment-requests', [
            'invoice_ids' => [$keep->id, $spare->id, $credit->id],
            'approvers' => [1 => $a1->id],
        ])->assertCreated();

        $this->actingAs($finance)
            ->postJson("/api/invoices/{$spare->id}/release", ['reason' => 'Not due yet'])
            ->assertOk();

        $this->assertSame('15000.00', PaymentRequest::first()->total_amount);
    }

    // ── Negative: the request floor ───────────────────────────────────────────────────────────

    /** NEG-01 — the one invoice-level rule that survives. */
    public function test_an_invoice_that_nets_to_exactly_zero_is_still_rejected(): void
    {
        $this->actingAs($this->user(User::ROLE_REQUESTER))
            ->postJson('/api/invoices', $this->payload([['amount' => 5000], ['amount' => -5000]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');

        $this->assertSame(0, Invoice::count());
    }

    /** NEG-03, NEG-04, NEG-05, NEG-06 — a selection that pays nothing is refused. */
    public function test_a_selection_that_does_not_net_above_zero_is_refused(): void
    {
        $this->levels();
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $finance = $this->user(User::ROLE_FINANCE);

        $cases = [
            'a credit note on its own' => [-5000],
            'nets to exactly zero' => [5000, -5000],
            'nets below zero' => [5000, -6000],
            'credits with no charge' => [-5000, -2000],
        ];

        foreach ($cases as $case => $totals) {
            $ids = collect($totals)->map(fn ($t) => $this->postedInvoice($t)->id)->all();

            $this->actingAs($finance)->postJson('/api/payment-requests', [
                'invoice_ids' => $ids,
                'approvers' => [1 => $a1->id],
            ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('invoices');

            $this->assertSame(0, PaymentRequest::count(), "a request that {$case} was created anyway");
            // Nothing was consumed either — the invoices stay in the log, selectable again.
            $this->assertSame(
                [Invoice::PAY_NOT_INITIATED],
                Invoice::whereIn('id', $ids)->pluck('payment_status')->unique()->all(),
                "invoices were held by a request that {$case}",
            );

            Invoice::whereIn('id', $ids)->delete();
        }
    }

    /** NEG-03 — the refusal has to tell Finance what to do, naming the credit to offset. */
    public function test_the_refusal_names_the_credit_and_says_what_to_do(): void
    {
        $this->levels();
        $credit = $this->postedInvoice(-5000);

        $message = $this->actingAs($this->user(User::ROLE_FINANCE))
            ->postJson('/api/payment-requests', [
                'invoice_ids' => [$credit->id],
                'approvers' => [1 => $this->user(User::ROLE_APPROVER, ['approval_level' => 1])->id],
            ])
            ->assertStatus(422)
            ->json('errors.invoices.0');

        $this->assertStringContainsString('AED -5,000.00', $message);
        $this->assertStringContainsString($credit->reference_no, $message);
        $this->assertStringContainsString('also select the invoice', $message);
    }

    /**
     * NEG-07, SEC-03, SEC-06 — refused as a netting problem, not as a missing approver. The chain is
     * built before creation runs, and a non-positive total resolves no levels, so without the guard
     * ahead of the builder this would surface as "add at least one approver" and read as a bug.
     */
    public function test_the_refusal_is_reported_as_netting_not_as_a_missing_approver(): void
    {
        $this->levels();
        $credit = $this->postedInvoice(-5000);

        $this->actingAs($this->user(User::ROLE_FINANCE))
            ->postJson('/api/payment-requests', ['invoice_ids' => [$credit->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoices')
            ->assertJsonMissingValidationErrors('approvers');
    }

    /** NEG-18 — an empty selection is still an empty selection, not a netting problem. */
    public function test_an_empty_selection_is_refused_as_before(): void
    {
        $this->actingAs($this->user(User::ROLE_FINANCE))
            ->postJson('/api/payment-requests', ['invoice_ids' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoice_ids');
    }

    /** NEG-14 — a mixed-currency selection is reported as a currency problem, not a netting one. */
    public function test_a_mixed_currency_selection_is_still_refused_as_mixed(): void
    {
        Currency::firstOrCreate(['name' => 'USD'], ['is_active' => true]);
        $this->levels();

        $message = $this->actingAs($this->user(User::ROLE_FINANCE))
            ->postJson('/api/payment-requests', [
                'invoice_ids' => [$this->postedInvoice(20000)->id, $this->postedInvoice(-5000, 'USD')->id],
                'approvers' => [1 => $this->user(User::ROLE_APPROVER, ['approval_level' => 1])->id],
            ])
            ->assertStatus(422)
            ->json('errors.invoices.0');

        $this->assertStringContainsString('one currency', $message);
    }

    // ── Negative: corrections and releases cannot get under the floor ─────────────────────────

    /** NEG-08, NEG-09 — a correction may not drag an in-approval request to zero or below. */
    public function test_a_correction_cannot_take_an_in_approval_request_to_zero_or_below(): void
    {
        $this->levels();
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $finance = $this->user(User::ROLE_FINANCE);

        foreach ([['amount' => 5000], ['amount' => 3000]] as $correction) {
            $charge = $this->postedInvoice(20000);
            $credit = $this->postedInvoice(-5000);

            $this->actingAs($finance)->postJson('/api/payment-requests', [
                'invoice_ids' => [$charge->id, $credit->id],
                'approvers' => [1 => $a1->id],
            ])->assertCreated();

            // Correcting the charge down to 5,000 nets the request to exactly zero; 3,000 takes it
            // below. Both fall, so the old "the total may not rise" rule would have allowed them.
            $this->actingAs($finance)
                ->postJson("/api/invoices/{$charge->id}", $this->payload([$correction]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('items');

            $pr = PaymentRequest::first();
            $this->assertSame('15000.00', $pr->total_amount);
            $this->assertSame('20000.00', $charge->refresh()->total_amount);

            $pr->invoices()->update(['payment_request_id' => null, 'payment_status' => Invoice::PAY_NOT_INITIATED]);
            $pr->delete();
            Invoice::query()->delete();
        }
    }

    /** NEG-10 — approvals already granted must never be left covering less than is asked for. */
    public function test_a_correction_cannot_take_an_approved_request_to_zero_or_below(): void
    {
        $this->levels();
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $finance = $this->user(User::ROLE_FINANCE);

        $charge = $this->postedInvoice(20000);
        $credit = $this->postedInvoice(-5000);

        $this->actingAs($finance)->postJson('/api/payment-requests', [
            'invoice_ids' => [$charge->id, $credit->id],
            'approvers' => [1 => $a1->id],
        ])->assertCreated();

        $pr = PaymentRequest::first();
        $this->actingAs($a1)->postJson("/api/payment-requests/{$pr->id}/approve")->assertOk();
        $this->assertSame(PaymentRequest::STATUS_APPROVED, $pr->refresh()->status);

        $this->actingAs($finance)
            ->postJson("/api/invoices/{$charge->id}", $this->payload([['amount' => 2000]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');

        $this->assertSame('15000.00', $pr->refresh()->total_amount);
    }

    /** NEG-11 — releasing the only charge would leave the request asking more than it was approved for. */
    public function test_releasing_the_only_charge_from_a_netted_request_is_refused(): void
    {
        $this->levels();
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $finance = $this->user(User::ROLE_FINANCE);

        $charge = $this->postedInvoice(20000);
        $credit = $this->postedInvoice(-5000);

        $this->actingAs($finance)->postJson('/api/payment-requests', [
            'invoice_ids' => [$charge->id, $credit->id],
            'approvers' => [1 => $a1->id],
        ])->assertCreated();

        // Releasing the credit is what the existing guard refuses: it would raise the request from
        // 15,000 back to 20,000, past what its approvals cover.
        $this->actingAs($finance)
            ->postJson("/api/invoices/{$credit->id}/release", ['reason' => 'Wrongly grouped'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoice');

        $this->assertNotNull($credit->refresh()->payment_request_id);
        $this->assertSame('15000.00', PaymentRequest::first()->total_amount);
    }

    // ── Security: the amount and who has to sign for it ───────────────────────────────────────

    /** SEC-01 — approval follows the netted figure, which is the amount actually paid. */
    public function test_routing_is_measured_on_the_netted_request_total(): void
    {
        $this->levels();
        $finance = $this->user(User::ROLE_FINANCE);
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $a2 = $this->user(User::ROLE_APPROVER, ['approval_level' => 2]);
        $a3 = $this->user(User::ROLE_APPROVER, ['approval_level' => 3]);

        // A 500,000 charge settled against a 450,000 credit pays 50,000, and 50,000 is what routes.
        $charge = $this->postedInvoice(500000);
        $credit = $this->postedInvoice(-450000);

        $this->assertSame([1, 2], ApprovalLevel::requiredForInvoices([$charge, $credit])->pluck('level')->all());

        $this->actingAs($finance)->postJson('/api/payment-requests', [
            'invoice_ids' => [$charge->id, $credit->id],
            'approvers' => [1 => $a1->id, 2 => $a2->id, 3 => $a3->id],
        ])->assertCreated();

        $this->assertSame([$a1->id, $a2->id], PaymentRequest::first()->approvals->pluck('approver_id')->all());
    }

    /** SEC-02 — netting must never cost the chain a level the net figure still requires. */
    public function test_routing_never_omits_a_level_the_netted_total_requires(): void
    {
        $this->levels();
        $finance = $this->user(User::ROLE_FINANCE);
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $a2 = $this->user(User::ROLE_APPROVER, ['approval_level' => 2]);

        // 500,000 netted down to 200,000 — still over the 50,000 level, so level 2 must sign.
        $this->actingAs($finance)->postJson('/api/payment-requests', [
            'invoice_ids' => [$this->postedInvoice(500000)->id, $this->postedInvoice(-300000)->id],
            'approvers' => [1 => $a1->id, 2 => $a2->id],
        ])->assertCreated();

        $pr = PaymentRequest::first();
        $this->assertSame('200000.00', $pr->total_amount);
        $this->assertSame([$a1->id, $a2->id], $pr->approvals->pluck('approver_id')->all());
    }

    /**
     * SEC-04 — the floor is what makes a credit-only invoice safe to accept: no route reaches an
     * approved or paid request whose total is zero or less.
     */
    public function test_no_request_with_a_non_positive_total_can_exist(): void
    {
        $this->levels();
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $finance = $this->user(User::ROLE_FINANCE);

        $charge = $this->postedInvoice(20000);
        $credit = $this->postedInvoice(-5000);

        $this->actingAs($finance)->postJson('/api/payment-requests', [
            'invoice_ids' => [$charge->id, $credit->id],
            'approvers' => [1 => $a1->id],
        ])->assertCreated();

        // Every way of moving the figure afterwards, refused above, tried once more here as a set.
        $this->actingAs($finance)->postJson("/api/invoices/{$charge->id}", $this->payload([['amount' => 1000]]))->assertStatus(422);
        $this->actingAs($finance)->postJson("/api/invoices/{$credit->id}/release", ['reason' => 'x'])->assertStatus(422);

        $this->actingAs($a1)->postJson('/api/payment-requests/'.PaymentRequest::first()->id.'/approve')->assertOk();

        $this->assertTrue(PaymentRequest::where('total_amount', '<=', 0)->doesntExist());
    }

    /** SEC-07 — a widened amount range grants no new access. */
    public function test_a_credit_only_invoice_grants_no_new_access(): void
    {
        $owner = $this->user(User::ROLE_REQUESTER);
        $stranger = $this->user(User::ROLE_REQUESTER);
        $credit = $this->postedInvoice(-5000);
        $credit->update(['submitted_by' => $owner->id]);

        $this->actingAs($stranger)
            ->postJson("/api/invoices/{$credit->id}", $this->payload([['amount' => -1]]))
            ->assertForbidden();

        // And a requester still cannot turn one into a payment request.
        $this->actingAs($owner)
            ->postJson('/api/payment-requests', ['invoice_ids' => [$credit->id]])
            ->assertForbidden();
    }

    /** SEC-10 — the credit and the netting both have to be findable in the log. */
    public function test_the_audit_log_records_the_credit_and_the_netted_total(): void
    {
        $this->levels();
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);

        $id = $this->actingAs($this->user(User::ROLE_REQUESTER))
            ->postJson('/api/invoices', $this->payload([['amount' => -5000, 'description' => 'CN-88']]))
            ->assertCreated()
            ->json('id');

        $submitted = Invoice::find($id)->auditLogs()->where('action', 'submitted')->value('description');
        $this->assertStringContainsString('AED -5000.00', $submitted);
        $this->assertStringContainsString('net of AED 5,000.00 credited on 1 line', $submitted);

        Invoice::whereKey($id)->update(['status' => Invoice::STATUS_POSTED]);
        $charge = $this->postedInvoice(20000);

        $this->actingAs($this->user(User::ROLE_FINANCE))->postJson('/api/payment-requests', [
            'invoice_ids' => [$charge->id, $id],
            'approvers' => [1 => $a1->id],
        ])->assertCreated();

        $initiated = PaymentRequest::first()->auditLogs()->where('action', 'payment_initiated')->value('description');
        $this->assertStringContainsString('2 invoice(s)', $initiated);
        $this->assertStringContainsString('15000', $initiated);
    }

    // ── Regression ────────────────────────────────────────────────────────────────────────────

    /** REG-07 — an all-positive selection is untouched: no new refusal, no new message. */
    public function test_an_ordinary_all_positive_request_is_unchanged(): void
    {
        $this->levels();
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);

        $this->actingAs($this->user(User::ROLE_FINANCE))->postJson('/api/payment-requests', [
            'invoice_ids' => [$this->postedInvoice(10000)->id, $this->postedInvoice(2500)->id],
            'approvers' => [1 => $a1->id],
        ])->assertCreated();

        $this->assertSame('12500.00', PaymentRequest::first()->total_amount);
    }

    /** REG-08 — rejection returns a credit-only invoice to the log like any other. */
    public function test_rejecting_a_netted_request_returns_the_credit_to_the_log(): void
    {
        $this->levels();
        $a1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);

        $charge = $this->postedInvoice(20000);
        $credit = $this->postedInvoice(-5000);

        $this->actingAs($this->user(User::ROLE_FINANCE))->postJson('/api/payment-requests', [
            'invoice_ids' => [$charge->id, $credit->id],
            'approvers' => [1 => $a1->id],
        ])->assertCreated();

        $this->actingAs($a1)
            ->postJson('/api/payment-requests/'.PaymentRequest::first()->id.'/reject', ['comments' => 'Wrong period'])
            ->assertOk();

        $this->assertNull($credit->refresh()->payment_request_id);
        $this->assertSame(Invoice::PAY_NOT_INITIATED, $credit->payment_status);
        $this->assertTrue($credit->isPayable(), 'the credit must be selectable again');
    }
}
