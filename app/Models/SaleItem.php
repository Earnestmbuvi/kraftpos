<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class SaleItem extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'product_variant_id', 'product_name', 'variant_name', 'sku', 'unit',
        'quantity', 'unit_price', 'unit_cost',
        'discount_type', 'discount_value', 'discount_amount', 'line_total', 'tax_class',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Sale items cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Sale items cannot be deleted.'));
    }

    public function sale() { return $this->belongsTo(Sale::class); }

    public function variant() { return $this->belongsTo(ProductVariant::class, 'product_variant_id'); }
}
