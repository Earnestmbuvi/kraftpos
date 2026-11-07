<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Picqer\Barcode\BarcodeGeneratorSVG;

class ItemCodes
{
    /** EAN-13 check digit for the first 12 digits. */
    public static function ean13(string $twelve): string
    {
        $sum = 0;
        foreach (str_split($twelve) as $i => $d) {
            $sum += (int) $d * ($i % 2 === 0 ? 1 : 3);
        }

        return $twelve . ((10 - $sum % 10) % 10);
    }

    public static function validEan13(string $code): bool
    {
        return preg_match('/^\d{13}$/', $code) === 1 && self::ean13(substr($code, 0, 12)) === $code;
    }

    /**
     * The next SKU and barcode for a shop. The barcode starts with 2, the range EAN reserves
     * for in-store use, so it can never clash with a product's manufacturer barcode.
     *
     * @return array{0:string,1:string} [sku, barcode]
     */
    public static function next(int $businessId): array
    {
        return DB::transaction(function () use ($businessId) {
            for ($try = 0; $try < 50; $try++) {
                $row = DB::table('businesses')->where('id', $businessId)->lockForUpdate()->first();
                $n = (int) $row->last_item_number + 1;
                DB::table('businesses')->where('id', $businessId)->update(['last_item_number' => $n]);

                $sku = 'SKU-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
                $barcode = self::ean13('2' . str_pad((string) ($businessId % 10000), 4, '0', STR_PAD_LEFT)
                    . str_pad((string) ($n % 10000000), 7, '0', STR_PAD_LEFT));

                $taken = DB::table('product_variants')->where('business_id', $businessId)
                    ->where(fn ($q) => $q->where('sku', $sku)->orWhere('barcode', $barcode))->exists();

                if (! $taken) {
                    return [$sku, $barcode];
                }
            }

            throw new \RuntimeException('Could not generate a free SKU.');
        });
    }

    public static function svg(string $code, int $height = 50): ?string
{
    if ($code === '') {
        return null;
    }

    try {
        $g = new BarcodeGeneratorSVG();

        return $g->getBarcode($code, self::validEan13($code) ? $g::TYPE_EAN_13 : $g::TYPE_CODE_128, 2, $height);
    } catch (\Throwable $e) {
        return null;
    }
}
}
