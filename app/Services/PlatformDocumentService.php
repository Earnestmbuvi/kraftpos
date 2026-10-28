<?php

namespace App\Services;

use App\Models\Business;
use App\Models\PlatformDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlatformDocumentService
{
    public const METHODS = ['Cash', 'M-Pesa', 'Bank transfer', 'Cheque'];

    private static function guard()
    {
        $u = auth()->user();
        abort_unless($u && $u->is_super_admin, 403);

        return $u;
    }

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private static function nextNumber(string $type): int
    {
        $row = DB::table('platform_sequences')->where('type', $type)->lockForUpdate()->first();

        if (! $row) {
            DB::table('platform_sequences')->insert(['type' => $type, 'last' => 1]);

            return 1;
        }

        DB::table('platform_sequences')->where('type', $type)->update(['last' => $row->last + 1]);

        return $row->last + 1;
    }

    /** $lines: [['item_id'?, 'name', 'description'?, 'quantity', 'unit_price']] */
    public static function create(string $type, int $businessId, array $lines, ?string $due, ?string $notes, float $taxRate, ?int $fromId = null): PlatformDocument
    {
        $user = self::guard();

        if (! in_array($type, ['quotation', 'invoice'], true)) {
            self::fail('type', 'Unknown document type.');
        }
        if (! Business::whereKey($businessId)->exists()) {
            self::fail('businessId', 'Choose a merchant.');
        }
        if ($taxRate < 0 || $taxRate > 100) {
            self::fail('taxRate', 'Tax must be between 0 and 100.');
        }
        if ($due && ! strtotime($due)) {
            self::fail('due', 'Enter a valid date.');
        }
        if (count($lines) === 0) {
            self::fail('lines', 'Add at least one item.');
        }
        if (mb_strlen((string) $notes) > 255) {
            self::fail('notes', 'The notes are too long (255 characters at most).');
        }

        $clean = [];
        $subtotal = 0.0;

        foreach ($lines as $l) {
            $name = trim((string) ($l['name'] ?? ''));
            $qty = (int) ($l['quantity'] ?? 0);
            $price = round((float) ($l['unit_price'] ?? 0), 2);

            if ($name === '' || mb_strlen($name) > 150) {
                self::fail('lines', 'Every line needs a name (150 characters at most).');
            }
            if ($qty < 1 || $qty > 100000 || $price < 0) {
                self::fail('lines', "Check the quantity and price of {$name}.");
            }

            $total = round($qty * $price, 2);
            $subtotal += $total;
            $clean[] = [
                'platform_item_id' => $l['item_id'] ?? null, 'name' => $name,
                'description' => trim((string) ($l['description'] ?? '')) ?: null,
                'quantity' => $qty, 'unit_price' => $price, 'line_total' => $total,
            ];
        }

        $subtotal = round($subtotal, 2);
        $tax = round($subtotal * $taxRate / 100, 2);

        return DB::transaction(function () use ($type, $businessId, $user, $due, $notes, $taxRate, $subtotal, $tax, $clean, $fromId) {
            $doc = PlatformDocument::create([
                'type' => $type, 'number' => self::nextNumber($type), 'business_id' => $businessId, 'user_id' => $user->id,
                'status' => $type === 'quotation' ? 'open' : 'issued', 'issue_date' => today(), 'due_date' => $due ?: null,
                'tax_rate' => $taxRate, 'subtotal' => $subtotal, 'tax_amount' => $tax, 'total' => round($subtotal + $tax, 2),
                'amount_paid' => 0, 'notes' => trim((string) $notes) ?: null, 'from_document_id' => $fromId,
            ]);

            foreach ($clean as $row) {
                $doc->items()->create($row);
            }

            return $doc->load('items');
        });
    }

    public static function convert(PlatformDocument $quotation): PlatformDocument
    {
        self::guard();

        return DB::transaction(function () use ($quotation) {
            $q = PlatformDocument::with('items')->lockForUpdate()->findOrFail($quotation->id);

            if ($q->type !== 'quotation' || $q->status !== 'open') {
                self::fail('status', 'Only an open quotation can be converted.');
            }

            $invoice = self::create('invoice', $q->business_id, $q->items->map(fn ($i) => [
                'item_id' => $i->platform_item_id, 'name' => $i->name, 'description' => $i->description,
                'quantity' => $i->quantity, 'unit_price' => $i->unit_price,
            ])->all(), today()->addDays((int) config('kraftpos.company.due_days'))->toDateString(), $q->notes, (float) $q->tax_rate, $q->id);

            $q->status = 'converted';
            $q->save();

            return $invoice;
        });
    }

    public static function decline(PlatformDocument $quotation): void
    {
        self::guard();

        $q = PlatformDocument::findOrFail($quotation->id);
        if ($q->type !== 'quotation' || $q->status !== 'open') {
            self::fail('status', 'Only an open quotation can be declined.');
        }

        $q->status = 'declined';
        $q->save();
    }

    public static function void(PlatformDocument $invoice, string $reason): void
    {
        self::guard();
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 255) {
            self::fail('reason', 'Give a reason (255 characters at most).');
        }

        DB::transaction(function () use ($invoice, $reason) {
            $i = PlatformDocument::lockForUpdate()->findOrFail($invoice->id);

            if ($i->type !== 'invoice' || $i->status !== 'issued') {
                self::fail('reason', 'Only an issued invoice can be voided.');
            }
            if ((float) $i->amount_paid > 0) {
                self::fail('reason', 'An invoice with payments cannot be voided.');
            }

            $i->status = 'voided';
            $i->closed_at = now();
            $i->close_reason = $reason;
            $i->save();
        });
    }

    public static function pay(PlatformDocument $invoice, string $method, float $amount, ?string $reference): PlatformDocument
    {
        $user = self::guard();
        $amount = round($amount, 2);
        $reference = trim((string) $reference);

        if (! in_array($method, self::METHODS, true)) {
            self::fail('method', 'Choose a payment method.');
        }
        if ($method !== 'Cash' && mb_strlen($reference) < 4) {
            self::fail('reference', 'Enter the transaction code or cheque number (at least 4 characters).');
        }
        if (mb_strlen($reference) > 50) {
            self::fail('reference', 'The reference is too long.');
        }

        return DB::transaction(function () use ($invoice, $method, $amount, $reference, $user) {
            $i = PlatformDocument::lockForUpdate()->findOrFail($invoice->id);

            if ($i->type !== 'invoice' || $i->status !== 'issued') {
                self::fail('amount', 'Payments can only be recorded on an issued invoice.');
            }
            if ($amount <= 0) {
                self::fail('amount', 'Enter an amount above zero.');
            }
            if ($amount > $i->balance) {
                self::fail('amount', 'That is more than the balance of ' . number_format($i->balance, 2) . '.');
            }

            $i->payments()->create(['user_id' => $user->id, 'method' => $method, 'reference' => $reference ?: null, 'amount' => $amount]);
            $i->amount_paid = round((float) $i->amount_paid + $amount, 2);
            $i->save();

            return $i;
        });
    }
}
