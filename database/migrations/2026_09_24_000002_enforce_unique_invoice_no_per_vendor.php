<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A vendor's invoice number may appear only once for that vendor.
 *
 * The number was captured on every invoice and never checked, so the same vendor document could be
 * recorded any number of times — each copy with its own reference, each able to be posted, approved
 * and paid on its own. That is the ordinary route to a duplicate payment, and the field that should
 * have prevented it was carrying the appearance of a control without being one.
 *
 * The constraint is expressed over a generated column rather than `invoice_no` directly, because
 * two of the rules it has to carry are invisible to a plain unique index:
 *
 *   - Cancelled invoices release their number. A cancelled mis-entry must not lock out the genuine
 *     invoice for good. The column is NULL for them, and both MySQL and SQLite exempt NULLs from a
 *     unique index.
 *   - Exempt invoices are outside the rule entirely: petty cash and reimbursement accounts, which
 *     have no vendor invoice number to be unique, and historic duplicates that were grandfathered
 *     because they had already been paid. Same NULL exemption (see the 000001 migration).
 *   - Capitalisation and surrounding spaces are ignored, so the same document does not slip through
 *     as `inv-1001` beside `INV-1001`.
 *
 * Rows with no `vendor_id` — invoices raised before vendors were a master list, whose name was too
 * ambiguous for the 2026_08_05 backfill to resolve — cannot be scoped to a vendor and are left
 * outside the rule by the same NULL exemption.
 *
 * The status literal is written out rather than read from `Invoice::STATUS_CANCELLED`: a migration
 * has to keep meaning what it meant on the day it ran, and a constant that is later renamed would
 * silently change an index that is already built.
 */
return new class extends Migration
{
    private const EXPRESSION = "CASE WHEN status = 'cancelled' OR invoice_no_exempt = 1 THEN NULL ELSE LOWER(TRIM(invoice_no)) END";

    public function up(): void
    {
        $this->assertNoExistingDuplicates();

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('invoice_no_key')
                ->nullable()
                ->virtualAs(self::EXPRESSION)
                ->after('invoice_no');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unique(['vendor_id', 'invoice_no_key'], 'invoices_vendor_invoice_no_unique');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('invoices_vendor_invoice_no_unique');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('invoice_no_key');
        });
    }

    /**
     * Refuse to build the index over data that already breaks it, and say exactly what to fix.
     *
     * The index would fail on its own, but with a driver error naming neither the vendor nor the
     * invoices involved — on a production database that is the difference between a five-minute
     * data correction and a failed deployment nobody can diagnose. Finance resolve the duplicates
     * first; the change request makes that a pre-deployment step.
     */
    private function assertNoExistingDuplicates(): void
    {
        $duplicates = DB::table('invoices')
            ->selectRaw('vendor_id, '.self::EXPRESSION.' AS invoice_no_key, COUNT(*) AS occurrences')
            ->whereNotNull('vendor_id')
            // Grouped by the expression, not by the `invoice_no_key` alias. Before this migration
            // runs the alias is all there is; afterwards the name also belongs to a real generated
            // column, and MySQL then resolves it to the column and rejects the whole query under
            // `only_full_group_by`, because the selected expression reads `status`. Naming the
            // expression works in both states.
            ->whereRaw(self::EXPRESSION.' IS NOT NULL')
            ->groupByRaw('vendor_id, '.self::EXPRESSION)
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isEmpty()) {
            return;
        }

        $lines = $duplicates->map(function ($row) {
            $references = DB::table('invoices')
                ->where('vendor_id', $row->vendor_id)
                ->where('status', '!=', 'cancelled')
                ->where('invoice_no_exempt', false)
                ->whereRaw(self::EXPRESSION.' = ?', [$row->invoice_no_key])
                ->pluck('reference_no')
                ->implode(', ');

            $vendor = DB::table('vendors')->where('id', $row->vendor_id)->value('name') ?? "vendor #{$row->vendor_id}";

            return "  {$vendor} — invoice no. {$row->invoice_no_key} on {$row->occurrences} invoices: {$references}";
        })->implode(PHP_EOL);

        throw new RuntimeException(
            'Invoice numbers cannot be made unique per vendor while duplicates exist.'.PHP_EOL
            .'Run `php artisan paf:check-invoice-duplicates` to review them, then '
            .'`php artisan paf:prepare-invoice-no-uniqueness --apply` to mark expense accounts and '
            .'grandfather what cannot be cancelled. Outstanding:'.PHP_EOL.$lines
        );
    }
};
