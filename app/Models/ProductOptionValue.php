<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\CannotBeDeleted;
use Illuminate\Database\Eloquent\Model;

class ProductOptionValue extends Model
{
    use BelongsToBusiness, CannotBeDeleted;

    protected $fillable = ['product_option_id', 'value', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function option()
    {
        return $this->belongsTo(ProductOption::class, 'product_option_id');
    }
}
