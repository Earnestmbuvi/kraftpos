<?php

use App\Models\ProductVariant;
use App\Services\BranchService;
use App\Services\ItemCodes;
use Livewire\Volt\Component;
use Picqer\Barcode\BarcodeGeneratorSVG;

new class extends Component
{
    public const SIZES = [
        '50x30' => ['w' => 50, 'h' => 30, 'bar' => 11, 'label' => 'Roll label 50 x 30 mm'],
        '40x25' => ['w' => 40, 'h' => 25, 'bar' => 8, 'label' => 'Roll label 40 x 25 mm'],
        'a4' => ['w' => 63.5, 'h' => 33.9, 'bar' => 12, 'label' => 'A4 sheet, 3 columns x 8 rows'],
    ];

    public string $search = '';
    public array $items = [];   // [variantId => copies]
    public string $size = '50x30';
    public bool $showName = true;
    public bool $showPrice = true;
    public bool $showCode = true;

   public function mount(): void
{
    abort_unless(auth()->user()->can('manage-products'), 403);

    if ($id = (int) request()->query('variant')) {
        $v = ProductVariant::active()->find($id);
        if ($v) {
            $this->ensureCodes($v);
            $this->items[$v->id] = 1;
        }
    }
}

    private function svg(string $code): ?string
    {
        if ($code === '') {
            return null;
        }

        try {
            $g = new BarcodeGeneratorSVG();
            $type = ItemCodes::validEan13($code) ? $g::TYPE_EAN_13 : $g::TYPE_CODE_128;

            return $g->getBarcode($code, $type, 2, 50);
        } catch (\Throwable $e) {
            return null; // a code the format cannot hold
        }
    }

    // Older items may have no codes yet: saving fills them in
    private function ensureCodes(ProductVariant $v): void
    {
        if (trim((string) $v->sku) === '' || trim((string) $v->barcode) === '') {
            $v->save();
        }
    }

    public function with(): array
    {
        $term = trim($this->search);

        $results = $term === '' ? collect() : ProductVariant::with('product')->active()
            ->whereHas('product', fn ($p) => $p->active())
            ->where(function ($q) use ($term) {
                $q->where('sku', 'like', "%{$term}%")
                  ->orWhere('barcode', 'like', "%{$term}%")
                  ->orWhere('name', 'like', "%{$term}%")
                  ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$term}%"));
            })->orderBy('product_id')->limit(8)->get();

        $variants = ProductVariant::with('product')->whereIn('id', array_keys($this->items))->get()->keyBy('id');

        $labels = [];
        foreach ($this->items as $id => $copies) {
            $v = $variants->get($id);
            if (! $v) {
                continue;
            }

            $svg = $this->svg((string) $v->barcode);
            $name = $v->product->name . ($v->name !== 'Default' ? ' - ' . $v->name : '');

            for ($i = 0; $i < max(1, min(500, (int) $copies)); $i++) {
                $labels[] = ['name' => $name, 'price' => $v->selling_price, 'code' => (string) $v->barcode, 'sku' => $v->sku, 'svg' => $svg];
                if (count($labels) >= 2000) {
                    break 2;
                }
            }
        }

        return [
            'results' => $results, 'variants' => $variants, 'labels' => $labels,
            'sizes' => self::SIZES, 'dim' => self::SIZES[$this->size] ?? self::SIZES['50x30'],
        ];
    }

    public function addVariant(int $id): void
    {
        $v = ProductVariant::with('product')->active()->findOrFail($id); // tenant scope applies
        $this->ensureCodes($v);

        $this->items[$id] = $this->items[$id] ?? 1;
        $this->search = '';
    }

    public function addAll(): void
    {
        $all = ProductVariant::with('product')->active()
            ->whereHas('product', fn ($p) => $p->active())
            ->orderBy('product_id')->limit(200)->get();

        foreach ($all as $v) {
            $this->ensureCodes($v);
            $this->items[$v->id] = $this->items[$v->id] ?? 1;
        }
    }

    // One label for each unit in stock at this branch (handy after receiving goods)
    public function copiesFromStock(): void
    {
        foreach (ProductVariant::whereIn('id', array_keys($this->items))->get() as $v) {
            $this->items[$v->id] = max(1, min(500, BranchService::quantity($v, BranchService::currentId())));
        }
    }

    public function removeItem(int $id): void
    {
        unset($this->items[$id]);
    }

    public function clear(): void
    {
        $this->reset('items');
    }
}; ?>

<div class="space-y-6">
    <div class="space-y-4 print:hidden">
        <x-card class="p-6 space-y-4">
            <h3 class="text-lg font-medium text-gray-900">Choose items</h3>

            <div class="relative">
                <x-text-input wire:model.live.debounce.300ms="search" type="search" placeholder="Search by name, SKU or barcode..." class="block w-full" />
                @if ($results->count())
                    <div class="absolute z-20 mt-1 w-full rounded-lg border border-gray-200 bg-white shadow-lg">
                        @foreach ($results as $r)
                            <button type="button" wire:key="lr-{{ $r->id }}" wire:click="addVariant({{ $r->id }})"
                                    class="flex w-full items-center justify-between px-4 py-2 text-left text-sm hover:bg-gray-50">
                                <span>{{ $r->product->name }}@if ($r->name !== 'Default') - {{ $r->name }}@endif</span>
                                <span class="text-gray-500">{{ $r->sku }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="flex flex-wrap gap-4 text-sm">
                <button type="button" wire:click="addAll" class="text-brand-600 hover:underline">Add every item (up to 200)</button>
                @if (count($items))
                    <button type="button" wire:click="copiesFromStock" class="text-brand-600 hover:underline">One label per unit in stock</button>
                    <button type="button" wire:click="clear" class="text-gray-500 hover:underline">Clear list</button>
                @endif
            </div>

            @if (count($items))
                <div class="max-h-64 divide-y divide-gray-100 overflow-y-auto rounded-lg border border-gray-200 text-sm">
                    @foreach ($items as $id => $copies)
                        @php $v = $variants->get($id); @endphp
                        @continue(! $v)
                        <div wire:key="li-{{ $id }}" class="flex items-center justify-between gap-3 px-3 py-2">
                            <span>{{ $v->product->name }}@if ($v->name !== 'Default') - {{ $v->name }}@endif <span class="text-xs text-gray-500">{{ $v->barcode }}</span></span>
                            <span class="flex items-center gap-3">
                                <x-text-input wire:model.live.debounce.400ms="items.{{ $id }}" type="number" min="1" max="500" class="w-20" />
                                <button type="button" wire:click="removeItem({{ $id }})" class="text-gray-500 hover:underline">Remove</button>
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-card>

        <x-card class="p-6 space-y-4">
            <h3 class="text-lg font-medium text-gray-900">Label</h3>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div>
                    <x-input-label for="l_size" value="Size" />
                    <select wire:model.live="size" id="l_size" class="mt-1 block w-full">
                        @foreach ($sizes as $key => $s) <option value="{{ $key }}">{{ $s['label'] }}</option> @endforeach
                    </select>
                </div>
                <div class="flex flex-wrap items-end gap-4 text-sm text-gray-700 md:col-span-2">
                    <label class="flex items-center gap-2"><input type="checkbox" wire:model.live="showName" class="rounded"> Name</label>
                    <label class="flex items-center gap-2"><input type="checkbox" wire:model.live="showPrice" class="rounded"> Price</label>
                    <label class="flex items-center gap-2"><input type="checkbox" wire:model.live="showCode" class="rounded"> Code under the bars</label>
                </div>
            </div>
            <p class="text-xs text-gray-500">
                In the print window set the paper to this label size, Margins to None, and untick Headers and footers. On an A4 sheet, use A4 and a scale of 100%.
            </p>
            <x-primary-button type="button" onclick="window.print()" :disabled="! count($labels)">Print {{ count($labels) }} label(s)</x-primary-button>
        </x-card>
    </div>

    <style>
        .lbl { box-sizing: border-box; width: {{ $dim['w'] }}mm; height: {{ $dim['h'] }}mm; padding: 1.5mm; overflow: hidden; background: #fff; color: #000;
               display: flex; flex-direction: column; justify-content: space-between; font-family: Arial, Helvetica, sans-serif; border: 1px dashed #cbd5e1; }
        .lbl .nm { font-size: 8pt; font-weight: 700; line-height: 1.1; max-height: 2.3em; overflow: hidden; }
        .lbl .pr { font-size: 9pt; font-weight: 700; text-align: right; }
        .lbl .bc svg { width: 100%; height: {{ $dim['bar'] }}mm; display: block; }
        .lbl .cd { font-size: 7pt; text-align: center; letter-spacing: .5px; }
        #labels { display: flex; flex-wrap: wrap; gap: 2mm; }
        @media print {
            @page { size: {{ $size === 'a4' ? 'A4' : $dim['w'] . 'mm ' . $dim['h'] . 'mm' }}; margin: {{ $size === 'a4' ? '10mm 7mm' : '0' }}; }
            .lbl { border: none; @if ($size === 'a4') break-inside: avoid; @else page-break-after: always; break-after: page; @endif }
            #labels { gap: {{ $size === 'a4' ? '2.5mm' : '0' }}; }
        }
    </style>

    <div id="labels">
        @forelse ($labels as $i => $l)
            <div class="lbl" wire:key="lbl-{{ $i }}">
                @if ($showName) <div class="nm">{{ $l['name'] }}</div> @endif
                <div class="bc">
                    @if ($l['svg']) {!! $l['svg'] !!} @else <div class="cd">Cannot make a barcode for "{{ $l['code'] }}"</div> @endif
                </div>
                <div class="flex items-end justify-between">
                    @if ($showCode) <div class="cd">{{ $l['code'] }}</div> @else <div></div> @endif
                    @if ($showPrice) <div class="pr">Kshs {{ number_format($l['price'], 2) }}</div> @endif
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500 print:hidden">Add items above and the labels appear here.</p>
        @endforelse
    </div>
</div>
