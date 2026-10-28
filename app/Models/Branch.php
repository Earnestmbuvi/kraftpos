<?php

// app/Models/Branch.php
namespace App\Models;
use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\CannotBeDeleted;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use BelongsToBusiness, CannotBeDeleted;
    protected $fillable = ['name', 'is_active'];
    protected $casts = ['is_active' => 'boolean', 'is_main' => 'boolean'];
}
