<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class SubscriptionPayment extends Model
{
    protected $fillable = [
        'recorded_by', 'amount', 'months', 'method', 'reference',
        'period_start', 'period_end', 'note',
    ];

    protected $casts = ['period_start' => 'date', 'period_end' => 'date'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Subscription payments cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Subscription payments cannot be deleted.'));
    }

    public function business() { return $this->belongsTo(Business::class); }

    public function recorder() { return $this->belongsTo(User::class, 'recorded_by'); }
}
