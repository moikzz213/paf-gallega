<?php

namespace Tests\Feature;

use App\Mail\InvoiceCorrectedDuringApproval;
use App\Models\AuditLog;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The invoice's own submitter may correct it while its payment request is in approval — within
 * Finance's limits plus a locked vendor — and the option closes once the chain has signed.
 *
 * CR: ai/change-requests/requester-corrections-during-approval-and-payment-request-attachments.md
 */
class SubmitterCorrectionDuringApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $submitter;

    private User $finance;

    private Vendor $vendor;

    private PaymentRequest $pr;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('local');
        BusinessUnit::create(['name' => 'GGL', 'is_active' => true]);
        Department::create(['name' => 'Freight Forwarding', 'is_active' => true]);
        Location::create(['name' => 'Dubai-Jafza', 'is_active' => true]);
        Currency::firstOrCreate(['name' => 'AED']);
        Currency::firstOrCreate(['name' => 'USD']);
        $this->vendor = Vendor::create(['name' => 'Atlas Maritime Shipping', 'vendor_code' => 'VEN2071', 'is_active' => true]);
        Vendor::create(['name' => 'Some Other Vendor', 'vendor_code' => 'VEN9999', 'is_active' => true]);

        $this->submitter = $this->user(User::ROLE_REQUESTER);
        $this->finance = $this->user(User::ROLE_FINANCE);

        $this->pr = PaymentRequest::create([
            'reference_no' => PaymentRequest::nextReferenceNo(),
            'created_by' => $this->finance->id,
            'status' => PaymentRequest::STATUS_IN_APPROVAL,
            'current_stage' => 2,
            'total_amount' => 5000,
            'sent_at' => now()->subDays(2),
        ]);

        // Stage 1 has already signed; stage 2 is waiting.
        foreach ([1 => PaymentRequestApproval::STATUS_APPROVED, 2 => PaymentRequestApproval::STATUS_PENDING] as $sequence => $status) {
            PaymentRequestApproval::create([
                'payment_request_id' => $this->pr->id, 'sequence' => $sequence, 'level' => $sequence,
                'label' => "Level {$sequence}", 'approver_id' => $this->user(User::ROLE_APPROVER)->id,
                'status' => $status, 'acted_at' => $status === PaymentRequestApproval::STATUS_APPROVED ? now()->subDay() : null,
            ]);
        }

        $this->invoice = Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_id' => $this->vendor->id, 'vendor_name' => $this->vendor->name, 'invoice_no' => 'INV-10732',
            'invoice_date' => now()->subDays(20)->toDateString(),
            'currency' => 'AED', 'amount' => 5000, 'tax_amount' => 0, 'total_amount' => 5000,
            'business_unit' => 'GGL', 'department' => 'Freight Forwarding', 'location' => 'Dubai-Jafza',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => Invoice::STATUS_POSTED, 'payment_status' => Invoice::PAY_IN_APPROVAL,
            'erp_doc_no' => '5139283648', 'posting_date' => now()->subDays(4)->toDateString(),
            'submitted_by' => $this->submitter->id, 'submitted_at' => now()->subDays(10),
            'payment_request_id' => $this->pr->id,
        ]);
        $this->invoice->items()->create([
            'sort_order' => 0, 'job_no' => 'SSE00005216', 'currency' => 'AED', 'description' => 'Ocean freight',
            'amount' => 5000, 'tax_amount' => 0, 'total_amount' => 5000,
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

    /** @param  array<string, mixed>  $line */
    private function payload(array $line = [], array $header = []): array
    {
        return array_merge([
            'vendor_id' => $this->vendor->id,
            'invoice_no' => 'INV-10732',
            'invoice_date' => $this->invoice->invoice_date->toDateString(),
            'business_unit' => 'GGL',
            'department' => 'Freight Forwarding',
            'location' => 'Dubai-Jafza',
            'payment_method' => 'bank_transfer',
            'priority' => 'normal',
            'items' => [array_merge(
                ['job_no' => 'SSE00005216', 'currency' => 'AED', 'description' => 'Ocean freight', 'amount' => 5000, 'tax_rate' => 0],
                $line,
            )],
        ], $header);
    }

    private function correct(array $line = [], array $header = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->submitter)->post("/api/invoices/{$this->invoice->id}", $this->payload($line, $header));
    }

    public function test_the_submitter_can_correct_while_the_request_is_in_approval(): void
    {
        $this->correct(['job_no' => 'SSE00005217', 'amount' => 4500])->assertOk();

        $this->invoice->refresh();
        $this->assertSame('SSE00005217', $this->invoice->items->first()->job_no);
        $this->assertEquals(4500, (float) $this->invoice->total_amount);
        // Still on the same request, still in approval, still at the same stage; the total follows.
        $this->pr->refresh();
        $this->assertSame($this->pr->id, $this->invoice->payment_request_id);
        $this->assertSame(Invoice::PAY_IN_APPROVAL, $this->invoice->payment_status);
        $this->assertSame(PaymentRequest::STATUS_IN_APPROVAL, $this->pr->status);
        $this->assertSame(2, $this->pr->current_stage);
        $this->assertEquals(4500, (float) $this->pr->total_amount);
    }

    public function test_approvals_already_given_are_kept(): void
    {
        $this->correct(['description' => 'Ocean freight — corrected'])->assertOk();

        $this->assertSame(
            [PaymentRequestApproval::STATUS_APPROVED, PaymentRequestApproval::STATUS_PENDING],
            $this->pr->approvals()->pluck('status')->all(),
        );
    }

    public function test_the_correction_is_audited_and_marks_the_request(): void
    {
        $this->correct(['description' => 'Ocean freight — corrected', 'amount' => 4800])->assertOk();

        $log = AuditLog::where('action', 'corrected_during_approval')->sole();
        $this->assertSame($this->invoice->id, $log->invoice_id);
        $this->assertSame($this->pr->id, $log->payment_request_id);
        $this->assertSame($this->submitter->id, $log->user_id);
        $this->assertStringContainsString('line items', $log->description);
        $this->assertEquals(5000, $log->old_values['total_amount']);

        $this->actingAs($this->finance)->getJson("/api/payment-requests/{$this->pr->id}")
            ->assertOk()
            ->assertJsonPath('corrections.0.invoice.reference_no', $this->invoice->reference_no)
            ->assertJsonPath('corrections.0.user.name', $this->submitter->name);
    }

    public function test_finance_who_raised_the_request_is_emailed(): void
    {
        $this->correct(['amount' => 4500])->assertOk();

        Mail::assertQueued(InvoiceCorrectedDuringApproval::class, function (InvoiceCorrectedDuringApproval $mail) {
            return $mail->hasTo($this->finance->email)
                && $mail->previousTotal === 5000.0
                && in_array('line items', $mail->changed, true);
        });
    }

    public function test_the_vendor_is_locked_for_the_submitter(): void
    {
        $other = Vendor::where('vendor_code', 'VEN9999')->sole();

        $this->correct([], ['vendor_id' => $other->id])->assertStatus(422)->assertJsonValidationErrors('vendor_id');
        $this->assertSame($this->vendor->id, $this->invoice->refresh()->vendor_id);
    }

    public function test_a_legacy_invoice_can_be_linked_to_its_own_vendor_record(): void
    {
        // Predates the vendor master list: name only, no vendor_id. Linking it to the record of the
        // same name changes nothing about who is paid.
        $this->invoice->update(['vendor_id' => null]);

        $this->correct()->assertOk();
        $this->assertSame($this->vendor->id, $this->invoice->refresh()->vendor_id);
    }

    public function test_the_total_cannot_rise(): void
    {
        $this->correct(['amount' => 5000.01])->assertStatus(422)->assertJsonValidationErrors('items');
        $this->assertEquals(5000, (float) $this->invoice->refresh()->total_amount);
    }

    public function test_the_currency_is_locked(): void
    {
        $this->correct(['currency' => 'USD', 'amount' => 100])->assertStatus(422)->assertJsonValidationErrors('items');
    }

    public function test_the_advance_marker_is_locked(): void
    {
        $this->correct([], ['is_advance_payment' => '1'])->assertStatus(422)->assertJsonValidationErrors('is_advance_payment');
    }

    public function test_the_submitter_can_attach_documents_while_correcting(): void
    {
        $this->actingAs($this->submitter)->post("/api/invoices/{$this->invoice->id}", $this->payload() + [
            'documents' => [UploadedFile::fake()->create('corrected-invoice.pdf', 20, 'application/pdf')],
        ])->assertOk();

        $document = $this->invoice->documents()->sole();
        $this->assertSame('corrected-invoice.pdf', $document->original_name);
        // The chain has not signed yet, so this was in front of the approvers still to act.
        $this->assertFalse($document->uploaded_after_approval);
    }

    public function test_the_option_closes_once_the_request_is_fully_approved(): void
    {
        $this->pr->update(['status' => PaymentRequest::STATUS_APPROVED, 'approved_at' => now()]);
        $this->invoice->update(['payment_status' => Invoice::PAY_APPROVED]);

        $this->correct(['job_no' => 'SSE00005217'])->assertStatus(422);
        $this->assertSame('SSE00005216', $this->invoice->refresh()->items->first()->job_no);
        Mail::assertNothingQueued();
    }

    public function test_the_option_is_closed_once_paid(): void
    {
        $this->pr->update(['status' => PaymentRequest::STATUS_PAID, 'paid_at' => now()]);
        $this->invoice->update(['payment_status' => Invoice::PAY_PAID]);

        $this->correct(['job_no' => 'SSE00005217'])->assertStatus(422);
    }

    public function test_someone_elses_invoice_cannot_be_corrected(): void
    {
        $this->correct(['job_no' => 'SSE00005217'], [], $this->user(User::ROLE_REQUESTER))->assertForbidden();
    }

    public function test_an_approver_on_the_chain_gains_no_correction_right(): void
    {
        $approver = $this->pr->approvals()->where('sequence', 2)->sole()->approver;

        $this->correct(['job_no' => 'SSE00005217'], [], $approver)->assertForbidden();
    }

    public function test_finance_corrections_are_unchanged_and_not_marked(): void
    {
        $this->correct(['description' => 'Finance fix'], [], $this->finance)->assertOk();

        $this->assertSame(0, AuditLog::where('action', 'corrected_during_approval')->count());
        $this->assertSame(1, AuditLog::where('action', 'updated')->count());
        Mail::assertNothingQueued();
    }

    public function test_the_paf_and_approval_page_show_the_correction(): void
    {
        $this->correct(['description' => 'Ocean freight — corrected'])->assertOk();

        $html = view('pdf.payment-request', [
            'paymentRequest' => $this->pr->fresh(['invoices.items', 'approvals', 'corrections.user', 'corrections.invoice']),
            'supplierCodes' => collect(),
            'approvalLevels' => collect(),
        ])->render();
        $this->assertStringContainsString('CORRECTED DURING APPROVAL', $html);
        $this->assertStringContainsString($this->invoice->reference_no, $html);

        $token = $this->pr->approvals()->where('sequence', 2)->value('view_token');
        $this->get("/prf/view/{$this->pr->id}/{$token}")
            ->assertOk()
            ->assertSee('Corrected during approval.');
    }
}
