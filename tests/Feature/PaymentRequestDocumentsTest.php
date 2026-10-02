<?php

namespace Tests\Feature;

use App\Models\ApprovalLevel;
use App\Models\AuditLog;
use App\Models\BusinessUnit;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestDocument;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PafDocumentService;
use App\Services\PdfMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Supporting documents attached to a payment request as a whole — at creation and later — and
 * merged into the PAF. Visible to Finance, admins and the request's approvers, never to submitters.
 *
 * CR: ai/change-requests/requester-corrections-during-approval-and-payment-request-attachments.md
 */
class PaymentRequestDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $approver;

    private User $submitter;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('local');
        BusinessUnit::create(['name' => 'GGH', 'is_active' => true]);
        Department::create(['name' => 'Yard', 'is_active' => true]);
        Location::create(['name' => 'Dubai', 'is_active' => true]);
        Currency::firstOrCreate(['name' => 'AED']);
        Currency::firstOrCreate(['name' => 'USD']);
        Vendor::create(['name' => 'Al Noor Logistics', 'vendor_code' => 'ALN-1', 'is_active' => true]);
        ApprovalLevel::create(['level' => 1, 'name' => 'Manager', 'min_amount' => 0, 'is_active' => true]);

        $this->finance = $this->user(User::ROLE_FINANCE);
        $this->approver = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $this->submitter = $this->user(User::ROLE_REQUESTER);
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

    private function postedInvoice(float $amount, string $currency = 'AED'): Invoice
    {
        $invoice = Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => 'Al Noor Logistics', 'invoice_no' => 'INV-'.uniqid(),
            'invoice_date' => now()->toDateString(),
            'currency' => $currency, 'amount' => $amount, 'tax_amount' => 0, 'total_amount' => $amount,
            'business_unit' => 'GGH', 'department' => 'Yard', 'location' => 'Dubai',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => Invoice::STATUS_POSTED, 'payment_status' => Invoice::PAY_NOT_INITIATED,
            'submitted_by' => $this->submitter->id, 'submitted_at' => now(),
        ]);
        $invoice->items()->create([
            'sort_order' => 0, 'currency' => $currency, 'description' => 'Line',
            'amount' => $amount, 'tax_amount' => 0, 'total_amount' => $amount,
        ]);

        return $invoice;
    }

    /** Multipart, the way the payment request page now sends it. */
    private function createWithDocuments(array $invoiceIds, array $files)
    {
        return $this->actingAs($this->finance)->post('/api/payment-requests', [
            'invoice_ids' => $invoiceIds,
            'approvers' => [1 => (string) $this->approver->id],
            'documents' => $files,
        ], ['Accept' => 'application/json']);
    }

    private function statement(string $name = 'vendor-statement.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 30, 'application/pdf');
    }

    private function requestWithDocument(): PaymentRequest
    {
        $this->createWithDocuments([$this->postedInvoice(1000)->id], [$this->statement()])->assertCreated();

        return PaymentRequest::sole();
    }

    // ── Attaching at creation ─────────────────────────────────────────────────────────────────

    public function test_documents_can_be_attached_when_the_request_is_raised(): void
    {
        $pr = $this->requestWithDocument();

        $document = $pr->documents()->sole();
        $this->assertSame('vendor-statement.pdf', $document->original_name);
        $this->assertFalse($document->uploaded_after_approval);
        $this->assertSame($this->finance->id, $document->uploaded_by);
        Storage::disk('local')->assertExists($document->file_path);
        $this->assertSame(1, AuditLog::where('action', 'request_document_uploaded')->where('payment_request_id', $pr->id)->count());
    }

    public function test_a_single_selected_invoice_is_enough(): void
    {
        $this->createWithDocuments([$this->postedInvoice(1000)->id], [$this->statement()])->assertCreated();
        $this->assertSame(1, PaymentRequestDocument::count());
    }

    public function test_documents_are_optional_and_multipart_creation_still_routes_the_chain(): void
    {
        $this->actingAs($this->finance)->post('/api/payment-requests', [
            'invoice_ids' => [$this->postedInvoice(1000)->id],
            'approvers' => [1 => (string) $this->approver->id],
        ], ['Accept' => 'application/json'])->assertCreated();

        $pr = PaymentRequest::sole();
        $this->assertSame(0, $pr->documents()->count());
        $this->assertSame($this->approver->id, $pr->approvals()->sole()->approver_id);
    }

    public function test_a_refused_request_leaves_no_documents_behind(): void
    {
        // Mixed currencies are refused before anything is written.
        $this->createWithDocuments(
            [$this->postedInvoice(1000)->id, $this->postedInvoice(500, 'USD')->id],
            [$this->statement()],
        )->assertStatus(422);

        $this->assertSame(0, PaymentRequest::count());
        $this->assertSame(0, PaymentRequestDocument::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_disallowed_file_type_is_refused(): void
    {
        $this->createWithDocuments([$this->postedInvoice(1000)->id], [UploadedFile::fake()->create('script.exe', 5)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('documents.0');
        $this->assertSame(0, PaymentRequest::count());
    }

    // ── Attaching later ───────────────────────────────────────────────────────────────────────

    public function test_finance_can_attach_while_in_approval(): void
    {
        $pr = $this->requestWithDocument();

        $this->actingAs($this->finance)->post("/api/payment-requests/{$pr->id}/documents", [
            'documents' => [$this->statement('covering-memo.pdf')],
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonCount(2);

        $this->assertFalse($pr->documents()->where('original_name', 'covering-memo.pdf')->sole()->uploaded_after_approval);
    }

    public function test_a_document_added_after_approval_is_marked_as_such(): void
    {
        $pr = $this->requestWithDocument();
        $pr->update(['status' => PaymentRequest::STATUS_PAID, 'paid_at' => now()]);

        $this->actingAs($this->finance)->post("/api/payment-requests/{$pr->id}/documents", [
            'documents' => [$this->statement('bank-confirmation.pdf')],
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertTrue($pr->documents()->where('original_name', 'bank-confirmation.pdf')->sole()->uploaded_after_approval);
        $this->assertSame(1, AuditLog::where('action', 'request_document_uploaded_after_approval')->count());
        // Attaching changes nothing else about the request.
        $this->assertSame(PaymentRequest::STATUS_PAID, $pr->refresh()->status);
    }

    public function test_a_rejected_request_accepts_no_documents(): void
    {
        $pr = $this->requestWithDocument();
        $pr->update(['status' => PaymentRequest::STATUS_REJECTED]);

        $this->actingAs($this->finance)->post("/api/payment-requests/{$pr->id}/documents", [
            'documents' => [$this->statement('late.pdf')],
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('documents');
    }

    public function test_the_document_limit_counts_what_is_already_attached(): void
    {
        config(['paf.max_documents' => 2]);
        $pr = $this->requestWithDocument();

        $this->actingAs($this->finance)->post("/api/payment-requests/{$pr->id}/documents", [
            'documents' => [$this->statement('a.pdf'), $this->statement('b.pdf')],
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('documents');
    }

    public function test_only_finance_or_admin_can_attach_later(): void
    {
        $pr = $this->requestWithDocument();

        foreach ([$this->approver, $this->submitter] as $user) {
            $this->actingAs($user)->post("/api/payment-requests/{$pr->id}/documents", [
                'documents' => [$this->statement('x.pdf')],
            ], ['Accept' => 'application/json'])->assertForbidden();
        }
    }

    // ── Who can see them ──────────────────────────────────────────────────────────────────────

    public function test_finance_and_the_requests_approvers_see_and_download_them(): void
    {
        $pr = $this->requestWithDocument();
        $document = $pr->documents()->sole();

        foreach ([$this->finance, $this->approver] as $user) {
            $this->actingAs($user)->getJson("/api/payment-requests/{$pr->id}")
                ->assertOk()->assertJsonCount(1, 'documents');
            $this->actingAs($user)->get("/api/payment-request-documents/{$document->id}/download")->assertOk();
        }
    }

    public function test_a_submitter_can_see_the_request_but_not_its_documents(): void
    {
        $pr = $this->requestWithDocument();
        $document = $pr->documents()->sole();

        $this->actingAs($this->submitter)->getJson("/api/payment-requests/{$pr->id}")
            ->assertOk()->assertJsonMissingPath('documents');
        $this->actingAs($this->submitter)->get("/api/payment-request-documents/{$document->id}/download")->assertForbidden();
    }

    public function test_an_approver_on_another_request_cannot_download_them(): void
    {
        $document = $this->requestWithDocument()->documents()->sole();
        $outsider = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);

        $this->actingAs($outsider)->get("/api/payment-request-documents/{$document->id}/download")->assertForbidden();
    }

    public function test_the_approval_link_lists_them_but_the_requests_own_link_does_not(): void
    {
        $pr = $this->requestWithDocument();
        $document = $pr->documents()->sole();
        $stageToken = $pr->approvals()->sole()->view_token;

        $this->get("/prf/view/{$pr->id}/{$stageToken}")->assertOk()->assertSee('vendor-statement.pdf');
        $this->get("/prf/view/{$pr->id}/{$stageToken}/request-document/{$document->id}")->assertOk();

        // The request's own token is emailed to every invoice submitter on approval or rejection.
        $this->get("/prf/view/{$pr->id}/{$pr->view_token}")->assertOk()->assertDontSee('vendor-statement.pdf');
        $this->get("/prf/view/{$pr->id}/{$pr->view_token}/request-document/{$document->id}")->assertNotFound();
    }

    public function test_a_document_from_another_request_is_not_served_on_this_link(): void
    {
        $pr = $this->requestWithDocument();
        $stageToken = $pr->approvals()->sole()->view_token;

        $this->createWithDocuments([$this->postedInvoice(2000)->id], [$this->statement('other.pdf')])->assertCreated();
        $foreign = PaymentRequestDocument::where('original_name', 'other.pdf')->sole();

        $this->get("/prf/view/{$pr->id}/{$stageToken}/request-document/{$foreign->id}")->assertNotFound();
    }

    // ── The PAF ───────────────────────────────────────────────────────────────────────────────

    public function test_the_paf_includes_them_only_when_asked_to(): void
    {
        $pr = $this->requestWithDocument();
        $document = $pr->documents()->sole();
        // The PAF reads from the real private disk path, not the faked one.
        $path = storage_path('app/private/'.$document->file_path);
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, '%PDF-1.4 test');

        $merged = [];
        $this->mock(PdfMergeService::class, function ($mock) use (&$merged) {
            $mock->shouldReceive('mergePdfs')->andReturnUsing(function ($main, $attachments, $links) use (&$merged) {
                $merged[] = array_merge($attachments, $links);

                return 'merged';
            });
        });

        try {
            $paf = app(PafDocumentService::class);
            $paf->render($pr->fresh(), true);
            $paf->render($pr->fresh());
        } finally {
            @unlink($path);
            @rmdir(dirname($path));
        }

        // Asked for: merged, labelled as covering the whole request. Not asked for: never reached the
        // merge at all (the request has no invoice documents).
        $this->assertCount(1, $merged);
        $this->assertSame('vendor-statement.pdf', $merged[0][0]['name']);
        $this->assertStringContainsString('Payment request documents', $merged[0][0]['group']);
    }
}
