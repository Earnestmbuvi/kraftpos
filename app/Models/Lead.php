<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    protected $fillable = ['type', 'name', 'email', 'phone', 'business_name', 'sector', 'branches_count', 'preferred_date', 'message'];

    protected $casts = ['preferred_date' => 'date'];
}
