<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\ProductVariant;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleService
{
    public static function complete(array $lines, ?array $discount, array $payments, ?int $customerId = null, float $credit = 0): Sale
    {
        $user = auth()->user();
        abort_unless($user && $user->business_id && $user->can('make-sales'), 403);

        $hasDiscount = (float) ($discount['value'] ?? 0) > 0
            || collect($lines)->contains(fn ($l) => (float) ($l['discount_value'] ?? 0) > 0);

        if ($hasDiscount && ! $user->can('give-discounts')) {
            self::fail('cart', 'You are not allowed to give discounts.');
        }

        if (count($lines) === 0) {
            self::fail('cart', 'The cart is empty.');
        }

        if ($customerId && ! Customer::active()->find($customerId)) {
            self::fail('customer', 'That customer is not available.');
        }

        return DB::transaction(function () use ($user, $lines, $discount, $payments, $customerId, $credit) {
            $business = Business::lockForUpdate()->findOrFail($user->business_id);

            $priced = PricingService::price($lines, $discount, $business);
            $total = $priced['total'];
            $credit = max(0, min(round($credit, 2), $total));

            $paid = 0.0;
            $cash = 0.0;
            $paymentRows = [];

            foreach ($payments as $p) {
                $amount = round((float) ($p['amount'] ?? 0), 2);
                $method = PaymentMethod::active()->find($p['method_id'] ?? 0);

                if (! $method || $amount <= 0) {
                    self::fail('payments', 'Each payment needs a valid method and an amount above zero.');
                }

                $ref = trim((string) ($p['reference'] ?? ''));
                if ($method->type === 'mobile') {
                    $ref = PaymentReference::required($ref, 'payments');
                }

                $paid += $amount;
                if ($method->type === 'cash') {
                    $cash += $amount;
                }

                $paymentRows[] = [
                    'payment_method_id' => $method->id,
                    'method_name' => $method->name,
                    'type' => $method->type,
                    'amount' => $amount,
                    'reference' => $ref !== '' ? $ref : null,
                ];
            }

            $paid = round($paid, 2);
            $covered = round($paid + $credit, 2);

            if ($covered < $total) {
                self::fail('payments', 'The payments do not cover the total of ' . number_format($total, 2) . '.');
            }

            $change = round($covered - $total, 2);
            if ($change > round($cash, 2)) {
                self::fail('payments', 'Change can only be given from cash.');
            }

            $receipt = $business->last_receipt_number + 1;
            $business->last_receipt_number = $receipt;
            $business->save();

            $sale = Sale::create([
                'user_id' => $user->id,
                'branch_id' => BranchService::currentId($user),
                'customer_id' => $customerId ?: null,
                'receipt_number' => $receipt,
                'subtotal' => $priced['subtotal'],
                'discount_type' => $priced['discount_type'],
                'discount_value' => $priced['discount_value'],
                'discount_amount' => $priced['discount_amount'],
                'tax_rate' => $priced['tax_rate'],
                'tax_inclusive' => $priced['tax_inclusive'],
                'tax_amount' => $priced['tax_amount'],
                'total' => $total,
                'amount_paid' => $covered,
                'credit_applied' => $credit,
                'change_given' => $change,
            ]);

            foreach ($priced['items'] as $item) {
                $saleItem = $sale->items()->create($item['attrs']);

                if (! $item['variant']->product->is_service) {
                    if ($item['variant']->product->tracks_batches) {
                        BatchService::attach($saleItem, BatchService::take($item['variant'], $item['attrs']['quantity']));
                    } else {
                        StockService::assertSellable($item['variant'], $item['attrs']['quantity']);
                    }

                    StockService::record($item['variant'], -$item['attrs']['quantity'], 'sale', 'Sale ' . $sale->receipt_label);
                }
            }

            foreach ($paymentRows as $row) {
                $sale->payments()->create($row);
            }

            return $sale->load('items', 'payments');
        });
    }

    public static function void(Sale $sale, string $reason): Sale
    {
        $user = auth()->user();
        abort_unless($user && $user->can('void-sales'), 403);

        $reason = trim($reason);
        if ($reason === '') {
            self::fail('reason', 'Please give a reason for voiding this sale.');
        }
        if (mb_strlen($reason) > 255) {
            self::fail('reason', 'The reason is too long (255 characters at most).');
        }

        return DB::transaction(function () use ($sale, $reason, $user) {
            $locked = Sale::with('items')->lockForUpdate()->findOrFail($sale->id);

            if ($locked->status !== 'completed') {
                self::fail('reason', 'This sale has already been voided.');
            }
            if ($locked->returns()->exists()) {
                self::fail('reason', 'This sale has returns or exchanges recorded, so it cannot be voided.');
            }

            foreach ($locked->items as $item) {
                $variant = ProductVariant::with('product')->findOrFail($item->product_variant_id);

                if ($variant->product->is_service) {
                    continue;
                }

                StockService::record($variant, $item->quantity, 'return', 'Voided sale ' . $locked->receipt_label, null, null, $locked->branch_id);
                BatchService::restore($item, $variant);
            }

            $locked->status = 'voided';
            $locked->voided_at = now();
            $locked->voided_by = $user->id;
            $locked->void_reason = $reason;
            $locked->save();

            return $locked;
        });
    }

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
