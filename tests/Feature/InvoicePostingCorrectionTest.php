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
 * The ERP document number is typed by hand from the ERP, so it can be keyed wrong, and until now a
 * posted invoice was stuck with it: `post()` refuses an invoice that is no longer `submitted`, and
 * `update()` never touches the posting fields.
 *
 * The number is what reconciles a PAF payment back to the ERP document, and it is printed on the
 * payment request, so it has to be correctable. Only by the person who recorded the posting, though
 * — or an admin, who is the way through once that person has left.
 *
 * CR: ai/change-requests/correct-the-erp-document-number-on-a-posted-invoice.md
 */
class InvoicePostingCorrectionTest extends TestCase
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

    private function invoice(string $status = Invoice::STATUS_SUBMITTED, array $extra = []): Invoice
    {
        return Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_id' => $this->vendor->id, 'vendor_name' => $this->vendor->name,
            'invoice_no' => 'INV-'.uniqid(),
            'invoice_date' => now()->toDateString(),
            'currency' => 'AED', 'amount' => 10000, 'tax_amount' => 0, 'total_amount' => 10000,
            'business_unit' => 'GGH', 'department' => 'Yard', 'location' => 'Dubai',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => $status, 'payment_status' => Invoice::PAY_NOT_INITIATED,
            'submitted_by' => $this->user(User::ROLE_REQUESTER)->id, 'submitted_at' => now(),
            ...$extra,
        ]);
    }

    /** An invoice posted by $poster, through the real posting endpoint. */
    private function posted(User $poster, string $docNo = '5112345678'): Invoice
    {
        $invoice = $this->invoice();

        $this->actingAs($poster)
            ->postJson("/api/invoices/{$invoice->id}/post", ['erp_doc_no' => $docNo, 'posting_date' => '2026-09-01'])
            ->assertOk();

        return $invoice->refresh();
    }

    // ── Happy path ────────────────────────────────────────────────────────────────────────────

    /** The gap this closes: a mistyped document number was permanent. */
    public function test_the_poster_can_correct_a_mistyped_erp_document_number(): void
    {
        $finance = $this->user(User::ROLE_FINANCE);
        $invoice = $this->posted($finance, '5112345678');

        $this->actingAs($finance)
            ->postJson("/api/invoices/{$invoice->id}/posting", ['erp_doc_no' => '5187654321'])
            ->assertOk()
            ->assertJsonPath('erp_doc_no', '5187654321');

        $this->assertSame('5187654321', $invoice->refresh()->erp_doc_no);
    }

    /** The posting date is keyed at the same moment, so it is correctable the same way. */
    public function test_the_posting_date_can_be_corrected_too(): void
    {
        $finance = $this->user(User::ROLE_FINANCE);
        $invoice = $this->posted($finance);

        $this->actingAs($finance)
            ->postJson("/api/invoices/{$invoice->id}/posting", [
                'erp_doc_no' => '5112345678',
                'posting_date' => '2026-08-15',
            ])
            ->assertOk();

        $this->assertSame('2026-08-15', $invoice->refresh()->posting_date->toDateString());
    }

    /** Omitting the date leaves it alone rather than silently resetting it to today. */
    public function test_omitting_the_posting_date_keeps_the_recorded_one(): void
    {
        $finance = $this->user(User::ROLE_FINANCE);
        $invoice = $this->posted($finance);

        $this->actingAs($finance)
            ->postJson("/api/invoices/{$invoice->id}/posting", ['erp_doc_no' => '5199999999'])
            ->assertOk();

        $this->assertSame('2026-09-01', $invoice->refresh()->posting_date->toDateString());
    }

    /** An admin is the way through when the person who posted it has left. */
    public function test_an_admin_can_correct_a_posting_they_did_not_make(): void
    {
        $finance = $this->user(User::ROLE_FINANCE);
        $invoice = $this->posted($finance);

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->postJson("/api/invoices/{$invoice->id}/posting", ['erp_doc_no' => '5187654321'])
            ->assertOk();

        $this->assertSame('5187654321', $invoice->refresh()->erp_doc_no);
    }

    /** Correcting is not re-posting: who posted it, and when, is a fact the correction leaves alone. */
    public function test_correcting_does_not_rewrite_who_posted_it(): void
    {
        $finance = $this->user(User::ROLE_FINANCE);
        $admin = $this->user(User::ROLE_ADMIN);
        $invoice = $this->posted($finance);
        $postedAt = $invoice->posted_at;

        $this->actingAs($admin)
            ->postJson("/api/invoices/{$invoice->id}/posting", ['erp_doc_no' => '5187654321'])
            ->assertOk();

        $invoice->refresh();
        $this->assertSame($finance->id, $invoice->posted_by, 'the admin must not become the poster');
        $this->assertEquals($postedAt, $invoice->posted_at);
        $this->assertSame(Invoice::STATUS_POSTED, $invoice->status);
    }

    /**
     * The number is a reference to an external document, not an amount — and reconciliation against
     * the ERP, where a wrong one actually surfaces, happens after the money has moved.
     */
    public function test_the_number_can_be_corrected_at_any_payment_status(): void
    {
        ApprovalLevel::create(['level' => 1, 'name' => 'Manager', 'min_amount' => 0, 'is_active' => true]);
        $finance = $this->user(User::ROLE_FINANCE);
        $approver = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $invoice = $this->posted($finance);

        $this->actingAs($finance)->postJson('/api/payment-requests', [
            'invoice_ids' => [$invoice->id],
            'approvers' => [1 => $approver->id],
        ])->assertCreated();

        $pr = PaymentRequest::first();

        // In approval.
        $this->actingAs($finance)
            ->postJson("/api/invoices/{$invoice->id}/posting", ['erp_doc_no' => '5100000001'])
            ->assertOk();

        $this->actingAs($approver)->postJson("/api/payment-requests/{$pr->id}/approve")->assertOk();

        // Approved.
        $this->actingAs($finance)
            ->postJson("/api/invoices/{$invoice->id}/posting", ['erp_doc_no' => '5100000002'])
            ->assertOk();

        $this->actingAs($finance)
            ->postJson("/api/payment-requests/{$pr->id}/mark-paid", ['payment_reference' => 'TT-9001'])
            ->assertOk();

        // Paid — the case that matters most, because that is when the ERP is reconciled.
        $this->actingAs($finance)
            ->postJson("/api/invoices/{$invoice->id}/posting", ['erp_doc_no' => '5100000003'])
            ->assertOk();

        $this->assertSame('5100000003', $invoice->refresh()->erp_doc_no);
        // Nothing about the payment moved.
        $this->assertSame(PaymentRequest::STATUS_PAID, $pr->refresh()->status);
        $this->assertSame('10000.00', $pr->total_amount);
        $this->assertSame(Invoice::PAY_PAID, $invoice->payment_status);
    }

    // ── Negative ──────────────────────────────────────────────────────────────────────────────

    /** Another Finance user cannot correct someone else's posting. */
    public function test_another_finance_user_cannot_correct_the_posting(): void
    {
        $poster = $this->user(User::ROLE_FINANCE);
        $other = $this->user(User::ROLE_FINANCE);
        $invoice = $this->posted($poster, '5112345678');

        $this->actingAs($other)
            ->postJson("/api/invoices/{$invoice->id}/posting", ['erp_doc_no' => '5187654321'])
            ->assertForbidden();

        $this->assertSame('5112345678', $invoice->refresh()->erp_doc_no);
    }

    /** The refusal has to say who to ask. */
    public function test_the_refusal_names_the_person_who_posted_it(): void
    {
        $poster = $this->user(User::ROLE_FINANCE);
        $invoice = $this->posted($poster);

        $message = $this->actingAs($this->user(User::ROLE_FINANCE))
            ->postJson("/api/invoices/{$invoice->id}/posting", ['erp_doc_no' => '5187654321'])
            ->assertForbidden()
            ->json('message');

        $this->assertStringContainsString($poster->name, $message);
    }

    /** Nobody outside Finance reaches the endpoint at all — the route is gated as `post` is. */
    public function test_a_requester_or_approver_cannot_reach_the_endpoint(): void
    {
        $finance = $this->user(User::ROLE_FINANCE);
        $invoice = $this->posted($finance, '5112345678');

        foreach ([User::ROLE_REQUESTER, User::ROLE_APPROVER] as $role) {
            $this->actingAs($this->user($role))
                ->postJson("/api/invoices/{$invoice->id}/posting", ['erp_doc_no' => '5187654321'])
                ->assertForbidden();
        }

        $this->assertSame('5112345678', $invoice->refresh()->erp_doc_no);
    }

    /** Even the submitter of the invoice cannot touch its posting details. */
    public function test_the_submitter_cannot_correct_the_posting(): void
    {
        $finance = $this->user(User::ROLE_FINANCE);
        $invoice = $this->posted($finance, '5112345678');

        $this->actingAs(User::find($invoice->submitted_by))
            ->postJson("/api/invoices/{$invoice->id}/posting", ['erp_doc_no' => '5187654321'])
            ->assertForbidden();

        $this->assertSame('5112345678', $invoice->refresh()->erp_doc_no);
    }

    /** There is nothing to correct until the invoice is posted. */
    public function test_an_unposted_invoice_has_no_posting_to_correct(): void
    {
        $finance = $this->user(User::ROLE_FINANCE);

        foreach ([Invoice::STATUS_SUBMITTED, Invoice::STATUS_QUERY, Invoice::STATUS_CANCELLED] as $status) {
            $invoice = $this->invoice($status);

            $this->actingAs($finance)
                ->postJson("/api/invoices/{$invoice->id}/posting", ['erp_doc_no' => '5187654321'])
                ->assertStatus(422);

            $this->assertNull($invoice->refresh()->erp_doc_no, "a {$status} invoice was given a document number");
        }
    }

    /**
     * A queried invoice that still carries an ERP document is the stranded state `raiseQuery` used
     * to allow. Correcting the number is not the way out of it — resolving the query is.
     */
    public function test_a_queried_invoice_carrying_a_document_number_cannot_be_corrected(): void
    {
        $finance = $this->user(User::ROLE_FINANCE);
        $invoice = $this->posted($finance, '5112345678');
        $invoice->update(['status' => Invoice::STATUS_QUERY]);

        $this->actingAs($finance)
            ->postJson("/api/invoices/{$invoice->id}/posting", ['erp_doc_no' => '5187654321'])
            ->assertStatus(422);

        $this->assertSame('5112345678', $invoice->refresh()->erp_doc_no);
    }

    /** The document number is required, and bounded as it is on posting. */
    public function test_the_document_number_is_validated(): void
    {
        $finance = $this->user(User::ROLE_FINANCE);
        $invoice = $this->posted($finance, '5112345678');

        foreach ([[], ['erp_doc_no' => ''], ['erp_doc_no' => str_repeat('9', 101)]] as $payload) {
            $this->actingAs($finance)
                ->postJson("/api/invoices/{$invoice->id}/posting", $payload)
                ->assertStatus(422)
                ->assertJsonValidationErrors('erp_doc_no');
        }

        $this->actingAs($finance)
            ->postJson("/api/invoices/{$invoice->id}/posting", ['erp_doc_no' => '5187654321', 'posting_date' => 'not-a-date'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('posting_date');

        $this->assertSame('5112345678', $invoice->refresh()->erp_doc_no);
    }

    // ── Audit ─────────────────────────────────────────────────────────────────────────────────

    /** A change to a posted record has to be traceable, with the value it replaced. */
    public function test_the_correction_is_audited_with_the_previous_values(): void
    {
        $finance = $this->user(User::ROLE_FINANCE);
        $invoice = $this->posted($finance, '5112345678');

        $this->actingAs($finance)
            ->postJson("/api/invoices/{$invoice->id}/posting", [
                'erp_doc_no' => '5187654321',
                'posting_date' => '2026-08-15',
            ])
            ->assertOk();

        $log = $invoice->auditLogs()->where('action', 'posting_corrected')->first();

        $this->assertNotNull($log, 'correcting posting details must be audited');
        $this->assertSame('5112345678', $log->old_values['erp_doc_no']);
        $this->assertSame('2026-09-01', $log->old_values['posting_date']);
        $this->assertSame('5187654321', $log->new_values['erp_doc_no']);
        $this->assertSame('2026-08-15', $log->new_values['posting_date']);
        $this->assertStringContainsString('5112345678 → 5187654321', $log->description);
        $this->assertStringContainsString('2026-09-01 → 2026-08-15', $log->description);
        $this->assertSame($finance->id, $log->user_id);
    }

    /** The original posting entry stays as it was — the correction is its own event. */
    public function test_the_original_posting_entry_is_left_intact(): void
    {
        $finance = $this->user(User::ROLE_FINANCE);
        $invoice = $this->posted($finance, '5112345678');

        $this->actingAs($finance)
            ->postJson("/api/invoices/{$invoice->id}/posting", ['erp_doc_no' => '5187654321'])
            ->assertOk();

        $posted = $invoice->auditLogs()->where('action', 'posted')->first();

        $this->assertStringContainsString('5112345678', $posted->description);
        $this->assertSame(2, $invoice->auditLogs()->whereIn('action', ['posted', 'posting_corrected'])->count());
    }

    /** A date left unchanged should not be reported as if it moved. */
    public function test_an_unchanged_date_is_not_reported_as_a_change(): void
    {
        $finance = $this->user(User::ROLE_FINANCE);
        $invoice = $this->posted($finance, '5112345678');

        $this->actingAs($finance)
            ->postJson("/api/invoices/{$invoice->id}/posting", [
                'erp_doc_no' => '5187654321',
                'posting_date' => '2026-09-01',
            ])
            ->assertOk();

        $description = $invoice->auditLogs()->where('action', 'posting_corrected')->value('description');

        $this->assertStringContainsString('ERP doc 5112345678 → 5187654321', $description);
        $this->assertStringNotContainsString('posting date', $description);
    }

    // ── Regression ────────────────────────────────────────────────────────────────────────────

    /** Posting itself is untouched. */
    public function test_posting_an_invoice_still_works_as_before(): void
    {
        $finance = $this->user(User::ROLE_FINANCE);
        $invoice = $this->invoice();

        $this->actingAs($finance)
            ->postJson("/api/invoices/{$invoice->id}/post", ['erp_doc_no' => '5112345678'])
            ->assertOk()
            ->assertJsonPath('erp_doc_no', '5112345678')
            ->assertJsonPath('status', Invoice::STATUS_POSTED);

        $this->assertSame($finance->id, $invoice->refresh()->posted_by);
        $this->assertSame(now()->toDateString(), $invoice->posting_date->toDateString());
    }

    /** Re-posting a posted invoice is still refused — correction is the route, not a second post. */
    public function test_a_posted_invoice_still_cannot_be_re_posted(): void
    {
        $finance = $this->user(User::ROLE_FINANCE);
        $invoice = $this->posted($finance, '5112345678');

        $this->actingAs($finance)
            ->postJson("/api/invoices/{$invoice->id}/post", ['erp_doc_no' => '5187654321'])
            ->assertStatus(422);

        $this->assertSame('5112345678', $invoice->refresh()->erp_doc_no);
    }
}
