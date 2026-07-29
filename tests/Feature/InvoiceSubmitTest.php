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

    private function seedMasterData(): void
    {
        Vendor::create(['name' => 'Al Noor Logistics', 'is_active' => true]);
        BusinessUnit::create(['name' => 'GGH', 'is_active' => true]);
        BusinessUnit::create(['name' => 'GIL', 'is_active' => true]);
        Department::create(['name' => 'Custom clearance', 'is_active' => true]);
        Department::create(['name' => 'Yard', 'is_active' => true]);
        Location::create(['name' => 'Dubai', 'is_active' => true]);
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
        $this->seedMasterData();

        $res = $this->actingAs($this->requester('req@t.local'))->postJson('/api/invoices', [
            'vendor_name' => 'Al Noor Logistics',
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
                ['job_no' => 'JOB-X', 'currency' => 'AED', 'amount' => 1000, 'tax_amount' => 50],
                ['job_no' => 'JOB-Y', 'currency' => 'AED', 'amount' => 200, 'tax_amount' => 0],
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
        $this->seedMasterData();

        $this->actingAs($this->requester('req2@t.local'))->postJson('/api/invoices', [
            'vendor_name' => 'Al Noor Logistics', 'invoice_no' => 'X', 'invoice_date' => now()->toDateString(),
            'currency' => 'AED', 'business_unit' => 'GIL', 'department' => 'Yard', 'location' => 'Dubai',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
        ])->assertStatus(422)->assertJsonValidationErrors('items');
    }
}
