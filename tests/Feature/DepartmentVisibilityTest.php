<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Invoice;
use App\Models\InvoiceDocument;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestApproval;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Everyone sees, read-only, the invoices raised by members of their own department — the
 * submitter's department, not the one picked on the invoice — and the PRFs holding them. Other
 * departments stay hidden, a blank department matches nobody, and no write right comes with it.
 *
 * CR: ai/change-requests/show-paf-invoices-to-all-users-in-the-same-department.md
 */
class DepartmentVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $submitter;

    private User $teammate;

    private User $outsider;

    private User $finance;

    private User $approver;

    private Invoice $invoice;

    private PaymentRequest $pr;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        foreach (['Customs Clearance', 'Yard', 'Land Department', 'Finance'] as $name) {
            Department::create(['name' => $name, 'is_active' => true]);
        }

        $this->submitter = $this->user(User::ROLE_REQUESTER, 'Customs Clearance');
        $this->teammate = $this->user(User::ROLE_REQUESTER, 'Customs Clearance');
        $this->outsider = $this->user(User::ROLE_REQUESTER, 'Yard');
        $this->finance = $this->user(User::ROLE_FINANCE, 'Finance');
        $this->approver = $this->user(User::ROLE_APPROVER, 'Finance');

        $this->pr = PaymentRequest::create([
            'reference_no' => PaymentRequest::nextReferenceNo(),
            'created_by' => $this->finance->id,
            'status' => PaymentRequest::STATUS_IN_APPROVAL,
            'current_stage' => 1,
            'total_amount' => 1000,
            'sent_at' => now(),
        ]);
        PaymentRequestApproval::create([
            'payment_request_id' => $this->pr->id, 'sequence' => 1, 'level' => 1, 'label' => 'Level 1',
            'approver_id' => $this->approver->id, 'status' => PaymentRequestApproval::STATUS_PENDING,
        ]);

        $this->invoice = $this->invoice($this->submitter, 'Customs Clearance', $this->pr);
    }

    private function user(string $role, ?string $department): User
    {
        return User::create([
            'name' => ucfirst($role).' '.uniqid(),
            'email' => $role.uniqid().'@t.local',
            'password' => 'password',
            'role' => $role,
            'department' => $department,
            'is_active' => true,
            'approval_level' => $role === User::ROLE_APPROVER ? 1 : null,
        ]);
    }

    private function invoice(User $by, string $department, ?PaymentRequest $pr = null): Invoice
    {
        $invoice = Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => 'Atlas Maritime Shipping', 'invoice_no' => 'INV-'.uniqid(),
            'invoice_date' => now()->subDays(5)->toDateString(),
            'currency' => 'AED', 'amount' => 1000, 'tax_amount' => 0, 'total_amount' => 1000,
            'business_unit' => 'GGL', 'department' => $department, 'location' => 'Dubai-Jafza',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => $pr ? Invoice::STATUS_POSTED : Invoice::STATUS_SUBMITTED,
            'payment_status' => $pr ? Invoice::PAY_IN_APPROVAL : Invoice::PAY_NOT_INITIATED,
            'submitted_by' => $by->id, 'submitted_at' => now()->subDays(2),
            'payment_request_id' => $pr?->id,
        ]);
        $invoice->items()->create([
            'sort_order' => 0, 'currency' => 'AED', 'description' => 'Customs duty',
            'amount' => 1000, 'tax_amount' => 0, 'total_amount' => 1000,
        ]);

        return $invoice;
    }

    private function visibleInvoiceIds(User $user): array
    {
        $this->actingAs($user);

        return collect($this->getJson('/api/invoices')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    }

    public function test_a_teammate_sees_the_invoice_its_documents_and_its_payment_request(): void
    {
        $path = UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf')->store('documents', 'local');
        $document = InvoiceDocument::create([
            'invoice_id' => $this->invoice->id, 'uploaded_by' => $this->submitter->id,
            'original_name' => 'invoice.pdf', 'file_path' => $path, 'mime_type' => 'application/pdf', 'size' => 10,
        ]);
        $this->actingAs($this->teammate);

        $this->getJson('/api/invoices')->assertOk()->assertJsonPath('data.0.id', $this->invoice->id);
        $this->getJson("/api/invoices/{$this->invoice->id}")->assertOk();
        $this->get("/api/documents/{$document->id}/download")->assertOk();
        $this->getJson('/api/payment-requests')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/payment-requests/{$this->pr->id}")->assertOk()->assertJsonMissingPath('documents');
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('cards.total_invoices', 1);
        $this->getJson('/api/reports')->assertOk()->assertJsonCount(1, 'rows.data');

        // "My invoices" still means mine.
        $this->getJson('/api/invoices?mine=1')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_another_department_sees_nothing(): void
    {
        $this->actingAs($this->outsider);

        $this->getJson('/api/invoices')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/invoices/{$this->invoice->id}")->assertForbidden();
        $this->getJson('/api/payment-requests')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/payment-requests/{$this->pr->id}")->assertForbidden();
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('cards.total_invoices', 0);
    }

    public function test_the_submitters_department_decides_not_the_one_picked_on_the_invoice(): void
    {
        // A Customs Clearance user files an invoice for Land Department.
        $filedElsewhere = $this->invoice($this->submitter, 'Land Department');
        $landMember = $this->user(User::ROLE_REQUESTER, 'Land Department');

        $this->assertContains($filedElsewhere->id, $this->visibleInvoiceIds($this->teammate));
        $this->assertNotContains($filedElsewhere->id, $this->visibleInvoiceIds($landMember));
    }

    public function test_a_blank_department_matches_nobody(): void
    {
        $loneA = $this->user(User::ROLE_REQUESTER, null);
        $loneB = $this->user(User::ROLE_REQUESTER, '');
        $own = $this->invoice($loneA, 'Yard');
        $this->invoice($loneB, 'Yard');

        $this->assertSame([$own->id], $this->visibleInvoiceIds($loneA));
    }

    public function test_department_names_match_without_regard_to_case_or_spacing(): void
    {
        $miscased = $this->user(User::ROLE_REQUESTER, ' customs clearance ');

        $this->assertSame([$this->invoice->id], $this->visibleInvoiceIds($miscased));
    }

    public function test_a_former_members_invoices_stay_with_the_department(): void
    {
        $this->submitter->update(['is_active' => false]);

        $this->assertSame([$this->invoice->id], $this->visibleInvoiceIds($this->teammate));
    }

    public function test_visibility_follows_a_department_move(): void
    {
        $this->teammate->update(['department' => 'Yard']);

        $this->assertSame([], $this->visibleInvoiceIds($this->teammate->fresh()));
    }

    public function test_a_teammate_cannot_change_the_invoice(): void
    {
        $editable = $this->invoice($this->submitter, 'Customs Clearance');
        $this->actingAs($this->teammate);

        $this->postJson("/api/invoices/{$editable->id}", ['description' => 'changed'])->assertForbidden();
        $this->postJson("/api/invoices/{$editable->id}/cancel")->assertForbidden();
        $this->deleteJson("/api/invoices/{$editable->id}")->assertForbidden();
        $this->postJson("/api/invoices/{$editable->id}/documents", [
            'documents' => [UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')],
        ])->assertForbidden();
        $this->postJson("/api/invoices/{$editable->id}/post", ['erp_doc_no' => '1', 'posting_date' => now()->toDateString()])->assertForbidden();
        $this->postJson("/api/payment-requests/{$this->pr->id}/approve")->assertForbidden();
        $this->postJson("/api/payment-requests/{$this->pr->id}/reject", ['comments' => 'no'])->assertForbidden();

        $this->assertSame(Invoice::STATUS_SUBMITTED, $editable->fresh()->status);
        $this->assertNull($editable->fresh()->description);
        $this->assertSame(PaymentRequest::STATUS_IN_APPROVAL, $this->pr->fresh()->status);
    }

    public function test_department_visibility_is_for_requesters_only(): void
    {
        // An approver in the same department keeps what they had: own submissions and what is
        // routed to them — not their department's invoices.
        $customsApprover = $this->user(User::ROLE_APPROVER, 'Customs Clearance');

        $this->assertSame([], $this->visibleInvoiceIds($customsApprover));
        $this->getJson("/api/invoices/{$this->invoice->id}")->assertForbidden();
        $this->getJson("/api/payment-requests/{$this->pr->id}")->assertForbidden();
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('cards.total_invoices', 0);
    }

    public function test_an_approver_still_does_not_see_the_prf_holding_their_own_invoice(): void
    {
        // Unchanged from before department visibility: an approver's PRF list is what is routed to
        // them (or granted), even when a PRF holds an invoice they submitted.
        $customsApprover = $this->user(User::ROLE_APPROVER, 'Customs Clearance');
        $own = $this->invoice($customsApprover, 'Customs Clearance', $this->pr);

        $this->assertSame([$own->id], $this->visibleInvoiceIds($customsApprover));
        $this->getJson("/api/payment-requests/{$this->pr->id}")->assertForbidden();
    }

    public function test_a_requester_sees_invoices_raised_by_anyone_in_their_department(): void
    {
        // The viewer must be a requester; the submitter may hold any role.
        $customsApprover = $this->user(User::ROLE_APPROVER, 'Customs Clearance');
        $byApprover = $this->invoice($customsApprover, 'Customs Clearance');

        $this->assertContains($byApprover->id, $this->visibleInvoiceIds($this->teammate));
    }

    public function test_finance_still_sees_everything(): void
    {
        $yardInvoice = $this->invoice($this->outsider, 'Yard');

        $this->assertSame([$this->invoice->id, $yardInvoice->id], $this->visibleInvoiceIds($this->finance));
    }
}
