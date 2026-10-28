<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\CannotBeDeleted;
use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    use BelongsToBusiness, CannotBeDeleted;

    public const DEFAULTS = ['pcs', 'kg', 'g', 'litres', 'ml', 'packet', 'box', 'dozen', 'bottle'];

    protected $fillable = ['name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public static function seedDefaultsFor(Business $business): void
    {
        foreach (self::DEFAULTS as $name) {
            $exists = static::withoutGlobalScopes()
                ->where('business_id', $business->id)
                ->where('name', $name)
                ->exists();

            if (! $exists) {
                static::withoutGlobalScopes()->forceCreate([
                    'business_id' => $business->id,
                    'name' => $name,
                ]);
            }
        }
    }
}
