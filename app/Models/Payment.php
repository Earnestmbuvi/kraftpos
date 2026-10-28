<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class Payment extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['payment_method_id', 'method_name', 'type', 'amount', 'reference'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Payments cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Payments cannot be deleted.'));
    }

    public function sale() { return $this->belongsTo(Sale::class); }
}
