<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PaymentRequestReferenceNoTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::firstOrCreate(
            ['email' => 'fin-ref@t.local'],
            ['name' => 'Fin', 'password' => 'password', 'role' => User::ROLE_FINANCE, 'is_active' => true],
        );
    }

    private function paymentRequest(string $referenceNo): PaymentRequest
    {
        return PaymentRequest::create([
            'reference_no' => $referenceNo,
            'view_token' => bin2hex(random_bytes(8)),
            'created_by' => $this->user()->id,
            'status' => PaymentRequest::STATUS_DRAFT,
            'current_stage' => 1,
            'total_amount' => 100,
        ]);
    }

    private function invoice(string $referenceNo): Invoice
    {
        return Invoice::create([
            'reference_no' => $referenceNo,
            'vendor_name' => 'Al Noor Logistics',
            'invoice_no' => 'SUP-'.bin2hex(random_bytes(4)),
            'invoice_date' => now()->toDateString(),
            'currency' => 'AED', 'amount' => 100, 'tax_amount' => 0, 'total_amount' => 100,
            'business_unit' => 'GIL', 'department' => 'Yard', 'location' => 'Head Office',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => Invoice::STATUS_SUBMITTED,
            'payment_status' => Invoice::PAY_NOT_INITIATED,
            'submitted_by' => $this->user()->id,
            'submitted_at' => now(),
        ]);
    }

    public function test_first_reference_of_the_year_uses_the_paf_prefix(): void
    {
        $this->assertSame('PAF-'.now()->year.'-00001', PaymentRequest::nextReferenceNo());
    }

    public function test_the_year_tracks_the_current_year(): void
    {
        Carbon::setTestNow('2031-03-09 10:00:00');

        $this->assertStringStartsWith('PAF-2031-', PaymentRequest::nextReferenceNo());

        Carbon::setTestNow();
    }

    public function test_sequence_continues_from_the_legacy_prf_prefix(): void
    {
        $year = now()->year;
        $this->paymentRequest("PRF-{$year}-00021");

        // Must not restart at 00001 just because the prefix changed.
        $this->assertSame("PAF-{$year}-00022", PaymentRequest::nextReferenceNo());
    }

    public function test_sequence_continues_from_its_own_prefix(): void
    {
        $year = now()->year;
        $this->paymentRequest("PRF-{$year}-00021");
        $this->paymentRequest(PaymentRequest::nextReferenceNo());

        $this->assertSame("PAF-{$year}-00023", PaymentRequest::nextReferenceNo());
    }

    public function test_numbering_restarts_in_a_new_year(): void
    {
        Carbon::setTestNow('2030-12-31 23:00:00');
        $this->paymentRequest(PaymentRequest::nextReferenceNo());
        $this->assertSame('PAF-2030-00002', PaymentRequest::nextReferenceNo());

        Carbon::setTestNow('2031-01-01 01:00:00');
        $this->assertSame('PAF-2031-00001', PaymentRequest::nextReferenceNo());

        Carbon::setTestNow();
    }

    public function test_first_invoice_reference_of_the_year_uses_the_inv_prefix(): void
    {
        $this->assertSame('INV-'.now()->year.'-00001', Invoice::nextReferenceNo());
    }

    public function test_invoice_sequence_continues_from_the_legacy_paf_prefix(): void
    {
        $year = now()->year;
        $this->invoice("PAF-{$year}-00049");

        $this->assertSame("INV-{$year}-00050", Invoice::nextReferenceNo());
    }

    /**
     * The two entities previously both minted PAF- references from independent counters, so the
     * same identifier could name an invoice and an unrelated payment request.
     */
    public function test_invoice_and_payment_request_references_cannot_collide(): void
    {
        $year = now()->year;
        $this->invoice("PAF-{$year}-00049");
        $this->paymentRequest("PRF-{$year}-00021");

        $invoiceRef = Invoice::nextReferenceNo();
        $paymentRequestRef = PaymentRequest::nextReferenceNo();

        $this->assertSame("INV-{$year}-00050", $invoiceRef);
        $this->assertSame("PAF-{$year}-00022", $paymentRequestRef);
        $this->assertNotSame($invoiceRef, $paymentRequestRef);

        // Each counter reads only its own table, so the sequences stay independent.
        $this->assertStringStartsWith('INV-', $invoiceRef);
        $this->assertStringStartsWith('PAF-', $paymentRequestRef);
    }
}
