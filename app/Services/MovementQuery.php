<?php

namespace App\Services;

use App\Models\StockMovement;

class MovementQuery
{
    public const TYPES = ['opening', 'adjustment', 'stocktake', 'sale', 'return', 'transfer_out', 'transfer_in'];

    public static function build(?string $from, ?string $to, string $type = 'all', string $search = '')
    {
        [$f, $t] = ReportService::range($from, $to);
        $term = trim($search);

        return StockMovement::query()
            ->with(['variant.product', 'user', 'batch', 'branch'])
            ->whereBetween('created_at', [$f, $t])
            ->when(in_array($type, self::TYPES, true), fn ($q) => $q->where('type', $type))
            ->when($term !== '', fn ($q) => $q->whereHas('variant', fn ($v) => $v->where(function ($v) use ($term) {
                $v->where('sku', 'like', "%{$term}%")
                  ->orWhere('barcode', 'like', "%{$term}%")
                  ->orWhere('name', 'like', "%{$term}%")
                  ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$term}%"));
            })));
    }
}
