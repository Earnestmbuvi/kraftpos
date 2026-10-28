<?php

namespace App\Models;

use App\Models\Concerns\CannotBeDeleted;
use Illuminate\Database\Eloquent\Model;

class PlatformItem extends Model
{
    use CannotBeDeleted;

    protected $fillable = ['name', 'kind', 'description', 'price', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'price' => 'decimal:2'];
}
