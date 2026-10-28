<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use \App\Models\Concerns\BranchBound;

class Sale extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'user_id', 'receipt_number', 'subtotal',
        'discount_type', 'discount_value', 'discount_amount',
        'tax_rate', 'tax_inclusive', 'tax_amount',
        'total', 'amount_paid', 'change_given','customer_id',
        'branch_id', 'credit_applied',
    ];

    protected $casts = [
        'tax_inclusive' => 'boolean',
        'voided_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'change_given' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Sales cannot be deleted. Void them instead.'));
    }

    public function items() { return $this->hasMany(SaleItem::class); }

    public function payments() { return $this->hasMany(Payment::class); }

    public function cashier() { return $this->belongsTo(User::class, 'user_id'); }

    public function getReceiptLabelAttribute(): string
    {
        return str_pad((string) $this->receipt_number, 4, '0', STR_PAD_LEFT);
    }

    public function customer() { return $this->belongsTo(Customer::class); }

    public function returns() { return $this->hasMany(SaleReturn::class); }
}
