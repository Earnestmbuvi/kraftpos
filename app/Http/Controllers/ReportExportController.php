<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReportExportController extends Controller
{
    private const REPORTS = [
        'sales', 'products', 'profit', 'expenses', 'expiry', 'stock', 'owed',
        'stocklist', 'additions', 'pricelist', 'expensereport', 'income', 'tax',
    ];

    public function __invoke(Request $request, string $report)
    {
        $user = $request->user();

        abort_unless(in_array($report, self::REPORTS), 404);
        abort_if(in_array($report, ['profit', 'income']) && ! $user->can('view-cost-prices'), 403);

        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'category' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['all', 'low', 'out'])],
            'search' => ['nullable', 'string', 'max:100'],
            'expcat' => ['nullable', 'integer'],
        ]);

        [$f, $t] = ReportService::range($request->query('from'), $request->query('to'));

        $filters = [
            'category' => $request->integer('category') ?: null,
            'status' => $request->query('status', 'all') ?: 'all',
            'search' => (string) $request->query('search', ''),
            'expcat' => $request->integer('expcat') ?: null,
            'cost' => $user->can('view-cost-prices'),
        ];

        $rows = ReportService::csv($report, $f, $t, $filters);
        $name = "kraftpos-{$report}-{$f->toDateString()}-to-{$t->toDateString()}.csv";

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads UTF-8 correctly

            foreach ($rows as $row) {
                fputcsv($out, array_map([self::class, 'safe'], $row));
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // Product and customer names are typed by users. A cell starting with = + - or @
    // would run as a formula when opened in Excel, so those get a leading apostrophe.
    public static function safe($cell)
    {
        if (is_string($cell) && preg_match('/^[=+\-@\t\r]/', $cell)) {
            return "'" . $cell;
        }

        return $cell;
    }
}
