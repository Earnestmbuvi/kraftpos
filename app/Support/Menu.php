<?php

namespace App\Support;

class Menu
{
    private const ICONS = [
        'home' => 'M2.25 12l8.954-8.955a1.126 1.126 0 011.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25',
        'pos' => 'M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 00-16.536-1.84M7.5 14.25L5.106 5.272M6 20.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm12.75 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z',
        'box' => 'M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9',
        'stock' => 'M3 7.5L7.5 3m0 0L12 7.5M7.5 3v13.5m13.5 0L16.5 21m0 0L12 16.5m4.5 4.5V7.5',
        'expense' => 'M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z',
        'chart' => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
        'people' => 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z',
        'staff' => 'M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM3 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 019.374 21c-2.331 0-4.512-.645-6.374-1.766z',
        'setup' => 'M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75',
        'shop' => 'M3.75 21h16.5M4.5 3h15l-.75 6.75a3 3 0 01-3 2.75h-7.5a3 3 0 01-3-2.75L4.5 3zM6 21v-7.5h12V21',
        'card' => 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z',
    ];

    /** The Setup pages this person may open, in order. */
    public static function setupTabs($user): array
    {
        if (! $user || $user->is_super_admin) {
            return [];
        }

        $tabs = [];

        if ($user->can('manage-categories')) {
            $tabs['categories'] = 'Categories';
        }
        if ($user->can('manage-products')) {
            $tabs['units'] = 'Units';
            $tabs['options'] = 'Options';
        }
        if ($user->can('manage-categories')) {
            $tabs['expense-categories'] = 'Expense categories';
        }
        if ($user->can('manage-settings')) {
            $tabs['payments'] = 'Payment methods';
            $tabs['tax'] = 'Sales settings';
            $tabs['branches'] = 'Branches';
            $tabs['roles'] = 'Staff roles';
            $tabs['receipts'] = 'Receipts and documents';
        }


        return $tabs;
    }

    /** Everything this person may open: flat links and groups (a group has children). */
    public static function items($user): array
    {
        if (! $user) {
            return [];
        }

        $c = fn (string $p) => $user->can($p);

        if ($user->is_super_admin) {
            $all = [
    ['label' => 'Merchants', 'icon' => 'shop', 'route' => 'admin.businesses', 'match' => ['admin.businesses', 'admin.merchant'], 'show' => true],
    ['label' => 'Billing', 'icon' => 'card', 'route' => 'admin.billing', 'match' => ['admin.billing'], 'show' => true],
    ['label' => 'Documents', 'icon' => 'expense', 'match' => ['admin.quotations', 'admin.invoices', 'admin.document', 'admin.document.create', 'admin.catalogue'], 'children' => [
        ['label' => 'Quotations', 'route' => 'admin.quotations', 'match' => ['admin.quotations'], 'show' => true],
        ['label' => 'Invoices', 'route' => 'admin.invoices', 'match' => ['admin.invoices'], 'show' => true],
        ['label' => 'Catalogue', 'route' => 'admin.catalogue', 'match' => ['admin.catalogue'], 'show' => true],
    ]],
    ['label' => 'Leads', 'icon' => 'people', 'route' => 'admin.leads', 'match' => ['admin.leads'], 'show' => true],
    ['label' => 'Admins', 'icon' => 'staff', 'route' => 'admin.admins', 'match' => ['admin.admins'], 'show' => true],
];
        } else {
            $setup = [];
            foreach (self::setupTabs($user) as $key => $label) {
                $setup[] = ['label' => $label, 'route' => 'setup', 'params' => ['tab' => $key], 'tab' => $key, 'match' => ['setup'], 'show' => true];
            }

            $all = [
                ['label' => 'Home', 'icon' => 'home', 'route' => 'dashboard', 'match' => ['dashboard'], 'show' => true],

                ['label' => 'POS', 'icon' => 'pos', 'match' => ['sell', 'quotations*', 'invoices*', 'sales', 'sales.*', 'returns*', 'exchanges*'], 'children' => [
                    ['label' => 'Sell', 'route' => 'sell', 'match' => ['sell'], 'show' => $c('make-sales')],
                    ['label' => 'Quotations', 'route' => 'quotations', 'match' => ['quotations*'], 'show' => $c('make-sales')],
                    ['label' => 'Invoices', 'route' => 'invoices', 'match' => ['invoices*'], 'show' => $c('issue-invoices')],
                    ['label' => 'Sales history', 'route' => 'sales', 'match' => ['sales', 'sales.*'], 'show' => $c('make-sales')],
                    ['label' => 'Returns', 'route' => 'returns', 'match' => ['returns*'], 'show' => $c('process-returns')],
                    ['label' => 'Exchange', 'route' => 'exchanges.create', 'match' => ['exchanges*'], 'show' => $c('process-returns')],
                ]],

                ['label' => 'Products', 'icon' => 'box', 'match' => ['products', 'products.*'], 'children' => [
                    ['label' => 'Product list', 'route' => 'products', 'match' => ['products'], 'show' => $c('view-products')],
                    ['label' => 'Import CSV', 'route' => 'products.import', 'match' => ['products.import'], 'show' => $c('manage-products')],
                    ['label' => 'Export CSV', 'route' => 'products.export', 'match' => ['products.export'], 'download' => true, 'show' => $c('view-products')],
                ]],

                ['label' => 'Stock', 'icon' => 'stock', 'match' => ['stock', 'stock.*'], 'children' => [
                    ['label' => 'Adjustment', 'route' => 'stock', 'match' => ['stock'], 'show' => $c('adjust-stock')],
                    ['label' => 'Movements', 'route' => 'stock.movements', 'match' => ['stock.movements*'], 'show' => $c('adjust-stock')],
                    ['label' => 'Stock take', 'route' => 'stock.take', 'match' => ['stock.take'], 'show' => $c('adjust-stock')],
                    ['label' => 'Transfers', 'route' => 'stock.transfers', 'match' => ['stock.transfers'], 'show' => $c('transfer-stock')],
                ]],

                ['label' => 'Expenses', 'icon' => 'expense', 'route' => 'expenses', 'match' => ['expenses'], 'show' => $c('record-expenses')],

                ['label' => 'Reports', 'icon' => 'chart', 'match' => ['reports', 'reports.*'], 'children' => [
                    ['label' => 'Sales and profit', 'route' => 'reports', 'match' => ['reports', 'reports.export'], 'show' => $c('view-reports')],
                    ['label' => 'Sale items', 'route' => 'reports.sale-items', 'match' => ['reports.sale-items'], 'show' => $c('make-sales')],
                    ['label' => 'Customer report', 'route' => 'reports.customers', 'match' => ['reports.customers'], 'show' => $c('view-reports')],
                    ['label' => 'Stock list', 'route' => 'reports.stock-list', 'match' => ['reports.stock-list'], 'show' => $c('view-reports')],
                    ['label' => 'Stock additions', 'route' => 'reports.additions', 'match' => ['reports.additions'], 'show' => $c('view-reports')],
                    ['label' => 'Price list', 'route' => 'reports.price-list', 'match' => ['reports.price-list'], 'show' => $c('view-reports')],
                    ['label' => 'Expense report', 'route' => 'reports.expenses', 'match' => ['reports.expenses'], 'show' => $c('view-reports')],
                    ['label' => 'Income statement', 'route' => 'reports.income', 'match' => ['reports.income'], 'show' => $c('view-reports') && $c('view-cost-prices')],
                    ['label' => 'Tax report', 'route' => 'reports.tax', 'match' => ['reports.tax'], 'show' => $c('view-reports')],
                ]],

                ['label' => 'Customers', 'icon' => 'people', 'route' => 'customers', 'match' => ['customers'], 'show' => $c('manage-customers')],
                ['label' => 'Staff', 'icon' => 'staff', 'route' => 'staff', 'match' => ['staff'], 'show' => $c('manage-users')],

                ['label' => 'Setup', 'icon' => 'setup', 'match' => ['setup'], 'children' => $setup],
            ];
        }

        $out = [];
        foreach ($all as $item) {
            $item['path'] = self::ICONS[$item['icon']];

            if (isset($item['children'])) {
                $item['children'] = array_values(array_filter($item['children'], fn ($ch) => $ch['show']));
                if (! $item['children']) {
                    continue;
                }
                $first = collect($item['children'])->first(fn ($ch) => empty($ch['download']));
                $item['route'] = $first['route'];
                $item['params'] = $first['params'] ?? [];
            } elseif (! $item['show']) {
                continue;
            }

            $out[] = $item;
        }

        return $out;
    }

    public static function active(array $item): bool
    {
        return request()->routeIs(...$item['match']);
    }

    public static function childActive(array $child): bool
    {
        if (isset($child['tab'])) {
            $current = request()->route('tab') ?: array_key_first(self::setupTabs(auth()->user()));

            return request()->routeIs('setup') && $current === $child['tab'];
        }

        return request()->routeIs(...$child['match']);
    }

    /** The group whose pages are open right now, if any. It decides whether the side panel shows. */
    public static function activeGroup(array $items): ?array
    {
        foreach ($items as $item) {
            if (isset($item['children']) && self::active($item)) {
                return $item;
            }
        }

        return null;
    }
    public static function icon(string $name): string
{
    return self::ICONS[$name] ?? self::ICONS['box'];
}
}
