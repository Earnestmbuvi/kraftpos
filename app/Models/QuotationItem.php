<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class QuotationItem extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'product_variant_id', 'product_name', 'variant_name', 'sku', 'unit',
        'quantity', 'unit_price', 'unit_cost',
        'discount_type', 'discount_value', 'discount_amount', 'line_total',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Quotation items cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Quotation items cannot be deleted.'));
    }

    public function quotation() { return $this->belongsTo(Quotation::class); }
}
