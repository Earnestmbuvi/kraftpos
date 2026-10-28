<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockBatch;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;

class ReportService
{
    public static function range(?string $from, ?string $to): array
    {
        return [self::parse($from, false), self::parse($to, true)];
    }

    private static function br($q, string $col)
{
    $b = BranchService::viewing();

    return $b === null ? $q : BranchService::limit($q, $b, $col);
}

    private static function parse(?string $value, bool $end): Carbon
    {
        try {
            $d = Carbon::createFromFormat('Y-m-d', (string) $value);
        } catch (\Throwable $e) {
            $d = now();
        }

        return $end ? $d->endOfDay() : $d->startOfDay();
    }

    private static function sales_q(Carbon $f, Carbon $t)
    {
        return Sale::query()->where('sales.status', 'completed')->whereBetween('sales.created_at', [$f, $t]);
    }


private static function items_q(Carbon $f, Carbon $t)
{
    return self::br(SaleItem::query()
        ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
        ->where('sales.status', 'completed')
        ->whereBetween('sales.created_at', [$f, $t]), 'sales.branch_id');
}


    private static function invoices_q(Carbon $f, Carbon $t)
    {
        return Invoice::query()->where('invoices.status', 'issued')->whereBetween('invoices.created_at', [$f, $t]);
    }

    private static function invoice_items_q(Carbon $f, Carbon $t)
{
    return self::br(InvoiceItem::query()
        ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
        ->where('invoices.status', 'issued')
        ->whereBetween('invoices.created_at', [$f, $t]), 'invoices.branch_id');
}


    private static function expenses_q(Carbon $f, Carbon $t)
    {
        return Expense::query()->where('expenses.status', 'recorded')
            ->whereDate('expenses.expense_date', '>=', $f->toDateString())
            ->whereDate('expenses.expense_date', '<=', $t->toDateString());
    }

    // ---------- Sales (till sales and issued invoices together) ----------

    public static function sales(Carbon $f, Carbon $t): array
    {
        $s = self::sales_q($f, $t)->selectRaw(
            'COUNT(*) as n, COALESCE(SUM(sales.total),0) as total, COALESCE(SUM(sales.discount_amount),0) as discount, '
            . 'COALESCE(SUM(sales.tax_amount),0) as tax, COALESCE(SUM(sales.change_given),0) as change_total'
        )->first();

        $i = self::invoices_q($f, $t)->selectRaw(
            'COUNT(*) as n, COALESCE(SUM(invoices.total),0) as total, COALESCE(SUM(invoices.discount_amount),0) as discount, '
            . 'COALESCE(SUM(invoices.tax_amount),0) as tax, COALESCE(SUM(invoices.total - invoices.amount_paid),0) as open'
        )->first();

        $change = (float) $s->change_total;
        $lineDiscount = (float) self::items_q($f, $t)->sum('sale_items.discount_amount')
    + (float) self::invoice_items_q($f, $t)->sum('invoice_items.discount_amount');
        $summary = (object) [
            'n' => (int) $s->n + (int) $i->n,
            'total' => (float) $s->total + (float) $i->total,
            'discount' => (float) $s->discount + (float) $i->discount + $lineDiscount,
            'tax' => (float) $s->tax + (float) $i->tax,
            'till_total' => (float) $s->total,
            'invoice_total' => (float) $i->total,
            'invoice_count' => (int) $i->n,
            'credit_open' => (float) $i->open,
            'change_total' => $change,
        ];

        $tillPay = self::br(Payment::query()
    ->join('sales', 'sales.id', '=', 'payments.sale_id')
    ->where('sales.status', 'completed')
    ->whereBetween('sales.created_at', [$f, $t]), 'sales.branch_id')
    ->selectRaw('payments.method_name, payments.type, SUM(payments.amount) as total')
    ->groupBy('payments.method_name', 'payments.type')->get();

$invPay = self::br(InvoicePayment::query()
    ->join('invoices', 'invoices.id', '=', 'invoice_payments.invoice_id')
    ->where('invoices.status', 'issued')
    ->where('invoice_payments.type', '!=', 'writeoff')
    ->whereBetween('invoice_payments.created_at', [$f, $t]), 'invoices.branch_id')
    ->selectRaw('invoice_payments.method_name, invoice_payments.type, SUM(invoice_payments.amount) as total')
    ->groupBy('invoice_payments.method_name', 'invoice_payments.type')->get();

        // Money received: till payments plus payments on invoices. Change is handed back out of cash.
        $methods = $tillPay->concat($invPay)->groupBy('method_name')->map(function ($g, $name) use ($change) {
            $type = $g->first()->type;
            $total = (float) $g->sum('total');

            return (object) [
                'method_name' => $name, 'type' => $type, 'total' => $total,
                'net' => $total - ($type === 'cash' ? $change : 0),
            ];
        })->sortByDesc('net')->values();

        $daily = self::sales_q($f, $t)
            ->selectRaw('DATE(sales.created_at) as day, COUNT(*) as n, SUM(sales.total) as total')->groupBy('day')->get()
            ->concat(self::invoices_q($f, $t)
                ->selectRaw('DATE(invoices.created_at) as day, COUNT(*) as n, SUM(invoices.total) as total')->groupBy('day')->get())
            ->groupBy('day')
            ->map(fn ($g, $day) => (object) ['day' => $day, 'n' => (int) $g->sum('n'), 'total' => (float) $g->sum('total')])
            ->sortKeys()->values();

        $cashiers = self::sales_q($f, $t)
            ->join('users', 'users.id', '=', 'sales.user_id')
            ->selectRaw('users.id as uid, users.name as cashier, COUNT(*) as n, SUM(sales.total) as total')
            ->groupBy('users.id', 'users.name')->get()
            ->concat(self::invoices_q($f, $t)
                ->join('users', 'users.id', '=', 'invoices.user_id')
                ->selectRaw('users.id as uid, users.name as cashier, COUNT(*) as n, SUM(invoices.total) as total')
                ->groupBy('users.id', 'users.name')->get())
            ->groupBy('uid')
            ->map(fn ($g) => (object) [
                'cashier' => $g->first()->cashier, 'n' => (int) $g->sum('n'), 'total' => (float) $g->sum('total'),
            ])->sortByDesc('total')->values();

            $ret = self::returns($f, $t);
$summary->returns = $ret['value'];
$methods = $methods->map(function ($m) use ($ret) {
    $m->net -= (float) ($ret['refunds'][$m->method_name] ?? 0);
    return $m;
});
foreach ($ret['refunds'] as $name => $amt) {
    if (! $methods->contains('method_name', $name)) {
        $methods->push((object) ['method_name' => $name, 'type' => 'other', 'total' => 0, 'net' => -$amt]);
    }
}

        return ['summary' => $summary, 'daily' => $daily, 'methods' => $methods, 'cashiers' => $cashiers];
    }

    /** Quantity and amount per variant across till sales and invoices. */
    private static function perProduct(Carbon $f, Carbon $t, bool $profit)
    {
        $rows = collect();

        foreach ([['sale_items', self::items_q($f, $t)], ['invoice_items', self::invoice_items_q($f, $t)]] as [$table, $q]) {
            $expr = $profit ? "{$table}.line_total - {$table}.unit_cost * {$table}.quantity" : "{$table}.line_total";

            $rows = $rows->concat($q->selectRaw(
                "{$table}.product_variant_id as vid, {$table}.product_name, {$table}.variant_name, "
                . "SUM({$table}.quantity) as qty, SUM({$expr}) as amount"
            )->groupBy("{$table}.product_variant_id", "{$table}.product_name", "{$table}.variant_name")->get());
        }

        return $rows->groupBy('vid')->map(fn ($g) => (object) [
            'product_name' => $g->first()->product_name,
            'variant_name' => $g->first()->variant_name,
            'qty' => (int) $g->sum('qty'),
            'amount' => (float) $g->sum('amount'),
        ])->values();
    }

    public static function products(Carbon $f, Carbon $t): array
    {
        return [
            'top' => self::perProduct($f, $t, false)->map(fn ($r) => (object) [
                'product_name' => $r->product_name, 'variant_name' => $r->variant_name,
                'qty' => $r->qty, 'revenue' => $r->amount,
            ])->sortByDesc('qty')->take(20)->values(),
        ];
    }

    // ---------- Profit and income ----------

   private static function parts(Carbon $f, Carbon $t): array
{
    $ret = self::returns($f, $t);

    $till = self::items_q($f, $t)->selectRaw(
        'COALESCE(SUM(sale_items.line_total),0) as revenue, COALESCE(SUM(sale_items.unit_cost * sale_items.quantity),0) as cost'
    )->first();
    $tillAdj = self::sales_q($f, $t)->selectRaw(
        'COALESCE(SUM(sales.discount_amount),0) as discount, '
        . 'COALESCE(SUM(CASE WHEN sales.tax_inclusive = 1 THEN sales.tax_amount ELSE 0 END),0) as tax'
    )->first();

    $inv = self::invoice_items_q($f, $t)->selectRaw(
        'COALESCE(SUM(invoice_items.line_total),0) as revenue, COALESCE(SUM(invoice_items.unit_cost * invoice_items.quantity),0) as cost'
    )->first();
    $invAdj = self::invoices_q($f, $t)->selectRaw(
        'COALESCE(SUM(invoices.discount_amount),0) as discount, '
        . 'COALESCE(SUM(CASE WHEN invoices.tax_inclusive = 1 THEN invoices.tax_amount ELSE 0 END),0) as tax'
    )->first();

    return [
        'tillRevenue' => (float) $till->revenue - (float) $tillAdj->discount - (float) $tillAdj->tax,
        'tillCost' => (float) $till->cost,
        'invoiceRevenue' => (float) $inv->revenue - (float) $invAdj->discount - (float) $invAdj->tax,
        'invoiceCost' => (float) $inv->cost,
        'returnRevenue' => $ret['revenue'],
        'returnCost' => $ret['cost'],
    ];
}

private static function returns(Carbon $f, Carbon $t): array
{
    $r = self::br(SaleReturnItem::query()
        ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')
        ->whereBetween('sale_returns.created_at', [$f, $t]), 'sale_returns.branch_id')
        ->selectRaw('COALESCE(SUM(sale_return_items.amount),0) as value, '
            . 'COALESCE(SUM(sale_return_items.amount - sale_return_items.tax_amount),0) as revenue, '
            . 'COALESCE(SUM(sale_return_items.cost),0) as cost')->first();

    $refunds = SaleReturn::whereBetween('created_at', [$f, $t])->where('refund_total', '>', 0)
        ->selectRaw('refund_method_name, SUM(refund_total) as total')->groupBy('refund_method_name')
        ->pluck('total', 'refund_method_name');

    return ['value' => (float) $r->value, 'revenue' => (float) $r->revenue, 'cost' => (float) $r->cost, 'refunds' => $refunds->all()];
}

public static function profit(Carbon $f, Carbon $t): array
{
    $p = self::parts($f, $t);

    // Change 1: returned goods come off revenue
    $net = $p['tillRevenue'] + $p['invoiceRevenue'] - $p['returnRevenue'];
    // Change 2: and their cost comes off cost of goods sold
    $cost = $p['tillCost'] + $p['invoiceCost'] - $p['returnCost'];
    $profit = $net - $cost;
    $expenses = (float) self::expenses_q($f, $t)->sum('expenses.amount');

    return [
        'net' => $net,
        'cost' => $cost,
        'profit' => $profit,
        'margin' => $net > 0 ? round($profit / $net * 100, 1) : 0,
        'expenses' => $expenses,
        'netProfit' => $profit - $expenses,
        'expenseByCategory' => self::expenses_q($f, $t)
            ->selectRaw('expenses.category_name, SUM(expenses.amount) as total')
            ->groupBy('expenses.category_name')->orderByDesc('total')->get(),
        'byProduct' => self::perProduct($f, $t, true)->map(fn ($r) => (object) [
            'product_name' => $r->product_name, 'variant_name' => $r->variant_name,
            'qty' => $r->qty, 'profit' => $r->amount,
        ])->sortByDesc('profit')->take(20)->values(),
    ];
}

public static function income(Carbon $f, Carbon $t): array
{
    $p = self::parts($f, $t);

    // The same two changes as in profit()
    $revenue = $p['tillRevenue'] + $p['invoiceRevenue'] - $p['returnRevenue'];
    $cost = $p['tillCost'] + $p['invoiceCost'] - $p['returnCost'];
    $gross = $revenue - $cost;

    $byCategory = self::expenses_q($f, $t)
        ->selectRaw('expenses.category_name, SUM(expenses.amount) as total')
        ->groupBy('expenses.category_name')->orderByDesc('total')->get();
    $expenses = (float) $byCategory->sum('total');

    return [
        'tillRevenue' => $p['tillRevenue'],
        'invoiceRevenue' => $p['invoiceRevenue'],
        'returns' => $p['returnRevenue'],
        'revenue' => $revenue,
        'cost' => $cost,
        'gross' => $gross,
        'grossMargin' => $revenue > 0 ? round($gross / $revenue * 100, 1) : 0,
        'byCategory' => $byCategory,
        'expenses' => $expenses,
        'net' => $gross - $expenses,
        'netMargin' => $revenue > 0 ? round(($gross - $expenses) / $revenue * 100, 1) : 0,
    ];
}

    // ---------- Expenses ----------

    public static function expenses(Carbon $f, Carbon $t): array
    {
        return [
            'expenseTotal' => (float) self::expenses_q($f, $t)->sum('expenses.amount'),
            'byCategory' => self::expenses_q($f, $t)
                ->selectRaw('expenses.category_name, COUNT(*) as n, SUM(expenses.amount) as total')
                ->groupBy('expenses.category_name')->orderByDesc('total')->get(),
            'list' => self::expenses_q($f, $t)->with('user')->latest('expense_date')->latest('id')->limit(100)->get(),
        ];
    }

    public static function expenseQuery(Carbon $f, Carbon $t, ?int $categoryId = null)
    {
        return self::expenses_q($f, $t)
            ->when($categoryId, fn ($q) => $q->where('expenses.expense_category_id', $categoryId));
    }

    public static function expenseReport(Carbon $f, Carbon $t, ?int $categoryId = null): array
    {
        $base = fn () => self::expenseQuery($f, $t, $categoryId);

        return [
            'total' => (float) $base()->sum('expenses.amount'),
            'entries' => $base()->count(),
            'byCategory' => $base()
                ->selectRaw('expenses.category_name, COUNT(*) as n, SUM(expenses.amount) as total')
                ->groupBy('expenses.category_name')->orderByDesc('total')->get(),
            'byUser' => $base()
                ->leftJoin('users', 'users.id', '=', 'expenses.user_id')
                ->selectRaw("COALESCE(users.name, 'Unknown') as who, COUNT(*) as n, SUM(expenses.amount) as total")
                ->groupBy('users.id', 'users.name')->orderByDesc('total')->get(),
        ];
    }

    // ---------- Stock ----------

   public static function stock(): array
{
    return [
        'low' => ProductVariant::with('product')->active()
            ->whereHas('product', fn ($p) => $p->active()->where('is_service', false))
            ->whereRaw(BranchService::qtySql() . ' <= product_variants.low_stock_threshold')
            ->orderBy('stock_quantity')->limit(100)->get(),
    ];
}

    private static function categoryIds($id)
    {
        return Category::where('id', (int) $id)->orWhere('parent_id', (int) $id)->pluck('id');
    }

    private static function matching($q, string $term)
    {
        return $q->where(function ($q) use ($term) {
            $q->where('sku', 'like', "%{$term}%")
              ->orWhere('barcode', 'like', "%{$term}%")
              ->orWhere('name', 'like', "%{$term}%")
              ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$term}%"));
        });
    }

    /** Every stock-keeping item with its current quantity. Services are left out. */
   public static function stockList(array $f = [])
{
    $term = trim($f['search'] ?? '');
    $status = $f['status'] ?? 'all';
    $qty = BranchService::qtySql();

    return ProductVariant::with('product.category')->active()
        ->whereHas('product', fn ($p) => $p->active()->where('is_service', false))
        ->when(! empty($f['category']), fn ($q) => $q->whereHas('product',
            fn ($p) => $p->whereIn('category_id', self::categoryIds($f['category']))))
        ->when($status === 'low', fn ($q) => $q->whereRaw("{$qty} > 0")->whereRaw("{$qty} <= product_variants.low_stock_threshold"))
        ->when($status === 'out', fn ($q) => $q->whereRaw("{$qty} <= 0"))
        ->when($term !== '', fn ($q) => self::matching($q, $term))
        ->orderBy('product_id')->orderBy('name');
}

public static function stockTotals(array $f = []): object
{
    $qty = BranchService::qtySql();

    $r = self::stockList($f)->setEagerLoads([])->reorder()->selectRaw(
        'COUNT(*) as items, '
        . "COALESCE(SUM(CASE WHEN {$qty} > 0 THEN {$qty} ELSE 0 END),0) as units, "
        . "COALESCE(SUM(CASE WHEN {$qty} > 0 THEN {$qty} * product_variants.cost_price ELSE 0 END),0) as cost_value, "
        . "COALESCE(SUM(CASE WHEN {$qty} > 0 THEN {$qty} * product_variants.selling_price ELSE 0 END),0) as retail_value"
    )->first();

    return (object) [
        'items' => (int) $r->items, 'units' => (int) $r->units,
        'cost_value' => (float) $r->cost_value, 'retail_value' => (float) $r->retail_value,
    ];
}
    /** Stock that came in: opening stock, purchases received and stock-take surpluses. */
    public static function additions(string $from, string $to, string $search = '')
    {
        return MovementQuery::build($from, $to, 'all', $search)
            ->where('quantity_change', '>', 0)
            ->whereIn('type', ['opening', 'adjustment', 'stocktake']);
    }

    public static function additionTotals(string $from, string $to, string $search, bool $withCost): object
    {
        $sql = 'COUNT(*) as entries, COALESCE(SUM(quantity_change),0) as units';
        if ($withCost) {
            $sql .= ', COALESCE(SUM(quantity_change * (select pv.cost_price from product_variants pv '
                . 'where pv.id = stock_movements.product_variant_id)),0) as value';
        }

        $r = self::additions($from, $to, $search)->setEagerLoads([])->reorder()->selectRaw($sql)->first();

        return (object) [
            'entries' => (int) $r->entries, 'units' => (int) $r->units,
            'value' => $withCost ? (float) $r->value : null,
        ];
    }

    public static function priceList(array $f = [])
    {
        $term = trim($f['search'] ?? '');

        return ProductVariant::with('product.category')->active()
            ->whereHas('product', fn ($p) => $p->active())
            ->when(! empty($f['category']), fn ($q) => $q->whereHas('product',
                fn ($p) => $p->whereIn('category_id', self::categoryIds($f['category']))))
            ->when($term !== '', fn ($q) => self::matching($q, $term))
            ->orderBy('product_id')->orderBy('name');
    }

    public static function expiry(): array
    {
        $warn = (int) config('inventory.expiry_warn_days');

        $batches = StockBatch::with('variant.product')
            ->where('quantity', '>', 0)->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', today()->addDays($warn)->toDateString())
            ->orderBy('expiry_date')->limit(200)->get();

        return [
            'batches' => $batches,
            'expiredCount' => $batches->filter(fn ($b) => $b->expiry_date->lt(today()))->count(),
            'warnDays' => $warn,
        ];
    }

    public static function owed(): array
    {
        return [
            'owed' => Invoice::with('customer')->where('status', 'issued')->whereColumn('amount_paid', '<', 'total')
                ->orderByRaw('due_date IS NULL')->orderBy('due_date')->limit(100)->get(),
            'owedTotal' => (float) Invoice::where('status', 'issued')->sum(DB::raw('total - amount_paid')),
        ];
    }

    // ---------- CSV rows ----------

    /** Rows for the CSV file. Numbers are real numbers, not strings. */
    public static function csv(string $report, Carbon $f, Carbon $t, array $filters = []): array
    {
        $period = ['Period', $f->toDateString(), $t->toDateString()];

        if ($report === 'sales') {
            $d = self::sales($f, $t);
            $s = $d['summary'];
            $rows = [
                ['Sales report (till sales and invoices)'], $period, [],
                ['Summary'], ['Sales', 'Total', 'Discounts', 'Tax', 'Of which invoiced', 'Invoices still unpaid'],
                [(int) $s->n, (float) $s->total, (float) $s->discount, (float) $s->tax, (float) $s->invoice_total, (float) $s->credit_open],
                [], ['By day'], ['Date', 'Sales', 'Total'],
            ];
            foreach ($d['daily'] as $x) {
                $rows[] = [$x->day, (int) $x->n, (float) $x->total];
            }
            $rows[] = [];
            $rows[] = ['Money received by method (cash is net of change)'];
            $rows[] = ['Method', 'Amount'];
            foreach ($d['methods'] as $m) {
                $rows[] = [$m->method_name, (float) $m->net];
            }
            $rows[] = [];
            $rows[] = ['By cashier'];
            $rows[] = ['Cashier', 'Sales', 'Total'];
            foreach ($d['cashiers'] as $c) {
                $rows[] = [$c->cashier, (int) $c->n, (float) $c->total];
            }

            return $rows;
        }

        if ($report === 'products') {
            $rows = [['Top products'], $period, [], ['Product', 'Variant', 'Quantity sold', 'Revenue']];
            foreach (self::products($f, $t)['top'] as $p) {
                $rows[] = [$p->product_name, $p->variant_name, (int) $p->qty, (float) $p->revenue];
            }

            return $rows;
        }

        if ($report === 'profit') {
            $d = self::profit($f, $t);
            $rows = [
                ['Profit report'], $period, [],
                ['Net revenue', 'Cost of goods', 'Gross profit', 'Gross margin %', 'Expenses', 'Net profit'],
                [round($d['net'], 2), round($d['cost'], 2), round($d['profit'], 2), (float) $d['margin'],
                 round($d['expenses'], 2), round($d['netProfit'], 2)],
                [], ['Expenses by category'], ['Category', 'Amount'],
            ];
            foreach ($d['expenseByCategory'] as $c) {
                $rows[] = [$c->category_name, (float) $c->total];
            }
            $rows[] = [];
            $rows[] = ['Most profitable products (before expenses)'];
            $rows[] = ['Product', 'Variant', 'Quantity sold', 'Profit'];
            foreach ($d['byProduct'] as $p) {
                $rows[] = [$p->product_name, $p->variant_name, (int) $p->qty, (float) $p->profit];
            }

            return $rows;
        }

        if ($report === 'expenses') {
            $d = self::expenses($f, $t);
            $rows = [['Expenses report'], $period, ['Total', round($d['expenseTotal'], 2)], [], ['By category'], ['Category', 'Entries', 'Amount']];
            foreach ($d['byCategory'] as $c) {
                $rows[] = [$c->category_name, (int) $c->n, (float) $c->total];
            }
            $rows[] = [];
            $rows[] = ['Date', 'Category', 'Description', 'Paid with', 'Reference', 'Recorded by', 'Amount'];
            foreach ($d['list'] as $e) {
                $rows[] = [$e->expense_date->toDateString(), $e->category_name, $e->description, $e->method_name, $e->reference, $e->user?->name, (float) $e->amount];
            }

            return $rows;
        }

        if ($report === 'expensereport') {
            $cat = $filters['expcat'] ?? null;
            $d = self::expenseReport($f, $t, $cat);
            $rows = [['Expense report'], $period, ['Total', round($d['total'], 2), 'Entries', $d['entries']], [], ['By category'], ['Category', 'Entries', 'Amount']];
            foreach ($d['byCategory'] as $c) {
                $rows[] = [$c->category_name, (int) $c->n, (float) $c->total];
            }
            $rows[] = [];
            $rows[] = ['By person'];
            $rows[] = ['Recorded by', 'Entries', 'Amount'];
            foreach ($d['byUser'] as $u) {
                $rows[] = [$u->who, (int) $u->n, (float) $u->total];
            }
            $rows[] = [];
            $rows[] = ['Date', 'Category', 'Description', 'Paid with', 'Reference', 'Recorded by', 'Amount'];
            foreach (self::expenseQuery($f, $t, $cat)->with('user')->latest('expense_date')->latest('id')->limit(20000)->get() as $e) {
                $rows[] = [$e->expense_date->toDateString(), $e->category_name, $e->description, $e->method_name, $e->reference, $e->user?->name, (float) $e->amount];
            }

            return $rows;
        }

        if ($report === 'income') {
            $d = self::income($f, $t);
            $rows = [
                ['Income statement'], $period, [],
                ['Revenue (after discounts, excluding tax)'],
                ['Till sales', round($d['tillRevenue'], 2)],
                ['Invoiced sales', round($d['invoiceRevenue'], 2)],
                ['Total revenue', round($d['revenue'], 2)],
                ['Cost of goods sold', round($d['cost'], 2)],
                ['Gross profit', round($d['gross'], 2)],
                ['Gross margin %', (float) $d['grossMargin']],
                [], ['Expenses'],
            ];
            foreach ($d['byCategory'] as $c) {
                $rows[] = [$c->category_name, (float) $c->total];
            }
            $rows[] = ['Total expenses', round($d['expenses'], 2)];
            $rows[] = ['Net profit', round($d['net'], 2)];
            $rows[] = ['Net margin %', (float) $d['netMargin']];

            return $rows;
        }
        if ($report === 'tax') {
    $d = self::tax($f, $t);
    $rows = [['Tax report'], $period, [], ['Sales by tax class (after line discounts)'], ['Class', 'Value']];
    foreach ($d['classes'] as $c) {
        $rows[] = [$c['label'], round($c['value'], 2)];
    }
    $rows[] = [];
    $rows[] = ['Output tax on till sales', round($d['outSales'], 2)];
    $rows[] = ['Output tax on invoices', round($d['outInvoices'], 2)];
    $rows[] = ['Less: tax on returns', round($d['returnsTax'], 2)];
    $rows[] = ['Total output tax', round($d['output'], 2)];
    $rows[] = ['Input tax (from expenses)', round($d['input'], 2)];
    $rows[] = ['Net tax payable', round($d['net'], 2)];
    $rows[] = [];
    $rows[] = ['Input tax entries'];
    $rows[] = ['Date', 'Category', 'Description', 'Amount', 'Input tax'];
    foreach ($d['inputList'] as $e) {
        $rows[] = [$e->expense_date->toDateString(), $e->category_name, $e->description, (float) $e->amount, (float) $e->input_tax];
    }

    return $rows;
}

        if ($report === 'stocklist') {
            $cost = (bool) ($filters['cost'] ?? false);
            $head = ['Item', 'Variant', 'Category', 'SKU', 'Unit', 'In stock', 'Low stock alert'];
            if ($cost) {
                $head[] = 'Cost price';
            }
            $head[] = 'Selling price';
            if ($cost) {
                $head[] = 'Stock value at cost';
            }

            $rows = [['Stock list'], ['As at', now()->toDateTimeString()], [], $head];
            foreach (self::stockList($filters)->limit(20000)->get() as $v) {
                $row = [
                    $v->product->name, $v->name === 'Default' ? '' : $v->name, $v->product->category?->name,
                    $v->sku, $v->product->unit, (int) $v->view_stock, (int) $v->low_stock_threshold,
                ];
                if ($cost) {
                    $row[] = (float) $v->cost_price;
                }
                $row[] = (float) $v->selling_price;
                if ($cost) {
                    $row[] = round(max($v->view_stock, 0) * (float) $v->cost_price, 2);
                }
                $rows[] = $row;
            }

            return $rows;
        }

        if ($report === 'additions') {
            $rows = [['Stock additions'], $period, [],
                ['Date', 'Item', 'Variant', 'Batch', 'Expiry', 'Quantity added', 'Reason', 'Note', 'By']];

            foreach (self::additions($f->toDateString(), $t->toDateString(), (string) ($filters['search'] ?? ''))
                ->latest('id')->limit(20000)->get() as $m) {
                $rows[] = [
                    $m->created_at->format('Y-m-d H:i'), $m->variant->product->name,
                    $m->variant->name === 'Default' ? '' : $m->variant->name,
                    $m->batch?->batch_number, $m->batch?->expiry_date?->toDateString(),
                    (int) $m->quantity_change, $m->reason, $m->note, $m->user?->name,
                ];
            }

            return $rows;
        }

        if ($report === 'pricelist') {
            $rows = [['Price list'], ['As at', now()->toDateString()], [], ['Item', 'Variant', 'Category', 'SKU', 'Unit', 'Price']];
            foreach (self::priceList($filters)->limit(20000)->get() as $v) {
                $rows[] = [
                    $v->product->name, $v->name === 'Default' ? '' : $v->name, $v->product->category?->name,
                    $v->sku, $v->product->unit, (float) $v->selling_price,
                ];
            }

            return $rows;
        }

        if ($report === 'stock') {
            $rows = [['Low stock'], ['As at', now()->toDateTimeString()], [], ['Item', 'Variant', 'In stock', 'Alert at', 'Unit']];
            foreach (self::stock()['low'] as $v) {
                $rows[] = [$v->product->name, $v->name === 'Default' ? '' : $v->name, (int) $v->view_stock, (int) $v->low_stock_threshold, $v->product->unit];
            }

            return $rows;
        }

        if ($report === 'expiry') {
            $rows = [['Expiring and expired stock'], ['As at', now()->toDateTimeString()], [],
                ['Item', 'Variant', 'Batch', 'Expiry date', 'Days left', 'Quantity']];
            foreach (self::expiry()['batches'] as $b) {
                $rows[] = [
                    $b->variant->product->name, $b->variant->name === 'Default' ? '' : $b->variant->name,
                    $b->batch_number, $b->expiry_date->toDateString(),
                    (int) today()->diffInDays($b->expiry_date, false), (int) $b->quantity,
                ];
            }

            return $rows;
        }

        $d = self::owed();
        $rows = [['Owed to you'], ['As at', now()->toDateTimeString()], ['Total owed', round($d['owedTotal'], 2)], [],
            ['Invoice', 'Customer', 'Phone', 'Due date', 'Status', 'Total', 'Paid', 'Balance']];
        foreach ($d['owed'] as $i) {
            $rows[] = [
                $i->number_label, $i->customer?->name, $i->customer?->phone, $i->due_date?->toDateString(),
                $i->payment_status, (float) $i->total, (float) $i->amount_paid, (float) $i->balance,
            ];
        }

        return $rows;
    }

    public static function tax(Carbon $f, Carbon $t): array
{
    $classes = ['standard' => 'Standard rated', 'zero' => 'Zero rated', 'exempt' => 'Exempt'];

    $values = self::items_q($f, $t)
        ->selectRaw('sale_items.tax_class as c, SUM(sale_items.line_total) as v')->groupBy('sale_items.tax_class')->pluck('v', 'c')
        ->map(fn ($v) => (float) $v)->all();

    foreach (self::invoice_items_q($f, $t)
        ->selectRaw('invoice_items.tax_class as c, SUM(invoice_items.line_total) as v')
        ->groupBy('invoice_items.tax_class')->pluck('v', 'c') as $c => $v) {
        $values[$c] = ($values[$c] ?? 0) + (float) $v;
    }

    $outSales = (float) self::sales_q($f, $t)->sum('sales.tax_amount');
    $outInvoices = (float) self::invoices_q($f, $t)->sum('invoices.tax_amount');

    $returnsTax = (float) self::br(SaleReturnItem::query()
        ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')
        ->whereBetween('sale_returns.created_at', [$f, $t]), 'sale_returns.branch_id')
        ->sum('sale_return_items.tax_amount');

    $input = (float) self::expenses_q($f, $t)->sum('expenses.input_tax');
    $output = $outSales + $outInvoices - $returnsTax;

    return [
        'classes' => collect($classes)->map(fn ($label, $key) => ['label' => $label, 'value' => $values[$key] ?? 0.0])->values()->all(),
        'outSales' => $outSales, 'outInvoices' => $outInvoices, 'returnsTax' => $returnsTax,
        'output' => $output, 'input' => $input, 'net' => $output - $input,
        'inputList' => self::expenses_q($f, $t)->where('expenses.input_tax', '>', 0)->with('user')
            ->latest('expense_date')->limit(100)->get(),
    ];
}

}
