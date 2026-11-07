<?php

namespace App\Http\Controllers;

use App\Models\Product;

class CatalogCsvController extends Controller
{
    private const HEADERS = [
        'name', 'category', 'unit', 'sku', 'barcode', 'cost_price', 'selling_price',
        'stock', 'low_stock_threshold', 'type', 'tax', 'branches', 'description',
    ];

    // Stops a spreadsheet from running a cell that starts like a formula
    private static function safe($v): string
    {
        $v = (string) $v;

        return $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $v : $v;
    }

    public function export(string $kind)
    {
        $user = auth()->user();
        abort_unless($user && $user->can('view-products'), 403);

        $costs = $user->can('view-cost-prices');
        $service = $kind === 'service';

        $products = Product::with(['variants', 'category', 'branches'])
            ->where('is_service', $service)->orderBy('name')->get();

        return response()->streamDownload(function () use ($products, $costs, $service) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, self::HEADERS);

            foreach ($products as $p) {
                foreach ($p->variants as $v) {
                    fputcsv($out, [
                        self::safe($p->name . ($v->name !== 'Default' ? ' - ' . $v->name : '')),
                        self::safe($p->category?->name),
                        self::safe($p->unit),
                        self::safe($v->sku),
                        self::safe($v->barcode),
                        $costs ? $v->cost_price : '',
                        $v->selling_price,
                        $service ? '' : $v->stock_quantity,
                        $service ? '' : $v->low_stock_threshold,
                        $service ? 'service' : 'product',
                        $p->tax_class ?? 'standard',
                        self::safe($p->branches->pluck('name')->implode('|')),
                        self::safe($p->description),
                    ]);
                }
            }
            fclose($out);
        }, 'kraftpos-' . $kind . 's-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function template(string $kind)
    {
        abort_unless(auth()->user()?->can('view-products'), 403);

        $rows = $kind === 'service'
            ? [['Haircut', '', 'pcs', '', '', '0', '500', '', '', 'service', 'standard', '', 'Wash, cut and style'],
               ['Delivery', '', 'pcs', '', '', '0', '300', '', '', 'service', 'zero', 'Karen|Westlands', '']]
            : [['Sugar 1kg', '', 'pcs', 'SUG-1', '', '120', '160', '50', '10', 'product', 'standard', '', 'White sugar'],
               ['Bread 400g', '', 'pcs', '', '', '45', '60', '20', '5', 'product', 'zero', 'Karen|Westlands', '']];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, self::HEADERS);
            foreach ($rows as $r) {
                fputcsv($out, $r);
            }
            fclose($out);
        }, 'kraftpos-' . $kind . '-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
