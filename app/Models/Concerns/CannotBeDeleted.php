<?php

namespace App\Models\Concerns;

use LogicException;

trait CannotBeDeleted
{
    protected static function bootCannotBeDeleted(): void
    {
        static::deleting(function ($model) {
            throw new LogicException(
                class_basename($model) . ' records cannot be deleted. Disable them instead.'
            );
        });
    }

    public function disable(): void
    {
        $this->update(['is_active' => false]);
    }

    public function enable(): void
    {
        $this->update(['is_active' => true]);
    }

    public function scopeActive($query)
    {
        return $query->where($this->getTable() . '.is_active', true);
    }
}
