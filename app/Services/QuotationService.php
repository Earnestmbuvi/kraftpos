<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Quotation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuotationService
{
    public static function create(int $customerId, ?string $validUntil, ?string $notes, array $lines, ?array $discount): Quotation
    {
        $user = auth()->user();
        [$customer, $until, $notes] = self::prepare($user, $customerId, $validUntil, $notes, $lines, $discount);

        return DB::transaction(function () use ($user, $customer, $until, $notes, $lines, $discount) {
            $business = Business::lockForUpdate()->findOrFail($user->business_id);
            $priced = PricingService::price($lines, $discount, $business);

            $number = $business->last_quote_number + 1;
            $business->last_quote_number = $number;
            $business->save();

            $quotation = Quotation::create([
                'user_id' => $user->id,
                'customer_id' => $customer->id,
                'quote_number' => $number,
                'valid_until' => $until,
                'notes' => $notes ?: null,
                'subtotal' => $priced['subtotal'],
                'discount_type' => $priced['discount_type'],
                'discount_value' => $priced['discount_value'],
                'discount_amount' => $priced['discount_amount'],
                'tax_rate' => $priced['tax_rate'],
                'tax_inclusive' => $priced['tax_inclusive'],
                'tax_amount' => $priced['tax_amount'],
                'total' => $priced['total'],
            ]);

            foreach ($priced['items'] as $item) {
                $quotation->items()->create($item['attrs']);
            }

            return $quotation->refresh()->load('items');
        });
    }

    /** Open and accepted quotations can be edited. An accepted one goes back to open. */
    public static function update(
        Quotation $quotation, int $customerId, ?string $validUntil, ?string $notes, array $lines, ?array $discount
    ): Quotation {
        $user = auth()->user();
        [$customer, $until, $notes] = self::prepare($user, $customerId, $validUntil, $notes, $lines, $discount);

        return DB::transaction(function () use ($user, $quotation, $customer, $until, $notes, $lines, $discount) {
            $business = Business::lockForUpdate()->findOrFail($user->business_id);
            $q = Quotation::lockForUpdate()->findOrFail($quotation->id); // tenant scope applies

            abort_unless($user->can('view-reports') || $q->user_id === $user->id, 403);

            if (! in_array($q->status, ['open', 'accepted'], true)) {
                self::fail('quotation', 'Only an open or accepted quotation can be edited.');
            }

            $priced = PricingService::price($lines, $discount, $business);

            // A quotation has no stock or money effect, so its items are replaced as a whole
            $q->items()->delete();

            $q->update([
                'customer_id' => $customer->id,
                'valid_until' => $until,
                'notes' => $notes ?: null,
                'subtotal' => $priced['subtotal'],
                'discount_type' => $priced['discount_type'],
                'discount_value' => $priced['discount_value'],
                'discount_amount' => $priced['discount_amount'],
                'tax_rate' => $priced['tax_rate'],
                'tax_inclusive' => $priced['tax_inclusive'],
                'tax_amount' => $priced['tax_amount'],
                'total' => $priced['total'],
            ]);

            $q->forceFill(['status' => 'open'])->save();

            foreach ($priced['items'] as $item) {
                $q->items()->create($item['attrs']);
            }

            return $q->refresh()->load('items');
        });
    }

    private static function prepare($user, int $customerId, ?string $validUntil, ?string $notes, array $lines, ?array $discount): array
    {
        abort_unless($user && $user->business_id && $user->can('make-sales'), 403);

        $hasDiscount = (float) ($discount['value'] ?? 0) > 0
            || collect($lines)->contains(fn ($l) => (float) ($l['discount_value'] ?? 0) > 0);

        if ($hasDiscount && ! $user->can('give-discounts')) {
            self::fail('cart', 'You are not allowed to give discounts.');
        }

        if (count($lines) === 0) {
            self::fail('cart', 'Add at least one item.');
        }

        $customer = Customer::active()->find($customerId); // tenant scope applies
        if (! $customer) {
            self::fail('customer', 'Choose a customer.');
        }

        try {
            $until = $validUntil
                ? Carbon::createFromFormat('Y-m-d', $validUntil)->startOfDay()
                : now()->addDays(14)->startOfDay();
        } catch (\Throwable $e) {
            self::fail('valid_until', 'Enter a valid date.');
        }

        if ($until->lt(today())) {
            self::fail('valid_until', 'The valid-until date cannot be in the past.');
        }

        $notes = trim((string) $notes);
        if (mb_strlen($notes) > 255) {
            self::fail('notes', 'Notes are too long (255 characters at most).');
        }

        return [$customer, $until, $notes];
    }

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
