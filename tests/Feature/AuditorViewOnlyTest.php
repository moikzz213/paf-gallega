<?php

namespace Tests\Feature;

use App\Models\ApprovalLevel;
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
 * An auditor opens every page and sees every record, but every write is refused — whatever the
 * endpoint, so a write route added later is covered without an auditor check of its own.
 */
class AuditorViewOnlyTest extends TestCase
{
    use RefreshDatabase;

    private User $auditor;

    private User $requester;

    private User $approver;

    private Invoice $invoice;

    private PaymentRequest $pr;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Department::create(['name' => 'Customs Clearance', 'is_active' => true]);

        $this->auditor = $this->user(User::ROLE_AUDITOR);
        $this->requester = $this->user(User::ROLE_REQUESTER);
        $this->approver = $this->user(User::ROLE_APPROVER);
        $finance = $this->user(User::ROLE_FINANCE);

        $this->pr = PaymentRequest::create([
            'reference_no' => PaymentRequest::nextReferenceNo(),
            'created_by' => $finance->id,
            'status' => PaymentRequest::STATUS_IN_APPROVAL,
            'current_stage' => 1,
            'total_amount' => 1000,
            'sent_at' => now(),
        ]);
        PaymentRequestApproval::create([
            'payment_request_id' => $this->pr->id, 'sequence' => 1, 'level' => 1, 'label' => 'Level 1',
            'approver_id' => $this->approver->id, 'status' => PaymentRequestApproval::STATUS_PENDING,
        ]);

        $this->invoice = Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => 'Atlas Maritime Shipping', 'invoice_no' => 'INV-'.uniqid(),
            'invoice_date' => now()->subDays(5)->toDateString(),
            'currency' => 'AED', 'amount' => 1000, 'tax_amount' => 0, 'total_amount' => 1000,
            'business_unit' => 'GGL', 'department' => 'Customs Clearance', 'location' => 'Dubai-Jafza',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => Invoice::STATUS_POSTED, 'payment_status' => Invoice::PAY_IN_APPROVAL,
            'submitted_by' => $this->requester->id, 'submitted_at' => now()->subDays(2),
            'payment_request_id' => $this->pr->id,
        ]);
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

    public function test_an_auditor_sees_every_invoice_and_payment_request(): void
    {
        $this->actingAs($this->auditor);

        $this->getJson('/api/invoices')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/invoices/{$this->invoice->id}")->assertOk();
        $this->getJson('/api/payment-requests')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/payment-requests/{$this->pr->id}")->assertOk();
        $this->getJson('/api/payment-requests/pending')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/dashboard')->assertOk();
        $this->getJson('/api/reports')->assertOk();
    }

    public function test_an_auditor_can_open_the_admin_and_master_data_pages(): void
    {
        $this->actingAs($this->auditor);

        $this->getJson('/api/audit-logs')->assertOk();
        $this->getJson('/api/audit-logs/actions')->assertOk();
        $this->getJson('/api/users')->assertOk();
        $this->getJson('/api/approval-levels')->assertOk();
        $this->getJson('/api/master-data/vendors')->assertOk();
    }

    public function test_an_auditor_can_open_documents(): void
    {
        $path = UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf')->store('documents', 'local');
        $document = InvoiceDocument::create([
            'invoice_id' => $this->invoice->id, 'uploaded_by' => $this->requester->id,
            'original_name' => 'invoice.pdf', 'file_path' => $path, 'mime_type' => 'application/pdf', 'size' => 10,
        ]);

        $this->actingAs($this->auditor)->get("/api/documents/{$document->id}/download")->assertOk();
    }

    public function test_every_write_is_refused_and_nothing_changes(): void
    {
        $level = ApprovalLevel::create(['level' => 1, 'name' => 'Level 1', 'min_amount' => 0, 'is_active' => true]);
        $this->actingAs($this->auditor);

        $writes = [
            ['postJson', '/api/invoices'],
            ['postJson', "/api/invoices/{$this->invoice->id}"],
            ['deleteJson', "/api/invoices/{$this->invoice->id}"],
            ['postJson', "/api/invoices/{$this->invoice->id}/cancel"],
            ['postJson', "/api/invoices/{$this->invoice->id}/post"],
            ['postJson', "/api/invoices/{$this->invoice->id}/query"],
            ['postJson', "/api/invoices/{$this->invoice->id}/release"],
            ['postJson', '/api/payment-requests'],
            ['postJson', "/api/payment-requests/{$this->pr->id}/approve"],
            ['postJson', "/api/payment-requests/{$this->pr->id}/query"],
            ['postJson', "/api/payment-requests/{$this->pr->id}/resubmit"],
            ['postJson', "/api/payment-requests/{$this->pr->id}/mark-paid"],
            ['postJson', "/api/payment-requests/{$this->pr->id}/withdraw"],
            ['postJson', '/api/users'],
            ['putJson', "/api/users/{$this->requester->id}"],
            ['postJson', '/api/approval-levels'],
            ['putJson', "/api/approval-levels/{$level->id}"],
            ['deleteJson', "/api/approval-levels/{$level->id}"],
            ['postJson', '/api/master-data/vendors'],
        ];

        foreach ($writes as [$method, $uri]) {
            $this->{$method}($uri, ['comments' => 'x'])
                ->assertForbidden()
                ->assertJson(['message' => 'Auditors have view-only access.']);
        }

        $this->assertSame(Invoice::STATUS_POSTED, $this->invoice->fresh()->status);
        $this->assertSame(PaymentRequest::STATUS_IN_APPROVAL, $this->pr->fresh()->status);
        $this->assertSame(1, Invoice::count());
        $this->assertNotNull(ApprovalLevel::find($level->id));
    }

    public function test_an_auditor_can_still_sign_out_and_change_their_own_password(): void
    {
        $this->actingAs($this->auditor);

        $this->putJson('/api/profile/password', [
            'current_password' => 'password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();

        $this->postJson('/api/logout')->assertOk();
    }

    public function test_an_auditor_is_never_offered_as_an_approver(): void
    {
        $this->assertFalse($this->auditor->canApprove());
        $this->assertFalse(User::eligibleApprovers()->whereKey($this->auditor->id)->exists());
    }

    public function test_an_admin_can_create_an_auditor(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);

        $this->actingAs($admin)->postJson('/api/users', [
            'name' => 'New Auditor', 'email' => 'new.auditor@t.local', 'password' => 'password123',
            'role' => User::ROLE_AUDITOR, 'is_active' => true,
        ])->assertCreated()->assertJsonPath('role', User::ROLE_AUDITOR);
    }

    public function test_other_roles_keep_their_write_access(): void
    {
        $this->actingAs($this->approver)
            ->postJson("/api/payment-requests/{$this->pr->id}/approve", ['comments' => 'ok'])
            ->assertOk();
    }
}
