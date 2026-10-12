<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\InvoiceDocument;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestApproval;
use App\Models\PaymentRequestDocument;
use App\Models\User;
use App\Services\PafDocumentService;
use App\Services\PdfMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Documents attached after posting — to a payment request as a whole, or to a posted invoice — can
 * be removed by their uploader or an admin until payment. Removal hides them everywhere (screens,
 * PAF, public links, downloads) but keeps the file and records who removed what. Documents on a
 * still-editable invoice keep being deleted outright, as before.
 *
 * CR: ai/change-requests/allow-removal-of-attachments-uploaded-by-finance.md
 */
class DocumentRemovalTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $otherFinance;

    private User $admin;

    private User $approver;

    private User $submitter;

    private PaymentRequest $pr;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->finance = $this->user(User::ROLE_FINANCE);
        $this->otherFinance = $this->user(User::ROLE_FINANCE);
        $this->admin = $this->user(User::ROLE_ADMIN);
        $this->approver = $this->user(User::ROLE_APPROVER);
        $this->submitter = $this->user(User::ROLE_REQUESTER);

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

        $this->invoice = $this->invoice(Invoice::STATUS_POSTED, Invoice::PAY_IN_APPROVAL, $this->pr);
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

    private function invoice(string $status, string $paymentStatus, ?PaymentRequest $pr = null): Invoice
    {
        return Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => 'Atlas Maritime Shipping', 'invoice_no' => 'INV-'.uniqid(),
            'invoice_date' => now()->subDays(5)->toDateString(),
            'currency' => 'AED', 'amount' => 1000, 'tax_amount' => 0, 'total_amount' => 1000,
            'business_unit' => 'GGL', 'department' => 'Yard', 'location' => 'Dubai',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => $status, 'payment_status' => $paymentStatus,
            'submitted_by' => $this->submitter->id, 'submitted_at' => now()->subDays(2),
            'payment_request_id' => $pr?->id,
        ]);
    }

    private function file(string $name): string
    {
        return UploadedFile::fake()->create($name, 10, 'application/pdf')->store('documents', 'local');
    }

    private function requestDocument(?User $by = null, string $name = 'wrong-statement.pdf'): PaymentRequestDocument
    {
        return $this->pr->documents()->create([
            'uploaded_by' => ($by ?? $this->finance)->id, 'original_name' => $name,
            'file_path' => $this->file($name), 'mime_type' => 'application/pdf', 'size' => 10,
        ]);
    }

    private function invoiceDocument(Invoice $invoice, ?User $by = null, string $name = 'wrong-invoice.pdf'): InvoiceDocument
    {
        return $invoice->documents()->create([
            'uploaded_by' => ($by ?? $this->finance)->id, 'original_name' => $name,
            'file_path' => $this->file($name), 'mime_type' => 'application/pdf', 'size' => 10,
        ]);
    }

    // ── Request-level documents ───────────────────────────────────────────────────────────────

    public function test_the_uploader_removes_a_request_document_and_the_file_is_kept(): void
    {
        $document = $this->requestDocument();
        $keep = $this->requestDocument(name: 'right-statement.pdf');

        $this->actingAs($this->finance)->deleteJson("/api/payment-request-documents/{$document->id}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $keep->id);

        $removed = PaymentRequestDocument::withTrashed()->find($document->id);
        $this->assertNotNull($removed->deleted_at);
        $this->assertSame($this->finance->id, $removed->removed_by);
        Storage::disk('local')->assertExists($document->file_path);

        $log = AuditLog::where('action', 'request_document_removed')->sole();
        $this->assertSame($this->pr->id, $log->payment_request_id);
        $this->assertSame('wrong-statement.pdf', $log->new_values['original_name']);
    }

    public function test_a_removed_request_document_is_gone_from_the_page_the_links_and_downloads(): void
    {
        $document = $this->requestDocument();
        $this->actingAs($this->finance)->deleteJson("/api/payment-request-documents/{$document->id}")->assertOk();
        $stageToken = $this->pr->approvals()->sole()->view_token;

        $this->getJson("/api/payment-requests/{$this->pr->id}")->assertOk()->assertJsonCount(0, 'documents');
        $this->get("/api/payment-request-documents/{$document->id}/download")->assertNotFound();
        $this->get("/prf/view/{$this->pr->id}/{$stageToken}")->assertOk()->assertDontSee('wrong-statement.pdf');
        $this->get("/prf/view/{$this->pr->id}/{$stageToken}/request-document/{$document->id}")->assertNotFound();
    }

    public function test_a_request_document_can_be_removed_once_approved_but_not_once_closed(): void
    {
        $this->pr->update(['status' => PaymentRequest::STATUS_APPROVED]);
        $this->actingAs($this->finance)->deleteJson("/api/payment-request-documents/{$this->requestDocument()->id}")->assertOk();

        foreach ([PaymentRequest::STATUS_PAID, PaymentRequest::STATUS_REJECTED, PaymentRequest::STATUS_WITHDRAWN] as $status) {
            $this->pr->update(['status' => $status]);
            $document = $this->requestDocument();

            $this->deleteJson("/api/payment-request-documents/{$document->id}")
                ->assertUnprocessable()->assertJsonValidationErrors('document');
            $this->assertNull($document->fresh()->deleted_at);
        }
    }

    public function test_only_the_uploader_or_an_admin_removes_a_request_document(): void
    {
        $document = $this->requestDocument();

        $this->actingAs($this->otherFinance)->deleteJson("/api/payment-request-documents/{$document->id}")->assertForbidden();
        $this->actingAs($this->approver)->deleteJson("/api/payment-request-documents/{$document->id}")->assertForbidden();
        $this->actingAs($this->submitter)->deleteJson("/api/payment-request-documents/{$document->id}")->assertForbidden();
        $this->assertNull($document->fresh()->deleted_at);

        $this->actingAs($this->admin)->deleteJson("/api/payment-request-documents/{$document->id}")->assertOk();
        $this->assertSame($this->admin->id, PaymentRequestDocument::withTrashed()->find($document->id)->removed_by);
    }

    public function test_a_removed_document_frees_its_place_under_the_limit(): void
    {
        config(['paf.max_documents' => 1]);
        $document = $this->requestDocument();
        $this->actingAs($this->finance)->deleteJson("/api/payment-request-documents/{$document->id}")->assertOk();

        $this->post("/api/payment-requests/{$this->pr->id}/documents", [
            'documents' => [UploadedFile::fake()->create('right.pdf', 10, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertOk();
    }

    public function test_removed_documents_are_left_out_of_the_paf(): void
    {
        $requestDocument = $this->requestDocument();
        $invoiceDocument = $this->invoiceDocument($this->invoice);
        $this->actingAs($this->finance);
        $this->deleteJson("/api/payment-request-documents/{$requestDocument->id}")->assertOk();
        $this->deleteJson("/api/documents/{$invoiceDocument->id}")->assertOk();

        // Nothing left to attach, so the PAF is rendered without a merge at all.
        $this->mock(PdfMergeService::class, fn ($mock) => $mock->shouldNotReceive('mergePdfs'));

        $this->assertNotSame('', app(PafDocumentService::class)->render($this->pr->fresh(), true));
    }

    // ── Invoice documents ─────────────────────────────────────────────────────────────────────

    public function test_a_document_on_an_invoice_in_a_payment_request_is_removed_not_deleted(): void
    {
        $document = $this->invoiceDocument($this->invoice);
        $stageToken = $this->pr->approvals()->sole()->view_token;

        $this->actingAs($this->finance)->deleteJson("/api/documents/{$document->id}")->assertOk()->assertJsonPath('message', 'Removed');

        $removed = InvoiceDocument::withTrashed()->find($document->id);
        $this->assertNotNull($removed->deleted_at);
        $this->assertSame($this->finance->id, $removed->removed_by);
        Storage::disk('local')->assertExists($document->file_path);

        // Recorded on both the invoice's and the request's history.
        $log = AuditLog::where('action', 'document_removed')->sole();
        $this->assertSame($this->invoice->id, $log->invoice_id);
        $this->assertSame($this->pr->id, $log->payment_request_id);

        $this->getJson("/api/invoices/{$this->invoice->id}")->assertOk()->assertJsonCount(0, 'documents');
        $this->get("/api/documents/{$document->id}/download")->assertNotFound();
        $this->get("/prf/view/{$this->pr->id}/{$stageToken}")->assertOk()->assertDontSee('wrong-invoice.pdf');
        $this->get("/prf/view/{$this->pr->id}/{$stageToken}/document/{$document->id}")->assertNotFound();
    }

    public function test_a_document_on_a_posted_invoice_not_yet_in_a_request_can_be_removed(): void
    {
        $posted = $this->invoice(Invoice::STATUS_POSTED, Invoice::PAY_NOT_INITIATED);
        $document = $this->invoiceDocument($posted);

        $this->actingAs($this->finance)->deleteJson("/api/documents/{$document->id}")->assertOk();

        $this->assertSoftDeleted($document);
        $this->assertSame(0, $posted->documents()->count());
    }

    public function test_an_approved_invoice_still_allows_removal_but_a_paid_one_does_not(): void
    {
        $this->invoice->update(['payment_status' => Invoice::PAY_APPROVED]);
        $this->actingAs($this->finance)->deleteJson("/api/documents/{$this->invoiceDocument($this->invoice)->id}")->assertOk();

        $this->invoice->update(['payment_status' => Invoice::PAY_PAID]);
        $document = $this->invoiceDocument($this->invoice);

        $this->deleteJson("/api/documents/{$document->id}")->assertUnprocessable();
        $this->assertNull($document->fresh()->deleted_at);
    }

    public function test_a_cancelled_invoice_allows_no_removal(): void
    {
        $cancelled = $this->invoice(Invoice::STATUS_CANCELLED, Invoice::PAY_NOT_INITIATED);
        $document = $this->invoiceDocument($cancelled);

        $this->actingAs($this->finance)->deleteJson("/api/documents/{$document->id}")->assertUnprocessable();
        $this->assertNull($document->fresh()->deleted_at);
    }

    public function test_only_the_uploader_or_an_admin_removes_an_invoice_document(): void
    {
        $document = $this->invoiceDocument($this->invoice);

        $this->actingAs($this->otherFinance)->deleteJson("/api/documents/{$document->id}")->assertForbidden();
        $this->actingAs($this->submitter)->deleteJson("/api/documents/{$document->id}")->assertForbidden();
        $this->assertNull($document->fresh()->deleted_at);

        $this->actingAs($this->admin)->deleteJson("/api/documents/{$document->id}")->assertOk();
        $this->assertSoftDeleted($document);
    }

    public function test_a_submitter_removes_a_document_they_added_to_their_posted_invoice(): void
    {
        $document = $this->invoiceDocument($this->invoice, $this->submitter, 'final-invoice.pdf');

        $this->actingAs($this->submitter)->deleteJson("/api/documents/{$document->id}")->assertOk();

        $this->assertSoftDeleted($document);
    }

    public function test_an_editable_invoice_still_deletes_the_document_outright(): void
    {
        $editable = $this->invoice(Invoice::STATUS_SUBMITTED, Invoice::PAY_NOT_INITIATED);
        $document = $this->invoiceDocument($editable, $this->submitter);

        $this->actingAs($this->submitter)->deleteJson("/api/documents/{$document->id}")->assertOk()->assertJsonPath('message', 'Deleted');

        $this->assertNull(InvoiceDocument::withTrashed()->find($document->id));
        Storage::disk('local')->assertMissing($document->file_path);
        $this->assertSame(1, AuditLog::where('action', 'document_deleted')->count());
    }
}
