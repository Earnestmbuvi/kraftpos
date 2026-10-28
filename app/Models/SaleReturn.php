<?php

// app/Models/SaleReturn.php
namespace App\Models;
use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use \App\Models\Concerns\BranchBound;

class SaleReturn extends Model
{
    use BelongsToBusiness;
    protected $fillable = [
        'branch_id', 'sale_id', 'user_id', 'return_number', 'type', 'value', 'refund_total',
        'refund_method_id', 'refund_method_name', 'refund_type', 'reference', 'reason', 'exchange_sale_id',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Returns cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Returns cannot be deleted.'));
    }

    public function items() { return $this->hasMany(SaleReturnItem::class); }
    public function sale() { return $this->belongsTo(Sale::class); }
    public function exchangeSale() { return $this->belongsTo(Sale::class, 'exchange_sale_id'); }
    public function user() { return $this->belongsTo(User::class); }

    public function getNumberLabelAttribute(): string
    {
        return 'RT-' . str_pad((string) $this->return_number, 4, '0', STR_PAD_LEFT);
    }
}
