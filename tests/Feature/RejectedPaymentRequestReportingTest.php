<?php

namespace Tests\Feature;

use App\Mail\PaymentRequestRejected;
use App\Models\ApprovalLevel;
use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\PaymentRequestService;
use App\Support\InvoiceReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A rejected PAF used to vanish from the report.
 *
 * Rejection returns the invoices to Finance by clearing `payment_request_id`, which is the only
 * link the report has — so the reference, the rejection reason and every approver comment
 * disappeared at the moment of rejection, and re-submitting the corrected invoices then reused the
 * column for the new request. `invoice_rejections` keeps the history out of that column's way.
 */
class RejectedPaymentRequestReportingTest extends TestCase
{
    use RefreshDatabase;

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

    private function invoice(User $submitter): Invoice
    {
        return Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => 'Acme Trading', 'invoice_no' => 'SUP-'.uniqid(), 'invoice_date' => now()->toDateString(),
            'currency' => 'AED', 'amount' => 5000, 'tax_amount' => 0, 'total_amount' => 5000,
            'business_unit' => 'GIL', 'department' => 'Warehouse', 'location' => 'Head Office',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => Invoice::STATUS_POSTED, 'payment_status' => Invoice::PAY_NOT_INITIATED,
            'submitted_by' => $submitter->id, 'submitted_at' => now(),
        ]);
    }

    /** @return array{invoice: Invoice, pr: PaymentRequest, l1: User, finance: User, requester: User} */
    private function requestInApproval(): array
    {
        ApprovalLevel::create(['level' => 1, 'name' => 'Manager', 'min_amount' => 0, 'is_active' => true]);

        $finance = $this->user(User::ROLE_FINANCE);
        $requester = $this->user(User::ROLE_REQUESTER);
        $l1 = $this->user(User::ROLE_APPROVER, ['approval_level' => 1]);
        $invoice = $this->invoice($requester);

        $pr = app(PaymentRequestService::class)->create([$invoice->id], $finance, [
            ['approver_id' => $l1->id, 'label' => 'Manager', 'level' => 1, 'is_adhoc' => false],
        ]);

        return compact('invoice', 'pr', 'l1', 'finance', 'requester');
    }

    /** The report row as the Reports page, the Excel download and the export API all produce it. */
    private function row(Invoice $invoice): array
    {
        return InvoiceReport::row($invoice->fresh()->load(InvoiceReport::RELATIONS));
    }

    public function test_the_rejected_payment_request_still_shows_on_the_report(): void
    {
        ['invoice' => $invoice, 'pr' => $pr, 'l1' => $l1] = $this->requestInApproval();

        app(PaymentRequestService::class)->reject($pr, $l1, 'Vendor bank details are wrong');

        $row = $this->row($invoice);

        $this->assertStringContainsString($pr->reference_no, $row['rejected_payment_requests']);
        $this->assertStringContainsString('Rejected: Vendor bank details are wrong', $row['remarks']);
        // The invoice is back in the pool, so it has no current request.
        $this->assertNull($row['payment_request']);
    }

    public function test_the_rejection_survives_the_invoice_being_re_submitted(): void
    {
        ['invoice' => $invoice, 'pr' => $pr, 'l1' => $l1, 'finance' => $finance] = $this->requestInApproval();

        app(PaymentRequestService::class)->reject($pr, $l1, 'Missing purchase order');

        // Finance corrects and re-initiates — the step that used to overwrite the only link back.
        $second = app(PaymentRequestService::class)->create([$invoice->id], $finance, [
            ['approver_id' => $l1->id, 'label' => 'Manager', 'level' => 1, 'is_adhoc' => false],
        ]);

        $row = $this->row($invoice);

        $this->assertSame($second->reference_no, $row['payment_request']);
        $this->assertStringContainsString($pr->reference_no, $row['rejected_payment_requests']);
        $this->assertStringContainsString('Missing purchase order', $row['remarks']);
    }

    public function test_two_rejections_are_both_reported_and_attributed(): void
    {
        ['invoice' => $invoice, 'pr' => $first, 'l1' => $l1, 'finance' => $finance] = $this->requestInApproval();

        app(PaymentRequestService::class)->reject($first, $l1, 'Wrong cost centre');

        $second = app(PaymentRequestService::class)->create([$invoice->id], $finance, [
            ['approver_id' => $l1->id, 'label' => 'Manager', 'level' => 1, 'is_adhoc' => false],
        ]);
        app(PaymentRequestService::class)->reject($second, $l1, 'Still wrong');

        $row = $this->row($invoice);

        $this->assertStringContainsString($first->reference_no, $row['rejected_payment_requests']);
        $this->assertStringContainsString($second->reference_no, $row['rejected_payment_requests']);
        // Each remark names the round it came from, or a twice-rejected invoice is unreadable.
        $this->assertStringContainsString("[{$first->reference_no}] Rejected: Wrong cost centre", $row['remarks']);
        $this->assertStringContainsString("[{$second->reference_no}] Rejected: Still wrong", $row['remarks']);
    }

    public function test_rejecting_via_the_public_link_records_the_same_history(): void
    {
        ['invoice' => $invoice, 'pr' => $pr] = $this->requestInApproval();
        $token = $pr->approvals()->where('sequence', 1)->value('view_token');

        Mail::fake();

        $this->post("/prf/view/{$pr->id}/{$token}/reject", ['comments' => 'Duplicate submission'])
            ->assertRedirect();

        $row = $this->row($invoice);

        $this->assertStringContainsString($pr->reference_no, $row['rejected_payment_requests']);
        $this->assertStringContainsString('Duplicate submission', $row['remarks']);
    }

    /** The blocking regression: rejection must still hand the invoices back to Finance. */
    public function test_a_rejected_invoice_is_still_available_for_a_new_request(): void
    {
        ['invoice' => $invoice, 'pr' => $pr, 'l1' => $l1, 'finance' => $finance] = $this->requestInApproval();

        app(PaymentRequestService::class)->reject($pr, $l1, 'Correct and resubmit');

        $invoice->refresh();

        $this->assertNull($invoice->payment_request_id);
        $this->assertSame(Invoice::PAY_NOT_INITIATED, $invoice->payment_status);
        $this->assertTrue($invoice->isPayable());

        $this->actingAs($finance)
            ->getJson('/api/payment-requests/eligible')
            ->assertOk()
            ->assertJsonFragment(['reference_no' => $invoice->reference_no]);
    }

    public function test_an_invoice_never_rejected_reports_nothing_extra(): void
    {
        $requester = $this->user(User::ROLE_REQUESTER);
        $row = $this->row($this->invoice($requester));

        $this->assertSame('', $row['rejected_payment_requests']);
        $this->assertSame('', $row['remarks']);
    }

    public function test_the_new_column_reaches_the_spreadsheet_and_the_export_api(): void
    {
        $this->assertArrayHasKey('rejected_payment_requests', InvoiceReport::columns());
        $this->assertContains('Rejected Payment Requests', InvoiceReport::headings());
    }

    public function test_the_rejection_notice_reaches_finance_and_the_approval_chain(): void
    {
        ['pr' => $pr, 'l1' => $l1, 'finance' => $finance, 'requester' => $requester] = $this->requestInApproval();
        $otherFinance = $this->user(User::ROLE_FINANCE);
        $inactiveFinance = $this->user(User::ROLE_FINANCE, ['is_active' => false]);

        Mail::fake();

        app(PaymentRequestService::class)->reject($pr, $l1, 'Vendor bank details are wrong');

        foreach ([$requester, $finance, $otherFinance, $l1] as $recipient) {
            Mail::assertQueued(
                PaymentRequestRejected::class,
                fn ($mail) => $mail->hasTo($recipient->email),
            );
        }

        Mail::assertNotQueued(
            PaymentRequestRejected::class,
            fn ($mail) => $mail->hasTo($inactiveFinance->email),
        );
    }

    public function test_each_recipient_is_notified_once(): void
    {
        ['pr' => $pr, 'l1' => $l1, 'finance' => $finance] = $this->requestInApproval();

        Mail::fake();

        app(PaymentRequestService::class)->reject($pr, $l1, 'Correct and resubmit');

        // The Finance user created the request and is also on the Finance team.
        $this->assertCount(
            1,
            Mail::queued(PaymentRequestRejected::class, fn ($mail) => $mail->hasTo($finance->email)),
        );
    }

    /**
     * The filter offers every status the client knows about, but only ever compared them against
     * `invoices.status` — so picking a PRF status returned an empty report rather than the rows.
     */
    public function test_filtering_by_rejected_finds_invoices_that_were_rejected(): void
    {
        ['invoice' => $invoice, 'pr' => $pr, 'l1' => $l1, 'finance' => $finance, 'requester' => $requester] = $this->requestInApproval();
        $untouched = $this->invoice($requester);

        app(PaymentRequestService::class)->reject($pr, $l1, 'Vendor bank details are wrong');

        // Finance, not the approver: rejection detaches the invoice, which correctly takes it back
        // out of an approver's scope. This filter is for the people who report on rejections.
        $found = InvoiceReport::query($finance, ['status' => PaymentRequest::STATUS_REJECTED])->pluck('id');

        $this->assertTrue($found->contains($invoice->id));
        $this->assertFalse($found->contains($untouched->id), 'an invoice never rejected should not match');
    }

    public function test_filtering_by_rejected_still_finds_it_after_re_submission(): void
    {
        ['invoice' => $invoice, 'pr' => $pr, 'l1' => $l1, 'finance' => $finance] = $this->requestInApproval();

        app(PaymentRequestService::class)->reject($pr, $l1, 'Missing purchase order');
        app(PaymentRequestService::class)->create([$invoice->id], $finance, [
            ['approver_id' => $l1->id, 'label' => 'Manager', 'level' => 1, 'is_adhoc' => false],
        ]);

        $found = InvoiceReport::query($finance, ['status' => PaymentRequest::STATUS_REJECTED])->pluck('id');

        $this->assertTrue($found->contains($invoice->id), 'the rejection is history, not the current request');
    }

    public function test_filtering_by_a_payment_status_matches_the_payment_status_column(): void
    {
        ['invoice' => $invoice, 'l1' => $l1, 'requester' => $requester] = $this->requestInApproval();
        $notInitiated = $this->invoice($requester);

        $found = InvoiceReport::query($l1, ['status' => Invoice::PAY_IN_APPROVAL])->pluck('id');

        $this->assertTrue($found->contains($invoice->id));
        $this->assertFalse($found->contains($notInitiated->id));
    }

    public function test_filtering_by_an_invoice_status_is_unchanged(): void
    {
        ['invoice' => $invoice, 'l1' => $l1] = $this->requestInApproval();

        $found = InvoiceReport::query($l1, ['status' => Invoice::STATUS_POSTED])->pluck('id');

        $this->assertTrue($found->contains($invoice->id));
        $this->assertSame(0, InvoiceReport::query($l1, ['status' => Invoice::STATUS_CANCELLED])->count());
    }

    public function test_selecting_several_statuses_widens_the_result(): void
    {
        ['invoice' => $invoice, 'pr' => $pr, 'l1' => $l1, 'finance' => $finance, 'requester' => $requester] = $this->requestInApproval();
        $other = $this->invoice($requester);

        app(PaymentRequestService::class)->reject($pr, $l1, 'Correct and resubmit');

        // An invoice status OR'd with a PRF status, as the multi-select sends them.
        $found = InvoiceReport::query($finance, [
            'status' => Invoice::STATUS_POSTED.','.PaymentRequest::STATUS_REJECTED,
        ])->pluck('id');

        $this->assertTrue($found->contains($invoice->id));
        $this->assertTrue($found->contains($other->id));
    }

    public function test_an_empty_status_filter_returns_everything(): void
    {
        ['invoice' => $invoice, 'l1' => $l1] = $this->requestInApproval();

        $this->assertTrue(InvoiceReport::query($l1, ['status' => ''])->pluck('id')->contains($invoice->id));
        $this->assertTrue(InvoiceReport::query($l1, [])->pluck('id')->contains($invoice->id));
    }
}
