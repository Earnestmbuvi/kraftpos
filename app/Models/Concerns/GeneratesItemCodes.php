<?php

namespace App\Models\Concerns;

use App\Services\ItemCodes;

trait GeneratesItemCodes
{
    protected static function bootGeneratesItemCodes(): void
    {
        static::saving(function ($variant) {
            $needSku = trim((string) $variant->sku) === '';
            $needCode = trim((string) $variant->barcode) === '';

            if (! $needSku && ! $needCode) {
                return;
            }

            $businessId = $variant->business_id ?: auth()->user()?->business_id;
            if (! $businessId) {
                return;
            }

            [$sku, $code] = ItemCodes::next((int) $businessId);

            if ($needSku) {
                $variant->sku = $sku;
            }
            if ($needCode) {
                $variant->barcode = $code;
            }
        });
    }
}
