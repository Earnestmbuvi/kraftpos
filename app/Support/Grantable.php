<?php

namespace App\Support;

class Grantable
{
    // Extras that can be added to anyone
    public const LIST = [
        'give-discounts' => 'Give discounts',
        'void-sales' => 'Void sales and invoices',
        'view-reports' => 'View reports',
        'view-cost-prices' => 'See cost prices',
        'adjust-stock' => 'Adjust stock',
        'manage-products' => 'Manage products',
        'manage-categories' => 'Manage categories',
        'manage-customers' => 'Manage customers',
        'issue-invoices' => 'Issue invoices',
        'record-expenses' => 'Record expenses',
        'process-returns' => 'Process returns and exchanges',
        'transfer-stock' => 'Transfer stock between branches',
    ];

    // What a custom role can be built from. Managing staff and settings stays with owners.
    public const ROLE_LIST = ['make-sales' => 'Sell at the till', 'view-products' => 'View products'] + self::LIST;
}
