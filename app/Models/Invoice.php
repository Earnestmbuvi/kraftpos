<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use \App\Models\Concerns\BranchBound;

class Invoice extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'user_id', 'branch_id', 'customer_id', 'invoice_number', 'due_date', 'notes',
        'subtotal', 'discount_type', 'discount_value', 'discount_amount',
        'tax_rate', 'tax_inclusive', 'tax_amount', 'total',
    ];

    protected $casts = [
        'due_date' => 'date',
        'voided_at' => 'datetime',
        'tax_inclusive' => 'boolean',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'written_off' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Invoices cannot be deleted. Void them instead.'));
    }

    public function items() { return $this->hasMany(InvoiceItem::class); }

    public function payments() { return $this->hasMany(InvoicePayment::class); }

    public function customer() { return $this->belongsTo(Customer::class); }

    public function user() { return $this->belongsTo(User::class); }

    public function getNumberLabelAttribute(): string
    {
        return 'INV-' . str_pad((string) $this->invoice_number, 4, '0', STR_PAD_LEFT);
    }

    public function getBalanceAttribute(): float
    {
        return round((float) $this->total - (float) $this->amount_paid, 2);
    }

    // Money actually received, without anything written off
    public function getCashPaidAttribute(): float
    {
        return round((float) $this->amount_paid - (float) $this->written_off, 2);
    }

    // voided, paid, written_off, overdue, partly_paid or unpaid
    public function getPaymentStatusAttribute(): string
    {
        if ($this->status === 'voided') {
            return 'voided';
        }
        if ($this->balance <= 0) {
            return (float) $this->written_off > 0 ? 'written_off' : 'paid';
        }
        if ($this->due_date && $this->due_date->lt(today())) {
            return 'overdue';
        }

        return (float) $this->cash_paid > 0 ? 'partly_paid' : 'unpaid';
    }
}
