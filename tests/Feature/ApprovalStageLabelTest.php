<?php

namespace Tests\Feature;

use App\Models\ApprovalLevel;
use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * A stage's job description is the approver's own job title, not the approval level's name. The
 * level names describe generic positions ("Department Manager") that are often not the position of
 * the person signing — Finance group members approve at L1/L2 — so the chain, the PAF and the public
 * link all show who actually signed and in what capacity.
 */
class ApprovalStageLabelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Vendor::create(['name' => 'Al Noor Logistics', 'vendor_code' => 'ALN-77', 'is_active' => true]);
        ApprovalLevel::create(['level' => 1, 'name' => 'Department Manager', 'min_amount' => 0, 'is_active' => true]);
        ApprovalLevel::create(['level' => 2, 'name' => 'Finance Director', 'min_amount' => 10000, 'is_active' => true]);
    }

    private function user(string $role, ?int $level = null, ?string $jobTitle = null): User
    {
        return User::create([
            'name' => ucfirst($role).' '.uniqid(),
            'email' => $role.uniqid().'@t.local',
            'password' => 'password',
            'role' => $role,
            'approval_level' => $level,
            'job_title' => $jobTitle,
            'is_active' => true,
        ]);
    }

    private function postedInvoice(float $total = 300): Invoice
    {
        $invoice = Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => 'Al Noor Logistics', 'invoice_no' => 'INV-'.uniqid(),
            'invoice_date' => now()->toDateString(),
            'currency' => 'AED', 'amount' => $total, 'tax_amount' => 0, 'total_amount' => $total,
            'business_unit' => 'GIL', 'department' => 'Warehouse', 'location' => 'Head Office',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => Invoice::STATUS_POSTED, 'payment_status' => Invoice::PAY_NOT_INITIATED,
            'submitted_by' => $this->user(User::ROLE_REQUESTER)->id, 'submitted_at' => now(),
        ]);

        $invoice->items()->create([
            'sort_order' => 0, 'job_no' => 'JOB-1', 'currency' => 'AED',
            'amount' => $total, 'tax_amount' => 0, 'total_amount' => $total,
        ]);

        return $invoice;
    }

    private function render(int $prId): string
    {
        $pr = PaymentRequest::with([
            'creator:id,name', 'payer:id,name', 'invoices.submitter:id,name', 'invoices.poster:id,name',
            'invoices.documents', 'invoices.items', 'approvals.approver:id,name',
            'approvals.approvalLevel:level,min_amount',
        ])->findOrFail($prId);

        $supplierCodes = Vendor::whereIn('name', $pr->invoices->pluck('vendor_name'))->pluck('vendor_code', 'name');
        $approvalLevels = ApprovalLevel::where('is_active', true)
            ->with('defaultApprover:id,name,job_title')
            ->orderBy('level')
            ->get();

        return View::make('pdf.payment-request', compact('pr', 'supplierCodes', 'approvalLevels') + ['paymentRequest' => $pr])->render();
    }

    public function test_a_level_stage_is_labelled_with_the_approvers_job_title(): void
    {
        // A Finance user nominated at L1, whose level is named "Department Manager".
        $approver = $this->user(User::ROLE_FINANCE, 1, 'AP Accountant');

        $pr = $this->actingAs($this->user(User::ROLE_FINANCE))->postJson('/api/payment-requests', [
            'invoice_ids' => [$this->postedInvoice()->id],
            'approvers' => [1 => $approver->id],
        ])->assertCreated()->json();

        $stage = PaymentRequest::find($pr['id'])->approvals()->where('sequence', 1)->first();
        $this->assertSame('AP Accountant', $stage->label);
        $this->assertSame(1, $stage->level, 'the level itself is still recorded on the stage');
    }

    public function test_the_level_name_is_the_fallback_when_the_approver_has_no_job_title(): void
    {
        $approver = $this->user(User::ROLE_APPROVER, 1);

        $pr = $this->actingAs($this->user(User::ROLE_FINANCE))->postJson('/api/payment-requests', [
            'invoice_ids' => [$this->postedInvoice()->id],
            'approvers' => [1 => $approver->id],
        ])->assertCreated()->json();

        $this->assertSame(
            'Department Manager',
            PaymentRequest::find($pr['id'])->approvals()->where('sequence', 1)->first()->label,
        );
    }

    public function test_every_reached_level_takes_its_own_approvers_job_title(): void
    {
        $l1 = $this->user(User::ROLE_FINANCE, 1, 'AP Accountant');
        $l2 = $this->user(User::ROLE_FINANCE, 2, 'Group Treasury Lead');

        $pr = $this->actingAs($this->user(User::ROLE_FINANCE))->postJson('/api/payment-requests', [
            'invoice_ids' => [$this->postedInvoice(20000)->id],
            'approvers' => [1 => $l1->id, 2 => $l2->id],
        ])->assertCreated()->json();

        $this->assertSame(
            ['AP Accountant', 'Group Treasury Lead'],
            PaymentRequest::find($pr['id'])->approvals()->orderBy('sequence')->pluck('label')->all(),
        );
    }

    public function test_an_adhoc_stage_keeps_its_typed_label_but_falls_back_to_the_job_title(): void
    {
        $typed = $this->user(User::ROLE_APPROVER, 1, 'Legal Counsel');
        $untyped = $this->user(User::ROLE_APPROVER, 2, 'Group Treasury Lead');
        $noTitle = $this->user(User::ROLE_APPROVER, 2);

        $pr = $this->actingAs($this->user(User::ROLE_FINANCE))->postJson('/api/payment-requests', [
            'invoice_ids' => [$this->postedInvoice()->id],
            'approvers' => [1 => $typed->id],
            'adhoc_approvers' => [
                ['approver_id' => $typed->id, 'label' => 'Legal review'],
                ['approver_id' => $untyped->id, 'label' => ''],
                ['approver_id' => $noTitle->id],
            ],
        ])->assertCreated()->json();

        $this->assertSame(
            ['Legal Counsel', 'Legal review', 'Group Treasury Lead', 'Additional approver'],
            PaymentRequest::find($pr['id'])->approvals()->orderBy('sequence')->pluck('label')->all(),
        );
    }

    public function test_the_label_is_a_snapshot_and_a_later_job_change_does_not_rewrite_history(): void
    {
        $approver = $this->user(User::ROLE_FINANCE, 1, 'AP Accountant');

        $pr = $this->actingAs($this->user(User::ROLE_FINANCE))->postJson('/api/payment-requests', [
            'invoice_ids' => [$this->postedInvoice()->id],
            'approvers' => [1 => $approver->id],
        ])->assertCreated()->json();

        $approver->update(['job_title' => 'Finance Manager']);

        $this->assertSame(
            'AP Accountant',
            PaymentRequest::find($pr['id'])->approvals()->where('sequence', 1)->first()->label,
        );
    }

    public function test_the_pdf_prints_the_job_title_for_a_reached_level(): void
    {
        $l1 = $this->user(User::ROLE_FINANCE, 1, 'AP Accountant');
        $l2default = $this->user(User::ROLE_APPROVER, 2, 'Group Treasury Lead');
        ApprovalLevel::where('level', 2)->first()->update(['default_approver_id' => $l2default->id]);

        // 300 reaches L1 only, so L2 is displayed through its default approver.
        $pr = $this->actingAs($this->user(User::ROLE_FINANCE))->postJson('/api/payment-requests', [
            'invoice_ids' => [$this->postedInvoice(300)->id],
            'approvers' => [1 => $l1->id],
        ])->assertCreated()->json();

        $html = $this->render($pr['id']);

        $this->assertStringContainsString('AP Accountant', $html);
        // A level nobody reached shows its default approver under their job title too.
        $this->assertStringContainsString('Group Treasury Lead', $html);
    }

    public function test_the_public_link_shows_the_job_title_against_the_stage(): void
    {
        $approver = $this->user(User::ROLE_FINANCE, 1, 'AP Accountant');

        $pr = $this->actingAs($this->user(User::ROLE_FINANCE))->postJson('/api/payment-requests', [
            'invoice_ids' => [$this->postedInvoice()->id],
            'approvers' => [1 => $approver->id],
        ])->assertCreated()->json();

        $model = PaymentRequest::find($pr['id']);
        $token = $model->approvals()->where('sequence', 1)->first()->view_token;

        $this->get("/prf/view/{$model->id}/{$token}")
            ->assertOk()
            ->assertSee('AP Accountant');
    }
}
