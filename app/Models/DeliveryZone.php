<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\CannotBeDeleted;
use Illuminate\Database\Eloquent\Model;

class DeliveryZone extends Model
{
    use BelongsToBusiness, CannotBeDeleted;

    protected $fillable = ['name', 'fee', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'fee' => 'decimal:2'];

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
