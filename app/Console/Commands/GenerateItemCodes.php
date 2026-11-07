<?php

namespace App\Console\Commands;

use App\Models\ProductVariant;
use Illuminate\Console\Command;

class GenerateItemCodes extends Command
{
    protected $signature = 'kraftpos:generate-codes';

    protected $description = 'Give every variant without a SKU or barcode a generated one';

    public function handle(): int
    {
        $done = 0;

        ProductVariant::withoutGlobalScopes()
            ->where(fn ($q) => $q->whereNull('sku')->orWhere('sku', '')->orWhereNull('barcode')->orWhere('barcode', ''))
            ->chunkById(200, function ($variants) use (&$done) {
                foreach ($variants as $v) {
                    $v->save();
                    $done++;
                }
            });

        $this->info("Codes generated for {$done} item(s).");

        return self::SUCCESS;
    }
}
