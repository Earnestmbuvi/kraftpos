<?php

// app/Models/StockTransferItem.php
namespace App\Models;
use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class StockTransferItem extends Model
{
    use BelongsToBusiness;
    protected $fillable = ['product_variant_id', 'product_name', 'variant_name', 'quantity'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Transfer items cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Transfer items cannot be deleted.'));
    }
}
