<?php

return [
    'leads_email' => env('LEADS_EMAIL', 'info@codekraft.co.ke'),

    'company' => [
        'name' => env('COMPANY_NAME', 'CodeCraft Solutions'),
        'address' => env('COMPANY_ADDRESS', 'Nairobi, Kenya'),
        'phone' => env('COMPANY_PHONE', ''),
        'email' => env('COMPANY_EMAIL', 'info@codekraft.co.ke'),
        'pin' => env('COMPANY_PIN', ''),
        'payment_details' => env('COMPANY_PAYMENT_DETAILS', ''),   // e.g. Paybill 123456, account name
        'tax_rate' => (float) env('COMPANY_TAX_RATE', 16),
        'due_days' => 30,
    ],
];
