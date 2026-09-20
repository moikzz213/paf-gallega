<?php

/*
|--------------------------------------------------------------------------
| PAF domain lists — driven by .env
|--------------------------------------------------------------------------
| Each list is a comma-separated env value (with a sensible default). Edit the
| PAF_* keys in .env to change the options shown in the app.
*/

$list = static function (string $key, string $default): array {
    return array_values(array_filter(array_map('trim', explode(',', (string) env($key, $default)))));
};

// payment_methods is a key:label map, e.g. "bank_transfer:Bank Transfer,cheque:Cheque"
$paymentMethods = [];
foreach (explode(',', (string) env('PAF_PAYMENT_METHODS', 'bank_transfer:Bank Transfer,cheque:Cheque,cash:Cash,card:Corporate Card')) as $pair) {
    $parts = array_map('trim', explode(':', $pair, 2));
    if ($parts[0] === '') {
        continue;
    }
    $paymentMethods[$parts[0]] = $parts[1] ?? $parts[0];
}

return [

    'business_units' => $list('PAF_BUSINESS_UNITS', 'GIL,GGL,GGH'),

    'departments' => $list('PAF_DEPARTMENTS', 'Warehouse,Yard,Freight forwarding,Custom clearance,Land transportation,Service center'),

    'locations' => $list('PAF_LOCATIONS', 'Head Office,Dubai,Abu Dhabi,Sharjah,Jebel Ali,Warehouse'),

    // Currencies are master data (the `currencies` table) — this list only seeds that table on the
    // create_currencies_table migration. Changing it afterwards has no effect on the app; edit the
    // Currencies tab under Master Data instead.
    'currencies' => $list('PAF_CURRENCIES', 'AED,USD,EUR,GBP,SAR'),

    // The currency approval thresholds are expressed in. Every invoice total is converted into it
    // (using the per-currency exchange rate held in master data) before it is measured against an
    // approval level's min_amount, so a USD invoice routes on what it is really worth.
    'base_currency' => strtoupper((string) env('PAF_BASE_CURRENCY', 'AED')),

    'priorities' => $list('PAF_PRIORITIES', 'normal,high,urgent'),

    'payment_methods' => $paymentMethods,

    // Repairing a vendor PDF that FPDI's free parser will not read — permissions-only encryption,
    // or the object/cross-reference streams PDF 1.5+ uses. Runs only where the ordinary merge has
    // already failed, and every failure falls back to listing the document as a link, so switching
    // this off (or leaving qpdf uninstalled) restores the behaviour that predates it.
    // See ai/change-requests/repair-unmergeable-pdf-attachments-with-qpdf.md
    'pdf_repair' => [
        'enabled' => (bool) env('PAF_PDF_REPAIR', true),
        'binary' => env('PAF_QPDF_PATH', 'qpdf'),
        // Seconds. A conversion that runs longer than this is abandoned: somebody is waiting on a
        // download, and the link-list fallback is a better answer than a hung request.
        'timeout' => (float) env('PAF_PDF_REPAIR_TIMEOUT', 20),
    ],

    // upload constraints
    'max_documents' => (int) env('PAF_MAX_DOCUMENTS', 10),
    'max_document_kb' => (int) env('PAF_MAX_DOCUMENT_KB', 10240),
    'document_mimes' => env('PAF_DOCUMENT_MIMES', 'pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx,csv,txt'),

];
