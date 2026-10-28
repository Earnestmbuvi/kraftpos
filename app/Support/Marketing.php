<?php

namespace App\Support;

class Marketing
{
    public static function features(): array
    {
        return [
            ['Fast till', 'pos', 'Scan or tap items, take cash, M-Pesa or split payments, and park carts for later.'],
            ['Quotations and invoices', 'expense', 'Turn a quotation into an invoice in one click, with your own terms printed on both.'],
            ['Credit sales', 'card', 'Sell on credit, take deposits, record part-payments and print a receipt for each one.'],
            ['Stock control', 'stock', 'Adjustments, full stock takes, transfers and a complete movement history.'],
            ['Batches and expiry', 'box', 'Sell the soonest-expiring batch first and never sell expired stock.'],
            ['Many branches', 'shop', 'Every branch has its own stock, sales and reports. Owners can also see them all together.'],
            ['Returns and exchanges', 'home', 'Take goods back, refund or swap, with stock and profit corrected automatically.'],
            ['Expenses and bad debts', 'expense', 'Record spending, claim input tax and write off debts that will not be paid.'],
            ['Reports that matter', 'chart', 'Sales, profit, stock, price lists, tax, income statement and customer reports, all exportable.'],
            ['Tax done right', 'setup', 'Mark items standard, zero-rated or exempt and get an output and input tax report.'],
            ['Staff and permissions', 'staff', 'Owners, managers and cashiers, each limited to the branches and actions you choose.'],
            ['Import and export', 'people', 'Bring your products in from a CSV file and take any report out to Excel.'],
        ];
    }

    public static function sectors(): array
    {
        return ['Supermarket and grocery', 'Pharmacy', 'Hardware', 'Boutique and fashion', 'Salon and barbershop', 'Restaurant and cafe', 'Wholesale', 'Other'];
    }
}
