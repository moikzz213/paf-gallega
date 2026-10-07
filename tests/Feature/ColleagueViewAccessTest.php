<?php

namespace Tests\Feature;

use App\Models\AuditLog;
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
 * An admin can give a user read-only sight of named colleagues' invoices and the payment requests
 * holding them. It widens what the user sees on every screen and nothing they can do.
 *
 * CR: ai/change-requests/grant-view-only-access-to-paf-records-of-nominated-colleagues.md
 */
class ColleagueViewAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $viewer;

    private User $colleague;

    private User $stranger;

    private User $finance;

    private User $approver;

    private Invoice $colleagueInvoice;

    private Invoice $strangerInvoice;

    private PaymentRequest $pr;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Department::create(['name' => 'Customs Clearance', 'is_active' => true]);

        $this->admin = $this->user(User::ROLE_ADMIN);
        $this->viewer = $this->user(User::ROLE_REQUESTER);
        $this->colleague = $this->user(User::ROLE_REQUESTER);
        $this->stranger = $this->user(User::ROLE_REQUESTER);
        $this->finance = $this->user(User::ROLE_FINANCE);
        $this->approver = $this->user(User::ROLE_APPROVER);

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

        $this->colleagueInvoice = $this->invoice($this->colleague, Invoice::STATUS_POSTED, $this->pr);
        $this->strangerInvoice = $this->invoice($this->stranger);
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

    private function invoice(User $by, string $status = Invoice::STATUS_SUBMITTED, ?PaymentRequest $pr = null): Invoice
    {
        $invoice = Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => 'Atlas Maritime Shipping', 'invoice_no' => 'INV-'.uniqid(),
            'invoice_date' => now()->subDays(5)->toDateString(),
            'currency' => 'AED', 'amount' => 1000, 'tax_amount' => 0, 'total_amount' => 1000,
            'business_unit' => 'GGL', 'department' => 'Customs Clearance', 'location' => 'Dubai-Jafza',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => $status,
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

    private function grant(User $viewer, User ...$colleagues): void
    {
        $viewer->viewableColleagues()->sync(array_map(fn (User $c) => $c->id, $colleagues));
    }

    private function document(Invoice $invoice): InvoiceDocument
    {
        $path = UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf')->store('documents', 'local');

        return InvoiceDocument::create([
            'invoice_id' => $invoice->id, 'uploaded_by' => $invoice->submitted_by,
            'original_name' => 'invoice.pdf', 'file_path' => $path, 'mime_type' => 'application/pdf', 'size' => 10,
        ]);
    }

    public function test_without_a_grant_a_requester_sees_only_their_own_records(): void
    {
        $this->actingAs($this->viewer);

        $this->getJson('/api/invoices')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/invoices/{$this->colleagueInvoice->id}")->assertForbidden();
        $this->getJson('/api/payment-requests')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/payment-requests/{$this->pr->id}")->assertForbidden();
    }

    public function test_a_grant_shows_the_colleagues_invoices_and_their_payment_requests(): void
    {
        $this->grant($this->viewer, $this->colleague);
        $own = $this->invoice($this->viewer);
        $this->actingAs($this->viewer);

        $ids = collect($this->getJson('/api/invoices')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$this->colleagueInvoice->id, $own->id], $ids);

        $this->getJson("/api/invoices/{$this->colleagueInvoice->id}")->assertOk();
        $this->getJson("/api/invoices/{$this->strangerInvoice->id}")->assertForbidden();

        // "My invoices" still means mine.
        $this->getJson('/api/invoices?mine=1')->assertOk()->assertJsonCount(1, 'data');

        $this->getJson('/api/payment-requests')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/payment-requests/{$this->pr->id}")->assertOk();
    }

    public function test_a_grant_widens_the_dashboard_and_reports(): void
    {
        $this->grant($this->viewer, $this->colleague);
        $this->actingAs($this->viewer);

        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('cards.total_invoices', 1);
        $this->getJson('/api/reports')->assertOk()->assertJsonCount(1, 'rows.data');
    }

    public function test_a_grant_opens_the_colleagues_invoice_documents_but_not_a_strangers(): void
    {
        $this->grant($this->viewer, $this->colleague);
        $mine = $this->document($this->colleagueInvoice);
        $theirs = $this->document($this->strangerInvoice);
        $this->actingAs($this->viewer);

        $this->get("/api/documents/{$mine->id}/download")->assertOk();
        $this->get("/api/documents/{$theirs->id}/download")->assertForbidden();
    }

    public function test_a_guest_cannot_download_an_invoice_document(): void
    {
        $document = $this->document($this->colleagueInvoice);

        $this->getJson("/api/documents/{$document->id}/download")->assertUnauthorized();
    }

    public function test_request_level_documents_stay_hidden_from_the_viewer(): void
    {
        $this->grant($this->viewer, $this->colleague);
        $this->actingAs($this->viewer);

        $this->getJson("/api/payment-requests/{$this->pr->id}")->assertOk()->assertJsonMissingPath('documents');
    }

    public function test_the_grant_confers_no_right_to_change_the_colleagues_records(): void
    {
        $editable = $this->invoice($this->colleague);
        $document = $this->document($editable);
        $this->grant($this->viewer, $this->colleague);
        $this->actingAs($this->viewer);

        $this->postJson("/api/invoices/{$editable->id}", ['description' => 'changed'])->assertForbidden();
        $this->postJson("/api/invoices/{$editable->id}/cancel")->assertForbidden();
        $this->deleteJson("/api/invoices/{$editable->id}")->assertForbidden();
        $this->postJson("/api/invoices/{$editable->id}/documents", [
            'documents' => [UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')],
        ])->assertForbidden();
        $this->deleteJson("/api/documents/{$document->id}")->assertForbidden();
        $this->postJson("/api/invoices/{$editable->id}/post", ['erp_doc_no' => '1', 'posting_date' => now()->toDateString()])->assertForbidden();

        $this->postJson("/api/payment-requests/{$this->pr->id}/approve")->assertForbidden();
        $this->postJson("/api/payment-requests/{$this->pr->id}/reject", ['comments' => 'no'])->assertForbidden();

        $this->assertSame(Invoice::STATUS_SUBMITTED, $editable->fresh()->status);
        $this->assertNull($editable->fresh()->description);
        $this->assertDatabaseHas('invoice_documents', ['id' => $document->id]);
        $this->assertSame(PaymentRequest::STATUS_IN_APPROVAL, $this->pr->fresh()->status);
    }

    public function test_the_grant_is_not_passed_on(): void
    {
        // The colleague may see the stranger; that does not let the viewer see the stranger.
        $this->grant($this->colleague, $this->stranger);
        $this->grant($this->viewer, $this->colleague);
        $this->actingAs($this->viewer);

        $this->getJson("/api/invoices/{$this->strangerInvoice->id}")->assertForbidden();
        $this->getJson('/api/invoices')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_an_approver_with_a_grant_keeps_their_queue_and_sees_the_colleagues_requests(): void
    {
        $otherApprover = $this->user(User::ROLE_APPROVER);
        $this->grant($otherApprover, $this->colleague);
        $this->actingAs($otherApprover);

        $this->getJson("/api/payment-requests/{$this->pr->id}")->assertOk();
        $this->getJson("/api/invoices/{$this->colleagueInvoice->id}")->assertOk();
        // Visible is not actionable: the stage is routed to someone else.
        $this->postJson("/api/payment-requests/{$this->pr->id}/approve")->assertForbidden();
        $this->getJson('/api/payment-requests/pending')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_an_admin_manages_the_grant_and_it_is_audited(): void
    {
        $this->actingAs($this->admin);

        $payload = fn (array $ids) => [
            'name' => $this->viewer->name, 'email' => $this->viewer->email, 'role' => $this->viewer->role,
            'is_active' => true, 'viewable_colleague_ids' => $ids,
        ];

        $this->putJson("/api/users/{$this->viewer->id}", $payload([$this->colleague->id]))
            ->assertOk()
            ->assertJsonPath('viewable_colleagues.0.id', $this->colleague->id);
        $this->assertDatabaseHas('user_view_grants', ['viewer_id' => $this->viewer->id, 'colleague_id' => $this->colleague->id]);

        $log = AuditLog::where('action', 'user_updated')->latest('id')->first();
        $this->assertSame([], $log->old_values['can_view_records_of']);
        $this->assertSame([$this->colleague->name], $log->new_values['can_view_records_of']);

        // Leaving the field out keeps the grant; sending it empty removes it.
        $this->putJson("/api/users/{$this->viewer->id}", array_diff_key($payload([]), ['viewable_colleague_ids' => 1]))->assertOk();
        $this->assertSame(1, $this->viewer->viewableColleagues()->count());

        $this->putJson("/api/users/{$this->viewer->id}", $payload([]))->assertOk();
        $this->assertSame(0, $this->viewer->viewableColleagues()->count());

        $this->actingAs($this->viewer->fresh());
        $this->getJson("/api/invoices/{$this->colleagueInvoice->id}")->assertForbidden();
    }

    public function test_a_user_cannot_be_granted_view_of_themselves_or_unknown_users(): void
    {
        $this->actingAs($this->admin);

        $payload = fn (array $ids) => [
            'name' => $this->viewer->name, 'email' => $this->viewer->email, 'role' => $this->viewer->role,
            'is_active' => true, 'viewable_colleague_ids' => $ids,
        ];

        $this->putJson("/api/users/{$this->viewer->id}", $payload([$this->viewer->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('viewable_colleague_ids.0');
        $this->putJson("/api/users/{$this->viewer->id}", $payload([999999]))
            ->assertUnprocessable()->assertJsonValidationErrors('viewable_colleague_ids.0');
    }

    public function test_only_an_admin_can_change_the_grant(): void
    {
        $this->actingAs($this->finance);

        $this->putJson("/api/users/{$this->finance->id}", [
            'name' => $this->finance->name, 'email' => $this->finance->email, 'role' => User::ROLE_FINANCE,
            'viewable_colleague_ids' => [$this->colleague->id],
        ])->assertForbidden();

        $this->assertDatabaseCount('user_view_grants', 0);
    }
}
