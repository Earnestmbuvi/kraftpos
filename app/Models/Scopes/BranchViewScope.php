<?php

namespace App\Models\Scopes;

use App\Services\BranchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class BranchViewScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();
        if (! $user || ! $user->business_id || ! BranchService::multi()) {
            return;
        }

        $branch = BranchService::viewing(); // null means all branches
        if ($branch !== null) {
            BranchService::limit($builder, $branch, $model->getTable() . '.branch_id');
        }
    }
}
