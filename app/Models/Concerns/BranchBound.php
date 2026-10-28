<?php

namespace App\Models\Concerns;

use App\Models\Scopes\BranchViewScope;
use App\Services\BranchService;

trait BranchBound
{
    protected static function bootBranchBound(): void
    {
        static::addGlobalScope(new BranchViewScope());

        static::creating(function ($model) {
            $user = auth()->user();

            if ($model->branch_id === null && $user && $user->business_id) {
                $model->branch_id = BranchService::currentId($user);
            }
        });
    }
}
