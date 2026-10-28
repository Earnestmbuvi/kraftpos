<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\BatchAllocation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Scopes\BranchViewScope;
use App\Models\StockBatch;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BatchService
{
    /** Batches of one variant at one branch, ignoring whatever the screen is viewing. */
    private static function at(int $variantId, int $branch)
    {
        return BranchService::limit(
            StockBatch::withoutGlobalScope(BranchViewScope::class)->where('product_variant_id', $variantId),
            $branch, 'branch_id'
        );
    }

    private static function inDate($q)
    {
        return $q->where(fn ($d) => $d->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', today()->toDateString()));
    }

    /** In-date quantity of a variant at a branch (the branch you work in by default). */
    public static function sellable(ProductVariant $v, ?int $branch = null): int
    {
        $branch ??= BranchService::currentId();

        return (int) self::inDate(self::at($v->id, $branch)->where('quantity', '>', 0))->sum('quantity');
    }

    /** Receive stock into a batch at the branch you work in. */
    public static function receive(
        ProductVariant $variant, int $qty, ?string $number, ?string $expiry,
        ?string $note = null, string $reason = 'Purchase received'
    ): StockBatch {
        $user = auth()->user();
        abort_unless($user && $user->can('adjust-stock'), 403);

        $number = trim((string) $number);
        $note = trim((string) $note);

        if ($qty < 1 || $qty > 1000000) {
            self::fail('quantity', 'Enter a quantity above zero.');
        }
        if ($number === '') {
            self::fail('batchNumber', 'Enter the batch number.');
        }
        if (mb_strlen($number) > 50) {
            self::fail('batchNumber', 'The batch number is too long (50 characters at most).');
        }
        if (! $expiry) {
            self::fail('expiry', 'Enter the expiry date.');
        }
        try {
            $day = Carbon::createFromFormat('Y-m-d', $expiry)->startOfDay();
        } catch (\Throwable $e) {
            self::fail('expiry', 'Enter a valid date.');
        }
        if ($day->lt(today())) {
            self::fail('expiry', 'That date has already passed. Expired stock cannot be received.');
        }

        return DB::transaction(function () use ($variant, $qty, $number, $day, $note, $reason) {
            $v = ProductVariant::with('product')->findOrFail($variant->id); // tenant scope applies

            if (! $v->product->tracks_batches || $v->product->is_service) {
                self::fail('quantity', 'This product does not track batches.');
            }

            $branch = BranchService::currentId();
            $batch = self::addToBatch($v, $qty, $number, $day->toDateString(), $branch);
            StockService::record($v, $qty, 'adjustment', $reason, $note ?: null, $batch->id, $branch);

            return $batch;
        });
    }

    /** Remove from, or count, one batch. The change happens at the batch's own branch. */
    public static function adjust(StockBatch $batch, string $mode, int $qty, string $reason, ?string $note): StockBatch
    {
        $user = auth()->user();
        abort_unless($user && $user->can('adjust-stock'), 403);

        if (! in_array($mode, ['remove', 'set'], true)) {
            self::fail('quantity', 'Unknown adjustment.');
        }
        if ($qty < ($mode === 'set' ? 0 : 1) || $qty > 1000000) {
            self::fail('quantity', 'Enter a valid quantity.');
        }

        return DB::transaction(function () use ($batch, $mode, $qty, $reason, $note) {
            $b = StockBatch::withoutGlobalScope(BranchViewScope::class)->lockForUpdate()->findOrFail($batch->id);
            $variant = ProductVariant::findOrFail($b->product_variant_id);
            $branch = $b->branch_id ?: BranchService::mainId();

            $change = $mode === 'remove' ? -$qty : $qty - $b->quantity;

            if ($change === 0) {
                self::fail('quantity', 'That is already the current quantity of this batch.');
            }
            if ($b->quantity + $change < 0) {
                self::fail('quantity', 'This would take the batch below zero. It holds ' . $b->quantity . '.');
            }

            $b->quantity += $change;
            $b->save();

            StockService::record($variant, $change, 'adjustment', $reason, $note ? trim($note) : null, $b->id, $branch);

            return $b;
        });
    }

    /**
     * FEFO at one branch: take from the soonest-expiring in-date batches.
     * Refuses (and the surrounding transaction rolls back) if there is not enough in-date stock.
     */
    public static function take(ProductVariant $variant, int $qty, ?int $branch = null): array
    {
        $branch ??= BranchService::currentId();

        $batches = self::inDate(self::at($variant->id, $branch)->where('quantity', '>', 0))
            ->orderByRaw('expiry_date IS NULL')->orderBy('expiry_date')->orderBy('id')
            ->lockForUpdate()->get();

        $available = (int) $batches->sum('quantity');

        if ($available < $qty) {
            $variant->loadMissing('product');
            $name = $variant->product->name . ($variant->name !== 'Default' ? ' - ' . $variant->name : '');
            self::fail('cart', "Not enough in-date stock of {$name} here. Available: {$available}.");
        }

        $left = $qty;
        $taken = [];

        foreach ($batches as $b) {
            $use = min($left, $b->quantity);
            $b->quantity -= $use;
            $b->save();

            $taken[] = [$b, $use];
            $left -= $use;

            if ($left === 0) {
                break;
            }
        }

        return $taken;
    }

    /** Move in-date stock from one branch's batches to another's, keeping batch numbers and expiry dates. */
    public static function move(ProductVariant $v, int $qty, int $from, int $to): void
    {
        foreach (self::take($v, $qty, $from) as [$batch, $used]) {
            self::addToBatch($v, $used, $batch->batch_number, $batch->expiry_date?->toDateString(), $to);
        }
    }

    public static function attach(Model $item, array $taken): void
    {
        foreach ($taken as [$batch, $qty]) {
            BatchAllocation::create([
                'stock_batch_id' => $batch->id,
                'allocatable_type' => $item->getMorphClass(),
                'allocatable_id' => $item->id,
                'quantity' => $qty,
            ]);
        }
    }

    /** Put a voided line back into the batches it came from. */
    public static function restore(Model $item, ProductVariant $variant): void
    {
        $allocations = BatchAllocation::where('allocatable_type', $item->getMorphClass())
            ->where('allocatable_id', $item->id)->get();

        if ($allocations->isEmpty()) {
            // Sold before tracking was switched on: return it as an undated batch at the branch it was sold from
            if ($variant->product->tracks_batches) {
                $branch = $item instanceof \App\Models\InvoiceItem
                    ? DB::table('invoices')->where('id', $item->invoice_id)->value('branch_id')
                    : DB::table('sales')->where('id', $item->sale_id)->value('branch_id');

                self::addToBatch($variant, (int) $item->quantity, 'Returned', null, $branch ?: BranchService::mainId());
            }
            return;
        }

        foreach ($allocations as $a) {
            $batch = StockBatch::withoutGlobalScope(BranchViewScope::class)->lockForUpdate()->find($a->stock_batch_id);
            if ($batch) {
                $batch->quantity += $a->quantity;
                $batch->save();
            }
        }
    }

    public static function returnStock(ProductVariant $v, int $qty, ?int $branch = null): StockBatch
    {
        return self::addToBatch($v, $qty, 'Returned', null, $branch ?: BranchService::currentId());
    }

    /** Switch tracking on for a product that already has stock: one "Existing stock" batch per branch that holds some. */
    public static function enableTracking(Product $product): void
    {
        if ($product->is_service) {
            self::fail('tracksBatches', 'Services have no stock to track.');
        }
        if ($product->tracks_batches) {
            return;
        }

        BranchService::forget();
        $branches = Branch::pluck('name', 'id');
        $variants = ProductVariant::where('product_id', $product->id)->lockForUpdate()->get();
        $plan = [];

        foreach ($variants as $v) {
            $name = $product->name . ($v->name !== 'Default' ? ' - ' . $v->name : '');

            foreach ($branches as $branchId => $branchName) {
                $q = BranchService::quantity($v, (int) $branchId);

                if ($q < 0) {
                    self::fail('tracksBatches', "Fix the negative stock of {$name} at {$branchName} first.");
                }
                if ($q > 0) {
                    $plan[] = [$v, $q, (int) $branchId];
                }
            }
        }

        $product->forceFill(['tracks_batches' => true])->save();

        foreach ($plan as [$v, $q, $branchId]) {
            self::addToBatch($v, $q, 'Existing stock', null, $branchId);
        }
    }

    private static function addToBatch(ProductVariant $v, int $qty, ?string $number, ?string $expiry, ?int $branch = null): StockBatch
    {
        $branch ??= BranchService::currentId();

        $batch = self::at($v->id, $branch)
            ->when($number === null, fn ($q) => $q->whereNull('batch_number'), fn ($q) => $q->where('batch_number', $number))
            ->when($expiry === null, fn ($q) => $q->whereNull('expiry_date'), fn ($q) => $q->whereDate('expiry_date', $expiry))
            ->lockForUpdate()->first();

        if ($batch) {
            $batch->quantity += $qty;
            $batch->received_quantity += $qty;
            $batch->save();

            return $batch;
        }

        return StockBatch::create([
            'product_variant_id' => $v->id,
            'branch_id' => $branch,
            'batch_number' => $number,
            'expiry_date' => $expiry,
            'quantity' => $qty,
            'received_quantity' => $qty,
        ]);
    }

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
