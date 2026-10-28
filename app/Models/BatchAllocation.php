<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class BatchAllocation extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['stock_batch_id', 'allocatable_type', 'allocatable_id', 'quantity'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Batch allocations cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Batch allocations cannot be deleted.'));
    }

    public function batch() { return $this->belongsTo(StockBatch::class, 'stock_batch_id'); }
}
