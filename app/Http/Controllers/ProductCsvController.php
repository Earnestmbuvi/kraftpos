<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\ProductImportService;
use Illuminate\Http\Request;

class ProductCsvController extends Controller
{
    public function export(Request $request)
    {
        $showCost = $request->user()->can('view-cost-prices');

        $cols = ['Name', 'Variant', 'Category', 'Unit', 'Type', 'SKU', 'Barcode'];
        if ($showCost) {
            $cols[] = 'Cost price';
        }
        array_push($cols, 'Selling price', 'Tax', 'Stock', 'Low stock alert', 'Tracks batches', 'Status');

        return response()->streamDownload(function () use ($cols, $showCost) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $cols);

            Product::with(['category', 'variants'])->chunkById(200, function ($products) use ($out, $showCost) {
                foreach ($products as $p) {
                    $type = $p->is_service ? 'Service' : ($p->type === 'variable' ? 'Variable' : 'Single');

                    foreach ($p->variants as $v) {
                        $row = [$p->name, $v->name === 'Default' ? '' : $v->name, $p->category?->name, $p->unit, $type, $v->sku, $v->barcode];
                        if ($showCost) {
                            $row[] = (float) $v->cost_price;
                        }
                        array_push(
                            $row,
                            (float) $v->selling_price,
                            $p->tax_class,
                            $p->is_service ? '' : (int) $v->stock_quantity,
                            $p->is_service ? '' : (int) $v->low_stock_threshold,
                            $p->tracks_batches ? 'Yes' : 'No',
                            ($p->is_active && $v->is_active) ? 'Active' : 'Disabled',
                        );
                        fputcsv($out, array_map([ReportExportController::class, 'safe'], $row));
                    }
                }
            });

            fclose($out);
        }, 'kraftpos-products-' . now()->toDateString() . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }


public function template()
{
    return response()->streamDownload(function () {
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, ProductImportService::HEADERS);

        // Product assigned to Karen and Westlands
        fputcsv($out, [
            'Sugar 1kg', 'Groceries', 'kg', 'SUG-1KG', '',
            '170', '200', '30', '5', 'product', 'standard',
            'Karen|Westlands',
        ]);

        // Product with no branch assignments specified
        fputcsv($out, [
            'Haircut', '', 'pcs', '', '',
            '0', '500', '', '', 'service', 'zero',
            '',
        ]);

        fclose($out);
    }, 'kraftpos-product-import-template.csv', [
        'Content-Type' => 'text/csv; charset=UTF-8',
    ]);
}

}
