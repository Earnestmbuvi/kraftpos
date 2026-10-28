<?php

namespace App\Http\Controllers;

use App\Services\MovementQuery;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StockMovementExportController extends Controller
{
    public function __invoke(Request $request)
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'type' => ['nullable', Rule::in(array_merge(['all'], MovementQuery::TYPES))],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $query = MovementQuery::build(
            $request->query('from'), $request->query('to'),
            $request->query('type') ?: 'all', (string) $request->query('search', '')
        );

        $name = 'kraftpos-stock-movements-' . now()->toDateString() . '.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Date', 'Branch','Product', 'Variant', 'Batch', 'Type', 'Change', 'Stock after', 'Reason', 'Note', 'By']);

            $query->chunkById(500, function ($movements) use ($out) {
                foreach ($movements as $m) {
                    fputcsv($out, array_map([ReportExportController::class, 'safe'], [
                        $m->created_at->format('Y-m-d H:i'),
                        $m->branch?->name,
                        $m->variant->product->name,
                        $m->variant->name === 'Default' ? '' : $m->variant->name,
                        $m->batch?->batch_number,
                        $m->type,
                        (int) $m->quantity_change,
                        (int) $m->quantity_after,
                        $m->reason,
                        $m->note,
                        $m->user?->name,
                    ]));
                }
            });

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
