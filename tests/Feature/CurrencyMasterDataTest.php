<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Location;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Invoice Log currency dropdown is fed by the currencies master data table, not by a
 * hard-coded list, and only currencies on that table can be saved on an invoice.
 */
class CurrencyMasterDataTest extends TestCase
{
    use RefreshDatabase;

    private function seedMasterData(): Vendor
    {
        BusinessUnit::create(['name' => 'GGH', 'is_active' => true]);
        Department::create(['name' => 'Yard', 'is_active' => true]);
        Location::create(['name' => 'Dubai', 'is_active' => true]);

        return Vendor::create(['name' => 'Al Noor Logistics', 'vendor_code' => 'ALN-1', 'is_active' => true]);
    }

    private function requester(string $email): User
    {
        return User::create([
            'name' => 'Req', 'email' => $email, 'password' => 'password',
            'role' => User::ROLE_REQUESTER, 'is_active' => true,
        ]);
    }

    private function invoicePayload(Vendor $vendor, string $currency): array
    {
        return [
            'vendor_id' => $vendor->id,
            'invoice_no' => 'INV-'.$currency,
            'invoice_date' => now()->toDateString(),
            'business_unit' => 'GGH',
            'department' => 'Yard',
            'location' => 'Dubai',
            'payment_method' => 'bank_transfer',
            'priority' => 'normal',
            'items' => [
                ['job_no' => 'JOB-1', 'currency' => $currency, 'amount' => 100, 'tax_rate' => 0],
            ],
        ];
    }

    public function test_meta_lists_active_currencies_from_master_data(): void
    {
        Currency::query()->delete();
        Currency::create(['name' => 'AED', 'is_active' => true]);
        Currency::create(['name' => 'ZAR', 'is_active' => true]);
        Currency::create(['name' => 'OMR', 'is_active' => false]);

        $this->actingAs($this->requester('meta@t.local'))
            ->getJson('/api/meta')
            ->assertOk()
            ->assertJsonPath('currencies', ['AED', 'ZAR']);
    }

    public function test_a_currency_added_to_master_data_can_be_used_on_an_invoice(): void
    {
        $vendor = $this->seedMasterData();
        Currency::create(['name' => 'JPY', 'is_active' => true]);

        $res = $this->actingAs($this->requester('new-currency@t.local'))
            ->postJson('/api/invoices', $this->invoicePayload($vendor, 'JPY'));

        $res->assertCreated()->assertJsonPath('currency', 'JPY');
    }

    public function test_a_currency_missing_from_master_data_is_rejected(): void
    {
        $vendor = $this->seedMasterData();

        $this->actingAs($this->requester('unknown-currency@t.local'))
            ->postJson('/api/invoices', $this->invoicePayload($vendor, 'XXX'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.currency');
    }

    public function test_a_deactivated_currency_is_rejected(): void
    {
        $vendor = $this->seedMasterData();
        Currency::create(['name' => 'JPY', 'is_active' => false]);

        $this->actingAs($this->requester('inactive-currency@t.local'))
            ->postJson('/api/invoices', $this->invoicePayload($vendor, 'JPY'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.currency');
    }

    public function test_currency_master_data_rejects_anything_other_than_a_three_letter_upper_case_code(): void
    {
        $finance = User::create([
            'name' => 'Fin', 'email' => 'fin@t.local', 'password' => 'password',
            'role' => User::ROLE_FINANCE, 'is_active' => true,
        ]);

        foreach (['Dirham', 'aed', 'AE'] as $invalid) {
            $this->actingAs($finance)
                ->postJson('/api/master-data/currencies', ['name' => $invalid, 'is_active' => true])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('name');
        }

        $this->actingAs($finance)
            ->postJson('/api/master-data/currencies', ['name' => 'ZAR', 'is_active' => true])
            ->assertCreated();
    }
}
