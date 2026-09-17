<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Job numbers live on the invoice lines, so the report has to reach through them — both in the
 * table (which renders the loaded lines) and in the Excel export.
 */
class ReportJobNumberTest extends TestCase
{
    use RefreshDatabase;

    private function finance(): User
    {
        return User::create([
            'name' => 'Fin', 'email' => 'report-fin@t.local', 'password' => 'password',
            'role' => User::ROLE_FINANCE, 'is_active' => true,
        ]);
    }

    private function invoiceWithJobs(User $owner, array $jobNumbers): Invoice
    {
        $invoice = Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => 'Delta Freight Co', 'invoice_no' => 'INV-'.uniqid(),
            'invoice_date' => now()->toDateString(),
            'currency' => 'AED', 'amount' => 500, 'tax_amount' => 0, 'total_amount' => 500,
            'business_unit' => 'GIL', 'department' => 'Warehouse', 'location' => 'Head Office',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => Invoice::STATUS_POSTED, 'payment_status' => Invoice::PAY_NOT_INITIATED,
            'submitted_by' => $owner->id, 'submitted_at' => now(),
        ]);

        foreach ($jobNumbers as $i => $jobNo) {
            $invoice->items()->create([
                'sort_order' => $i,
                'job_no' => $jobNo,
                'currency' => 'AED',
                'amount' => 500 / max(count($jobNumbers), 1),
                'tax_amount' => 0,
                'total_amount' => 500 / max(count($jobNumbers), 1),
            ]);
        }

        return $invoice;
    }

    public function test_report_rows_carry_the_job_numbers_of_their_lines(): void
    {
        $finance = $this->finance();
        $this->invoiceWithJobs($finance, ['JOB-001', 'JOB-002']);

        $row = $this->actingAs($finance)->getJson('/api/reports')
            ->assertOk()
            ->json('rows.data.0');

        $this->assertSame(['JOB-001', 'JOB-002'], collect($row['items'])->pluck('job_no')->all());
    }

    public function test_a_line_without_a_job_number_does_not_break_the_row(): void
    {
        $finance = $this->finance();
        $this->invoiceWithJobs($finance, [null]);

        $row = $this->actingAs($finance)->getJson('/api/reports')
            ->assertOk()
            ->json('rows.data.0');

        $this->assertNull($row['items'][0]['job_no']);
    }

    public function test_the_excel_export_has_a_job_no_column(): void
    {
        $finance = $this->finance();
        $this->invoiceWithJobs($finance, ['JOB-001', 'JOB-002', 'JOB-001']);

        $response = $this->actingAs($finance)->get('/api/reports/export')->assertOk();
        $sheet = IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet();

        $headings = $sheet->rangeToArray('A1:'.$sheet->getHighestColumn().'1')[0];
        $this->assertSame('Job No', $headings[3]);

        // Repeated job numbers collapse to one entry.
        $this->assertSame('JOB-001, JOB-002', $sheet->rangeToArray('A2:'.$sheet->getHighestColumn().'2')[0][3]);
    }
}
