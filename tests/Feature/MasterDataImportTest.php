<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Location;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\QueryException;
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

            if (in_array($entity, ['vendors', 'customers'], true)) {
                $instructions = collect($workbook->getSheetByName('Instructions')->toArray())
                    ->flatten()
                    ->filter()
                    ->implode(' ');
                $this->assertStringContainsString('Names may be shared', $instructions);
                $this->assertStringContainsString('code must be unique', $instructions);
            }
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
                    ['Another Vendor', 'EX-1', 1000, 30],
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

    public function test_vendor_and_customer_manual_crud_uses_code_instead_of_name_for_uniqueness(): void
    {
        foreach ([
            ['entity' => 'vendors', 'model' => Vendor::class, 'code_key' => 'vendor_code', 'code_prefix' => 'V'],
            ['entity' => 'customers', 'model' => Customer::class, 'code_key' => 'customer_code', 'code_prefix' => 'C'],
        ] as $definition) {
            $base = [
                'name' => 'Shared Name',
                'credit_limit' => 0,
                'credit_days' => 0,
                'is_active' => true,
            ];

            $first = $this->actingAs($this->admin)->postJson("/api/master-data/{$definition['entity']}", [
                ...$base,
                $definition['code_key'] => "{$definition['code_prefix']}-001",
            ])->assertCreated();

            $second = $this->postJson("/api/master-data/{$definition['entity']}", [
                ...$base,
                $definition['code_key'] => "{$definition['code_prefix']}-002",
            ])->assertCreated();

            $this->postJson("/api/master-data/{$definition['entity']}", [
                ...$base,
                'name' => 'Different Name',
                $definition['code_key'] => "  {$definition['code_prefix']}-001  ",
            ])->assertUnprocessable()->assertJsonValidationErrors($definition['code_key']);

            $this->putJson("/api/master-data/{$definition['entity']}/{$second->json('id')}", [
                ...$base,
                $definition['code_key'] => "{$definition['code_prefix']}-002",
            ])->assertOk();

            $this->putJson("/api/master-data/{$definition['entity']}/{$second->json('id')}", [
                ...$base,
                $definition['code_key'] => "{$definition['code_prefix']}-001",
            ])->assertUnprocessable()->assertJsonValidationErrors($definition['code_key']);

            $this->assertNotSame($first->json('id'), $second->json('id'));

            try {
                $definition['model']::create([
                    'name' => 'Direct Database Duplicate',
                    $definition['code_key'] => "{$definition['code_prefix']}-001",
                    'is_active' => true,
                ]);
                $this->fail("The {$definition['code_key']} database index did not reject a duplicate code.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_vendor_and_customer_imports_allow_duplicate_names_but_reject_duplicate_codes(): void
    {
        foreach ([
            ['entity' => 'vendors', 'heading' => 'Vendor Code', 'prefix' => 'V'],
            ['entity' => 'customers', 'heading' => 'Customer Code', 'prefix' => 'C'],
        ] as $definition) {
            $headings = ['Name *', $definition['heading'], 'Credit Limit', 'Credit Days'];

            $this->actingAs($this->admin)
                ->post("/api/master-data/{$definition['entity']}/import", [
                    'file' => $this->workbookUpload("{$definition['entity']}-same-name.xlsx", [
                        $headings,
                        ['Shared Import Name', "{$definition['prefix']}-101", 0, 0],
                        ['Shared Import Name', "{$definition['prefix']}-102", 0, 0],
                    ]),
                ])
                ->assertOk()
                ->assertJson(['imported_count' => 2]);

            $response = $this->post("/api/master-data/{$definition['entity']}/import", [
                'file' => $this->workbookUpload("{$definition['entity']}-duplicate-code.xlsx", [
                    $headings,
                    ['Atomic First', "{$definition['prefix']}-103", 0, 0],
                    ['Atomic Second', "{$definition['prefix']}-103", 0, 0],
                ]),
            ])->assertUnprocessable();

            $this->assertStringContainsString('code is duplicated', implode(' ', $response->json('errors.file')));
            $this->assertDatabaseMissing($definition['entity'], ['name' => 'Atomic First']);
        }
    }

    public function test_master_data_lists_are_paginated_and_searchable(): void
    {
        foreach (range(1, 12) as $number) {
            Location::create(['name' => sprintf('Warehouse %02d', $number), 'is_active' => true]);
        }
        Vendor::create(['name' => 'Blue Water Logistics', 'vendor_code' => 'V-SEARCH', 'is_active' => true]);
        Customer::create(['name' => 'Searchable Customer', 'customer_code' => 'C-SEARCH', 'is_active' => true]);

        $this->actingAs($this->admin)
            ->getJson('/api/master-data/locations?per_page=5&page=2')
            ->assertOk()
            ->assertJsonPath('total', 12)
            ->assertJsonPath('per_page', 5)
            ->assertJsonPath('current_page', 2)
            ->assertJsonCount(5, 'data');

        $this->getJson('/api/master-data/vendors?q=V-SEARCH')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.name', 'Blue Water Logistics');

        $this->getJson('/api/master-data/customers?q=Searchable')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.customer_code', 'C-SEARCH');
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
