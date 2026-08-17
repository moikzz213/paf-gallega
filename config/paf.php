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

    'priorities' => $list('PAF_PRIORITIES', 'normal,high,urgent'),

    'payment_methods' => $paymentMethods,

    // upload constraints
    'max_documents' => (int) env('PAF_MAX_DOCUMENTS', 10),
    'max_document_kb' => (int) env('PAF_MAX_DOCUMENT_KB', 10240),
    'document_mimes' => env('PAF_DOCUMENT_MIMES', 'pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx,csv,txt'),

];
