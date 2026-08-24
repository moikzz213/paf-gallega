<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * The report carries the query history and the approvers' remarks. The invoice itself only keeps
 * the latest query text, so a second query must not hide the first one.
 */
class ReportQueryRemarksTest extends TestCase
{
    use RefreshDatabase;

    private function finance(): User
    {
        return User::create([
            'name' => 'Fin', 'email' => 'report-qr-fin@t.local', 'password' => 'password',
            'role' => User::ROLE_FINANCE, 'is_active' => true,
        ]);
    }

    private function invoice(User $owner): Invoice
    {
        return Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => 'Delta Freight Co', 'invoice_no' => 'INV-'.uniqid(),
            'invoice_date' => now()->toDateString(),
            'currency' => 'AED', 'amount' => 500, 'tax_amount' => 0, 'total_amount' => 500,
            'business_unit' => 'GIL', 'department' => 'Warehouse', 'location' => 'Head Office',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => Invoice::STATUS_POSTED, 'payment_status' => Invoice::PAY_NOT_INITIATED,
            'submitted_by' => $owner->id, 'submitted_at' => now(),
        ]);
    }

    /** @return array<int, string> The single data row of the Excel export, positional. */
    private function exportRow(User $user): array
    {
        $response = $this->actingAs($user)->get('/api/reports/export')->assertOk();
        $sheet = IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet();
        $last = $sheet->getHighestColumn();

        $this->assertSame(
            ['Query Raised', 'Remarks'],
            array_slice($sheet->rangeToArray("A1:{$last}1")[0], -2),
        );

        return $sheet->rangeToArray("A2:{$last}2")[0];
    }

    public function test_every_query_raised_reaches_the_export_comma_separated(): void
    {
        $finance = $this->finance();
        $invoice = $this->invoice($finance);

        $this->actingAs($finance)
            ->postJson("/api/invoices/{$invoice->id}/query", ['finance_remarks' => 'Missing PO'])
            ->assertOk();

        // The department corrects and resubmits, then Finance queries it a second time.
        $invoice->update(['status' => Invoice::STATUS_SUBMITTED]);
        $this->actingAs($finance)
            ->postJson("/api/invoices/{$invoice->id}/query", ['finance_remarks' => 'Wrong TRN'])
            ->assertOk();

        $row = $this->exportRow($finance);

        $this->assertSame('Missing PO, Wrong TRN', $row[count($row) - 2]);
    }

    public function test_approver_remarks_reach_the_export_in_approval_order(): void
    {
        $finance = $this->finance();
        $invoice = $this->invoice($finance);

        $paymentRequest = PaymentRequest::create([
            'reference_no' => PaymentRequest::nextReferenceNo(),
            'created_by' => $finance->id,
            'status' => PaymentRequest::STATUS_APPROVED,
            'total_amount' => 500,
        ]);
        $paymentRequest->approvals()->create([
            'sequence' => 2, 'level' => 2, 'label' => 'CFO',
            'status' => 'approved', 'approver_id' => $finance->id, 'comments' => 'Approved, pay on due date',
        ]);
        $paymentRequest->approvals()->create([
            'sequence' => 1, 'level' => 1, 'label' => 'Manager',
            'status' => 'approved', 'approver_id' => $finance->id, 'comments' => 'Budget checked',
        ]);
        // An approval nobody commented on adds nothing to the column.
        $paymentRequest->approvals()->create([
            'sequence' => 3, 'level' => 3, 'label' => 'CEO', 'status' => 'pending',
        ]);

        $invoice->update([
            'payment_request_id' => $paymentRequest->id,
            'payment_status' => Invoice::PAY_APPROVED,
        ]);

        $row = $this->exportRow($finance);

        $this->assertSame('Budget checked, Approved, pay on due date', $row[count($row) - 1]);
    }

    public function test_the_requests_own_rejection_and_withdrawal_reasons_join_the_remarks(): void
    {
        $finance = $this->finance();
        $invoice = $this->invoice($finance);

        $paymentRequest = PaymentRequest::create([
            'reference_no' => PaymentRequest::nextReferenceNo(),
            'created_by' => $finance->id,
            'status' => PaymentRequest::STATUS_REJECTED,
            'total_amount' => 500,
            'rejection_reason' => 'Vendor bank details unverified',
            'withdrawal_reason' => 'Pulled back for reissue',
        ]);
        $paymentRequest->approvals()->create([
            'sequence' => 1, 'level' => 1, 'label' => 'Manager',
            'status' => 'approved', 'approver_id' => $finance->id, 'comments' => 'Budget checked',
        ]);

        $invoice->update(['payment_request_id' => $paymentRequest->id]);

        $row = $this->exportRow($finance);

        $this->assertSame(
            'Budget checked, Rejected: Vendor bank details unverified, Withdrawn: Pulled back for reissue',
            $row[count($row) - 1],
        );
    }

    public function test_an_invoice_with_neither_leaves_both_columns_empty(): void
    {
        $finance = $this->finance();
        $this->invoice($finance);

        $row = $this->exportRow($finance);

        // An empty string is read back from the sheet as an empty cell.
        $this->assertNull($row[count($row) - 2]);
        $this->assertNull($row[count($row) - 1]);
    }
}
