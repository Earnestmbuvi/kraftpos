<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\CannotBeDeleted;
use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    use BelongsToBusiness, CannotBeDeleted;

    protected $fillable = [
        'product_id', 'name', 'options', 'sku', 'barcode',
        'cost_price', 'selling_price', 'stock_quantity',
        'low_stock_threshold', 'is_active',
    ];

    protected $casts = [
        'options' => 'array',
        'cost_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'stock_quantity' => 'integer',
        'low_stock_threshold' => 'integer',
        'is_active' => 'boolean',
    ];

    public function product() { return $this->belongsTo(Product::class); }
    public function batches() { return $this->hasMany(StockBatch::class, 'product_variant_id'); }

// In-date quantity that can actually be sold
public function sellableQuantity(): int
{
    return (int) $this->batches
        ->filter(fn ($b) => $b->quantity > 0 && ($b->expiry_date === null || $b->expiry_date->gte(today())))
        ->sum('quantity');
}

// Stock at the branch you work in (till, adjustment, counting)
public function getBranchStockAttribute(): int
{
    return \App\Services\BranchService::multi()
        ? \App\Services\BranchService::quantity($this, \App\Services\BranchService::currentId())
        : (int) $this->stock_quantity;
}

// Stock in what you are viewing: one branch, or all branches together
public function getViewStockAttribute(): int
{
    $b = \App\Services\BranchService::viewing();

    return ($b === null || ! \App\Services\BranchService::multi())
        ? (int) $this->stock_quantity
        : \App\Services\BranchService::quantity($this, $b);
}
}
