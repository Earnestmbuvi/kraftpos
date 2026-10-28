<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\CannotBeDeleted;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use BelongsToBusiness, CannotBeDeleted;

    protected $fillable = ['category_id', 'name', 'description', 'type', 'unit', 'image_path', 'is_active', 'tracks_batches', 'is_service', 'tax_class'];

    protected $casts = ['is_active', 'is_service' => 'boolean', 'tracks_batches' => 'boolean'];


    public function category() { return $this->belongsTo(Category::class); }

    public function variants() { return $this->hasMany(ProductVariant::class); }

    public function getImageUrlAttribute(): ?string
{
    return $this->image_path ? asset('storage/' . $this->image_path) : null;
}
public function branches()
{
    return $this->belongsToMany(\App\Models\Branch::class, 'branch_product');
}

// No mapping means every branch
public function scopeForBranch($q, ?int $branch)
{
    if (! $branch || ! \App\Services\BranchService::multi()) {
        return $q;
    }

    return $q->where(fn ($w) => $w->whereDoesntHave('branches')
        ->orWhereHas('branches', fn ($b) => $b->where('branches.id', $branch)));
}

public function isSoldAt(?int $branch): bool
{
    if (! $branch || ! \App\Services\BranchService::multi()) {
        return true;
    }

    $ids = $this->branches()->pluck('branches.id');

    return $ids->isEmpty() || $ids->contains($branch);
}
}
