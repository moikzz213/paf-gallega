<?php

namespace Tests\Feature;

use App\Mail\InvoiceCorrectedAfterPosting;
use App\Models\AuditLog;
use App\Models\BusinessUnit;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Finance and administrators may correct a posted invoice that is not yet in a payment cycle,
 * instead of querying it back to the submitter. Nothing is locked, the invoice stays posted under
 * its ERP document, and the submitter is told what changed.
 *
 * CR: ai/change-requests/allow-finance-to-correct-posted-invoices-before-payment.md
 */
class FinanceCorrectionAfterPostingTest extends TestCase
{
    use RefreshDatabase;

    private User $submitter;

    private User $finance;

    private User $poster;

    private Vendor $vendor;

    private Vendor $otherVendor;

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
        $this->otherVendor = Vendor::create(['name' => 'Some Other Vendor', 'vendor_code' => 'VEN9999', 'is_active' => true]);

        $this->submitter = $this->user(User::ROLE_REQUESTER);
        $this->finance = $this->user(User::ROLE_FINANCE);
        $this->poster = $this->user(User::ROLE_FINANCE);

        $this->invoice = Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_id' => $this->vendor->id, 'vendor_name' => $this->vendor->name, 'invoice_no' => 'INV-10732',
            'invoice_date' => now()->subDays(20)->toDateString(),
            'currency' => 'AED', 'amount' => 5000, 'tax_amount' => 0, 'total_amount' => 5000,
            'business_unit' => 'GGL', 'department' => 'Freight Forwarding', 'location' => 'Dubai-Jafza',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => Invoice::STATUS_POSTED, 'payment_status' => Invoice::PAY_NOT_INITIATED,
            'erp_doc_no' => '5139283648', 'posting_date' => now()->subDays(4)->toDateString(),
            'posted_by' => $this->poster->id, 'posted_at' => now()->subDays(4),
            'submitted_by' => $this->submitter->id, 'submitted_at' => now()->subDays(10),
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

    private function correct(array $line = [], array $header = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->finance)->post("/api/invoices/{$this->invoice->id}", array_merge([
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
        ], $header));
    }

    public function test_finance_can_correct_a_posted_invoice_and_it_stays_posted(): void
    {
        $this->correct(['job_no' => 'SSE00005217', 'amount' => 4800])->assertOk();

        $this->invoice->refresh();
        $this->assertSame('SSE00005217', $this->invoice->items->first()->job_no);
        $this->assertEquals(4800, (float) $this->invoice->total_amount);
        // Still posted under the same ERP document, and still recorded as posted by whoever posted it.
        $this->assertSame(Invoice::STATUS_POSTED, $this->invoice->status);
        $this->assertSame(Invoice::PAY_NOT_INITIATED, $this->invoice->payment_status);
        $this->assertSame('5139283648', $this->invoice->erp_doc_no);
        $this->assertSame($this->poster->id, $this->invoice->posted_by);
        $this->assertTrue($this->invoice->isPayable());
    }

    public function test_nothing_is_locked_the_total_currency_vendor_and_advance_marker_can_all_change(): void
    {
        $this->correct(
            ['currency' => 'USD', 'amount' => 9000],
            ['vendor_id' => $this->otherVendor->id, 'is_advance_payment' => '1'],
        )->assertOk();

        $this->invoice->refresh();
        $this->assertSame('USD', $this->invoice->currency);
        $this->assertEquals(9000, (float) $this->invoice->total_amount);
        $this->assertSame($this->otherVendor->id, $this->invoice->vendor_id);
        $this->assertSame('Some Other Vendor', $this->invoice->vendor_name);
        $this->assertTrue($this->invoice->is_advance_payment);
    }

    public function test_an_administrator_can_correct_it_too(): void
    {
        $this->correct(['description' => 'Ocean freight — corrected'], as: $this->user(User::ROLE_ADMIN))->assertOk();

        $this->assertSame('Ocean freight — corrected', $this->invoice->items()->first()->description);
    }

    public function test_the_correction_is_audited_with_the_erp_document_and_what_it_replaced(): void
    {
        $this->correct(['currency' => 'USD', 'amount' => 4800], ['vendor_id' => $this->otherVendor->id])->assertOk();

        $log = AuditLog::where('action', 'corrected_after_posting')->sole();
        $this->assertSame($this->invoice->id, $log->invoice_id);
        $this->assertSame($this->finance->id, $log->user_id);
        $this->assertStringContainsString('ERP doc 5139283648', $log->description);
        $this->assertStringContainsString('AED 5,000.00 → USD 4,800.00', $log->description);
        $this->assertStringContainsString('vendor', $log->description);
        $this->assertStringContainsString('currency', $log->description);
        $this->assertStringContainsString('line items', $log->description);
        $this->assertSame('Atlas Maritime Shipping', $log->old_values['vendor_name']);
        $this->assertEquals(5000, $log->old_values['total_amount']);
        $this->assertSame('Some Other Vendor', $log->new_values['vendor_name']);
        $this->assertEquals(4800, $log->new_values['total_amount']);
    }

    public function test_the_submitter_is_emailed_what_changed(): void
    {
        $this->correct(['job_no' => 'SSE00005217', 'amount' => 4800])->assertOk();

        Mail::assertQueued(InvoiceCorrectedAfterPosting::class, function (InvoiceCorrectedAfterPosting $mail) {
            return $mail->hasTo($this->submitter->email)
                && $mail->corrector->is($this->finance)
                && $mail->previousCurrency === 'AED'
                && $mail->previousTotal === 5000.0
                && in_array('line items', $mail->changed, true);
        });
    }

    public function test_no_email_when_finance_corrects_an_invoice_they_submitted_themselves(): void
    {
        $this->invoice->update(['submitted_by' => $this->finance->id]);

        $this->correct(['amount' => 4800])->assertOk();

        Mail::assertNothingQueued();
    }

    public function test_the_email_renders(): void
    {
        $html = (new InvoiceCorrectedAfterPosting($this->invoice->load('submitter'), $this->finance, 'AED', 6000.0, ['line items']))->render();

        $this->assertStringContainsString($this->invoice->reference_no, $html);
        $this->assertStringContainsString('5139283648', $html);
        $this->assertStringContainsString('line items', $html);
        $this->assertStringContainsString($this->finance->name, $html);
    }

    public function test_the_submitter_still_cannot_edit_a_posted_invoice(): void
    {
        $this->correct(['amount' => 4800], as: $this->submitter)->assertStatus(422);

        $this->assertEquals(5000, (float) $this->invoice->refresh()->total_amount);
    }

    public function test_an_approver_cannot_correct_it(): void
    {
        $this->correct(['amount' => 4800], as: $this->user(User::ROLE_APPROVER))->assertForbidden();
    }

    public function test_the_duplicate_invoice_number_rule_still_applies(): void
    {
        Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_id' => $this->vendor->id, 'vendor_name' => $this->vendor->name, 'invoice_no' => 'INV-TAKEN',
            'invoice_date' => now()->toDateString(), 'currency' => 'AED', 'amount' => 100, 'tax_amount' => 0, 'total_amount' => 100,
            'business_unit' => 'GGL', 'department' => 'Freight Forwarding', 'location' => 'Dubai-Jafza',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => Invoice::STATUS_SUBMITTED, 'payment_status' => Invoice::PAY_NOT_INITIATED,
            'submitted_by' => $this->submitter->id, 'submitted_at' => now(),
        ]);

        $this->correct(header: ['invoice_no' => 'INV-TAKEN'])->assertStatus(422)->assertJsonValidationErrors('invoice_no');
    }

    public function test_once_in_a_payment_request_the_in_cycle_limits_apply_instead(): void
    {
        $pr = PaymentRequest::create([
            'reference_no' => PaymentRequest::nextReferenceNo(), 'created_by' => $this->finance->id,
            'status' => PaymentRequest::STATUS_IN_APPROVAL, 'current_stage' => 1, 'total_amount' => 5000, 'sent_at' => now(),
        ]);
        $this->invoice->update(['payment_status' => Invoice::PAY_IN_APPROVAL, 'payment_request_id' => $pr->id]);

        // The approvals cover AED 5,000, so the currency is locked again and the total cannot rise.
        $this->correct(['currency' => 'USD', 'amount' => 5000])->assertStatus(422)->assertJsonValidationErrors('items');
        $this->correct(['amount' => 6000])->assertStatus(422)->assertJsonValidationErrors('items');
        $this->assertSame(0, AuditLog::where('action', 'corrected_after_posting')->count());
    }

    public function test_a_returned_invoice_can_be_corrected(): void
    {
        // Rejected, withdrawn and released invoices all come back posted and not initiated.
        $pr = PaymentRequest::create([
            'reference_no' => PaymentRequest::nextReferenceNo(), 'created_by' => $this->finance->id,
            'status' => PaymentRequest::STATUS_IN_APPROVAL, 'current_stage' => 1, 'total_amount' => 5000, 'sent_at' => now(),
        ]);
        $this->invoice->update(['payment_status' => Invoice::PAY_IN_APPROVAL, 'payment_request_id' => $pr->id]);
        $this->invoice->update(['payment_status' => Invoice::PAY_NOT_INITIATED, 'payment_request_id' => null]);

        $this->correct(['amount' => 6000])->assertOk();

        $this->assertEquals(6000, (float) $this->invoice->refresh()->total_amount);
    }

    public function test_a_cancelled_invoice_cannot_be_corrected(): void
    {
        $this->invoice->update(['status' => Invoice::STATUS_CANCELLED]);

        $this->correct(['amount' => 4800])->assertStatus(422);
    }
}
