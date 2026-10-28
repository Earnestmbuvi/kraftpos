<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AdminAuditLog extends Model
{
    protected $fillable = ['admin_id', 'business_id', 'target_user_id', 'action', 'detail'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit logs cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Audit logs cannot be deleted.'));
    }

    public function admin() { return $this->belongsTo(User::class, 'admin_id'); }

    public function target() { return $this->belongsTo(User::class, 'target_user_id'); }
}
