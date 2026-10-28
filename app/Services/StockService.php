<?php

namespace App\Services;

use App\Models\BranchStock;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockService
{
    /** Change stock by a signed amount at a branch and log it. */
    public static function record(
        ProductVariant $variant,
        int $change,
        string $type = 'adjustment',
        ?string $reason = null,
        ?string $note = null,
        ?int $batchId = null,
        ?int $branchId = null,
    ): StockMovement {
        return DB::transaction(function () use ($variant, $change, $type, $reason, $note, $batchId, $branchId) {
            $locked = ProductVariant::lockForUpdate()->findOrFail($variant->id);

            $main = BranchService::mainId($locked->business_id);
            $branch = $branchId ?: BranchService::currentId() ?: $main;

            if ($branch !== $main) {
                $row = BranchStock::withoutGlobalScopes()->where('branch_id', $branch)
                    ->where('product_variant_id', $locked->id)->lockForUpdate()->first()
                    ?? BranchStock::forceCreate([
                        'business_id' => $locked->business_id, 'branch_id' => $branch,
                        'product_variant_id' => $locked->id, 'quantity' => 0,
                    ]);
                $row->quantity += $change;
                $row->save();
            }

            $locked->stock_quantity += $change;
            $locked->save();

            BranchService::forget();
            $after = BranchService::multi() ? BranchService::quantity($locked, $branch) : (int) $locked->stock_quantity;

            return StockMovement::create([
                'product_variant_id' => $locked->id,
                'stock_batch_id' => $batchId,
                'branch_id' => $branch,
                'user_id' => auth()->id(),
                'type' => $type,
                'quantity_change' => $change,
                'quantity_after' => $after,
                'reason' => $reason,
                'note' => $note,
            ]);
        });
    }


    /** Log the opening stock of a variant that was just created. It belongs to the branch you are working in. */
public static function recordOpening(ProductVariant $variant): void
{
    if ($variant->stock_quantity == 0) {
        return;
    }

    $main = BranchService::mainId($variant->business_id);
    $branch = BranchService::currentId() ?: $main;

    if ($branch !== $main) {
        BranchStock::forceCreate([
            'business_id' => $variant->business_id, 'branch_id' => $branch,
            'product_variant_id' => $variant->id, 'quantity' => $variant->stock_quantity,
        ]);
        BranchService::forget();
    }

    StockMovement::create([
        'product_variant_id' => $variant->id,
        'branch_id' => $branch,
        'user_id' => auth()->id(),
        'type' => 'opening',
        'quantity_change' => $variant->stock_quantity,
        'quantity_after' => $variant->stock_quantity,
        'reason' => 'Opening stock',
    ]);
}

   /** Why this quantity cannot be put in a cart at this branch, or null if it can. */
public static function cartMessage(ProductVariant $variant, int $qty): ?string
{
    $v = ProductVariant::with('product')->find($variant->id);
    if (! $v) {
        return null;
    }

    $name = $v->product->name . ($v->name !== 'Default' ? ' - ' . $v->name : '');

    if (! $v->product->isSoldAt(BranchService::currentId())) {
        return "{$name} is not sold at this branch.";
    }
    if ($v->product->is_service) {
        return null;
    }

    if ($v->product->tracks_batches) {
        $have = BatchService::sellable($v);

        return $have >= $qty ? null : ($have <= 0
            ? "{$name} has no in-date stock to sell here."
            : "Only {$have} of {$name} in date here. You cannot add more.");
    }

    if (auth()->user()->business->allow_zero_stock_sales) {
        return null;
    }

    $have = BranchService::quantity($v, BranchService::currentId());

    return $have >= $qty ? null : ($have <= 0
        ? "{$name} is out of stock here and cannot be sold."
        : "Only {$have} of {$name} left here. You cannot add more.");
}

public static function assertSellable(ProductVariant $variant, int $qty): void
{
    $v = ProductVariant::with('product')->findOrFail($variant->id);
    $name = $v->product->name . ($v->name !== 'Default' ? ' - ' . $v->name : '');

    if (! $v->product->isSoldAt(BranchService::currentId())) {
        throw ValidationException::withMessages(['cart' => "{$name} is not sold at this branch."]);
    }

    if (auth()->user()->business->allow_zero_stock_sales) {
        return;
    }
    if ($v->product->is_service || $v->product->tracks_batches) {
        return; // services have no stock; batches refuse on their own
    }

    $have = BranchService::quantity($v, BranchService::currentId());
    if ($have < $qty) {
        throw ValidationException::withMessages(['cart' => "Not enough stock of {$name}. Available here: {$have}."]);
    }
}
}
