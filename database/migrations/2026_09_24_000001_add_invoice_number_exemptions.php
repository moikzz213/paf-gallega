<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two exemptions the per-vendor invoice number rule has to carry, before the rule itself.
 *
 * `vendors.is_expense_account` marks an account that issues no vendor invoice numbers — petty cash
 * floats, staff reimbursements, fuel claims. Production holds about a hundred of them, and because
 * there is no document number to record, submitters type a word in the field instead: `petty cash`
 * ten times over for one account, `na` seven times, `august` five. Those are different transactions
 * with different amounts, not duplicates, and a rule that refused them would have stopped petty
 * cash working on the day it shipped.
 *
 * `invoices.invoice_no_exempt` is what the index actually reads, and it exists because a generated
 * column cannot look at another table. It is set from the vendor's flag when an invoice is written,
 * and it is also how historic duplicates are grandfathered: rows already through or inside a
 * payment cycle cannot be cancelled — that would rewrite payment history — so they are marked
 * exempt instead, preserved exactly as they are, while the earliest invoice of each group keeps the
 * number and goes on refusing anything new that collides with it.
 *
 * CR: ai/change-requests/enforce-unique-invoice-number-per-vendor.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->boolean('is_expense_account')
                ->default(false)
                ->after('vendor_code')
                ->index();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('invoice_no_exempt')
                ->default(false)
                ->after('invoice_no');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('invoice_no_exempt');
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropIndex(['is_expense_account']);
            $table->dropColumn('is_expense_account');
        });
    }
};
