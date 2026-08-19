<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceSubmitTest extends TestCase
{
    use RefreshDatabase;

    private function seedMasterData(): Vendor
    {
        $vendor = Vendor::create(['name' => 'Al Noor Logistics', 'vendor_code' => 'ALN-1', 'is_active' => true]);
        BusinessUnit::create(['name' => 'GGH', 'is_active' => true]);
        BusinessUnit::create(['name' => 'GIL', 'is_active' => true]);
        Department::create(['name' => 'Custom clearance', 'is_active' => true]);
        Department::create(['name' => 'Yard', 'is_active' => true]);
        Location::create(['name' => 'Dubai', 'is_active' => true]);

        return $vendor;
    }

    private function requester(string $email): User
    {
        return User::create([
            'name' => 'Req', 'email' => $email, 'password' => 'password',
            'role' => User::ROLE_REQUESTER, 'is_active' => true,
        ]);
    }

    public function test_submitting_an_invoice_computes_totals_from_line_items(): void
    {
        $vendor = $this->seedMasterData();

        $res = $this->actingAs($this->requester('req@t.local'))->postJson('/api/invoices', [
            'vendor_id' => $vendor->id,
            'invoice_no' => '2312321',
            'invoice_date' => now()->toDateString(),
            'currency' => 'AED',
            'business_unit' => 'GGH',
            'department' => 'Custom clearance',
            'location' => 'Dubai',
            'payment_method' => 'bank_transfer',
            'priority' => 'normal',
            'description' => 'test only',
            'items' => [
                ['job_no' => 'JOB-X', 'currency' => 'AED', 'amount' => 1000, 'tax_rate' => 5],
                ['job_no' => 'JOB-Y', 'currency' => 'AED', 'amount' => 200, 'tax_rate' => 0],
            ],
        ]);

        $res->assertCreated()
            ->assertJsonPath('amount', '1200.00')
            ->assertJsonPath('tax_amount', '50.00')
            ->assertJsonPath('total_amount', '1250.00');

        $invoice = Invoice::first();
        $this->assertSame(Invoice::STATUS_SUBMITTED, $invoice->status);
        $this->assertCount(2, $invoice->items);
    }

    public function test_submitting_without_items_fails_validation(): void
    {
        $vendor = $this->seedMasterData();

        $this->actingAs($this->requester('req2@t.local'))->postJson('/api/invoices', [
            'vendor_id' => $vendor->id, 'invoice_no' => 'X', 'invoice_date' => now()->toDateString(),
            'currency' => 'AED', 'business_unit' => 'GIL', 'department' => 'Yard', 'location' => 'Dubai',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
        ])->assertStatus(422)->assertJsonValidationErrors('items');
    }

    public function test_invoice_currency_comes_from_the_line_items(): void
    {
        $vendor = $this->seedMasterData();

        $res = $this->actingAs($this->requester('eur@t.local'))->postJson('/api/invoices', [
            'vendor_id' => $vendor->id,
            'invoice_no' => 'EUR-1',
            'invoice_date' => now()->toDateString(),
            'currency' => 'AED', // the form's stale default — the lines are what count
            'business_unit' => 'GGH',
            'department' => 'Custom clearance',
            'location' => 'Dubai',
            'payment_method' => 'bank_transfer',
            'priority' => 'normal',
            'items' => [
                ['currency' => 'EUR', 'amount' => 500, 'tax_rate' => 5],
            ],
        ])->assertCreated();

        $res->assertJsonPath('currency', 'EUR');
        $this->assertSame('EUR', Invoice::find($res->json('id'))->currency);
    }

    public function test_line_items_in_different_currencies_are_rejected(): void
    {
        $vendor = $this->seedMasterData();

        $this->actingAs($this->requester('mixed@t.local'))->postJson('/api/invoices', [
            'vendor_id' => $vendor->id,
            'invoice_no' => 'MIX-1',
            'invoice_date' => now()->toDateString(),
            'business_unit' => 'GGH',
            'department' => 'Custom clearance',
            'location' => 'Dubai',
            'payment_method' => 'bank_transfer',
            'priority' => 'normal',
            'items' => [
                ['currency' => 'EUR', 'amount' => 500, 'tax_rate' => 0],
                ['currency' => 'AED', 'amount' => 300, 'tax_rate' => 0],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('items');

        $this->assertSame(0, Invoice::count());
    }

    public function test_same_named_vendors_remain_distinct_by_vendor_id(): void
    {
        $this->seedMasterData();
        $selectedVendor = Vendor::create([
            'name' => 'Al Noor Logistics',
            'vendor_code' => 'ALN-2',
            'credit_days' => 45,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->requester('duplicate-vendor@t.local'))->postJson('/api/invoices', [
            'vendor_id' => $selectedVendor->id,
            'invoice_no' => 'DUPLICATE-NAME-1',
            'invoice_date' => now()->toDateString(),
            'currency' => 'AED',
            'business_unit' => 'GGH',
            'department' => 'Custom clearance',
            'location' => 'Dubai',
            'payment_method' => 'bank_transfer',
            'priority' => 'normal',
            'items' => [
                ['currency' => 'AED', 'amount' => 100, 'tax_rate' => 0],
            ],
        ])->assertCreated();

        $response
            ->assertJsonPath('vendor_id', $selectedVendor->id)
            ->assertJsonPath('vendor_name', 'Al Noor Logistics')
            ->assertJsonPath('vendor.vendor_code', 'ALN-2');

        $this->assertDatabaseHas('invoices', [
            'id' => $response->json('id'),
            'vendor_id' => $selectedVendor->id,
            'vendor_name' => 'Al Noor Logistics',
        ]);
    }
}
