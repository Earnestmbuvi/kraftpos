<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\BranchBound;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class StockBatch extends Model
{
    use BelongsToBusiness, BranchBound;

    protected $fillable = ['product_variant_id', 'branch_id', 'batch_number', 'expiry_date', 'quantity', 'received_quantity'];

    protected $casts = ['expiry_date' => 'date:Y-m-d'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Batches cannot be deleted. Remove the stock instead.'));
    }

    public function variant() { return $this->belongsTo(ProductVariant::class, 'product_variant_id'); }

    public function getIsExpiredAttribute(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->lt(today());
    }
}
