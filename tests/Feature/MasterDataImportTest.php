<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class MasterDataImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Master Data Admin',
            'email' => 'master-data-admin@t.local',
            'password' => 'password',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_download_a_template_for_every_master_data_entity(): void
    {
        $entities = [
            'vendors' => ['Name *', 'Vendor Code', 'Credit Limit', 'Credit Days'],
            'customers' => ['Name *', 'Customer Code', 'Credit Limit', 'Credit Days'],
            'business-units' => ['Name *'],
            'departments' => ['Name *'],
            'locations' => ['Name *'],
        ];

        foreach ($entities as $entity => $expectedHeadings) {
            $response = $this->actingAs($this->admin)
                ->get("/api/master-data/{$entity}/template")
                ->assertOk();

            $workbook = IOFactory::load($response->baseResponse->getFile()->getPathname());

            $this->assertSame(['Import Data', 'Instructions'], $workbook->getSheetNames());
            $this->assertTrue($workbook->getSheetByName('Import Data')->getShowGridlines());
            $this->assertTrue($workbook->getSheetByName('Instructions')->getShowGridlines());
            $this->assertSame(
                $expectedHeadings,
                $workbook->getSheetByName('Import Data')
                    ->rangeToArray('A1:'.$workbook->getSheetByName('Import Data')->getHighestColumn().'1')[0],
            );
        }
    }

    public function test_admin_can_import_all_master_data_entities(): void
    {
        $imports = [
            'vendors' => [
                ['Name *', 'Vendor Code', 'Credit Limit', 'Credit Days'],
                ['Bulk Vendor', 'V-100', 25000.50, 45],
            ],
            'customers' => [
                ['Name *', 'Customer Code', 'Credit Limit', 'Credit Days'],
                ['Bulk Customer', 'C-100', 50000, 30],
            ],
            'business-units' => [
                ['Name *'],
                ['Bulk Business Unit'],
            ],
            'departments' => [
                ['Name *'],
                ['Bulk Department'],
            ],
            'locations' => [
                ['Name *'],
                ['Bulk Location'],
            ],
        ];

        foreach ($imports as $entity => $rows) {
            $this->actingAs($this->admin)
                ->post("/api/master-data/{$entity}/import", [
                    'file' => $this->workbookUpload("{$entity}.xlsx", $rows),
                ])
                ->assertOk()
                ->assertJson(['imported_count' => 1]);
        }

        $this->assertDatabaseHas('vendors', [
            'name' => 'Bulk Vendor',
            'vendor_code' => 'V-100',
            'credit_days' => 45,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('customers', [
            'name' => 'Bulk Customer',
            'customer_code' => 'C-100',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('business_units', ['name' => 'Bulk Business Unit']);
        $this->assertDatabaseHas('departments', ['name' => 'Bulk Department']);
        $this->assertDatabaseHas('locations', ['name' => 'Bulk Location']);
        $this->assertSame(5, AuditLog::where('action', 'master_data_imported')->count());
    }

    public function test_import_is_atomic_when_a_row_is_invalid_or_duplicate(): void
    {
        $this->actingAs($this->admin)->postJson('/api/master-data/vendors', [
            'name' => 'Existing Vendor',
            'vendor_code' => 'EX-1',
            'credit_limit' => 0,
            'credit_days' => 0,
            'is_active' => true,
        ])->assertCreated();

        $response = $this->actingAs($this->admin)
            ->post('/api/master-data/vendors/import', [
                'file' => $this->workbookUpload('vendors.xlsx', [
                    ['Name *', 'Vendor Code', 'Credit Limit', 'Credit Days'],
                    ['New Vendor', 'NEW-1', 1000, 30],
                    ['Existing Vendor', 'EX-2', 1000, 30],
                ]),
            ])
            ->assertUnprocessable();

        $this->assertStringContainsString('Row 3', implode(' ', $response->json('errors.file')));
        $this->assertDatabaseMissing('vendors', ['name' => 'New Vendor']);
        $this->assertSame(1, AuditLog::where('action', 'master_data_created')->count());
        $this->assertSame(0, AuditLog::where('action', 'master_data_imported')->count());
    }

    public function test_actual_downloaded_template_imports_with_only_the_name_entered(): void
    {
        $templateResponse = $this->actingAs($this->admin)
            ->get('/api/master-data/vendors/template')
            ->assertOk();
        $workbook = IOFactory::load($templateResponse->baseResponse->getFile()->getPathname());
        $workbook->getSheetByName('Import Data')->setCellValue('A2', 'Template Round Trip Vendor');

        $this->actingAs($this->admin)
            ->post('/api/master-data/vendors/import', [
                'file' => $this->spreadsheetUpload('completed-vendors-template.xlsx', $workbook),
            ])
            ->assertOk()
            ->assertJson(['imported_count' => 1]);

        $this->assertDatabaseHas('vendors', [
            'name' => 'Template Round Trip Vendor',
            'vendor_code' => null,
            'credit_limit' => 0,
            'credit_days' => 0,
            'is_active' => true,
        ]);
    }

    public function test_non_admin_cannot_import_or_download_templates(): void
    {
        $finance = User::create([
            'name' => 'Finance User',
            'email' => 'finance-master-data@t.local',
            'password' => 'password',
            'role' => User::ROLE_FINANCE,
            'is_active' => true,
        ]);

        $this->actingAs($finance)
            ->get('/api/master-data/vendors/template')
            ->assertForbidden();
        $this->actingAs($finance)
            ->post('/api/master-data/vendors/import', [
                'file' => $this->workbookUpload('vendors.xlsx', [
                    ['Name *', 'Vendor Code', 'Credit Limit', 'Credit Days'],
                    ['Blocked Vendor', 'BLOCKED', 0, 0],
                ]),
            ])
            ->assertForbidden();
    }

    public function test_admin_can_edit_a_user_with_a_department_from_master_data(): void
    {
        $this->actingAs($this->admin)
            ->post('/api/master-data/departments/import', [
                'file' => $this->workbookUpload('departments.xlsx', [
                    ['Name *'],
                    ['Imported Operations'],
                ]),
            ])
            ->assertOk();

        $user = User::create([
            'name' => 'Department User',
            'email' => 'department-user@t.local',
            'password' => 'password',
            'role' => User::ROLE_REQUESTER,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->putJson("/api/users/{$user->id}", [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'approval_level' => null,
                'department' => 'Imported Operations',
                'job_title' => 'Coordinator',
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('department', 'Imported Operations');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'department' => 'Imported Operations',
        ]);
    }

    private function workbookUpload(string $name, array $rows): UploadedFile
    {
        $workbook = new Spreadsheet;
        $workbook->getActiveSheet()->fromArray($rows);

        return $this->spreadsheetUpload($name, $workbook);
    }

    private function spreadsheetUpload(string $name, Spreadsheet $workbook): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'master_import_');
        (new Xlsx($workbook))->save($path);
        $contents = file_get_contents($path);
        unlink($path);

        return UploadedFile::fake()->createWithContent($name, $contents);
    }
}
