<?php

namespace App\Services;

use App\Models\Business;
use App\Models\PaymentMethod;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReturnService
{
    /** Take goods back and pay the money out. */
    public static function refund(int $saleId, array $qtys, string $reason, ?int $methodId, ?string $reference): SaleReturn
    {
        $reason = self::reason($reason);

        return DB::transaction(function () use ($saleId, $qtys, $reason, $methodId, $reference) {
            $plan = self::plan($saleId, $qtys);
            [$method, $ref] = self::refundMethod($methodId, $reference);

            return self::commit($plan, 'return', $reason, $plan['value'], $method, $ref, null);
        });
    }

    /** Take goods back and put their value towards new goods. Returns [return, new sale]. */
    public static function exchange(
        int $saleId, array $qtys, array $newLines, array $payments, string $reason, ?int $refundMethodId, ?string $refundRef
    ): array {
        $reason = self::reason($reason);

        return DB::transaction(function () use ($saleId, $qtys, $newLines, $payments, $reason, $refundMethodId, $refundRef) {
            $plan = self::plan($saleId, $qtys);

            if (count($newLines) === 0) {
                self::fail('cart', 'Add the items the customer is taking instead.');
            }

            $new = PricingService::price($newLines, null, auth()->user()->business)['total'];
            $credit = min($plan['value'], $new);
            $cashBack = round($plan['value'] - $credit, 2);

            [$method, $ref] = $cashBack > 0 ? self::refundMethod($refundMethodId, $refundRef) : [null, null];

            $sale = SaleService::complete($newLines, null, $payments, $plan['sale']->customer_id, $credit);
            $return = self::commit($plan, 'exchange', $reason, $cashBack, $method, $ref, $sale->id);

            return [$return, $sale];
        });
    }

    private static function plan(int $saleId, array $qtys): array
    {
        $user = auth()->user();
        abort_unless($user && $user->business_id && $user->can('process-returns'), 403);

        $sale = Sale::with('items')->lockForUpdate()->find($saleId);
        if (! $sale) {
            self::fail('sale', 'That sale was not found.');
        }
        if ($sale->status !== 'completed') {
            self::fail('sale', 'A voided sale cannot be returned.');
        }

        $returned = SaleReturnItem::whereIn('sale_item_id', $sale->items->pluck('id'))
            ->selectRaw('sale_item_id, SUM(quantity) as q')->groupBy('sale_item_id')->pluck('q', 'sale_item_id');

        $values = PricingService::lineValues(
            $sale->items->map(fn ($i) => ['line_total' => (float) $i->line_total, 'tax_class' => $i->tax_class ?? 'standard'])->all(),
            (float) $sale->discount_amount, (float) $sale->tax_rate, (bool) $sale->tax_inclusive
        );

        $lines = [];
        $value = 0.0;

        foreach ($qtys as $itemId => $q) {
            $q = (int) $q;
            if ($q <= 0) {
                continue;
            }

            $idx = $sale->items->search(fn ($i) => $i->id === (int) $itemId);
            if ($idx === false) {
                self::fail('qty', 'That item is not on this sale.');
            }

            $item = $sale->items[$idx];
            $left = (int) $item->quantity - (int) ($returned[$item->id] ?? 0);
            if ($q > $left) {
                self::fail('qty', "Only {$left} of {$item->product_name} can still be returned.");
            }

            $amount = round($values[$idx]['paid'] * $q / $item->quantity, 2);
            $lines[] = [
                'item' => $item, 'qty' => $q, 'amount' => $amount,
                'tax' => round($values[$idx]['tax'] * $q / $item->quantity, 2),
                'cost' => round((float) $item->unit_cost * $q, 2),
            ];
            $value += $amount;
        }

        if (! $lines) {
            self::fail('qty', 'Choose at least one item to return.');
        }

        return ['sale' => $sale, 'lines' => $lines, 'value' => round($value, 2)];
    }

    private static function commit(array $plan, string $type, string $reason, float $refund, ?PaymentMethod $method, ?string $ref, ?int $exchangeSaleId): SaleReturn
    {
        $user = auth()->user();
        $sale = $plan['sale'];

        $business = Business::lockForUpdate()->findOrFail($user->business_id);
        $number = $business->last_return_number + 1;
        $business->last_return_number = $number;
        $business->save();

        $ret = SaleReturn::create([
            'branch_id' => $sale->branch_id, 'sale_id' => $sale->id, 'user_id' => $user->id,
            'return_number' => $number, 'type' => $type, 'value' => $plan['value'], 'refund_total' => $refund,
            'refund_method_id' => $method?->id, 'refund_method_name' => $method?->name, 'refund_type' => $method?->type,
            'reference' => $ref, 'reason' => $reason, 'exchange_sale_id' => $exchangeSaleId,
        ]);

        foreach ($plan['lines'] as $l) {
            $item = $l['item'];

            $ret->items()->create([
                'sale_item_id' => $item->id, 'product_variant_id' => $item->product_variant_id,
                'product_name' => $item->product_name, 'variant_name' => $item->variant_name,
                'quantity' => $l['qty'], 'amount' => $l['amount'], 'tax_amount' => $l['tax'], 'cost' => $l['cost'],
            ]);

            $variant = ProductVariant::with('product')->findOrFail($item->product_variant_id);
            if ($variant->product->is_service) {
                continue;
            }

            $label = "Return {$ret->number_label}";
            if ($variant->product->tracks_batches) {
                $batch = BatchService::returnStock($variant, $l['qty']);
                StockService::record($variant, $l['qty'], 'return', $label, null, $batch->id);
            } else {
                StockService::record($variant, $l['qty'], 'return', $label, null, null, $sale->branch_id);
            }
        }

        return $ret->load('items');
    }

    private static function refundMethod(?int $methodId, ?string $reference): array
    {
        $method = PaymentMethod::active()->find((int) $methodId);
        if (! $method) {
            self::fail('method', 'Choose how the money is paid back.');
        }

        $ref = $method->type === 'mobile' ? PaymentReference::required($reference) : trim((string) $reference);

        return [$method, $ref !== '' ? $ref : null];
    }

    private static function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            self::fail('reason', 'Give a reason.');
        }
        if (mb_strlen($reason) > 255) {
            self::fail('reason', 'The reason is too long (255 characters at most).');
        }

        return $reason;
    }

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
