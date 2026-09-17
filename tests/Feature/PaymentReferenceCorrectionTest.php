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
 * The payment reference is the transfer or cheque number, keyed by hand when a request is marked
 * paid, and it is what reconciles a PAF payment to the bank statement. It could only ever be set
 * once: `markPaid` refuses a request that is not `approved`, so one already paid can never pass
 * through it again, and nothing else writes the field — a typo was permanent.
 *
 * Correcting it is restricted to the user who recorded the payment, or an admin. Correcting is not
 * re-paying: `paid_by`, `paid_at` and the `paid` status stand, and every correction is logged with
 * the value it replaced.
 *
 * CR: ai/change-requests/correct-the-payment-reference-on-a-paid-request.md
 */
class PaymentReferenceCorrectionTest extends TestCase
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
        ApprovalLevel::create(['level' => 1, 'name' => 'Manager', 'min_amount' => 0, 'is_active' => true]);
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

    private function postedInvoice(float $total = 10000): Invoice
    {
        return Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_id' => $this->vendor->id, 'vendor_name' => $this->vendor->name,
            'invoice_no' => 'INV-'.uniqid(),
            'invoice_date' => now()->toDateString(),
            'currency' => 'AED', 'amount' => $total, 'tax_amount' => 0, 'total_amount' => $total,
            'business_unit' => 'GGH', 'department' => 'Yard', 'location' => 'Dubai',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => Invoice::STATUS_POSTED, 'payment_status' => Invoice::PAY_NOT_INITIATED,
            'submitted_by' => $this->user(User::ROLE_REQUESTER)->id, 'submitted_at' => now(),
        ]);
    }

    /** A request taken all the way through to paid, through the real endpoints. */
    private function paid(User $payer, string $reference = 'TT-9001', ?User $creator = null): PaymentRequest
    {
        $creator ??= $this->user(User::ROLE_FINANCE);
        $approver = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);

        $this->actingAs($creator)->postJson('/api/payment-requests', [
            'invoice_ids' => [$this->postedInvoice()->id],
            'approvers' => [1 => $approver->id],
        ])->assertCreated();

        $pr = PaymentRequest::latest('id')->first();

        $this->actingAs($approver)->postJson("/api/payment-requests/{$pr->id}/approve")->assertOk();
        $this->actingAs($payer)
            ->postJson("/api/payment-requests/{$pr->id}/mark-paid", ['payment_reference' => $reference])
            ->assertOk();

        return $pr->refresh();
    }

    // ── Happy path ────────────────────────────────────────────────────────────────────────────

    /** The gap this closes: a mistyped payment reference was permanent. */
    public function test_the_payer_can_correct_a_mistyped_payment_reference(): void
    {
        $payer = $this->user(User::ROLE_FINANCE);
        $pr = $this->paid($payer, 'TT-9001');

        $this->actingAs($payer)
            ->postJson("/api/payment-requests/{$pr->id}/payment-reference", ['payment_reference' => 'TT-9002'])
            ->assertOk()
            ->assertJsonPath('payment_reference', 'TT-9002');

        $this->assertSame('TT-9002', $pr->refresh()->payment_reference);
    }

    /** An admin is the way through once the person who recorded the payment has left. */
    public function test_an_admin_can_correct_a_payment_they_did_not_record(): void
    {
        $payer = $this->user(User::ROLE_FINANCE);
        $pr = $this->paid($payer, 'TT-9001');

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->postJson("/api/payment-requests/{$pr->id}/payment-reference", ['payment_reference' => 'TT-9002'])
            ->assertOk();

        $this->assertSame('TT-9002', $pr->refresh()->payment_reference);
    }

    /** Correcting is not re-paying: who recorded the payment, and when, is a fact left alone. */
    public function test_correcting_does_not_rewrite_who_recorded_the_payment(): void
    {
        $payer = $this->user(User::ROLE_FINANCE);
        $pr = $this->paid($payer, 'TT-9001');
        $paidAt = $pr->paid_at;

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->postJson("/api/payment-requests/{$pr->id}/payment-reference", ['payment_reference' => 'TT-9002'])
            ->assertOk();

        $pr->refresh();
        $this->assertSame($payer->id, $pr->paid_by, 'the admin must not become the payer');
        $this->assertEquals($paidAt, $pr->paid_at);
        $this->assertSame(PaymentRequest::STATUS_PAID, $pr->status);
    }

    /** One transfer can settle several requests, so a shared reference is normal, not a clash. */
    public function test_two_requests_may_share_a_payment_reference(): void
    {
        $payer = $this->user(User::ROLE_FINANCE);
        $first = $this->paid($payer, 'TT-7000');
        $second = $this->paid($payer, 'TT-9001');

        $this->actingAs($payer)
            ->postJson("/api/payment-requests/{$second->id}/payment-reference", ['payment_reference' => 'TT-7000'])
            ->assertOk();

        $this->assertSame('TT-7000', $second->refresh()->payment_reference);
        $this->assertSame('TT-7000', $first->refresh()->payment_reference);
    }

    /** Correcting it twice is fine — each correction is its own logged event. */
    public function test_the_reference_can_be_corrected_more_than_once(): void
    {
        $payer = $this->user(User::ROLE_FINANCE);
        $pr = $this->paid($payer, 'TT-9001');

        foreach (['TT-9002', 'TT-9003'] as $reference) {
            $this->actingAs($payer)
                ->postJson("/api/payment-requests/{$pr->id}/payment-reference", ['payment_reference' => $reference])
                ->assertOk();
        }

        $this->assertSame('TT-9003', $pr->refresh()->payment_reference);
        $this->assertSame(2, $pr->auditLogs()->where('action', 'payment_reference_corrected')->count());
    }

    // ── Negative ──────────────────────────────────────────────────────────────────────────────

    /** Another Finance user cannot correct someone else's payment. */
    public function test_another_finance_user_cannot_correct_the_reference(): void
    {
        $payer = $this->user(User::ROLE_FINANCE);
        $pr = $this->paid($payer, 'TT-9001');

        $this->actingAs($this->user(User::ROLE_FINANCE))
            ->postJson("/api/payment-requests/{$pr->id}/payment-reference", ['payment_reference' => 'TT-9002'])
            ->assertForbidden();

        $this->assertSame('TT-9001', $pr->refresh()->payment_reference);
    }

    /** Nor can the person who raised the request, unless they also recorded the payment. */
    public function test_the_request_creator_cannot_correct_the_reference(): void
    {
        $creator = $this->user(User::ROLE_FINANCE);
        $payer = $this->user(User::ROLE_FINANCE);
        $pr = $this->paid($payer, 'TT-9001', $creator);

        $this->assertSame($creator->id, $pr->created_by);

        $this->actingAs($creator)
            ->postJson("/api/payment-requests/{$pr->id}/payment-reference", ['payment_reference' => 'TT-9002'])
            ->assertForbidden();

        $this->assertSame('TT-9001', $pr->refresh()->payment_reference);
    }

    /** The refusal has to say who to ask. */
    public function test_the_refusal_names_the_person_who_recorded_the_payment(): void
    {
        $payer = $this->user(User::ROLE_FINANCE);
        $pr = $this->paid($payer, 'TT-9001');

        $message = $this->actingAs($this->user(User::ROLE_FINANCE))
            ->postJson("/api/payment-requests/{$pr->id}/payment-reference", ['payment_reference' => 'TT-9002'])
            ->assertForbidden()
            ->json('message');

        $this->assertStringContainsString($payer->name, $message);
    }

    /** Nobody outside Finance reaches the endpoint — the route is gated as mark-paid is. */
    public function test_a_requester_or_approver_cannot_reach_the_endpoint(): void
    {
        $payer = $this->user(User::ROLE_FINANCE);
        $pr = $this->paid($payer, 'TT-9001');

        foreach ([User::ROLE_REQUESTER, User::ROLE_APPROVER] as $role) {
            $this->actingAs($this->user($role))
                ->postJson("/api/payment-requests/{$pr->id}/payment-reference", ['payment_reference' => 'TT-9002'])
                ->assertForbidden();
        }

        $this->assertSame('TT-9001', $pr->refresh()->payment_reference);
    }

    /**
     * There is no reference until the payment is recorded — "when there is a value" is the whole
     * precondition, and every pre-paid status is refused.
     */
    public function test_a_request_that_is_not_paid_has_no_reference_to_correct(): void
    {
        $finance = $this->user(User::ROLE_FINANCE);
        $approver = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);

        $this->actingAs($finance)->postJson('/api/payment-requests', [
            'invoice_ids' => [$this->postedInvoice()->id],
            'approvers' => [1 => $approver->id],
        ])->assertCreated();

        $pr = PaymentRequest::latest('id')->first();

        // In approval.
        $this->actingAs($finance)
            ->postJson("/api/payment-requests/{$pr->id}/payment-reference", ['payment_reference' => 'TT-9002'])
            ->assertStatus(422);

        $this->actingAs($approver)->postJson("/api/payment-requests/{$pr->id}/approve")->assertOk();

        // Approved but unpaid.
        $this->actingAs($finance)
            ->postJson("/api/payment-requests/{$pr->id}/payment-reference", ['payment_reference' => 'TT-9002'])
            ->assertStatus(422);

        $this->assertNull($pr->refresh()->payment_reference);
    }

    /** A rejected or withdrawn request never had a payment either. */
    public function test_a_rejected_request_has_no_reference_to_correct(): void
    {
        $finance = $this->user(User::ROLE_FINANCE);
        $approver = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);

        $this->actingAs($finance)->postJson('/api/payment-requests', [
            'invoice_ids' => [$this->postedInvoice()->id],
            'approvers' => [1 => $approver->id],
        ])->assertCreated();

        $pr = PaymentRequest::latest('id')->first();

        $this->actingAs($approver)
            ->postJson("/api/payment-requests/{$pr->id}/reject", ['comments' => 'Wrong period'])
            ->assertOk();

        $this->actingAs($finance)
            ->postJson("/api/payment-requests/{$pr->id}/payment-reference", ['payment_reference' => 'TT-9002'])
            ->assertStatus(422);

        $this->assertNull($pr->refresh()->payment_reference);
    }

    /** The reference is required, and bounded exactly as it is on mark-paid. */
    public function test_the_reference_is_validated(): void
    {
        $payer = $this->user(User::ROLE_FINANCE);
        $pr = $this->paid($payer, 'TT-9001');

        foreach ([[], ['payment_reference' => ''], ['payment_reference' => str_repeat('9', 101)]] as $payload) {
            $this->actingAs($payer)
                ->postJson("/api/payment-requests/{$pr->id}/payment-reference", $payload)
                ->assertStatus(422)
                ->assertJsonValidationErrors('payment_reference');
        }

        $this->assertSame('TT-9001', $pr->refresh()->payment_reference);
    }

    // ── Security ──────────────────────────────────────────────────────────────────────────────

    /** The action reaches no money: the amount, the invoices and their status stand. */
    public function test_no_amount_or_invoice_is_affected(): void
    {
        $payer = $this->user(User::ROLE_FINANCE);
        $pr = $this->paid($payer, 'TT-9001');
        $total = $pr->total_amount;

        $this->actingAs($payer)
            ->postJson("/api/payment-requests/{$pr->id}/payment-reference", ['payment_reference' => 'TT-9002'])
            ->assertOk();

        $pr->refresh();
        $this->assertSame($total, $pr->total_amount);
        $this->assertSame(
            [Invoice::PAY_PAID],
            $pr->invoices()->pluck('payment_status')->unique()->all(),
        );
    }

    /** No approval is touched — the chain and its recorded decisions are untouched. */
    public function test_no_approval_is_affected(): void
    {
        $payer = $this->user(User::ROLE_FINANCE);
        $pr = $this->paid($payer, 'TT-9001');
        $before = $pr->approvals()->orderBy('sequence')->get(['sequence', 'approver_id', 'status', 'acted_at']);

        $this->actingAs($payer)
            ->postJson("/api/payment-requests/{$pr->id}/payment-reference", ['payment_reference' => 'TT-9002'])
            ->assertOk();

        $after = $pr->refresh()->approvals()->orderBy('sequence')->get(['sequence', 'approver_id', 'status', 'acted_at']);
        $this->assertEquals($before->toArray(), $after->toArray());
    }

    /** The correction has to be recoverable and attributable. */
    public function test_the_correction_is_audited_with_the_previous_value(): void
    {
        $payer = $this->user(User::ROLE_FINANCE);
        $pr = $this->paid($payer, 'TT-9001');

        $this->actingAs($payer)
            ->postJson("/api/payment-requests/{$pr->id}/payment-reference", ['payment_reference' => 'TT-9002'])
            ->assertOk();

        $log = $pr->auditLogs()->where('action', 'payment_reference_corrected')->first();

        $this->assertNotNull($log, 'correcting a payment reference must be audited');
        $this->assertSame('TT-9001', $log->old_values['payment_reference']);
        $this->assertSame('TT-9002', $log->new_values['payment_reference']);
        $this->assertStringContainsString('TT-9001 → TT-9002', $log->description);
        $this->assertStringContainsString($payer->name, $log->description);
        $this->assertSame($payer->id, $log->user_id);
        $this->assertSame($pr->id, $log->payment_request_id);
    }

    /** The original payment entry stays as it was — the correction is its own event. */
    public function test_the_original_payment_entry_is_left_intact(): void
    {
        $payer = $this->user(User::ROLE_FINANCE);
        $pr = $this->paid($payer, 'TT-9001');

        $this->actingAs($payer)
            ->postJson("/api/payment-requests/{$pr->id}/payment-reference", ['payment_reference' => 'TT-9002'])
            ->assertOk();

        $paidEntry = $pr->auditLogs()->where('action', 'paid')->first();

        $this->assertStringContainsString('TT-9001', $paidEntry->description);
    }

    // ── Regression ────────────────────────────────────────────────────────────────────────────

    /** Marking paid is untouched. */
    public function test_marking_paid_still_works_as_before(): void
    {
        $payer = $this->user(User::ROLE_FINANCE);
        $pr = $this->paid($payer, 'TT-9001');

        $this->assertSame(PaymentRequest::STATUS_PAID, $pr->status);
        $this->assertSame('TT-9001', $pr->payment_reference);
        $this->assertSame($payer->id, $pr->paid_by);
        $this->assertNotNull($pr->paid_at);
    }

    /** A paid request still cannot be marked paid again — correction is the route, not a re-pay. */
    public function test_a_paid_request_still_cannot_be_marked_paid_again(): void
    {
        $payer = $this->user(User::ROLE_FINANCE);
        $pr = $this->paid($payer, 'TT-9001');

        $this->actingAs($payer)
            ->postJson("/api/payment-requests/{$pr->id}/mark-paid", ['payment_reference' => 'TT-9002'])
            ->assertStatus(422);

        $this->assertSame('TT-9001', $pr->refresh()->payment_reference);
    }
}
