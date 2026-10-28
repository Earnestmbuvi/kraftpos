<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class BusinessScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if (!$user) {
            return;
        }

        if ($user->is_super_admin) {
            return;
        }

        // Everyone else is restricted. No business means no rows.
        $builder->where($model->getTable() . '.business_id', $user->business_id ?? 0);
    }
}
