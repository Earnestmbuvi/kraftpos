<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PlatformDocumentItem extends Model
{
    protected $fillable = ['platform_item_id', 'name', 'description', 'quantity', 'unit_price', 'line_total'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Items cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Items cannot be deleted.'));
    }
}
