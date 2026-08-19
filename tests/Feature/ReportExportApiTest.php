<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Vendor;
use App\Support\InvoiceReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * The key-authenticated export API serves the same report a person downloads from the Reports page,
 * for spreadsheets and BI tools that cannot hold a session. A key carries the visibility of the user
 * it was issued for and nothing wider.
 */
class ReportExportApiTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private string $secret;

    private ApiKey $apiKey;

    protected function setUp(): void
    {
        parent::setUp();

        Vendor::create(['name' => 'Al Noor Logistics', 'vendor_code' => 'ALN-1', 'is_active' => true]);
        $this->finance = $this->user(User::ROLE_FINANCE);

        ['key' => $key, 'secret' => $secret] = ApiKey::issue('Finance Excel dashboard', $this->finance);
        $this->apiKey = $key;
        $this->secret = $secret;
    }

    private function user(string $role): User
    {
        return User::create([
            'name' => ucfirst($role).' '.uniqid(),
            'email' => $role.uniqid().'@t.local',
            'password' => 'password',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function invoice(User $owner, array $overrides = [], array $jobNumbers = ['JOB-1']): Invoice
    {
        $invoice = Invoice::create(array_merge([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => 'Al Noor Logistics', 'invoice_no' => 'INV-'.uniqid(),
            'invoice_date' => now()->toDateString(),
            'currency' => 'AED', 'amount' => 1000, 'tax_amount' => 50, 'total_amount' => 1050,
            'business_unit' => 'GIL', 'department' => 'Warehouse', 'location' => 'Head Office',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => Invoice::STATUS_POSTED, 'payment_status' => Invoice::PAY_NOT_INITIATED,
            'submitted_by' => $owner->id, 'submitted_at' => now(),
        ], $overrides));

        foreach ($jobNumbers as $i => $jobNo) {
            $invoice->items()->create([
                'sort_order' => $i, 'job_no' => $jobNo, 'currency' => 'AED',
                'amount' => 1000, 'tax_rate' => 5, 'tax_amount' => 50, 'total_amount' => 1050,
            ]);
        }

        return $invoice;
    }

    private function url(array $params = []): string
    {
        return '/api/export-report?'.http_build_query(array_merge([
            'key' => $this->apiKey->key,
            'secret' => $this->secret,
        ], $params));
    }

    public function test_a_valid_key_returns_the_report_rows(): void
    {
        $invoice = $this->invoice($this->finance, [], ['JOB-1', 'JOB-2']);

        $res = $this->getJson($this->url())->assertOk();

        $res->assertJsonPath('total', 1)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('rows.0.reference_no', $invoice->reference_no)
            ->assertJsonPath('rows.0.job_no', 'JOB-1, JOB-2')
            ->assertJsonPath('rows.0.vendor', 'Al Noor Logistics')
            ->assertJsonPath('rows.0.status', 'Posted');

        // Numbers arrive as JSON numbers, not pre-formatted text, so a spreadsheet can sum them.
        // (A whole float serialises without its decimal point, hence the loose comparison.)
        $this->assertEquals(1000, $res->json('rows.0.amount'));
        $this->assertEquals(50, $res->json('rows.0.tax'));
        $this->assertEquals(1050, $res->json('rows.0.total'));
        $this->assertIsNumeric($res->json('rows.0.total'));

        // Every column the spreadsheet has is present in the payload, under a stable key.
        $this->assertSame(array_keys(InvoiceReport::columns()), array_keys($res->json('rows.0')));
        $this->assertSame(InvoiceReport::columns(), $res->json('columns'));
    }

    public function test_the_api_rows_match_the_downloaded_spreadsheet(): void
    {
        $this->invoice($this->finance);
        $this->invoice($this->finance, ['department' => 'Yard']);

        $apiRows = collect($this->getJson($this->url())->assertOk()->json('rows'))
            ->map(fn (array $row) => array_values($row))
            ->all();

        // The same report, downloaded the way a person would from the Reports page.
        $download = $this->actingAs($this->finance)->get('/api/reports/export')->assertOk();
        $sheet = IOFactory::load($download->baseResponse->getFile()->getPathname())->getActiveSheet();
        $table = $sheet->toArray();
        $headings = array_shift($table);

        $this->assertSame(InvoiceReport::headings(), $headings);
        $this->assertCount(count($apiRows), $table);

        foreach ($apiRows as $i => $apiRow) {
            $this->assertSame(
                array_map(fn ($value) => is_numeric($value) ? (float) $value : (string) $value, $apiRow),
                array_map(fn ($value) => is_numeric($value) ? (float) $value : (string) $value, $table[$i]),
                "row {$i} differs between the API and the download",
            );
        }
    }

    public function test_it_can_also_return_the_xlsx_itself(): void
    {
        $this->invoice($this->finance);

        $res = $this->get($this->url(['format' => 'xlsx']))->assertOk();

        $sheet = IOFactory::load($res->baseResponse->getFile()->getPathname())->getActiveSheet();
        $this->assertSame(InvoiceReport::headings(), $sheet->toArray()[0]);
    }

    public function test_the_same_filters_as_the_reports_page_apply(): void
    {
        $this->invoice($this->finance, ['department' => 'Warehouse', 'vendor_name' => 'Al Noor Logistics']);
        $this->invoice($this->finance, ['department' => 'Yard', 'vendor_name' => 'Delta Freight Co']);

        $this->getJson($this->url(['department' => 'Yard']))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('rows.0.department', 'Yard');

        $this->getJson($this->url(['vendor' => 'Noor']))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('rows.0.vendor', 'Al Noor Logistics');

        $this->getJson($this->url(['status' => 'submitted']))
            ->assertOk()
            ->assertJsonPath('total', 0);

        $this->getJson($this->url(['date_from' => now()->addDay()->toDateString()]))
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    public function test_rows_are_scoped_to_the_user_the_key_was_issued_for(): void
    {
        $requester = $this->user(User::ROLE_REQUESTER);
        $other = $this->user(User::ROLE_REQUESTER);
        $own = $this->invoice($requester);
        $this->invoice($other);

        ['key' => $key, 'secret' => $secret] = ApiKey::issue('Requester sheet', $requester);

        // Finance sees both invoices; the requester's key sees only their own.
        $this->getJson($this->url())->assertOk()->assertJsonPath('total', 2);

        $this->getJson("/api/export-report?key={$key->key}&secret={$secret}")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('rows.0.reference_no', $own->reference_no);
    }

    public function test_credentials_can_be_sent_as_headers_instead_of_query_parameters(): void
    {
        $this->invoice($this->finance);

        $this->getJson('/api/export-report', [
            'X-Api-Key' => $this->apiKey->key,
            'X-Api-Secret' => $this->secret,
        ])->assertOk()->assertJsonPath('total', 1);
    }

    /** Power Query's "Web API" credential holds one value, so key and secret can travel combined. */
    public function test_a_single_combined_token_is_accepted_as_one_field(): void
    {
        $this->invoice($this->finance);

        $token = $this->apiKey->key.':'.$this->secret;

        $this->getJson('/api/export-report?token='.urlencode($token))
            ->assertOk()
            ->assertJsonPath('total', 1);

        $this->getJson('/api/export-report', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('total', 1);

        $this->getJson('/api/export-report?token='.urlencode($this->apiKey->key.':wrong'))
            ->assertStatus(401);

        $this->getJson('/api/export-report?token='.urlencode($this->apiKey->key))
            ->assertStatus(401);
    }

    public function test_missing_wrong_and_revoked_credentials_are_all_refused(): void
    {
        $this->getJson('/api/export-report')->assertStatus(401);
        $this->getJson('/api/export-report?key='.$this->apiKey->key)->assertStatus(401);
        $this->getJson('/api/export-report?key='.$this->apiKey->key.'&secret=wrong')->assertStatus(401);
        $this->getJson('/api/export-report?key=nope&secret='.$this->secret)->assertStatus(401);

        $this->apiKey->update(['is_active' => false]);
        $this->getJson($this->url())->assertStatus(401);
    }

    public function test_a_key_stops_working_when_its_user_is_deactivated(): void
    {
        $this->finance->update(['is_active' => false]);

        $this->getJson($this->url())->assertStatus(401);
    }

    public function test_the_secret_is_stored_only_as_a_hash(): void
    {
        $this->assertNotSame($this->secret, $this->apiKey->secret_hash);
        $this->assertTrue($this->apiKey->secretMatches($this->secret));
        $this->assertFalse($this->apiKey->secretMatches('something else'));
        $this->assertArrayNotHasKey('secret_hash', $this->apiKey->fresh()->toArray());
    }

    public function test_use_is_recorded_and_audited(): void
    {
        $this->invoice($this->finance);

        $this->getJson($this->url(['department' => 'Warehouse']))->assertOk();

        $this->apiKey->refresh();
        $this->assertNotNull($this->apiKey->last_used_at);
        $this->assertNotNull($this->apiKey->last_used_ip);

        $log = AuditLog::where('action', 'report_exported_via_api')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Finance Excel dashboard', $log->description);
        $this->assertStringContainsString('department=Warehouse', $log->description);
    }

    public function test_large_result_sets_can_be_paged(): void
    {
        foreach (range(1, 3) as $i) {
            $this->invoice($this->finance);
        }

        $first = $this->getJson($this->url(['limit' => 2]))->assertOk();
        $first->assertJsonPath('total', 3)->assertJsonPath('count', 2)->assertJsonPath('has_more', true);

        $second = $this->getJson($this->url(['limit' => 2, 'offset' => 2]))->assertOk();
        $second->assertJsonPath('count', 1)->assertJsonPath('has_more', false);
    }

    public function test_an_invalid_filter_is_rejected_rather_than_ignored(): void
    {
        $this->getJson($this->url(['date_from' => 'not-a-date']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('date_from');

        $this->getJson($this->url(['format' => 'pdf']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('format');
    }
}
