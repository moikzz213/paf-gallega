<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A vendor's invoice number may be recorded once for that vendor.
 *
 * The number was captured on every invoice and never checked, so the same vendor document could be
 * entered repeatedly and each copy posted, approved and paid on its own — the ordinary route to a
 * duplicate payment.
 */
class UniqueVendorInvoiceNumberTest extends TestCase
{
    use RefreshDatabase;

    private function seedMasterData(): void
    {
        BusinessUnit::create(['name' => 'GGH', 'is_active' => true]);
        Department::create(['name' => 'Yard', 'is_active' => true]);
        Location::create(['name' => 'Dubai', 'is_active' => true]);
    }

    private function vendor(string $name, string $code): Vendor
    {
        return Vendor::create(['name' => $name, 'vendor_code' => $code, 'is_active' => true]);
    }

    private function requester(string $email = 'req@t.local', string $role = User::ROLE_REQUESTER): User
    {
        return User::create([
            'name' => 'Req', 'email' => $email, 'password' => 'password',
            'role' => $role, 'is_active' => true,
        ]);
    }

    private function payload(Vendor $vendor, string $invoiceNo): array
    {
        return [
            'vendor_id' => $vendor->id,
            'invoice_no' => $invoiceNo,
            'invoice_date' => now()->toDateString(),
            'currency' => 'AED',
            'business_unit' => 'GGH',
            'department' => 'Yard',
            'location' => 'Dubai',
            'payment_method' => 'bank_transfer',
            'priority' => 'normal',
            'items' => [
                ['job_no' => 'JOB-1', 'currency' => 'AED', 'description' => 'Line', 'amount' => 1000, 'tax_rate' => 0],
            ],
        ];
    }

    private function submit(User $user, Vendor $vendor, string $invoiceNo)
    {
        return $this->actingAs($user)->postJson('/api/invoices', $this->payload($vendor, $invoiceNo));
    }

    // ---- Happy path -----------------------------------------------------------------------

    public function test_a_first_invoice_for_a_vendor_is_accepted(): void
    {
        $this->seedMasterData();

        $this->submit($this->requester(), $this->vendor('Al Noor', 'ALN-1'), 'INV-1001')
            ->assertCreated();
    }

    public function test_a_different_number_for_the_same_vendor_is_accepted(): void
    {
        $this->seedMasterData();
        $vendor = $this->vendor('Al Noor', 'ALN-1');
        $user = $this->requester();

        $this->submit($user, $vendor, 'INV-1001')->assertCreated();
        $this->submit($user, $vendor, 'INV-1002')->assertCreated();
    }

    /** Two vendors numbering their own documents identically is ordinary, not a duplicate. */
    public function test_the_same_number_for_a_different_vendor_is_accepted(): void
    {
        $this->seedMasterData();
        $user = $this->requester();

        $this->submit($user, $this->vendor('Al Noor', 'ALN-1'), 'INV-1001')->assertCreated();
        $this->submit($user, $this->vendor('Gulf Freight', 'GLF-1'), 'INV-1001')->assertCreated();
    }

    // ---- Duplicates -----------------------------------------------------------------------

    public function test_an_exact_duplicate_for_the_same_vendor_is_declined(): void
    {
        $this->seedMasterData();
        $vendor = $this->vendor('Al Noor', 'ALN-1');
        $user = $this->requester();

        $this->submit($user, $vendor, 'INV-1001')->assertCreated();
        $this->submit($user, $vendor, 'INV-1001')
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoice_no');

        $this->assertSame(1, Invoice::count());
    }

    /**
     * Production compares `invoice_no` under a case-insensitive collation and the test database
     * does not, so the rule normalises both sides itself rather than inheriting either behaviour.
     */
    public function test_a_duplicate_differing_only_in_capitalisation_is_declined(): void
    {
        $this->seedMasterData();
        $vendor = $this->vendor('Al Noor', 'ALN-1');
        $user = $this->requester();

        $this->submit($user, $vendor, 'INV-1001')->assertCreated();
        $this->submit($user, $vendor, 'inv-1001')
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoice_no');
    }

    public function test_a_duplicate_differing_only_in_surrounding_spaces_is_declined(): void
    {
        $this->seedMasterData();
        $vendor = $this->vendor('Al Noor', 'ALN-1');
        $user = $this->requester();

        $this->submit($user, $vendor, 'INV-1001')->assertCreated();
        $this->submit($user, $vendor, '  INV-1001  ')
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoice_no');
    }

    /** "Already taken" would leave Finance hunting; the clash has to be named. */
    public function test_the_message_names_the_invoice_already_holding_the_number(): void
    {
        $this->seedMasterData();
        $vendor = $this->vendor('Al Noor', 'ALN-1');
        $user = $this->requester();

        $this->submit($user, $vendor, 'INV-1001')->assertCreated();
        $existing = Invoice::first();

        $response = $this->submit($user, $vendor, 'INV-1001')->assertStatus(422);

        $this->assertStringContainsString(
            $existing->reference_no,
            $response->json('errors.invoice_no.0'),
        );
    }

    public function test_a_declined_duplicate_leaves_nothing_behind(): void
    {
        $this->seedMasterData();
        $vendor = $this->vendor('Al Noor', 'ALN-1');
        $user = $this->requester();

        $this->submit($user, $vendor, 'INV-1001')->assertCreated();
        $before = Invoice::first();

        $this->submit($user, $vendor, 'INV-1001')->assertStatus(422);

        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, DB::table('invoice_items')->count());
        $this->assertEquals($before->toArray(), Invoice::first()->toArray());
    }

    // ---- Corrections ----------------------------------------------------------------------

    /** The obvious way to get this wrong: an invoice blocking itself on every save. */
    public function test_an_invoice_can_be_corrected_without_changing_its_number(): void
    {
        $this->seedMasterData();
        $vendor = $this->vendor('Al Noor', 'ALN-1');
        $user = $this->requester();

        $this->submit($user, $vendor, 'INV-1001')->assertCreated();
        $invoice = Invoice::first();

        $this->actingAs($user)
            ->postJson("/api/invoices/{$invoice->id}", [
                ...$this->payload($vendor, 'INV-1001'),
                'description' => 'Corrected description',
            ])
            ->assertOk();
    }

    public function test_a_correction_cannot_take_a_number_another_invoice_holds(): void
    {
        $this->seedMasterData();
        $vendor = $this->vendor('Al Noor', 'ALN-1');
        $user = $this->requester();

        $this->submit($user, $vendor, 'INV-1001')->assertCreated();
        $this->submit($user, $vendor, 'INV-1002')->assertCreated();
        $second = Invoice::where('invoice_no', 'INV-1002')->first();

        $this->actingAs($user)
            ->postJson("/api/invoices/{$second->id}", $this->payload($vendor, 'INV-1001'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoice_no');
    }

    // ---- Cancellation ---------------------------------------------------------------------

    /**
     * A cancelled invoice is a withdrawn record. If it kept its number, one mis-entry would lock
     * the genuine invoice out of the system permanently.
     */
    public function test_a_cancelled_invoice_releases_its_number(): void
    {
        $this->seedMasterData();
        $vendor = $this->vendor('Al Noor', 'ALN-1');
        $user = $this->requester();

        $this->submit($user, $vendor, 'INV-2001')->assertCreated();
        Invoice::first()->update(['status' => Invoice::STATUS_CANCELLED]);

        $this->submit($user, $vendor, 'INV-2001')->assertCreated();

        $this->assertSame(2, Invoice::count());
    }

    // ---- The database backstop --------------------------------------------------------------

    /**
     * Validation can be raced by two simultaneous submissions, so the same rule is carried by a
     * unique index. This writes straight past the application to prove the index is really there.
     */
    public function test_the_database_refuses_a_duplicate_that_bypasses_validation(): void
    {
        $this->seedMasterData();
        $vendor = $this->vendor('Al Noor', 'ALN-1');

        $this->submit($this->requester(), $vendor, 'INV-1001')->assertCreated();
        $existing = Invoice::first();

        $this->expectException(QueryException::class);

        Invoice::create([
            ...$existing->only([
                'vendor_name', 'vendor_id', 'invoice_no', 'invoice_date', 'currency',
                'amount', 'tax_amount', 'total_amount', 'business_unit', 'department',
                'location', 'payment_method', 'priority', 'status', 'payment_status',
                'submitted_by',
            ]),
            'reference_no' => Invoice::nextReferenceNo(),
            'submitted_at' => now(),
        ]);
    }

    /** Cancelled rows are exempt from the index too, not only from the validation rule. */
    public function test_the_database_allows_a_number_reused_after_cancellation(): void
    {
        $this->seedMasterData();
        $vendor = $this->vendor('Al Noor', 'ALN-1');

        $this->submit($this->requester(), $vendor, 'INV-3001')->assertCreated();
        $first = Invoice::first();
        $first->update(['status' => Invoice::STATUS_CANCELLED]);

        $second = Invoice::create([
            ...$first->only([
                'vendor_name', 'vendor_id', 'invoice_no', 'invoice_date', 'currency',
                'amount', 'tax_amount', 'total_amount', 'business_unit', 'department',
                'location', 'payment_method', 'priority', 'payment_status', 'submitted_by',
            ]),
            'status' => Invoice::STATUS_SUBMITTED,
            'reference_no' => Invoice::nextReferenceNo(),
            'submitted_at' => now(),
        ]);

        $this->assertTrue($second->exists);
    }

    // ---- Expense accounts -------------------------------------------------------------------

    /**
     * Petty cash floats and reimbursements issue no vendor invoice number, so submitters type a
     * word in the field. Production had `petty cash` ten times over for one account and `na` seven
     * times, over genuinely different amounts. Enforcing uniqueness there would have stopped petty
     * cash working on the day this shipped.
     */
    public function test_an_expense_account_may_reuse_the_same_text_indefinitely(): void
    {
        $this->seedMasterData();
        $vendor = $this->vendor('PETTY CASH ADVANCE-YARD', 'PC-1');
        $vendor->update(['is_expense_account' => true]);
        $user = $this->requester();

        foreach (range(1, 3) as $ignored) {
            $this->submit($user, $vendor, 'petty cash')->assertCreated();
        }

        $this->assertSame(3, Invoice::count());
    }

    public function test_an_expense_accounts_invoices_are_stamped_exempt(): void
    {
        $this->seedMasterData();
        $vendor = $this->vendor('PETTY CASH ADVANCE-YARD', 'PC-1');
        $vendor->update(['is_expense_account' => true]);

        $this->submit($this->requester(), $vendor, 'petty cash')->assertCreated();

        $this->assertTrue(Invoice::first()->invoice_no_exempt);
    }

    /** An ordinary vendor is unaffected by the exemption existing. */
    public function test_an_ordinary_vendors_invoices_are_not_exempt(): void
    {
        $this->seedMasterData();
        $vendor = $this->vendor('Al Noor', 'ALN-1');

        $this->submit($this->requester(), $vendor, 'INV-1001')->assertCreated();

        $this->assertFalse(Invoice::first()->invoice_no_exempt);
    }

    /**
     * The flag is denormalised onto the invoice because a generated column cannot read another
     * table, so it has to be re-stamped whenever the invoice is written — otherwise moving an
     * invoice between an expense account and a real vendor would carry the wrong exemption.
     */
    public function test_moving_an_invoice_to_an_ordinary_vendor_restores_the_rule(): void
    {
        $this->seedMasterData();
        $expense = $this->vendor('PETTY CASH ADVANCE-YARD', 'PC-1');
        $expense->update(['is_expense_account' => true]);
        $ordinary = $this->vendor('Al Noor', 'ALN-1');
        $user = $this->requester();

        $this->submit($user, $expense, 'shared-no')->assertCreated();
        $invoice = Invoice::first();

        $this->actingAs($user)
            ->postJson("/api/invoices/{$invoice->id}", $this->payload($ordinary, 'shared-no'))
            ->assertOk();

        $this->assertFalse($invoice->refresh()->invoice_no_exempt);
    }

    // ---- Grandfathered history ----------------------------------------------------------------

    /**
     * A historic duplicate that was already paid cannot be cancelled without rewriting payment
     * history, so it is marked exempt instead. It keeps its record; it stops holding the number.
     */
    public function test_a_grandfathered_invoice_does_not_hold_its_number(): void
    {
        $this->seedMasterData();
        $vendor = $this->vendor('Al Noor', 'ALN-1');
        $user = $this->requester();

        $this->submit($user, $vendor, 'INV-1001')->assertCreated();
        Invoice::first()->update(['invoice_no_exempt' => true]);

        $this->submit($user, $vendor, 'INV-1001')->assertCreated();
    }

    /** But the earliest copy still holds it, so the rule keeps working for everything new. */
    public function test_the_copy_that_keeps_the_number_still_refuses_new_collisions(): void
    {
        $this->seedMasterData();
        $vendor = $this->vendor('Al Noor', 'ALN-1');
        $user = $this->requester();

        $this->submit($user, $vendor, 'INV-1001')->assertCreated();
        $this->submit($user, $vendor, 'INV-1001')->assertStatus(422);
    }

    // ---- The preparation command --------------------------------------------------------------

    public function test_the_prepare_command_changes_nothing_without_apply(): void
    {
        $this->seedMasterData();
        $vendor = $this->vendor('PETTY CASH ADVANCE-YARD', 'PC-1');

        $this->artisan('paf:prepare-invoice-no-uniqueness')->assertSuccessful();

        $this->assertFalse($vendor->refresh()->is_expense_account);
    }

    public function test_the_prepare_command_marks_expense_accounts_on_apply(): void
    {
        $this->seedMasterData();
        $vendor = $this->vendor('PETTY CASH ADVANCE-YARD', 'PC-1');
        $ordinary = $this->vendor('Al Noor', 'ALN-1');
        $this->submit($this->requester(), $vendor, 'petty cash')->assertCreated();

        $this->artisan('paf:prepare-invoice-no-uniqueness --apply')->assertSuccessful();

        $this->assertTrue($vendor->refresh()->is_expense_account);
        $this->assertFalse($ordinary->refresh()->is_expense_account);
        $this->assertTrue(Invoice::first()->invoice_no_exempt);
    }

    public function test_the_prepare_command_grandfathers_a_differing_amount_duplicate(): void
    {
        $this->seedMasterData();
        $vendor = $this->vendor('Al Noor', 'ALN-1');
        $invoices = $this->forceDuplicates($vendor, 'INV-9001');
        $invoices[1]->update(['total_amount' => 999, 'payment_status' => Invoice::PAY_PAID]);

        $this->artisan('paf:prepare-invoice-no-uniqueness --apply')->assertSuccessful();

        $this->assertFalse($invoices[0]->refresh()->invoice_no_exempt, 'the earliest keeps the number');
        $this->assertTrue($invoices[1]->refresh()->invoice_no_exempt, 'the later copy is grandfathered');
    }

    /**
     * The case that must never be waved through: identical amounts under one number is what a
     * double-billing looks like, and grandfathering it would leave an unpaid copy still payable.
     */
    public function test_the_prepare_command_refuses_to_grandfather_identical_amounts(): void
    {
        $this->seedMasterData();
        $vendor = $this->vendor('Al Noor', 'ALN-1');
        $invoices = $this->forceDuplicates($vendor, 'INV-9001');

        $this->artisan('paf:prepare-invoice-no-uniqueness --apply')
            ->expectsOutputToContain('NOT grandfathered')
            ->assertSuccessful();

        foreach ($invoices as $invoice) {
            $this->assertFalse($invoice->refresh()->invoice_no_exempt);
        }
    }

    // ---- Pre-deployment tooling -------------------------------------------------------------

    /**
     * Production already holds duplicates, so the guard that refuses to build the index over them
     * is the part that decides whether a deployment succeeds or fails halfway. It has to fire, and
     * it has to name what to fix — a bare driver error names neither the vendor nor the invoices.
     *
     * The index is dropped first to recreate the pre-migration state, which is the only state this
     * code ever runs in.
     */
    public function test_the_migration_refuses_to_run_while_duplicates_exist(): void
    {
        $this->seedMasterData();
        $vendor = $this->vendor('Al Noor', 'ALN-1');
        $duplicates = $this->forceDuplicates($vendor, 'INV-9001');

        $migration = require database_path('migrations/2026_09_24_000002_enforce_unique_invoice_no_per_vendor.php');

        // The detail is echoed rather than carried in the exception: an exception message that
        // long renders under a stack trace, where the part telling you what to do is the part you
        // cannot find. The exception stays short; the terminal gets the list.
        ob_start();

        try {
            $migration->up();
            ob_get_clean();
            $this->fail('The migration should refuse to build the index while duplicates exist.');
        } catch (\RuntimeException $e) {
            $printed = ob_get_clean();

            $this->assertStringContainsString('paf:prepare-invoice-no-uniqueness', $e->getMessage());
            $this->assertStringContainsString('Al Noor', $printed);
            $this->assertStringContainsString('INV-9001', strtoupper($printed));

            foreach ($duplicates as $invoice) {
                $this->assertStringContainsString($invoice->reference_no, $printed);
            }
        }
    }

    /** The command Finance run before deployment has to find what the guard would block. */
    public function test_the_check_command_reports_duplicates_and_fails(): void
    {
        $this->seedMasterData();
        $vendor = $this->vendor('Al Noor', 'ALN-1');
        $this->forceDuplicates($vendor, 'INV-9001');

        $this->artisan('paf:check-invoice-duplicates')
            ->expectsOutputToContain('Al Noor')
            ->assertFailed();
    }

    public function test_the_check_command_passes_on_clean_data(): void
    {
        $this->seedMasterData();
        $vendor = $this->vendor('Al Noor', 'ALN-1');

        $this->submit($this->requester(), $vendor, 'INV-1001')->assertCreated();

        $this->artisan('paf:check-invoice-duplicates')
            ->expectsOutputToContain('No duplicates')
            ->assertSuccessful();
    }

    /**
     * Put the database into the state production is in: duplicates present, index not yet built.
     *
     * @return array<int, Invoice>
     */
    private function forceDuplicates(Vendor $vendor, string $invoiceNo): array
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('invoices_vendor_invoice_no_unique');
        });

        $user = $this->requester('dup@t.local');

        return collect(range(1, 2))->map(fn () => Invoice::create([
            'reference_no' => Invoice::nextReferenceNo(),
            'vendor_name' => $vendor->name, 'vendor_id' => $vendor->id,
            'invoice_no' => $invoiceNo, 'invoice_date' => now()->toDateString(),
            'currency' => 'AED', 'amount' => 100, 'tax_amount' => 0, 'total_amount' => 100,
            'business_unit' => 'GGH', 'department' => 'Yard', 'location' => 'Dubai',
            'payment_method' => 'bank_transfer', 'priority' => 'normal',
            'status' => Invoice::STATUS_POSTED, 'payment_status' => Invoice::PAY_NOT_INITIATED,
            'submitted_by' => $user->id, 'submitted_at' => now(),
        ]))->all();
    }

    /**
     * Invoices raised before vendors were a master list carry no vendor link, so there is no
     * vendor to be unique within. They must not be caught by the index.
     */
    public function test_invoices_with_no_vendor_link_are_outside_the_rule(): void
    {
        $this->seedMasterData();
        $user = $this->requester();

        foreach (['legacy-a', 'legacy-b'] as $reference) {
            Invoice::create([
                'reference_no' => strtoupper($reference),
                'vendor_name' => 'Historic Vendor', 'vendor_id' => null,
                'invoice_no' => 'OLD-1', 'invoice_date' => now()->toDateString(),
                'currency' => 'AED', 'amount' => 100, 'tax_amount' => 0, 'total_amount' => 100,
                'business_unit' => 'GGH', 'department' => 'Yard', 'location' => 'Dubai',
                'payment_method' => 'bank_transfer', 'priority' => 'normal',
                'status' => Invoice::STATUS_POSTED, 'payment_status' => Invoice::PAY_NOT_INITIATED,
                'submitted_by' => $user->id, 'submitted_at' => now(),
            ]);
        }

        $this->assertSame(2, Invoice::whereNull('vendor_id')->count());
    }
}
