<?php

namespace App\Providers;

use App\Models\ProductVariant;
use App\Services\ActivityLogger;
use App\Services\ItemCodes;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use App\Models\DocumentText;
use App\Models\Invoice;
use App\Models\Quotation;

class ActivityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        ActivityLogger::register();

        // Every variant gets a SKU and an EAN-13 barcode when none is given
        Event::listen('eloquent.saving: ' . ProductVariant::class, function (ProductVariant $v) {
            $needSku = trim((string) $v->sku) === '';
            $needCode = trim((string) $v->barcode) === '';

            if (! $needSku && ! $needCode) {
                return;
            }

            $bid = $v->business_id ?: auth()->user()?->business_id;
            if (! $bid) {
                return;
            }

            [$sku, $code] = ItemCodes::next((int) $bid);

            if ($needSku) {
                $v->sku = $sku;
            }
            if ($needCode) {
                $v->barcode = $code;
            }
        });

        // A new invoice or quotation starts with the shop's default terms and payment details
foreach (['invoice' => Invoice::class, 'quotation' => Quotation::class] as $type => $class) {
    Event::listen("eloquent.creating: {$class}", function ($doc) use ($type) {
        if (! auth()->user()?->business_id) {
            return;
        }

        foreach (['terms', 'payment'] as $kind) {
            if ($doc->getAttribute($kind . '_text') !== null) {
                continue;
            }

            $t = DocumentText::active()->where('kind', $kind)->where('default_' . $type, true)->first();
            if ($t) {
                $doc->setAttribute($kind . '_text', $t->body);
                $doc->setAttribute($kind . '_text_id', $t->id);
            }
        }
    });
}
    }
}
