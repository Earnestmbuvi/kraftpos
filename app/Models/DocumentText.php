<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\CannotBeDeleted;
use Illuminate\Database\Eloquent\Model;

class DocumentText extends Model
{
    use BelongsToBusiness, CannotBeDeleted;

    protected $fillable = ['kind', 'name', 'body', 'default_invoice', 'default_quotation', 'is_active'];

    protected $casts = ['default_invoice' => 'boolean', 'default_quotation' => 'boolean', 'is_active' => 'boolean'];

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
