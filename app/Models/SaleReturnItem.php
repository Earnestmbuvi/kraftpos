<?php
// app/Models/SaleReturnItem.php
namespace App\Models;
use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class SaleReturnItem extends Model
{
    use BelongsToBusiness;
    protected $fillable = ['sale_item_id', 'product_variant_id', 'product_name', 'variant_name', 'quantity', 'amount', 'tax_amount', 'cost'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Return items cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Return items cannot be deleted.'));
    }
}
