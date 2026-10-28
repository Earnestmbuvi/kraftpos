<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use \App\Models\Concerns\BranchBound;

class StockMovement extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'product_variant_id', 'user_id', 'type',
        'quantity_change', 'quantity_after', 'reason', 'note','stock_batch_id', 'branch_id',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Stock movements cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Stock movements cannot be deleted.'));
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function batch()
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
        }

        public function branch() { return $this->belongsTo(Branch::class); }
}
