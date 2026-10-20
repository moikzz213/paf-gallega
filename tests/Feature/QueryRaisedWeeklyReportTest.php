<?php

namespace Tests\Feature;

use App\Console\Commands\SendQueryRaisedReport;
use App\Mail\QueryRaisedReportSummary;
use App\Mail\QueryRaisedWeeklyReport;
use App\Models\ApprovalLevel;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\InvoiceRejection;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\PaymentRequestService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Every Friday at 08:00 Dubai time, each department with open Finance queries emails its list to
 * the Department Head(s) an admin nominated in Master Data; a department with none gets nothing.
 * Finance and admins get only a summary of what was sent. No attachments.
 *
 * CR: ai/change-requests/schedule-weekly-query-raised-report-email-to-department-heads.md
 */
class QueryRaisedWeeklyReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $finance;

    private User $requester;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->admin = $this->user(User::ROLE_ADMIN, 'Ada Admin');
        $this->finance = $this->user(User::ROLE_FINANCE, 'Fiona Finance');
        $this->requester = $this->user(User::ROLE_REQUESTER, 'Sam Submitter');
    }

    private function user(string $role, ?string $name = null, bool $active = true): User
    {
        return User::create([
            'name' => $name ?? ucfirst($role).' '.uniqid(),
            'email' => $role.uniqid().'@t.local',
            'password' => 'password',
            'role' => $role,
            'is_active' => $active,
        ]);
    }

    /** @param  array<int, User>  $heads */
    private function department(string $name, array $heads = []): Department
    {
        $department = Department::create(['name' => $name, 'is_active' => true]);
        $department->heads()->sync(collect($heads)->pluck('id'));

        return $department;
    }

    private function invoice(string $department, array $overrides = []): Invoice
    {
        return Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => 'Acme Trading', 'invoice_no' => 'SUP-'.uniqid(), 'invoice_date' => now()->toDateString(),
            'currency' => 'AED', 'amount' => 1200, 'tax_amount' => 0, 'total_amount' => 1200,
            'business_unit' => 'GIL', 'department' => $department, 'location' => 'Head Office',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => Invoice::STATUS_SUBMITTED, 'payment_status' => Invoice::PAY_NOT_INITIATED,
            'submitted_by' => $this->requester->id, 'submitted_at' => now(),
            ...$overrides,
        ]);
    }

    /** An invoice Finance queried $daysAgo days ago, as InvoiceController::raiseQuery leaves it. */
    private function queried(string $department, string $query = 'Missing job sheet', int $daysAgo = 3, array $overrides = []): Invoice
    {
        $invoice = $this->invoice($department, $overrides);
        $invoice->update(['status' => Invoice::STATUS_QUERY, 'finance_remarks' => $query]);

        AuditLog::create([
            'user_id' => $this->finance->id, 'invoice_id' => $invoice->id, 'action' => 'query_raised',
            'description' => "Query raised on {$invoice->reference_no}: {$query}", 'created_at' => now()->subDays($daysAgo),
        ]);

        return $invoice;
    }

    private function runReport(): void
    {
        $this->artisan(SendQueryRaisedReport::class)->assertSuccessful();
    }

    public function test_each_head_receives_only_their_own_departments_open_queries(): void
    {
        $warehouseHead = $this->user(User::ROLE_APPROVER, 'Wendy Warehouse');
        $yardHead = $this->user(User::ROLE_APPROVER, 'Yusuf Yard');
        $this->department('Warehouse', [$warehouseHead]);
        $this->department('Yard', [$yardHead]);

        $warehouseQuery = $this->queried('Warehouse', 'Wrong amount');
        $yardQuery = $this->queried('Yard', 'No PO attached');
        $this->invoice('Warehouse'); // not queried: never reported

        $this->runReport();

        Mail::assertQueued(QueryRaisedWeeklyReport::class, 2);
        Mail::assertQueued(QueryRaisedWeeklyReport::class, fn (QueryRaisedWeeklyReport $m) => $m->department === 'Warehouse'
            && $m->hasBcc($warehouseHead->email) && ! $m->hasBcc($yardHead->email)
            && collect($m->rows)->pluck('id')->all() === [$warehouseQuery->id]);
        Mail::assertQueued(QueryRaisedWeeklyReport::class, fn (QueryRaisedWeeklyReport $m) => $m->department === 'Yard'
            && $m->hasBcc($yardHead->email) && ! $m->hasBcc($warehouseHead->email)
            && collect($m->rows)->pluck('id')->all() === [$yardQuery->id]);
    }

    public function test_a_department_with_no_open_queries_is_not_emailed(): void
    {
        $head = $this->user(User::ROLE_APPROVER);
        $this->department('Warehouse', [$head]);
        $this->invoice('Warehouse'); // submitted, not queried
        $this->invoice('Warehouse', ['status' => Invoice::STATUS_POSTED]);

        $this->runReport();

        Mail::assertNotQueued(QueryRaisedWeeklyReport::class);
        // The summary still goes, so Finance can tell a quiet week from a run that never happened.
        Mail::assertQueued(QueryRaisedReportSummary::class, fn (QueryRaisedReportSummary $m) => $m->sent === []);
    }

    public function test_several_heads_are_each_copied_privately(): void
    {
        $first = $this->user(User::ROLE_APPROVER);
        $second = $this->user(User::ROLE_REQUESTER);
        $this->department('Warehouse', [$first, $second]);
        $this->queried('Warehouse');

        $this->runReport();

        Mail::assertQueued(QueryRaisedWeeklyReport::class, fn (QueryRaisedWeeklyReport $m) => $m->hasBcc($first->email)
            && $m->hasBcc($second->email) && ! $m->hasTo($first->email) && ! $m->hasTo($second->email));
    }

    public function test_department_names_match_regardless_of_case_and_spacing(): void
    {
        $head = $this->user(User::ROLE_APPROVER);
        $this->department('Freight Forwarding', [$head]);
        $this->queried('  freight forwarding ');

        $this->runReport();

        Mail::assertQueued(QueryRaisedWeeklyReport::class, fn (QueryRaisedWeeklyReport $m) => $m->department === 'Freight Forwarding'
            && $m->hasBcc($head->email));
    }

    public function test_an_inactive_head_is_not_sent_the_report(): void
    {
        $active = $this->user(User::ROLE_APPROVER);
        $inactive = $this->user(User::ROLE_APPROVER, null, active: false);
        $this->department('Warehouse', [$active, $inactive]);
        $this->queried('Warehouse');

        $this->runReport();

        Mail::assertQueued(QueryRaisedWeeklyReport::class, fn (QueryRaisedWeeklyReport $m) => $m->hasBcc($active->email)
            && ! $m->hasBcc($inactive->email));
    }

    public function test_finance_and_admins_get_only_the_summary_which_names_the_gaps(): void
    {
        $head = $this->user(User::ROLE_APPROVER, 'Wendy Warehouse');
        $this->department('Warehouse', [$head]);
        $this->department('Yard'); // no Head
        $this->queried('Warehouse');
        $this->queried('Yard');
        $this->queried('Yard');
        $this->queried('Old Department Name'); // not in master data
        $this->user(User::ROLE_FINANCE, null, active: false);

        $this->runReport();

        Mail::assertQueued(QueryRaisedWeeklyReport::class, 1);
        Mail::assertQueued(QueryRaisedWeeklyReport::class, fn (QueryRaisedWeeklyReport $m) => ! $m->hasBcc($this->finance->email)
            && ! $m->hasBcc($this->admin->email));

        Mail::assertQueued(QueryRaisedReportSummary::class, 1);
        Mail::assertQueued(QueryRaisedReportSummary::class, function (QueryRaisedReportSummary $m) use ($head) {
            return $m->hasBcc($this->finance->email) && $m->hasBcc($this->admin->email)
                && ! $m->hasBcc($head->email) && ! $m->hasBcc($this->requester->email)
                && $m->sent === [['department' => 'Warehouse', 'recipients' => ['Wendy Warehouse'], 'count' => 1, 'sent' => true]]
                && $m->withoutHead === [['department' => 'Yard', 'count' => 2]]
                && $m->unmatched === [['department' => 'Old Department Name', 'count' => 1]];
        });

        // The summary does not repeat the invoice details.
        $summary = (new QueryRaisedReportSummary([['department' => 'Warehouse', 'recipients' => ['Wendy Warehouse'], 'count' => 1, 'sent' => true]], [['department' => 'Yard', 'count' => 2]], [], 'today'))->render();
        $this->assertStringContainsString('Wendy Warehouse', $summary);
        $this->assertStringContainsString('no Department Head nominated', $summary);
        $this->assertStringNotContainsString('Acme Trading', $summary);
    }

    public function test_the_report_shows_the_agreed_columns_oldest_query_first_with_no_attachments(): void
    {
        ApprovalLevel::create(['level' => 1, 'name' => 'Manager', 'min_amount' => 0, 'is_active' => true]);
        $approver = $this->user(User::ROLE_APPROVER);
        $head = $this->user(User::ROLE_APPROVER);
        $this->department('Warehouse', [$head]);

        // An invoice that was on a PAF that was rejected, then queried by Finance.
        $onRejectedPaf = $this->invoice('Warehouse', ['status' => Invoice::STATUS_POSTED, 'invoice_no' => 'SUP-REJ-1', 'total_amount' => 5000]);
        $pr = app(PaymentRequestService::class)->create([$onRejectedPaf->id], $this->finance, [
            ['approver_id' => $approver->id, 'label' => 'Manager', 'level' => 1, 'is_adhoc' => false],
        ]);
        $pr->approvals()->update(['comments' => 'Budget code wrong']);
        $pr->update(['status' => PaymentRequest::STATUS_REJECTED, 'current_stage' => null, 'rejected_at' => now(), 'rejection_reason' => 'Budget code wrong']);
        InvoiceRejection::create(['invoice_id' => $onRejectedPaf->id, 'payment_request_id' => $pr->id, 'rejected_at' => now()]);
        $onRejectedPaf->update(['payment_request_id' => null, 'payment_status' => Invoice::PAY_NOT_INITIATED, 'status' => Invoice::STATUS_QUERY, 'finance_remarks' => 'Attach the corrected budget approval']);
        AuditLog::create(['invoice_id' => $onRejectedPaf->id, 'action' => 'query_raised', 'description' => 'Query raised', 'created_at' => now()->subDays(2)]);

        $older = $this->queried('Warehouse', 'Vendor TRN missing', daysAgo: 9, overrides: ['invoice_no' => 'SUP-OLD-1', 'vendor_name' => 'Gulf Supplies', 'total_amount' => 750, 'currency' => 'USD']);

        $this->runReport();

        $mail = null;
        Mail::assertQueued(QueryRaisedWeeklyReport::class, function (QueryRaisedWeeklyReport $m) use (&$mail) {
            $mail = $m;

            return true;
        });

        $this->assertSame([$older->id, $onRejectedPaf->id], collect($mail->rows)->pluck('id')->all(), 'Oldest query first.');
        $this->assertSame(9, $mail->rows[0]['days_open']);
        $this->assertSame(['AED' => 5000.0, 'USD' => 750.0], $mail->totals);
        $this->assertSame([], $mail->attachments);
        $this->assertSame([], $mail->rawAttachments);

        $html = $mail->render();
        foreach (['Invoice Number', 'PAF Number', 'Vendor Name', 'Invoice Amount', 'Submitter Name', 'Query Raised', 'Days Open', 'Remarks'] as $heading) {
            $this->assertStringContainsString($heading, $html);
        }
        $this->assertStringContainsString('SUP-OLD-1', $html);
        $this->assertStringContainsString('Gulf Supplies', $html);
        $this->assertStringContainsString('Vendor TRN missing', $html);
        $this->assertStringContainsString('Sam Submitter', $html);
        $this->assertStringContainsString($pr->reference_no, $html);
        $this->assertStringContainsString('Budget code wrong', $html);
        $this->assertStringContainsString(url('/invoices/'.$older->id), $html);
        $this->assertStringContainsString('2 open queries', $mail->envelope()->subject);
    }

    public function test_each_delivered_report_is_audited(): void
    {
        $head = $this->user(User::ROLE_APPROVER, 'Wendy Warehouse');
        $this->department('Warehouse', [$head]);
        $this->queried('Warehouse');

        $this->runReport();

        $this->assertDatabaseHas('audit_logs', ['action' => 'query_raised_report_sent']);
        $this->assertStringContainsString('Wendy Warehouse', AuditLog::where('action', 'query_raised_report_sent')->value('description'));
    }

    public function test_it_is_scheduled_for_friday_at_eight_in_dubai_time(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $e) => str_contains((string) $e->command, 'paf:send-query-raised-report'));

        $this->assertNotNull($event, 'The weekly Query Raised Report is not scheduled.');
        $this->assertSame('0 8 * * 5', $event->expression);
        $this->assertSame('Asia/Dubai', (string) $event->timezone);
    }

    public function test_only_an_admin_can_nominate_heads_and_they_must_be_active_users(): void
    {
        $department = $this->department('Warehouse');
        $head = $this->user(User::ROLE_APPROVER, 'Wendy Warehouse');
        $inactive = $this->user(User::ROLE_APPROVER, null, active: false);

        $this->actingAs($this->finance)
            ->putJson("/api/master-data/departments/{$department->id}", ['name' => 'Warehouse', 'is_active' => true, 'head_ids' => [$head->id]])
            ->assertForbidden();
        $this->assertSame(0, $department->heads()->count());

        // Finance can still maintain the department itself; leaving head_ids out leaves the Heads alone.
        $department->heads()->sync([$head->id]);
        $this->actingAs($this->finance)
            ->putJson("/api/master-data/departments/{$department->id}", ['name' => 'Warehouse Ops', 'is_active' => true])
            ->assertOk();
        $this->assertSame([$head->id], $department->heads()->pluck('users.id')->all());
        $department->heads()->sync([]);

        $this->actingAs($this->admin)
            ->putJson("/api/master-data/departments/{$department->id}", ['name' => 'Warehouse', 'is_active' => true, 'head_ids' => [$inactive->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('head_ids.0');

        $this->actingAs($this->admin)
            ->putJson("/api/master-data/departments/{$department->id}", ['name' => 'Warehouse', 'is_active' => true, 'head_ids' => [$head->id]])
            ->assertOk()
            ->assertJsonPath('heads.0.name', 'Wendy Warehouse');

        $this->assertSame([$head->id], $department->heads()->pluck('users.id')->all());
        $log = AuditLog::where('action', 'department_heads_updated')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame(['Wendy Warehouse'], $log->new_values['heads']);

        $this->actingAs($this->admin)
            ->getJson('/api/master-data/departments')
            ->assertOk()
            ->assertJsonPath('data.0.heads.0.name', 'Wendy Warehouse');
    }

    public function test_heads_can_be_nominated_through_the_departments_spreadsheet(): void
    {
        $head = $this->user(User::ROLE_APPROVER, 'Wendy Warehouse');

        $this->actingAs($this->admin)
            ->post('/api/master-data/departments/import', ['file' => $this->workbook([
                ['Name *', 'Department Head Emails'],
                ['Imported Warehouse', strtoupper($head->email)],
                ['Imported Yard', ''],
            ])])
            ->assertOk()
            ->assertJson(['imported_count' => 2]);

        $this->assertSame([$head->id], Department::where('name', 'Imported Warehouse')->first()->heads()->pluck('users.id')->all());
        $this->assertSame(0, Department::where('name', 'Imported Yard')->first()->heads()->count());

        $response = $this->actingAs($this->admin)
            ->post('/api/master-data/departments/import', ['file' => $this->workbook([
                ['Name *', 'Department Head Emails'],
                ['Imported Land', 'nobody@t.local'],
            ])])
            ->assertUnprocessable();

        $this->assertStringContainsString('nobody@t.local', implode(' ', $response->json('errors.file')));
        $this->assertDatabaseMissing('departments', ['name' => 'Imported Land']);
    }

    private function workbook(array $rows): UploadedFile
    {
        $workbook = new Spreadsheet;
        $workbook->getActiveSheet()->fromArray($rows);
        $path = tempnam(sys_get_temp_dir(), 'heads_import_');
        (new Xlsx($workbook))->save($path);
        $contents = file_get_contents($path);
        unlink($path);

        return UploadedFile::fake()->createWithContent('departments.xlsx', $contents);
    }
}
