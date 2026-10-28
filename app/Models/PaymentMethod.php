<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\CannotBeDeleted;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use BelongsToBusiness, CannotBeDeleted;

    public const TYPES = ['cash' => 'Cash', 'mobile' => 'Mobile money', 'card' => 'Card', 'other' => 'Other'];

    public const DEFAULTS = [
        ['name' => 'Cash', 'type' => 'cash'],
        ['name' => 'M-Pesa', 'type' => 'mobile'],
    ];

    protected $fillable = ['name', 'type', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public static function seedDefaultsFor(Business $business): void
    {
        foreach (self::DEFAULTS as $d) {
            $exists = static::withoutGlobalScopes()
                ->where('business_id', $business->id)
                ->where('name', $d['name'])
                ->exists();

            if (! $exists) {
                static::withoutGlobalScopes()->forceCreate($d + ['business_id' => $business->id]);
            }
        }
    }
}
