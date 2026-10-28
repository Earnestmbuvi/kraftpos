<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Business;
use App\Models\ProductVariant;
use App\Models\StockTransfer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransferService
{
    /** Moves stock from the branch you are working in to another branch. Takes effect immediately. */
    public static function create(int $toId, array $lines, ?string $note): StockTransfer
    {
        $user = auth()->user();
        abort_unless($user && $user->business_id && $user->can('transfer-stock'), 403);

        $fromId = BranchService::currentId($user);
        $from = Branch::find($fromId);
        $to = Branch::active()->find($toId);

        if (! $to) {
            self::fail('to', 'Choose where the stock is going.');
        }
        if ($to->id === $fromId) {
            self::fail('to', 'Choose a different branch from the one you are working in.');
        }
        if (count($lines) === 0) {
            self::fail('cart', 'Add at least one item.');
        }

        $note = trim((string) $note);
        if (mb_strlen($note) > 255) {
            self::fail('note', 'The note is too long (255 characters at most).');
        }

        return DB::transaction(function () use ($user, $from, $to, $lines, $note) {
            $business = Business::lockForUpdate()->findOrFail($user->business_id);
            $number = $business->last_transfer_number + 1;
            $business->last_transfer_number = $number;
            $business->save();

            $transfer = StockTransfer::create([
                'from_branch_id' => $from->id, 'to_branch_id' => $to->id, 'user_id' => $user->id,
                'transfer_number' => $number, 'note' => $note ?: null,
            ]);

            foreach ($lines as $l) {
                $v = ProductVariant::with('product')->active()->find((int) $l['variant_id']);
                if (! $v) {
                    self::fail('cart', 'An item is not available.');
                }

                $name = $v->product->name . ($v->name !== 'Default' ? ' - ' . $v->name : '');
                $qty = (int) $l['quantity'];

                if ($v->product->is_service) {
                    self::fail('cart', "{$name} is a service and has no stock.");
                }
                if ($qty < 1) {
                    self::fail('cart', "Enter a quantity for {$name}.");
                }

                if ($v->product->tracks_batches) {
                    $have = BatchService::sellable($v, $from->id);
                    if ($have < $qty) {
                        self::fail('cart', "Only {$have} in-date of {$name} at {$from->name}. Expired stock cannot be transferred.");
                    }
                    BatchService::move($v, $qty, $from->id, $to->id);
                } else {
                    $have = BranchService::quantity($v, $from->id);
                    if ($have < $qty) {
                        self::fail('cart', "Only {$have} of {$name} at {$from->name}.");
                    }
                }

                $transfer->items()->create([
                    'product_variant_id' => $v->id, 'product_name' => $v->product->name,
                    'variant_name' => $v->name === 'Default' ? null : $v->name, 'quantity' => $qty,
                ]);

                StockService::record($v, -$qty, 'transfer_out', "Transfer {$transfer->number_label} to {$to->name}", null, null, $from->id);
                StockService::record($v, $qty, 'transfer_in', "Transfer {$transfer->number_label} from {$from->name}", null, null, $to->id);
            }

            return $transfer->load('items');
        });
    }

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
