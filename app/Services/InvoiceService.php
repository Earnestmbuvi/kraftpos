<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\PaymentMethod;
use App\Models\ProductVariant;
use App\Models\Quotation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public static function issue(
        int $customerId, ?string $dueDate, ?string $notes,
        array $lines, ?array $discount, ?int $quotationId = null
    ): Invoice {
        $user = auth()->user();
        abort_unless($user && $user->business_id && $user->can('issue-invoices'), 403);

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

        $due = null;
        if ($dueDate) {
            try {
                $due = Carbon::createFromFormat('Y-m-d', $dueDate)->startOfDay();
            } catch (\Throwable $e) {
                self::fail('due_date', 'Enter a valid date.');
            }
            if ($due->lt(today())) {
                self::fail('due_date', 'The due date cannot be in the past.');
            }
        }

        $notes = trim((string) $notes);
        if (mb_strlen($notes) > 255) {
            self::fail('notes', 'Notes are too long (255 characters at most).');
        }

        return DB::transaction(function () use ($user, $customer, $due, $notes, $lines, $discount, $quotationId) {
            $business = Business::lockForUpdate()->findOrFail($user->business_id);

            if ($quotationId) {
                $quote = Quotation::lockForUpdate()->find($quotationId);

                if (! $quote || ! in_array($quote->status, ['open', 'accepted']) || $quote->valid_until->lt(today())) {
                    self::fail('quotation', 'That quotation cannot be converted. It is expired, declined or already converted.');
                }
                abort_unless($user->can('view-reports') || $quote->user_id === $user->id, 403);

                $quote->status = 'converted';
                $quote->save();
            }

            $priced = PricingService::price($lines, $discount, $business);

            $number = $business->last_invoice_number + 1;
            $business->last_invoice_number = $number;
            $business->save();

            $invoice = Invoice::create([
                'user_id' => $user->id,
                'customer_id' => $customer->id,
                'invoice_number' => $number,
                'due_date' => $due,
                'notes' => $notes ?: null,
                'subtotal' => $priced['subtotal'],
                'discount_type' => $priced['discount_type'],
                'discount_value' => $priced['discount_value'],
                'discount_amount' => $priced['discount_amount'],
                'tax_rate' => $priced['tax_rate'],
                'tax_inclusive' => $priced['tax_inclusive'],
                'tax_amount' => $priced['tax_amount'],
                'total' => $priced['total'],
                'branch_id' => BranchService::currentId($user),
            ]);

            foreach ($priced['items'] as $item) {
    $invoiceItem = $invoice->items()->create($item['attrs']);

    // Stock leaves when the invoice is issued. Services have none.

    if (! $item['variant']->product->is_service) {
        if (! $item['variant']->product->tracks_batches) { StockService::assertSellable($item['variant'], $item['attrs']['quantity']); }
        if ($item['variant']->product->tracks_batches) {
            BatchService::attach($invoiceItem, BatchService::take($item['variant'], $item['attrs']['quantity']));
        }

        StockService::record(
            $item['variant'],
            -$item['attrs']['quantity'],
            'sale',
            'Invoice ' . $invoice->number_label,
        );
    }
}

            return $invoice->refresh()->load('items');
        });
    }

    public static function recordPayment(Invoice $invoice, int $methodId, float $amount, ?string $reference): InvoicePayment
{
    $user = auth()->user();
    abort_unless($user && $user->can('issue-invoices'), 403);

    $amount = round($amount, 2);
    if ($amount <= 0) {
        self::fail('amount', 'Enter an amount above zero.');
    }

    $method = PaymentMethod::active()->find($methodId);
    if (! $method) {
        self::fail('method', 'Choose a payment method.');
    }

    $reference = $method->type === 'mobile'
        ? PaymentReference::required($reference)
        : trim((string) $reference);

    if (mb_strlen($reference) > 50) {
        self::fail('reference', 'The reference is too long (50 characters at most).');
    }

    return DB::transaction(function () use ($invoice, $method, $amount, $reference, $user) {
        $business = Business::lockForUpdate()->findOrFail($user->business_id);
        $locked = Invoice::lockForUpdate()->findOrFail($invoice->id); // tenant scope applies

        if ($locked->status !== 'issued') {
            self::fail('amount', 'This invoice has been voided.');
        }

        $balance = round((float) $locked->total - (float) $locked->amount_paid, 2);
        if ($amount > $balance) {
            self::fail('amount', 'That is more than the balance of ' . number_format($balance, 2) . '.');
        }

        $number = $business->last_payment_number + 1;
        $business->last_payment_number = $number;
        $business->save();

        $payment = $locked->payments()->create([
            'user_id' => $user->id,
            'payment_method_id' => $method->id,
            'method_name' => $method->name,
            'type' => $method->type,
            'amount' => $amount,
            'reference' => $reference !== '' ? $reference : null,
            'receipt_number' => $number,
        ]);

        $locked->amount_paid = round((float) $locked->amount_paid + $amount, 2);
        $locked->save();

        return $payment->refresh();
    });
}

    public static function void(Invoice $invoice, string $reason): Invoice
    {
        $user = auth()->user();
        abort_unless($user && $user->can('void-sales'), 403);

        $reason = trim($reason);
        if ($reason === '') {
            self::fail('reason', 'Please give a reason for voiding this invoice.');
        }
        if (mb_strlen($reason) > 255) {
            self::fail('reason', 'The reason is too long (255 characters at most).');
        }

        return DB::transaction(function () use ($invoice, $reason, $user) {
            $locked = Invoice::with('items')->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->status !== 'issued') {
                self::fail('reason', 'This invoice has already been voided.');
            }
            if ((float) $locked->amount_paid > 0) {
                self::fail('reason', 'This invoice has payments recorded, so it cannot be voided.');
            }

            foreach ($locked->items as $item) {
                $variant = ProductVariant::with('product')->findOrFail($item->product_variant_id);

                if ($variant->product->is_service) {
                    continue;
                }

                StockService::record($variant, $item->quantity, 'return', 'Voided invoice ' . $locked->number_label, null, null, $locked->branch_id);
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
