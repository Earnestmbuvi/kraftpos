<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\CannotBeDeleted;
use Illuminate\Database\Eloquent\Model;

class ProductOption extends Model
{
    use BelongsToBusiness, CannotBeDeleted;

    public const DEFAULTS = [
        'Size' => ['S', 'M', 'L', 'XL'],
        'Colour' => ['Red', 'Blue', 'Black', 'White'],
    ];

    protected $fillable = ['name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function values()
    {
        return $this->hasMany(ProductOptionValue::class)->orderBy('id');
    }

    public static function seedDefaultsFor(Business $business): void
    {
        foreach (self::DEFAULTS as $name => $values) {
            $option = static::withoutGlobalScopes()->firstOrNew([
                'business_id' => $business->id,
                'name' => $name,
            ]);

            if (! $option->exists) {
                $option->business_id = $business->id;
                $option->save();
            }

            foreach ($values as $value) {
                $exists = ProductOptionValue::withoutGlobalScopes()
                    ->where('product_option_id', $option->id)
                    ->where('value', $value)
                    ->exists();

                if (! $exists) {
                    ProductOptionValue::withoutGlobalScopes()->forceCreate([
                        'business_id' => $business->id,
                        'product_option_id' => $option->id,
                        'value' => $value,
                    ]);
                }
            }
        }
    }
}
