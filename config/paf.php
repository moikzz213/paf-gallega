<?php

return [

    'categories' => [
        'Goods / Inventory',
        'Services',
        'Utilities',
        'Rent & Facilities',
        'Marketing & Advertising',
        'IT & Software',
        'Logistics & Freight',
        'Maintenance',
        'Professional Fees',
        'Travel & Accommodation',
        'Other',
    ],

    'departments' => [
        'Finance',
        'Procurement',
        'IT',
        'HR',
        'Operations',
        'Marketing',
        'Sales',
        'Logistics',
        'Administration',
    ],

    'currencies' => ['AED', 'USD', 'EUR', 'GBP', 'SAR'],

    'payment_methods' => [
        'bank_transfer' => 'Bank Transfer',
        'cheque' => 'Cheque',
        'cash' => 'Cash',
        'card' => 'Corporate Card',
    ],

    'priorities' => ['low', 'normal', 'high', 'urgent'],

    // upload constraints
    'max_documents' => 10,
    'max_document_kb' => 10240,
    'document_mimes' => 'pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx,csv,txt',

];
