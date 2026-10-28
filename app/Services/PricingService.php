<?php

namespace App\Services;

use App\Models\Business;
use App\Models\ProductVariant;
use Illuminate\Validation\ValidationException;

class PricingService
{
    /**
     * Prices lines from the database: line discounts, then cart discount, then tax.
     * @param array $lines    [['variant_id'=>1,'quantity'=>2,'discount_type'=>'percent|fixed|null','discount_value'=>10], ...]
     * @param array|null $discount ['type'=>'percent|fixed','value'=>5]
     */
    private static function basePrice(array $lines, ?array $discount, Business $business): array
    {
        $hasDiscount = (float) ($discount['value'] ?? 0) > 0
    || collect($lines)->contains(fn ($l) => (float) ($l['discount_value'] ?? 0) > 0);

if ($hasDiscount && ! $business->discounts_enabled) {
    self::fail('cart', 'Discounts are turned off for this shop.');
}
        $items = [];
        $subtotal = 0.0;

        foreach ($lines as $line) {
            $qty = (int) ($line['quantity'] ?? 0);
            if ($qty < 1) {
                self::fail('cart', 'Quantities must be at least 1.');
            }

            $variant = ProductVariant::with('product')->find($line['variant_id'] ?? 0);
            if (! $variant || ! $variant->is_active || ! $variant->product->is_active) {
                self::fail('cart', 'One of the items is no longer available.');
            }

            $price = (float) $variant->selling_price;
            $gross = round($price * $qty, 2);
            $lineDiscount = self::discountAmount($line['discount_type'] ?? null, $line['discount_value'] ?? 0, $gross);
            $net = round($gross - $lineDiscount, 2);

            $items[] = [
                'variant' => $variant,
                'attrs' => [
                    'product_variant_id' => $variant->id,
                    'product_name' => $variant->product->name,
                    'variant_name' => $variant->name === 'Default' ? null : $variant->name,
                    'sku' => $variant->sku,
                    'unit' => $variant->product->unit,
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'unit_cost' => (float) $variant->cost_price,
                    'discount_type' => $lineDiscount > 0 ? $line['discount_type'] : null,
                    'discount_value' => $lineDiscount > 0 ? (float) $line['discount_value'] : 0,
                    'discount_amount' => $lineDiscount,
                    'line_total' => $net,
                ],
            ];

            $subtotal += $net;
        }

        $subtotal = round($subtotal, 2);
        $cartDiscount = self::discountAmount($discount['type'] ?? null, $discount['value'] ?? 0, $subtotal);
        $taxable = round($subtotal - $cartDiscount, 2);

        $rate = (float) $business->tax_rate;
        $inclusive = (bool) $business->tax_inclusive;

        if ($rate <= 0) {
            $tax = 0.0;
            $total = $taxable;
        } elseif ($inclusive) {
            $tax = round($taxable - ($taxable / (1 + $rate / 100)), 2);
            $total = $taxable;
        } else {
            $tax = round($taxable * $rate / 100, 2);
            $total = round($taxable + $tax, 2);
        }

        return [
            'items' => $items,
            'subtotal' => $subtotal,
            'discount_type' => $cartDiscount > 0 ? $discount['type'] : null,
            'discount_value' => $cartDiscount > 0 ? (float) $discount['value'] : 0,
            'discount_amount' => $cartDiscount,
            'tax_rate' => $rate,
            'tax_inclusive' => $inclusive,
            'tax_amount' => $tax,
            'total' => $total,
        ];
    }

    public static function discountAmount(?string $type, $value, float $base): float
    {
        $value = (float) $value;

        if (! $type || $value <= 0) {
            return 0.0;
        }

        if (! in_array($type, ['percent', 'fixed'])) {
            self::fail('cart', 'Unknown discount type.');
        }

        if ($type === 'percent') {
            if ($value > 100) {
                self::fail('cart', 'A discount cannot be more than 100%.');
            }
            return round($base * $value / 100, 2);
        }

        return round(min($value, $base), 2);
    }

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public static function price(array $lines, ?array $discount, \App\Models\Business $business): array
{
    $r = self::basePrice($lines, $discount, $business);

    foreach ($r['items'] as $k => $item) {
        $r['items'][$k]['attrs']['tax_class'] = $item['variant']->product->tax_class ?? 'standard';
    }

    $rate = (float) $business->tax_rate;
    $inclusive = (bool) $business->tax_inclusive;
    $cartDiscount = (float) $r['discount_amount'];
    $after = round((float) $r['subtotal'] - $cartDiscount, 2);

    // Value of the zero-rated and exempt lines, after their share of the cart discount
    $items = array_values($r['items']);
    $nets = self::lineValues(array_map(fn ($i) => [
        'line_total' => (float) $i['attrs']['line_total'], 'tax_class' => $i['attrs']['tax_class'],
    ], $items), $cartDiscount, 0, false);

    $untaxed = 0.0;
    foreach ($items as $idx => $i) {
        if ($i['attrs']['tax_class'] !== 'standard') {
            $untaxed += $nets[$idx]['net'];
        }
    }

    $base = max(round($after - $untaxed, 2), 0);

    if ($rate <= 0 || $base <= 0) {
        $tax = 0.0;
    } elseif ($inclusive) {
        $tax = round($base - $base / (1 + $rate / 100), 2);
    } else {
        $tax = round($base * $rate / 100, 2);
    }

    $r['tax_rate'] = $base > 0 ? $rate : 0;
    $r['tax_inclusive'] = $inclusive;
    $r['tax_amount'] = $tax;
    $r['total'] = round($after + ($inclusive ? 0 : $tax), 2);

    return $r;
}

/**
 * Per-line net, tax and amount paid, with the cart discount shared out by line value.
 * @param array $items [['line_total' => 100.0, 'tax_class' => 'standard'], ...]
 */
public static function lineValues(array $items, float $cartDiscount, float $rate, bool $inclusive): array
{
    $subtotal = array_sum(array_column($items, 'line_total'));
    $remaining = $cartDiscount;
    $n = count($items);
    $i = 0;
    $out = [];

    foreach ($items as $k => $it) {
        $i++;
        $lt = (float) $it['line_total'];
        $share = $i === $n ? $remaining : ($subtotal > 0 ? round($cartDiscount * $lt / $subtotal, 2) : 0.0);
        $remaining = round($remaining - $share, 2);

        $net = round($lt - $share, 2);
        $taxed = ($it['tax_class'] ?? 'standard') === 'standard' && $rate > 0;
        $tax = ! $taxed ? 0.0 : ($inclusive ? round($net - $net / (1 + $rate / 100), 2) : round($net * $rate / 100, 2));

        $out[$k] = ['net' => $net, 'tax' => $tax, 'paid' => round($net + ($inclusive ? 0 : $tax), 2)];
    }

    return $out;
}
}
