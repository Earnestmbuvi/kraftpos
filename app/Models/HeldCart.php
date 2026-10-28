<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use \App\Models\Concerns\BranchBound;

class HeldCart extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['user_id', 'label', 'cart', 'cart_discount_type', 'cart_discount_value','customer_id'];

    protected $casts = ['cart' => 'array'];

    public function user() { return $this->belongsTo(User::class); }
}
