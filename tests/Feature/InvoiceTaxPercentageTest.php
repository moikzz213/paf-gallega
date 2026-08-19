<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tax / VAT is entered as a percentage of the line amount. The cash figure is derived on the server
 * from the rate, so the two can never disagree, and the header totals stay plain sums of the lines.
 */
class InvoiceTaxPercentageTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        BusinessUnit::create(['name' => 'GGH', 'is_active' => true]);
        Department::create(['name' => 'Yard', 'is_active' => true]);
        Location::create(['name' => 'Dubai', 'is_active' => true]);
        Currency::firstOrCreate(['name' => 'AED']);
        $this->vendor = Vendor::create(['name' => 'Al Noor Logistics', 'vendor_code' => 'ALN-1', 'is_active' => true]);
    }

    private function requester(): User
    {
        return User::create([
            'name' => 'Req', 'email' => 'req'.uniqid().'@t.local', 'password' => 'password',
            'role' => User::ROLE_REQUESTER, 'is_active' => true,
        ]);
    }

    /** @param  array<int, array<string, mixed>>  $items */
    private function payload(array $items): array
    {
        return [
            'vendor_id' => $this->vendor->id,
            'invoice_no' => 'INV-'.uniqid(),
            'invoice_date' => now()->toDateString(),
            'business_unit' => 'GGH',
            'department' => 'Yard',
            'location' => 'Dubai',
            'payment_method' => 'bank_transfer',
            'priority' => 'normal',
            'items' => array_map(fn ($item) => array_merge(['currency' => 'AED'], $item), $items),
        ];
    }

    public function test_the_rate_is_stored_and_the_tax_amount_is_derived_from_it(): void
    {
        $res = $this->actingAs($this->requester())
            ->postJson('/api/invoices', $this->payload([['amount' => 1000, 'tax_rate' => 5]]))
            ->assertCreated();

        $item = Invoice::find($res->json('id'))->items->first();
        $this->assertSame('5.00', $item->tax_rate);
        $this->assertSame('50.00', $item->tax_amount);
        $this->assertSame('1050.00', $item->total_amount);

        // The header stays a plain sum of the lines.
        $res->assertJsonPath('amount', '1000.00')
            ->assertJsonPath('tax_amount', '50.00')
            ->assertJsonPath('total_amount', '1050.00');
    }

    public function test_a_posted_tax_amount_is_ignored_now_that_the_rate_is_the_input(): void
    {
        $res = $this->actingAs($this->requester())
            ->postJson('/api/invoices', $this->payload([
                ['amount' => 1000, 'tax_rate' => 5, 'tax_amount' => 999],
            ]))
            ->assertCreated();

        $this->assertSame('50.00', Invoice::find($res->json('id'))->items->first()->tax_amount);
    }

    public function test_an_omitted_rate_means_no_tax(): void
    {
        $res = $this->actingAs($this->requester())
            ->postJson('/api/invoices', $this->payload([['amount' => 800]]))
            ->assertCreated();

        $item = Invoice::find($res->json('id'))->items->first();
        $this->assertSame('0.00', $item->tax_rate);
        $this->assertSame('0.00', $item->tax_amount);
        $this->assertSame('800.00', $item->total_amount);
    }

    public function test_each_line_carries_its_own_rate(): void
    {
        $res = $this->actingAs($this->requester())
            ->postJson('/api/invoices', $this->payload([
                ['amount' => 1000, 'tax_rate' => 5],
                ['amount' => 500, 'tax_rate' => 0],
                ['amount' => 200, 'tax_rate' => 2.5],
            ]))
            ->assertCreated();

        $invoice = Invoice::find($res->json('id'));

        $this->assertSame(['50.00', '0.00', '5.00'], $invoice->items->pluck('tax_amount')->all());
        $this->assertSame('1700.00', $invoice->amount);
        $this->assertSame('55.00', $invoice->tax_amount);
        $this->assertSame('1755.00', $invoice->total_amount);
    }

    public function test_the_derived_amount_is_rounded_to_the_currency(): void
    {
        $res = $this->actingAs($this->requester())
            ->postJson('/api/invoices', $this->payload([['amount' => 333.33, 'tax_rate' => 5]]))
            ->assertCreated();

        // 333.33 at 5% is 16.6665
        $this->assertSame('16.67', Invoice::find($res->json('id'))->items->first()->tax_amount);
    }

    public function test_a_rate_above_one_hundred_or_below_zero_is_rejected(): void
    {
        foreach ([101, -1] as $rate) {
            $this->actingAs($this->requester())
                ->postJson('/api/invoices', $this->payload([['amount' => 100, 'tax_rate' => $rate]]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('items.0.tax_rate');
        }
    }

    public function test_editing_recomputes_the_tax_from_the_new_rate(): void
    {
        $requester = $this->requester();
        $created = $this->actingAs($requester)
            ->postJson('/api/invoices', $this->payload([['amount' => 1000, 'tax_rate' => 5]]))
            ->assertCreated()
            ->json();

        $this->actingAs($requester)
            ->post("/api/invoices/{$created['id']}", $this->payload([['amount' => 1000, 'tax_rate' => 10]]))
            ->assertOk()
            ->assertJsonPath('tax_amount', '100.00')
            ->assertJsonPath('total_amount', '1100.00');

        $this->assertSame('10.00', Invoice::find($created['id'])->items->first()->tax_rate);
    }

    /** A line predating the rate column keeps its cash figure; the migration recovers the rate. */
    public function test_the_migration_recovers_a_rate_from_an_existing_tax_amount(): void
    {
        $invoice = Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => $this->vendor->name, 'invoice_no' => 'LEGACY-1',
            'invoice_date' => now()->toDateString(),
            'currency' => 'AED', 'amount' => 1000, 'tax_amount' => 50, 'total_amount' => 1050,
            'business_unit' => 'GGH', 'department' => 'Yard', 'location' => 'Dubai',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => Invoice::STATUS_SUBMITTED, 'payment_status' => Invoice::PAY_NOT_INITIATED,
            'submitted_by' => $this->requester()->id, 'submitted_at' => now(),
        ]);

        // Written the way it would have been before tax_rate existed: cash figure only.
        DB::table('invoice_items')->insert([
            'invoice_id' => $invoice->id, 'sort_order' => 0, 'currency' => 'AED',
            'amount' => 1000, 'tax_rate' => 0, 'tax_amount' => 50, 'total_amount' => 1050,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // The column is already in place here, so run only the backfill half of the migration.
        (require database_path('migrations/2026_08_18_000001_add_tax_rate_to_invoice_items_table.php'))
            ->backfillRates();

        $item = $invoice->items()->first();
        $this->assertSame('5.00', $item->tax_rate);
        $this->assertSame('50.00', $item->tax_amount, 'the money already on the invoice is never rewritten');
    }
}
