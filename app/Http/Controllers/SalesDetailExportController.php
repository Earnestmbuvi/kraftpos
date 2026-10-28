<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use App\Services\SalesDetail;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SalesDetailExportController extends Controller
{
    public function __invoke(Request $request, string $kind)
    {
        $user = $request->user();

        abort_unless(in_array($kind, ['items', 'customer']), 404);
        abort_unless($user->can($kind === 'items' ? 'make-sales' : 'view-reports'), 403);

        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'show' => ['nullable', Rule::in(['all', 'sale', 'invoice'])],
            'user' => ['nullable', 'integer'], 'customer' => ['nullable', 'integer'], 'search' => ['nullable', 'string', 'max:100'],
        ]);

        [$f, $t] = ReportService::range($request->query('from'), $request->query('to'));
        $cost = $user->can('view-cost-prices');

        $query = SalesDetail::lines($f, $t, [
            'kind' => $request->query('show', 'all') ?: 'all', 'user' => $request->integer('user') ?: null,
            'customer' => $request->integer('customer') ?: null, 'search' => (string) $request->query('search', ''),
        ])->orderBy('created_at')->orderBy('doc_id');

        $name = "kraftpos-sale-items-{$f->toDateString()}-to-{$t->toDateString()}.csv";

        return response()->streamDownload(function () use ($query, $cost) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            $head = ['Date', 'Type', 'Number', 'Product', 'Variant', 'Quantity', 'Unit price', 'Discount', 'Line total'];
            if ($cost) {
                $head[] = 'Cost';
            }
            fputcsv($out, $head);

            foreach ($query->limit(50000)->get() as $r) {
                $row = [
                    \Carbon\Carbon::parse($r->created_at)->format('Y-m-d H:i'), $r->kind, SalesDetail::label($r->kind, $r->number),
                    $r->product_name, $r->variant_name, (int) $r->quantity, (float) $r->unit_price, (float) $r->discount_amount, (float) $r->line_total,
                ];
                if ($cost) {
                    $row[] = round((float) $r->unit_cost * (int) $r->quantity, 2);
                }
                fputcsv($out, array_map([ReportExportController::class, 'safe'], $row));
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
