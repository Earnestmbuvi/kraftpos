<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PlatformDocumentPayment extends Model
{
    protected $fillable = ['user_id', 'method', 'reference', 'amount'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Payments cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Payments cannot be deleted.'));
    }

    public function user() { return $this->belongsTo(User::class); }
}
