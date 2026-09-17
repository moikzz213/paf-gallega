<?php

namespace Tests\Feature;

use App\Models\ApprovalLevel;
use App\Models\Invoice;
use App\Models\InvoiceDocument;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PaymentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class EndpointSmokeTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $approver1;

    private User $approver2;

    private User $requester;

    private $pr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finance = User::create(['name' => 'Fin', 'email' => 'fin@t.local', 'password' => 'password', 'role' => User::ROLE_FINANCE, 'is_active' => true]);
        $this->requester = User::create(['name' => 'Req', 'email' => 'req@t.local', 'password' => 'password', 'role' => User::ROLE_REQUESTER, 'is_active' => true]);
        $this->approver1 = User::create(['name' => 'A1', 'email' => 'a1@t.local', 'password' => 'password', 'role' => User::ROLE_APPROVER, 'approval_level' => 1, 'is_active' => true]);
        $this->approver2 = User::create(['name' => 'A2', 'email' => 'a2@t.local', 'password' => 'password', 'role' => User::ROLE_APPROVER, 'approval_level' => 2, 'is_active' => true]);

        ApprovalLevel::create(['level' => 1, 'name' => 'Department Manager', 'min_amount' => 0, 'default_approver_id' => $this->approver1->id, 'is_active' => true]);
        ApprovalLevel::create(['level' => 2, 'name' => 'Director', 'min_amount' => 10000, 'default_approver_id' => $this->approver2->id, 'is_active' => true]);

        $inv = Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => 'V', 'invoice_no' => 'INV-1', 'invoice_date' => now()->toDateString(),
            'currency' => 'AED', 'amount' => 20000, 'tax_amount' => 0, 'total_amount' => 20000,
            'business_unit' => 'GIL', 'department' => 'Warehouse', 'location' => 'Head Office', 'payment_method' => 'bank_transfer',
            'priority' => 'normal', 'status' => Invoice::STATUS_POSTED, 'payment_status' => Invoice::PAY_NOT_INITIATED,
            'submitted_by' => $this->requester->id, 'submitted_at' => now(),
        ]);

        $this->pr = app(PaymentRequestService::class)->create(
            [$inv->id], $this->finance,
            [
                ['approver_id' => $this->approver1->id, 'label' => 'Department Manager', 'level' => 1, 'is_adhoc' => false],
                ['approver_id' => $this->approver2->id, 'label' => 'Director', 'level' => 2, 'is_adhoc' => false],
            ],
        );
    }

    public function test_finance_endpoints_respond(): void
    {
        $this->actingAs($this->finance);

        $this->getJson('/api/meta')->assertOk();
        $this->getJson('/api/approvers')->assertOk();
        $this->getJson('/api/dashboard')->assertOk();
        $this->getJson('/api/invoices')->assertOk();
        $this->getJson('/api/payment-requests/eligible')->assertOk();
        $this->getJson('/api/payment-requests')->assertOk();
        $this->getJson("/api/payment-requests/{$this->pr->id}")->assertOk();
        $this->getJson('/api/reports')->assertOk();
    }

    public function test_approver_queue_shows_assigned_request(): void
    {
        $res = $this->actingAs($this->approver1)->getJson('/api/payment-requests/pending')->assertOk();
        $this->assertSame(1, $res->json('total'));

        // approver 2 is not the current stage yet
        $res2 = $this->actingAs($this->approver2)->getJson('/api/payment-requests/pending')->assertOk();
        $this->assertSame(0, $res2->json('total'));
    }

    public function test_approver_dashboard_and_visibility(): void
    {
        $this->actingAs($this->approver1);
        $dash = $this->getJson('/api/dashboard')->assertOk();
        $this->assertSame(1, $dash->json('my_queue'));

        // approver can view the PRF routed to them and its invoices
        $this->getJson("/api/payment-requests/{$this->pr->id}")->assertOk();
    }

    public function test_finance_can_download_the_payment_request_pdf(): void
    {
        $invoice = $this->pr->invoices()->firstOrFail();
        $relativePath = 'tests/payment-request-attachment.txt';
        $absolutePath = storage_path('app/private/'.$relativePath);
        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, 'attachment body');
        $document = InvoiceDocument::create([
            'invoice_id' => $invoice->id,
            'uploaded_by' => $this->requester->id,
            'original_name' => 'supporting-document.txt',
            'file_path' => $relativePath,
            'mime_type' => 'text/plain',
            'size' => File::size($absolutePath),
        ]);

        $view = view('pdf.payment-request', [
            'paymentRequest' => $this->pr->load([
                'creator:id,name', 'payer:id,name',
                'invoices.submitter:id,name', 'invoices.poster:id,name', 'invoices.documents',
                'approvals.approver:id,name', 'approvals.approvalLevel:level,min_amount',
            ]),
            'supplierCodes' => Vendor::whereIn('name', $this->pr->invoices->pluck('vendor_name'))->pluck('vendor_code', 'name'),
            'approvalLevels' => ApprovalLevel::where('is_active', true)->with('defaultApprover:id,name')->orderBy('level')->get(),
        ])->render();
        $this->assertStringNotContainsString('APPROVAL STATUS', $view);
        $this->assertStringNotContainsString('IN APPROVAL', $view);
        $this->assertStringContainsString('class="company-logo"', $view);
        $this->assertStringContainsString('For Accounts Dept. Use', $view);
        $this->assertStringContainsString('Verified / Posted By', $view);
        $this->assertSame(1, substr_count($view, 'Department Manager'));
        $this->assertSame(
            ['Department Manager'],
            $this->pr->approvals
                ->filter(fn ($approval) => (float) $approval->approvalLevel?->min_amount === 0.0)
                ->pluck('label')
                ->all(),
        );
        $this->assertSame(
            ['Director'],
            $this->pr->approvals
                ->filter(fn ($approval) => (float) $approval->approvalLevel?->min_amount > 0.0)
                ->pluck('label')
                ->all(),
        );

        $this->pr->update(['status' => 'approved']);

        $response = $this->actingAs($this->finance)
            ->get("/api/payment-requests/{$this->pr->id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        // Attachments are merged into the document rather than PDF-embedded (ADR-003). A text file
        // cannot be rendered inline, so it must surface as a real link annotation on the trailing
        // Additional Documents page — not as an /EmbeddedFiles entry in the viewer's side panel.
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString('/Annots', $response->getContent());
        $this->assertStringContainsString('/URI', $response->getContent());
        $this->assertStringContainsString(
            url("/api/documents/{$document->id}/download"),
            $response->getContent(),
        );

        File::delete($absolutePath);
    }

    public function test_requester_cannot_create_payment_request(): void
    {
        $this->actingAs($this->requester)
            ->postJson('/api/payment-requests', ['invoice_ids' => [1]])
            ->assertForbidden();
    }
}
