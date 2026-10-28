<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class InvoicePayment extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['user_id', 'payment_method_id', 'method_name', 'type', 'amount', 'reference','receipt_number'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Payments cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Payments cannot be deleted.'));
    }

    public function invoice() { return $this->belongsTo(Invoice::class); }

    public function user() { return $this->belongsTo(User::class); }
    public function getReceiptLabelAttribute(): string
{
    return 'RP-' . str_pad((string) ($this->receipt_number ?? $this->id), 4, '0', STR_PAD_LEFT);
}
}
