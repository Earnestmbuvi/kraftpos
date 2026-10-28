<?php

namespace App\Support;

class ReceiptSettings
{
    public const TOGGLES = [
        'show_phone' => 'Phone number',
        'show_address' => 'Address',
        'show_email' => 'Business email',
        'show_branch' => 'Branch name',
        'show_till' => 'Till number',
        'show_cashier' => 'Served by (cashier)',
        'show_customer' => 'Customer name',
        'show_discounts' => 'Discount lines',
        'show_tax' => 'Tax line',
        'show_payments' => 'How it was paid',
        'show_powered' => 'Powered by KraftPOS line',
    ];

    public const DEFAULTS = [
        'show_phone' => true, 'show_address' => true, 'show_email' => false, 'show_branch' => true,
        'show_till' => true, 'show_cashier' => true, 'show_customer' => true, 'show_discounts' => true,
        'show_tax' => true, 'show_payments' => true, 'show_powered' => true,
        'header_text' => '', 'footer_text' => 'Thank you for shopping with us.', 'paper' => 'thermal',
    ];

    public static function for($business): array
    {
        return array_merge(self::DEFAULTS, (array) ($business?->receipt_settings ?? []));
    }
}
