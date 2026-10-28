<?php

// app/Models/StockTransfer.php
namespace App\Models;
use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class StockTransfer extends Model
{
    use BelongsToBusiness;
    protected $fillable = ['from_branch_id', 'to_branch_id', 'user_id', 'transfer_number', 'note'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Transfers cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Transfers cannot be deleted.'));
    }

    public function items() { return $this->hasMany(StockTransferItem::class); }
    public function from() { return $this->belongsTo(Branch::class, 'from_branch_id'); }
    public function to() { return $this->belongsTo(Branch::class, 'to_branch_id'); }
    public function user() { return $this->belongsTo(User::class); }

    public function getNumberLabelAttribute(): string
    {
        return 'TR-' . str_pad((string) $this->transfer_number, 4, '0', STR_PAD_LEFT);
    }
}
