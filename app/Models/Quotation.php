<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use \App\Models\Concerns\BranchBound;

class Quotation extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'user_id', 'customer_id', 'quote_number', 'valid_until', 'notes',
        'subtotal', 'discount_type', 'discount_value', 'discount_amount',
        'tax_rate', 'tax_inclusive', 'tax_amount', 'total',
    ];

    protected $casts = [
        'valid_until' => 'date',
        'tax_inclusive' => 'boolean',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Quotations cannot be deleted.'));
    }

    public function items() { return $this->hasMany(QuotationItem::class); }

    public function customer() { return $this->belongsTo(Customer::class); }

    public function user() { return $this->belongsTo(User::class); }

    public function getNumberLabelAttribute(): string
    {
        return 'Q-' . str_pad((string) $this->quote_number, 4, '0', STR_PAD_LEFT);
    }

    public function getDisplayStatusAttribute(): string
    {
        return $this->status === 'open' && $this->valid_until->lt(today()) ? 'expired' : $this->status;
    }
}
