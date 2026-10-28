<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SalesDetail
{
    public static function label(string $kind, $number): string
    {
        return $kind === 'Invoice'
            ? 'INV-' . str_pad((string) $number, 4, '0', STR_PAD_LEFT)
            : (new \App\Models\Sale)->forceFill(['receipt_number' => $number])->receipt_label;
    }

    /** One row per product line, across till sales and issued invoices. */
    public static function lines(Carbon $f, Carbon $t, array $fl = [])
    {
        $user = auth()->user();
        $bid = $user->business_id;
        $own = ! $user->can('view-reports'); // cashiers only ever see their own
        $b = BranchService::viewing();

        $apply = fn ($q) => $q
            ->when($b !== null, fn ($q) => BranchService::limit($q, $b, 'd.branch_id'))
            ->when($own, fn ($q) => $q->where('d.user_id', $user->id))
            ->when(! $own && ! empty($fl['user']), fn ($q) => $q->where('d.user_id', (int) $fl['user']))
            ->when(! empty($fl['customer']), fn ($q) => $q->where('d.customer_id', (int) $fl['customer']))
            ->when(trim($fl['search'] ?? '') !== '', fn ($q) => $q->where('i.product_name', 'like', '%' . trim($fl['search']) . '%'));

        $cols = 'd.id as doc_id, d.created_at, d.user_id, d.customer_id, i.product_name, i.variant_name, '
              . 'i.quantity, i.unit_price, i.discount_amount, i.line_total, i.unit_cost';

        $sales = $apply(DB::table('sale_items as i')->join('sales as d', 'd.id', '=', 'i.sale_id')
            ->where('d.business_id', $bid)->where('d.status', 'completed')->whereBetween('d.created_at', [$f, $t]))
            ->selectRaw("'Till' as kind, d.receipt_number as number, {$cols}");

        $invoices = $apply(DB::table('invoice_items as i')->join('invoices as d', 'd.id', '=', 'i.invoice_id')
            ->where('d.business_id', $bid)->where('d.status', 'issued')->whereBetween('d.created_at', [$f, $t]))
            ->selectRaw("'Invoice' as kind, d.invoice_number as number, {$cols}");

        $kind = $fl['kind'] ?? 'all';
        $union = $kind === 'sale' ? $sales : ($kind === 'invoice' ? $invoices : $sales->unionAll($invoices));

        return DB::query()->fromSub($union, 'l');
    }
}
