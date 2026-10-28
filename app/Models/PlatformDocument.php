<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PlatformDocument extends Model
{
    protected $fillable = [
        'type', 'number', 'business_id', 'user_id', 'status', 'issue_date', 'due_date', 'tax_rate',
        'subtotal', 'tax_amount', 'total', 'amount_paid', 'notes', 'from_document_id',
    ];

    protected $casts = [
        'issue_date' => 'date', 'due_date' => 'date', 'closed_at' => 'datetime',
        'subtotal' => 'decimal:2', 'tax_amount' => 'decimal:2', 'total' => 'decimal:2', 'amount_paid' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Documents cannot be deleted.'));
    }

    public function business() { return $this->belongsTo(Business::class); }
    public function items() { return $this->hasMany(PlatformDocumentItem::class); }
    public function payments() { return $this->hasMany(PlatformDocumentPayment::class); }
    public function user() { return $this->belongsTo(User::class); }

    public function getNumberLabelAttribute(): string
    {
        return ($this->type === 'quotation' ? 'KQ-' : 'KI-') . str_pad((string) $this->number, 4, '0', STR_PAD_LEFT);
    }

    public function getBalanceAttribute(): float
    {
        return round((float) $this->total - (float) $this->amount_paid, 2);
    }

    // quotation: open, converted, declined. invoice: voided, paid, overdue, partly_paid, unpaid
    public function getDisplayStatusAttribute(): string
    {
        if ($this->type === 'quotation') {
            return $this->status === 'open' && $this->due_date && $this->due_date->lt(today()) ? 'expired' : $this->status;
        }
        if ($this->status === 'voided') {
            return 'voided';
        }
        if ($this->balance <= 0) {
            return 'paid';
        }
        if ($this->due_date && $this->due_date->lt(today())) {
            return 'overdue';
        }

        return (float) $this->amount_paid > 0 ? 'partly_paid' : 'unpaid';
    }
}
