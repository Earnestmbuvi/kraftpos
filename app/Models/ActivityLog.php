<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'business_id', 'user_id', 'user_name', 'branch_id', 'action', 'subject_type',
        'subject_id', 'subject_label', 'description', 'changes', 'ip',
    ];

    protected $casts = ['changes' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Activity records cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Activity records cannot be deleted.'));
    }
}
