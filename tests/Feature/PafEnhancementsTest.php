<?php

namespace Tests\Feature;

use App\Mail\PaymentRequestSubmitted;
use App\Models\ApprovalLevel;
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
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * The five PAF Enhancements: a mandatory line description, an approval email subject that identifies
 * the payment, the PAF available from creation rather than only after the last signature, that PAF
 * reachable from the approval link, and the optional L2-A second signature at level 2.
 *
 * CR: ai/change-requests/paf-enhancements-approval-workflow-and-email-improvements.md
 */
class PafEnhancementsTest extends TestCase
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

        ApprovalLevel::create(['level' => 1, 'name' => 'Manager', 'min_amount' => 0, 'is_active' => true]);
        ApprovalLevel::create(['level' => 2, 'name' => 'Head of Finance', 'min_amount' => 10000, 'is_active' => true]);
        ApprovalLevel::create(['level' => 3, 'name' => 'MD', 'min_amount' => 250000, 'is_active' => true]);
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
            'items' => array_map(fn ($item) => array_merge(['currency' => 'AED', 'description' => 'Test line'], $item), $items),
        ];
    }

    private function postedInvoice(User $owner, float $total, string $description = 'CCTV replacement in KIZAD'): Invoice
    {
        $invoice = Invoice::create([
            'reference_no' => 'INV-'.uniqid(),
            'vendor_id' => $this->vendor->id,
            'vendor_name' => $this->vendor->name,
            'invoice_no' => 'V-'.uniqid(),
            'invoice_date' => now()->toDateString(),
            'currency' => 'AED',
            'amount' => $total,
            'tax_amount' => 0,
            'total_amount' => $total,
            'business_unit' => 'GGH',
            'department' => 'Yard',
            'location' => 'Dubai',
            'payment_method' => 'bank_transfer',
            'priority' => 'normal',
            'status' => Invoice::STATUS_POSTED,
            'payment_status' => Invoice::PAY_NOT_INITIATED,
            'submitted_by' => $owner->id,
        ]);

        $invoice->items()->create([
            'sort_order' => 0,
            'currency' => 'AED',
            'description' => $description,
            'amount' => $total,
            'tax_amount' => 0,
            'total_amount' => $total,
        ]);

        return $invoice->refresh();
    }

    // ---------------------------------------------------------------- item 1

    public function test_a_line_without_a_description_is_refused(): void
    {
        $this->actingAs($this->user(User::ROLE_REQUESTER))
            ->postJson('/api/invoices', $this->payload([['amount' => 1000, 'description' => null]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.description');

        $this->assertSame(0, Invoice::count());
    }

    public function test_whitespace_alone_is_not_a_description(): void
    {
        $this->actingAs($this->user(User::ROLE_REQUESTER))
            ->postJson('/api/invoices', $this->payload([['amount' => 1000, 'description' => '   ']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.description');
    }

    public function test_every_line_is_checked_not_just_the_first(): void
    {
        $this->actingAs($this->user(User::ROLE_REQUESTER))
            ->postJson('/api/invoices', $this->payload([
                ['amount' => 1000],
                ['amount' => 500, 'description' => ''],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.1.description')
            ->assertJsonMissingValidationErrors('items.0.description');
    }

    // ---------------------------------------------------------------- item 2

    public function test_the_approval_email_subject_identifies_the_payment(): void
    {
        $requester = $this->user(User::ROLE_REQUESTER);
        $invoice = $this->postedInvoice($requester, 8142.75, 'CCTV replacement in KIZAD');
        $pr = $this->routed($invoice, [['level' => 1]]);

        $subject = (new PaymentRequestSubmitted($pr->fresh()))->envelope()->subject;

        $this->assertStringContainsString('CCTV replacement in KIZAD', $subject);
        $this->assertStringContainsString('Al Noor Logistics', $subject);
        $this->assertStringContainsString('8,142.75', $subject);
        $this->assertStringContainsString($pr->reference_no, $subject);
    }

    public function test_a_request_spanning_two_vendors_is_labelled_rather_than_mislabelled(): void
    {
        $other = Vendor::create(['name' => 'Gulf Marine', 'vendor_code' => 'GM-1', 'is_active' => true]);
        $requester = $this->user(User::ROLE_REQUESTER);

        $a = $this->postedInvoice($requester, 500);
        $b = $this->postedInvoice($requester, 500);
        $b->update(['vendor_id' => $other->id, 'vendor_name' => $other->name]);

        $pr = $this->routed([$a, $b], [['level' => 1]]);

        // The first vendor alone would read as the whole request's payee, which it is not.
        $this->assertStringContainsString('+ 1 more', $pr->fresh()->subjectSummary());
    }

    public function test_a_long_purpose_is_shortened_so_the_reference_survives(): void
    {
        $requester = $this->user(User::ROLE_REQUESTER);
        $invoice = $this->postedInvoice($requester, 500, str_repeat('Very long description ', 20));
        $pr = $this->routed($invoice, [['level' => 1]]);

        $summary = $pr->fresh()->subjectSummary();

        $this->assertStringContainsString($pr->reference_no, $summary);
        $this->assertStringContainsString('Al Noor Logistics', $summary);
        $this->assertLessThan(140, strlen($summary));
    }

    // ---------------------------------------------------------------- item 3

    public function test_the_paf_is_available_while_the_request_is_still_in_approval(): void
    {
        $finance = $this->user(User::ROLE_FINANCE);
        $invoice = $this->postedInvoice($this->user(User::ROLE_REQUESTER), 500);
        $pr = $this->routed($invoice, [['level' => 1]]);

        $this->assertSame(PaymentRequest::STATUS_IN_APPROVAL, $pr->status);

        $this->actingAs($finance)
            ->get("/api/payment-requests/{$pr->id}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_a_user_who_cannot_see_the_request_cannot_fetch_its_paf(): void
    {
        $invoice = $this->postedInvoice($this->user(User::ROLE_REQUESTER), 500);
        $pr = $this->routed($invoice, [['level' => 1]]);

        // A requester sees only requests carrying one of their own invoices.
        $this->actingAs($this->user(User::ROLE_REQUESTER))
            ->get("/api/payment-requests/{$pr->id}/pdf")
            ->assertForbidden();
    }

    public function test_an_unapproved_paf_is_stamped_so_it_cannot_pass_as_an_authorisation(): void
    {
        $invoice = $this->postedInvoice($this->user(User::ROLE_REQUESTER), 500);
        $pr = $this->routed($invoice, [['level' => 1]]);

        $html = $this->renderSheet($pr);

        $this->assertStringContainsString('PENDING APPROVAL', $html);
        $this->assertStringContainsString('not an authorisation to pay', $html);
    }

    public function test_an_approved_paf_carries_no_draft_stamp(): void
    {
        $invoice = $this->postedInvoice($this->user(User::ROLE_REQUESTER), 500);
        $pr = $this->routed($invoice, [['level' => 1]]);
        $pr->update(['status' => PaymentRequest::STATUS_APPROVED, 'current_stage' => null, 'approved_at' => now()]);

        $html = $this->renderSheet($pr->refresh());

        $this->assertStringNotContainsString('PENDING APPROVAL', $html);
        // The class is always defined in the stylesheet; what must be absent is an element using it.
        $this->assertStringNotContainsString('class="draft-banner"', $html);
        $this->assertStringNotContainsString('class="draft-mark"', $html);
    }

    public function test_a_rejected_paf_is_stamped_as_rejected(): void
    {
        $invoice = $this->postedInvoice($this->user(User::ROLE_REQUESTER), 500);
        $pr = $this->routed($invoice, [['level' => 1]]);
        $pr->update(['status' => PaymentRequest::STATUS_REJECTED, 'rejected_at' => now()]);

        $this->assertStringContainsString('REJECTED', $this->renderSheet($pr->refresh()));
    }

    private function renderSheet(PaymentRequest $pr): string
    {
        return View::make('pdf.payment-request', [
            'paymentRequest' => $pr->load('invoices.items', 'approvals.approver', 'creator'),
            'supplierCodes' => collect(),
            'approvalLevels' => ApprovalLevel::where('is_active', true)->with('defaultApprover')->orderBy('level')->get(),
        ])->render();
    }

    // ---------------------------------------------------------------- item 4

    public function test_the_approval_page_embeds_the_paf(): void
    {
        $invoice = $this->postedInvoice($this->user(User::ROLE_REQUESTER), 500);
        $pr = $this->routed($invoice, [['level' => 1]]);
        $stage = $pr->approvals()->first();

        $this->get("/prf/view/{$pr->id}/{$stage->view_token}")
            ->assertOk()
            ->assertSee("/prf/view/{$pr->id}/{$stage->view_token}/paf", false);
    }

    public function test_the_paf_link_serves_the_document_inline(): void
    {
        $invoice = $this->postedInvoice($this->user(User::ROLE_REQUESTER), 500);
        $pr = $this->routed($invoice, [['level' => 1]]);
        $stage = $pr->approvals()->first();

        $response = $this->get("/prf/view/{$pr->id}/{$stage->view_token}/paf");

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));
    }

    public function test_the_paf_link_refuses_a_token_that_belongs_to_no_stage(): void
    {
        $invoice = $this->postedInvoice($this->user(User::ROLE_REQUESTER), 500);
        $pr = $this->routed($invoice, [['level' => 1]]);

        $this->get("/prf/view/{$pr->id}/".str_repeat('a', 64).'/paf')->assertNotFound();
    }

    // ---------------------------------------------------------------- item 5

    public function test_l2a_signs_straight_after_l2(): void
    {
        $finance = $this->user(User::ROLE_FINANCE);
        $l1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1, 'job_title' => 'Manager']);
        $l2 = $this->user(User::ROLE_APPROVER, ['approval_level' => 2, 'job_title' => 'Head of Finance']);
        $l2a = $this->user(User::ROLE_APPROVER, ['approval_level' => 2, 'job_title' => 'Business Unit Controller']);

        $invoice = $this->postedInvoice($this->user(User::ROLE_REQUESTER), 20000);

        $this->actingAs($finance)->postJson('/api/payment-requests', [
            'invoice_ids' => [$invoice->id],
            'approvers' => [1 => $l1->id, 2 => $l2->id],
            'l2a_approver_id' => $l2a->id,
        ])->assertCreated();

        $chain = PaymentRequest::first()->approvals()->orderBy('sequence')->get();

        $this->assertSame([$l1->id, $l2->id, $l2a->id], $chain->pluck('approver_id')->all());
        $this->assertNull($chain[2]->level, 'L2-A must not claim a level — the PAF keys required approvers by level.');
        $this->assertTrue((bool) $chain[2]->is_adhoc);
        $this->assertStringStartsWith('L2-A', $chain[2]->label);
    }

    public function test_l2a_lands_before_a_general_additional_approver(): void
    {
        $finance = $this->user(User::ROLE_FINANCE);
        $l1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $l2 = $this->user(User::ROLE_APPROVER, ['approval_level' => 2]);
        $l2a = $this->user(User::ROLE_APPROVER, ['approval_level' => 2]);
        $extra = $this->user(User::ROLE_APPROVER, ['approval_level' => 3]);

        $invoice = $this->postedInvoice($this->user(User::ROLE_REQUESTER), 20000);

        $this->actingAs($finance)->postJson('/api/payment-requests', [
            'invoice_ids' => [$invoice->id],
            'approvers' => [1 => $l1->id, 2 => $l2->id],
            'l2a_approver_id' => $l2a->id,
            'adhoc_approvers' => [['approver_id' => $extra->id, 'label' => 'Legal review']],
        ])->assertCreated();

        $this->assertSame(
            [$l1->id, $l2->id, $l2a->id, $extra->id],
            PaymentRequest::first()->approvals()->orderBy('sequence')->pluck('approver_id')->all(),
        );
    }

    public function test_l2a_is_refused_when_the_chain_does_not_reach_level_two(): void
    {
        $finance = $this->user(User::ROLE_FINANCE);
        $l1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $l2a = $this->user(User::ROLE_APPROVER, ['approval_level' => 2]);

        // 500 is under the level-2 threshold, so there is no L2 for L2-A to sit behind.
        $invoice = $this->postedInvoice($this->user(User::ROLE_REQUESTER), 500);

        $this->actingAs($finance)->postJson('/api/payment-requests', [
            'invoice_ids' => [$invoice->id],
            'approvers' => [1 => $l1->id],
            'l2a_approver_id' => $l2a->id,
        ])->assertStatus(422)->assertJsonValidationErrors('l2a_approver_id');

        $this->assertSame(0, PaymentRequest::count());
    }

    public function test_the_same_person_cannot_hold_l2_and_l2a(): void
    {
        $finance = $this->user(User::ROLE_FINANCE);
        $l1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $l2 = $this->user(User::ROLE_APPROVER, ['approval_level' => 2]);

        $invoice = $this->postedInvoice($this->user(User::ROLE_REQUESTER), 20000);

        $this->actingAs($finance)->postJson('/api/payment-requests', [
            'invoice_ids' => [$invoice->id],
            'approvers' => [1 => $l1->id, 2 => $l2->id],
            'l2a_approver_id' => $l2->id,
        ])->assertStatus(422)->assertJsonValidationErrors('l2a_approver_id');
    }

    public function test_an_empty_l2a_leaves_the_chain_exactly_as_it_was(): void
    {
        $finance = $this->user(User::ROLE_FINANCE);
        $l1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $l2 = $this->user(User::ROLE_APPROVER, ['approval_level' => 2]);

        $invoice = $this->postedInvoice($this->user(User::ROLE_REQUESTER), 20000);

        $this->actingAs($finance)->postJson('/api/payment-requests', [
            'invoice_ids' => [$invoice->id],
            'approvers' => [1 => $l1->id, 2 => $l2->id],
            'l2a_approver_id' => null,
        ])->assertCreated();

        $this->assertSame(
            [$l1->id, $l2->id],
            PaymentRequest::first()->approvals()->orderBy('sequence')->pluck('approver_id')->all(),
        );
    }

    /**
     * Route one or more invoices through a chain built straight from the service, for the cases that
     * are about the resulting request rather than about how the chain was assembled.
     *
     * @param  Invoice|array<int, Invoice>  $invoices
     * @param  array<int, array{level: int}>  $stages
     */
    private function routed($invoices, array $stages): PaymentRequest
    {
        $invoices = is_array($invoices) ? $invoices : [$invoices];
        $finance = $this->user(User::ROLE_FINANCE);

        $pr = PaymentRequest::create([
            'reference_no' => PaymentRequest::nextReferenceNo(),
            'created_by' => $finance->id,
            'status' => PaymentRequest::STATUS_IN_APPROVAL,
            'current_stage' => 1,
            'total_amount' => collect($invoices)->sum('total_amount'),
            'sent_at' => now(),
        ]);

        foreach ($stages as $i => $stage) {
            PaymentRequestApproval::create([
                'payment_request_id' => $pr->id,
                'sequence' => $i + 1,
                'level' => $stage['level'],
                'label' => 'Approver',
                'is_adhoc' => false,
                'approver_id' => $this->user(User::ROLE_APPROVER, ['approval_level' => $stage['level']])->id,
                'status' => PaymentRequestApproval::STATUS_PENDING,
            ]);
        }

        Invoice::whereIn('id', collect($invoices)->pluck('id'))->update([
            'payment_request_id' => $pr->id,
            'payment_status' => Invoice::PAY_IN_APPROVAL,
        ]);

        return $pr->refresh();
    }
}
