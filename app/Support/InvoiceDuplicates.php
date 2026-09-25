<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Finds vendor invoice numbers recorded more than once, in a way every engine agrees on.
 *
 * This query has broken twice on the shape of its GROUP BY, both times only outside development,
 * so the reasoning is worth keeping.
 *
 * The key is an expression, not a column — `LOWER(TRIM(invoice_no))`, nulled for rows the rule does
 * not cover. Grouping by that expression while also selecting it is exactly the case
 * `ONLY_FULL_GROUP_BY` polices, and the engines do not agree on it:
 *
 *   - Grouping by the `invoice_no_key` *alias* works until the migration adds a generated column of
 *     that name, after which MySQL resolves the alias to the column, notices the selected
 *     expression reads `status`, and rejects the query. It therefore worked on first run and failed
 *     on re-run — the worst possible failure for a deployment guard.
 *   - Grouping by the expression *itself* satisfies MySQL 8, which matches it against the identical
 *     expression in the select list. MariaDB does not perform that matching and rejects it, which
 *     is what production does.
 *
 * So the expression is computed in a derived table and the grouping is done on an ordinary column
 * of it. A plain column in GROUP BY is unambiguous everywhere — MySQL 5.7 and 8, MariaDB, SQLite —
 * and no longer depends on how clever a particular optimiser is about equivalence.
 */
class InvoiceDuplicates
{
    /**
     * Rows the rule does not cover carry a NULL key and never collide.
     *
     * Cancelled invoices release their number; exempt invoices are petty cash and reimbursement
     * accounts, which issue no vendor invoice number, plus historic duplicates that were
     * grandfathered because they had already been paid.
     */
    public static function expression(bool $honourExemption = true): string
    {
        return $honourExemption
            ? "CASE WHEN status = 'cancelled' OR invoice_no_exempt = 1 THEN NULL ELSE LOWER(TRIM(invoice_no)) END"
            : "CASE WHEN status = 'cancelled' THEN NULL ELSE LOWER(TRIM(invoice_no)) END";
    }

    /**
     * Vendor + normalised number pairs holding more than one invoice.
     *
     * @param  bool  $honourExemption  false to keep grandfathered history visible, which is what
     *                                 reporting wants and what the index itself ignores
     */
    public static function groups(bool $honourExemption = true): Builder
    {
        $inner = DB::table('invoices')
            ->selectRaw('vendor_id, '.self::expression($honourExemption).' AS invoice_no_key')
            ->whereNotNull('vendor_id');

        return DB::query()
            ->fromSub($inner, 'keyed')
            ->select('vendor_id', 'invoice_no_key')
            ->selectRaw('COUNT(*) AS occurrences')
            ->whereNotNull('invoice_no_key')
            ->groupBy('vendor_id', 'invoice_no_key')
            ->havingRaw('COUNT(*) > 1');
    }
}
