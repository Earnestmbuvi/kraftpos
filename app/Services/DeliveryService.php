<?php

namespace App\Services;

use App\Models\DeliveryZone;
use Illuminate\Validation\ValidationException;

class DeliveryService
{
    /** @return array{0:float,1:?string} [fee, label]. A zone gives its name as the label, a typed fee is labelled "Delivery". */
    public static function resolve($zoneId, $amount): array
    {
        $fee = round((float) $amount, 2);

        if ($fee < 0 || $fee > 1000000) {
            throw ValidationException::withMessages(['delivery' => 'Enter a delivery fee between 0 and 1,000,000.']);
        }
        if ($fee == 0) {
            return [0.0, null];
        }

        $zone = $zoneId ? DeliveryZone::active()->find((int) $zoneId) : null; // tenant scope applies

        return [$fee, $zone?->name ? 'Delivery: ' . $zone->name : 'Delivery'];
    }
}
