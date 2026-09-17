<?php

namespace Tests\Feature;

use App\Models\ApprovalLevel;
use App\Models\BusinessUnit;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\InvoiceDocument;
use App\Models\Location;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestApproval;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * An advance pays before the business has what it paid for, and its vendor tax invoice arrives after
 * the payment is done — the point at which everything else on the record is deliberately frozen.
 *
 * The marker is the easy half. The half that matters is the upload route: it exists so evidence can
 * still reach a closed record, and most of what follows is there to prove it cannot do anything
 * else. Approved figures stay approved.
 *
 * CR: ai/change-requests/flag-advance-payment-invoices-and-allow-final-invoice-upload.md
 */
class AdvancePaymentInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        BusinessUnit::create(['name' => 'GGH', 'is_active' => true]);
        Department::create(['name' => 'Yard', 'is_active' => true]);
        Location::create(['name' => 'Dubai', 'is_active' => true]);
        Currency::firstOrCreate(['name' => 'AED']);
        $this->vendor = Vendor::create(['name' => 'Al Noor Logistics', 'vendor_code' => 'ALN-1', 'is_active' => true]);

        ApprovalLevel::create(['level' => 1, 'name' => 'Manager', 'min_amount' => 0, 'is_active' => true]);
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

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): array
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
            'items' => [['currency' => 'AED', 'description' => 'Deposit against LPO', 'amount' => 5000, 'tax_rate' => 0]],
            ...$overrides,
        ];
    }

    private function invoice(User $owner, array $attributes = []): Invoice
    {
        $invoice = Invoice::create([
            'reference_no' => 'INV-'.uniqid(),
            'vendor_id' => $this->vendor->id,
            'vendor_name' => $this->vendor->name,
            'invoice_no' => 'V-'.uniqid(),
            'invoice_date' => now()->toDateString(),
            'currency' => 'AED',
            'amount' => 5000,
            'tax_amount' => 0,
            'total_amount' => 5000,
            'business_unit' => 'GGH',
            'department' => 'Yard',
            'location' => 'Dubai',
            'payment_method' => 'bank_transfer',
            'priority' => 'normal',
            'status' => Invoice::STATUS_POSTED,
            'payment_status' => Invoice::PAY_NOT_INITIATED,
            'submitted_by' => $owner->id,
            ...$attributes,
        ]);

        $invoice->items()->create([
            'sort_order' => 0, 'currency' => 'AED', 'description' => 'Deposit against LPO',
            'amount' => 5000, 'tax_amount' => 0, 'total_amount' => 5000,
        ]);

        return $invoice->refresh();
    }

    /** An advance sitting in a fully approved, paid payment request — the state this change is for. */
    private function paidAdvance(User $owner): Invoice
    {
        $invoice = $this->invoice($owner, ['is_advance_payment' => true]);

        $pr = PaymentRequest::create([
            'reference_no' => PaymentRequest::nextReferenceNo(),
            'created_by' => $this->user(User::ROLE_FINANCE)->id,
            'status' => PaymentRequest::STATUS_PAID,
            'total_amount' => 5000,
            'sent_at' => now(),
            'approved_at' => now(),
            'paid_at' => now(),
        ]);

        PaymentRequestApproval::create([
            'payment_request_id' => $pr->id, 'sequence' => 1, 'level' => 1, 'label' => 'Manager',
            'approver_id' => $this->user(User::ROLE_APPROVER, ['approval_level' => 1])->id,
            'status' => PaymentRequestApproval::STATUS_APPROVED, 'acted_at' => now(),
        ]);

        $invoice->update(['payment_request_id' => $pr->id, 'payment_status' => Invoice::PAY_PAID]);

        return $invoice->refresh();
    }

    // ------------------------------------------------------------ the marker

    public function test_an_invoice_can_be_raised_as_an_advance_payment(): void
    {
        $this->actingAs($this->user(User::ROLE_REQUESTER))
            ->postJson('/api/invoices', $this->payload(['is_advance_payment' => true]))
            ->assertCreated()
            ->assertJsonPath('is_advance_payment', true);
    }

    public function test_an_invoice_raised_without_the_marker_is_an_ordinary_payable(): void
    {
        $this->actingAs($this->user(User::ROLE_REQUESTER))
            ->postJson('/api/invoices', $this->payload())
            ->assertCreated()
            ->assertJsonPath('is_advance_payment', false);
    }

    /**
     * The invoice form posts multipart, and FormData stringifies everything it is given, so the
     * marker reaches the server as a string rather than a JSON boolean. `postJson` would send a real
     * boolean and never exercise that — which is exactly how "the is advance payment field must be
     * true or false" reached the screen on a form that had just been tested.
     */
    public function test_the_marker_survives_a_multipart_submission(): void
    {
        $requester = $this->user(User::ROLE_REQUESTER);

        // Every form a browser might send, including the "true"/"false" that FormData produces and
        // that the `boolean` validation rule refuses.
        foreach ([['1', true], ['0', false], ['true', true], ['false', false], ['on', true]] as [$sent, $expected]) {
            $this->actingAs($requester)
                ->post('/api/invoices', $this->payload(['is_advance_payment' => $sent]))
                ->assertCreated()
                ->assertJsonPath('is_advance_payment', $expected);
        }
    }

    public function test_an_invoice_submits_with_the_marker_left_off(): void
    {
        // The switch is off far more often than on, so this is the common path, not an edge case.
        $this->actingAs($this->user(User::ROLE_REQUESTER))
            ->post('/api/invoices', $this->payload(['is_advance_payment' => '0']))
            ->assertCreated()
            ->assertJsonPath('is_advance_payment', false);
    }

    public function test_the_marker_can_be_set_and_cleared_while_the_invoice_is_editable(): void
    {
        $owner = $this->user(User::ROLE_REQUESTER);
        $invoice = $this->invoice($owner, ['status' => Invoice::STATUS_SUBMITTED]);

        $this->actingAs($owner)
            ->postJson("/api/invoices/{$invoice->id}", $this->payload(['is_advance_payment' => true]))
            ->assertOk();
        $this->assertTrue($invoice->refresh()->is_advance_payment);

        $this->actingAs($owner)
            ->postJson("/api/invoices/{$invoice->id}", $this->payload(['is_advance_payment' => false]))
            ->assertOk();
        $this->assertFalse($invoice->refresh()->is_advance_payment);
    }

    public function test_the_marker_cannot_be_changed_once_a_payment_request_holds_the_invoice(): void
    {
        $invoice = $this->invoice($this->user(User::ROLE_REQUESTER), [
            'is_advance_payment' => true,
            'payment_status' => Invoice::PAY_IN_APPROVAL,
        ]);

        $this->actingAs($this->user(User::ROLE_FINANCE))
            ->postJson("/api/invoices/{$invoice->id}", $this->payload(['is_advance_payment' => false]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_advance_payment');

        $this->assertTrue($invoice->refresh()->is_advance_payment, 'The marker is part of what was approved.');
    }

    public function test_the_log_can_be_filtered_to_advance_payments(): void
    {
        $finance = $this->user(User::ROLE_FINANCE);
        $advance = $this->invoice($finance, ['is_advance_payment' => true]);
        $ordinary = $this->invoice($finance);

        $advances = $this->actingAs($finance)->getJson('/api/invoices?advance_payment=1')->assertOk()->json('data');
        $this->assertSame([$advance->id], array_column($advances, 'id'));

        $ordinaries = $this->actingAs($finance)->getJson('/api/invoices?advance_payment=0')->assertOk()->json('data');
        $this->assertSame([$ordinary->id], array_column($ordinaries, 'id'));

        $all = $this->actingAs($finance)->getJson('/api/invoices')->assertOk()->json('data');
        $this->assertCount(2, $all, 'No filter means no filtering.');
    }

    public function test_the_paf_states_that_the_request_is_an_advance(): void
    {
        $invoice = $this->paidAdvance($this->user(User::ROLE_REQUESTER));

        $html = View::make('pdf.payment-request', [
            'paymentRequest' => $invoice->paymentRequest->load('invoices.items', 'approvals.approver', 'creator'),
            'supplierCodes' => collect(),
            'approvalLevels' => ApprovalLevel::where('is_active', true)->with('defaultApprover')->get(),
        ])->render();

        $this->assertStringContainsString('ADVANCE PAYMENT', $html);
    }

    // ------------------------------------------------------------ late upload

    public function test_finance_can_attach_the_final_vendor_invoice_after_payment(): void
    {
        $invoice = $this->paidAdvance($this->user(User::ROLE_REQUESTER));

        $this->actingAs($this->user(User::ROLE_FINANCE))
            ->post("/api/invoices/{$invoice->id}/documents", [
                'documents' => [UploadedFile::fake()->create('final-tax-invoice.pdf', 40, 'application/pdf')],
            ])
            ->assertOk();

        $document = $invoice->documents()->sole();
        $this->assertSame('final-tax-invoice.pdf', $document->original_name);
        $this->assertTrue($document->uploaded_after_approval);
    }

    public function test_the_submitter_can_attach_to_their_own_paid_advance(): void
    {
        $owner = $this->user(User::ROLE_REQUESTER);
        $invoice = $this->paidAdvance($owner);

        $this->actingAs($owner)
            ->post("/api/invoices/{$invoice->id}/documents", [
                'documents' => [UploadedFile::fake()->create('tax-invoice.pdf', 10, 'application/pdf')],
            ])
            ->assertOk();

        $this->assertSame(1, $invoice->documents()->count());
    }

    public function test_a_late_upload_is_audited_as_its_own_action(): void
    {
        $invoice = $this->paidAdvance($this->user(User::ROLE_REQUESTER));

        $this->actingAs($this->user(User::ROLE_FINANCE))
            ->post("/api/invoices/{$invoice->id}/documents", [
                'documents' => [UploadedFile::fake()->create('tax-invoice.pdf', 10, 'application/pdf')],
            ])->assertOk();

        $this->assertSame(1, $invoice->auditLogs()->where('action', 'document_uploaded_after_approval')->count());
    }

    /** The whole point of the route: evidence in, nothing else. */
    public function test_the_upload_route_changes_nothing_but_the_documents(): void
    {
        $invoice = $this->paidAdvance($this->user(User::ROLE_REQUESTER));
        // Compared as the database holds them: `only()` hands back Carbon instances, and two equal
        // dates are never the same object.
        $snapshot = fn (Invoice $i) => $i->newQuery()->whereKey($i->id)->first()->getAttributes();
        $prSnapshot = fn (PaymentRequest $p) => $p->newQuery()->whereKey($p->id)->first()->getAttributes();

        $before = $snapshot($invoice);
        $prBefore = $prSnapshot($invoice->paymentRequest);

        $this->actingAs($this->user(User::ROLE_FINANCE))
            ->post("/api/invoices/{$invoice->id}/documents", [
                // Everything below is an attempt to smuggle an edit through the upload route.
                'documents' => [UploadedFile::fake()->create('tax-invoice.pdf', 10, 'application/pdf')],
                'amount' => 999999,
                'total_amount' => 999999,
                'currency' => 'USD',
                'invoice_no' => 'REWRITTEN',
                'status' => Invoice::STATUS_SUBMITTED,
                'payment_status' => Invoice::PAY_NOT_INITIATED,
                'is_advance_payment' => false,
                'items' => [['currency' => 'USD', 'description' => 'x', 'amount' => 999999]],
            ])
            ->assertOk();

        $this->assertSame($before, $snapshot($invoice), 'The upload route must not touch the invoice.');
        $this->assertSame($prBefore, $prSnapshot($invoice->paymentRequest), 'Nor its payment request.');
        $this->assertSame(1, $invoice->items()->count());
        $this->assertSame('5000.00', (string) $invoice->items()->sole()->total_amount);
    }

    public function test_a_late_upload_does_not_make_the_invoice_payable_again(): void
    {
        $invoice = $this->paidAdvance($this->user(User::ROLE_REQUESTER));

        $this->actingAs($this->user(User::ROLE_FINANCE))
            ->post("/api/invoices/{$invoice->id}/documents", [
                'documents' => [UploadedFile::fake()->create('tax-invoice.pdf', 10, 'application/pdf')],
            ])->assertOk();

        $this->assertFalse($invoice->refresh()->isPayable());

        $eligible = $this->actingAs($this->user(User::ROLE_FINANCE))
            ->getJson('/api/payment-requests/eligible')->assertOk()->json('data');

        $this->assertNotContains($invoice->id, array_column($eligible, 'id'));
    }

    // ------------------------------------------------------------ what is refused

    public function test_a_paid_ordinary_invoice_stays_closed(): void
    {
        $owner = $this->user(User::ROLE_REQUESTER);
        $invoice = $this->paidAdvance($owner);
        $invoice->update(['is_advance_payment' => false]);

        $this->actingAs($this->user(User::ROLE_FINANCE))
            ->post("/api/invoices/{$invoice->id}/documents", [
                'documents' => [UploadedFile::fake()->create('late.pdf', 10, 'application/pdf')],
            ])
            ->assertStatus(422);

        $this->assertSame(0, $invoice->documents()->count());
    }

    public function test_an_unrelated_user_cannot_attach_to_someone_elses_advance(): void
    {
        $invoice = $this->paidAdvance($this->user(User::ROLE_REQUESTER));

        $this->actingAs($this->user(User::ROLE_REQUESTER))
            ->post("/api/invoices/{$invoice->id}/documents", [
                'documents' => [UploadedFile::fake()->create('late.pdf', 10, 'application/pdf')],
            ])
            ->assertForbidden();
    }

    public function test_an_approver_on_the_request_cannot_attach(): void
    {
        $invoice = $this->paidAdvance($this->user(User::ROLE_REQUESTER));
        $approver = $invoice->paymentRequest->approvals()->sole()->approver;

        $this->actingAs($approver)
            ->post("/api/invoices/{$invoice->id}/documents", [
                'documents' => [UploadedFile::fake()->create('late.pdf', 10, 'application/pdf')],
            ])
            ->assertForbidden();
    }

    public function test_a_cancelled_advance_is_closed(): void
    {
        $invoice = $this->invoice($this->user(User::ROLE_REQUESTER), [
            'is_advance_payment' => true,
            'status' => Invoice::STATUS_CANCELLED,
            'payment_status' => Invoice::PAY_PAID,
        ]);

        $this->actingAs($this->user(User::ROLE_FINANCE))
            ->post("/api/invoices/{$invoice->id}/documents", [
                'documents' => [UploadedFile::fake()->create('late.pdf', 10, 'application/pdf')],
            ])
            ->assertStatus(422);
    }

    public function test_an_upload_with_no_file_is_refused(): void
    {
        $invoice = $this->paidAdvance($this->user(User::ROLE_REQUESTER));

        $this->actingAs($this->user(User::ROLE_FINANCE))
            ->postJson("/api/invoices/{$invoice->id}/documents", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('documents');

        $this->assertSame(0, $invoice->documents()->count());
    }

    public function test_a_disallowed_file_type_is_refused(): void
    {
        $invoice = $this->paidAdvance($this->user(User::ROLE_REQUESTER));

        $this->actingAs($this->user(User::ROLE_FINANCE))
            ->post("/api/invoices/{$invoice->id}/documents", [
                'documents' => [UploadedFile::fake()->create('payload.exe', 10)],
            ])
            ->assertStatus(422);

        $this->assertSame(0, $invoice->documents()->count());
    }

    public function test_the_document_limit_counts_what_is_already_attached(): void
    {
        $invoice = $this->paidAdvance($this->user(User::ROLE_REQUESTER));
        $finance = $this->user(User::ROLE_FINANCE);

        foreach (range(1, (int) config('paf.max_documents')) as $i) {
            InvoiceDocument::create([
                'invoice_id' => $invoice->id, 'uploaded_by' => $finance->id,
                'original_name' => "existing-{$i}.pdf", 'file_path' => "invoices/{$invoice->id}/e{$i}.pdf",
                'mime_type' => 'application/pdf', 'size' => 10,
            ]);
        }

        $this->actingAs($finance)
            ->post("/api/invoices/{$invoice->id}/documents", [
                'documents' => [UploadedFile::fake()->create('one-too-many.pdf', 10, 'application/pdf')],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('documents');
    }

    public function test_amounts_are_still_locked_on_a_paid_advance(): void
    {
        $invoice = $this->paidAdvance($this->user(User::ROLE_REQUESTER));

        // The ordinary edit route is unchanged: paid is paid, advance or not.
        $this->actingAs($this->user(User::ROLE_FINANCE))
            ->postJson("/api/invoices/{$invoice->id}", $this->payload([
                'is_advance_payment' => true,
                'items' => [['currency' => 'AED', 'description' => 'Deposit', 'amount' => 1]],
            ]))
            ->assertStatus(422);

        $this->assertSame('5000.00', (string) $invoice->refresh()->total_amount);
    }
}
