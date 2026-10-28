<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Business extends Model
{
    protected $fillable = [
        'name', 'trading_name', 'business_type', 'registration_number',
        'category', 'phone', 'email', 'address', 'county', 'town',
        'expected_branches',
        'owner_name', 'owner_id_number', 'owner_phone', 'owner_email', 'owner_dob',
        'notes',
    ];

    protected $casts = [
        'owner_dob' => 'date',
        'reviewed_at' => 'datetime',
        'tax_rate' => 'decimal:2',
         'tax_inclusive' => 'boolean',
         'subscription_ends_at' => 'date',
        'monthly_fee' => 'decimal:2',
        'billing_suspended' => 'boolean',
        'discounts_enabled' => 'boolean',
        'receipt_settings' => 'array',
        'allow_zero_stock_sales' => 'boolean'

    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
    public function subscriptionPayments()
{
    return $this->hasMany(SubscriptionPayment::class);
}

// none, ok, expiring, grace (ended but not yet suspended)
public function getSubscriptionStateAttribute(): string
{
    if (! $this->subscription_ends_at) {
        return 'none';
    }

    $days = (int) today()->diffInDays($this->subscription_ends_at, false);

    if ($days > config('billing.warn_days')) {
        return 'ok';
    }

    return $days >= 0 ? 'expiring' : 'grace';
   }
}
