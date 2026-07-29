<?php

namespace Tests\Feature;

use App\Models\ApprovalLevel;
use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PaymentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class PaymentRequestPdfTest extends TestCase
{
    use RefreshDatabase;

    /** Build the three-level ladder with default approvers on L2/L3, and return the users. */
    private function ladder(): array
    {
        $mgr = User::create(['name' => 'Zulfiqar Manager', 'email' => 'm'.uniqid().'@t.local', 'password' => 'password', 'role' => User::ROLE_APPROVER, 'approval_level' => 1, 'is_active' => true]);
        $dir = User::create(['name' => 'Dinah Director', 'email' => 'd'.uniqid().'@t.local', 'password' => 'password', 'role' => User::ROLE_APPROVER, 'approval_level' => 2, 'is_active' => true]);
        $cfo = User::create(['name' => 'Carl CFO', 'email' => 'c'.uniqid().'@t.local', 'password' => 'password', 'role' => User::ROLE_APPROVER, 'approval_level' => 3, 'is_active' => true]);

        ApprovalLevel::create(['level' => 1, 'name' => 'Department Manager', 'min_amount' => 0, 'default_approver_id' => $mgr->id, 'is_active' => true]);
        ApprovalLevel::create(['level' => 2, 'name' => 'Finance Director', 'min_amount' => 10000, 'default_approver_id' => $dir->id, 'is_active' => true]);
        ApprovalLevel::create(['level' => 3, 'name' => 'CFO', 'min_amount' => 50000, 'default_approver_id' => $cfo->id, 'is_active' => true]);

        return compact('mgr', 'dir', 'cfo');
    }

    private function postedInvoice(User $owner, float $total, array $items): Invoice
    {
        $inv = Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => 'Al Noor Logistics', 'invoice_no' => 'INV-'.uniqid(), 'invoice_date' => now()->toDateString(),
            'currency' => 'AED', 'amount' => $total, 'tax_amount' => 0, 'total_amount' => $total,
            'business_unit' => 'GIL', 'department' => 'Yard', 'location' => 'Head Office',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => Invoice::STATUS_POSTED, 'payment_status' => Invoice::PAY_NOT_INITIATED,
            'submitted_by' => $owner->id, 'submitted_at' => now(),
        ]);
        foreach ($items as $i => $item) {
            $inv->items()->create(['sort_order' => $i, 'job_no' => $item['job_no'], 'currency' => 'AED', 'amount' => $item['amount'], 'tax_amount' => 0, 'total_amount' => $item['amount']]);
        }

        return $inv;
    }

    private function render(int $prId): string
    {
        $pr = PaymentRequest::with([
            'creator:id,name', 'payer:id,name', 'invoices.submitter:id,name', 'invoices.poster:id,name',
            'invoices.documents', 'invoices.items', 'approvals.approver:id,name', 'approvals.approvalLevel:level,min_amount',
        ])->findOrFail($prId);

        $supplierCodes = Vendor::whereIn('name', $pr->invoices->pluck('vendor_name'))->pluck('vendor_code', 'name');
        $approvalLevels = ApprovalLevel::where('is_active', true)->with('defaultApprover:id,name')->orderBy('level')->get();

        return View::make('pdf.payment-request', compact('pr', 'supplierCodes', 'approvalLevels') + ['paymentRequest' => $pr])->render();
    }

    public function test_pdf_lists_job_numbers_amounts_and_supplier_code(): void
    {
        $u = $this->ladder();
        Vendor::create(['name' => 'Al Noor Logistics', 'vendor_code' => 'ALN-77', 'is_active' => true]);
        $finance = User::create(['name' => 'Fin', 'email' => 'fin@t.local', 'password' => 'password', 'role' => User::ROLE_FINANCE, 'is_active' => true]);
        $requester = User::create(['name' => 'Req', 'email' => 'req@t.local', 'password' => 'password', 'role' => User::ROLE_REQUESTER, 'is_active' => true]);

        $invoice = $this->postedInvoice($requester, 300, [['job_no' => 'JOB-AAA', 'amount' => 100], ['job_no' => 'JOB-BBB', 'amount' => 200]]);
        $pr = app(PaymentRequestService::class)->create([$invoice->id], $finance, [
            ['approver_id' => $u['mgr']->id, 'label' => 'Department Manager', 'level' => 1, 'is_adhoc' => false],
        ]);

        $html = $this->render($pr->id);

        $this->assertStringContainsString('JOB-AAA', $html);
        $this->assertStringContainsString('JOB-BBB', $html);
        $this->assertStringContainsString('AED 100.00', $html);
        $this->assertStringContainsString('AED 200.00', $html);
        $this->assertStringContainsString('ALN-77', $html);
    }

    public function test_for_approval_shows_reached_and_not_reached_approvers(): void
    {
        $u = $this->ladder();
        Vendor::create(['name' => 'Al Noor Logistics', 'vendor_code' => 'ALN-77', 'is_active' => true]);
        $finance = User::create(['name' => 'Fin', 'email' => 'fin@t.local', 'password' => 'password', 'role' => User::ROLE_FINANCE, 'is_active' => true]);
        $requester = User::create(['name' => 'Req', 'email' => 'req@t.local', 'password' => 'password', 'role' => User::ROLE_REQUESTER, 'is_active' => true]);

        // total 300 → only L1 (min 0) reached; L2/L3 thresholds not reached.
        $invoice = $this->postedInvoice($requester, 300, [['job_no' => 'JOB-1', 'amount' => 300]]);
        $pr = app(PaymentRequestService::class)->create([$invoice->id], $finance, [
            ['approver_id' => $u['mgr']->id, 'label' => 'Department Manager', 'level' => 1, 'is_adhoc' => false],
        ]);

        $html = $this->render($pr->id);

        // L1 approver appears (requisition column, reverted setup)
        $this->assertStringContainsString('Zulfiqar Manager', $html);
        // L2 and L3 are NOT reached but must still be displayed via their default approvers
        $this->assertStringContainsString('Dinah Director', $html);
        $this->assertStringContainsString('Carl CFO', $html);
        $this->assertStringContainsString('Not Required', $html);
    }

    public function test_reached_higher_level_is_shown_as_required(): void
    {
        $u = $this->ladder();
        Vendor::create(['name' => 'Al Noor Logistics', 'vendor_code' => 'ALN-77', 'is_active' => true]);
        $finance = User::create(['name' => 'Fin', 'email' => 'fin@t.local', 'password' => 'password', 'role' => User::ROLE_FINANCE, 'is_active' => true]);
        $requester = User::create(['name' => 'Req', 'email' => 'req@t.local', 'password' => 'password', 'role' => User::ROLE_REQUESTER, 'is_active' => true]);

        // total 20000 → L1 + L2 reached (required); L3 not reached (displayed).
        $invoice = $this->postedInvoice($requester, 20000, [['job_no' => 'JOB-2', 'amount' => 20000]]);
        $pr = app(PaymentRequestService::class)->create([$invoice->id], $finance, [
            ['approver_id' => $u['mgr']->id, 'label' => 'Department Manager', 'level' => 1, 'is_adhoc' => false],
            ['approver_id' => $u['dir']->id, 'label' => 'Finance Director', 'level' => 2, 'is_adhoc' => false],
        ]);

        $html = $this->render($pr->id);

        // L2 director is a required (reached) approver; L3 CFO still displayed as not required
        $this->assertStringContainsString('Dinah Director', $html);
        $this->assertStringContainsString('Carl CFO', $html);
        $this->assertStringContainsString('Not Required', $html);
    }

    public function test_pdf_endpoint_returns_a_pdf(): void
    {
        $u = $this->ladder();
        $finance = User::create(['name' => 'Fin', 'email' => 'fin2@t.local', 'password' => 'password', 'role' => User::ROLE_FINANCE, 'is_active' => true]);
        $requester = User::create(['name' => 'Req', 'email' => 'req2@t.local', 'password' => 'password', 'role' => User::ROLE_REQUESTER, 'is_active' => true]);

        $invoice = $this->postedInvoice($requester, 100, [['job_no' => 'JOB-CCC', 'amount' => 100]]);
        $pr = app(PaymentRequestService::class)->create([$invoice->id], $finance, [
            ['approver_id' => $u['mgr']->id, 'label' => 'Department Manager', 'level' => 1, 'is_adhoc' => false],
        ]);
        $pr->update(['status' => 'approved']);

        $res = $this->actingAs($finance)->get("/api/payment-requests/{$pr->id}/pdf");
        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
    }
}
