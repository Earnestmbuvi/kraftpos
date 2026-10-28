<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Services\BatchService;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use App\Models\Branch;

new class extends Component
{
    use WithPagination, WithFileUploads;

    public string $search = '';
    public bool $showForm = false;

    public string $mode = 'single'; // single | variable
    public bool $isService = false;
    public bool $tracksBatches = false;
    public bool $wasTracked = false;
    public ?int $editingId = null;
    public ?int $editingVariantId = null;

    // shared
    public string $name = '';
    public $category_id = null;
    public string $unit = 'pcs';
    public string $tax_class = 'standard';
    public $photo = null;
    public ?string $existingImage = null;
    public bool $removePhoto = false;

    // single
    public ?string $sku = null;
    public ?string $barcode = null;
    public $cost_price = 0;
    public $selling_price = '';
    public $stock_quantity = 0;
    public $low_stock_threshold = 0;

    // variable
    public array $optionIds = [];
    public array $valueIds = [];
    public array $rows = [];
    public $bulkCost = '';
    public $bulkPrice = '';
    public $bulkStock = '';
    public array $branchIds = [];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $term = trim($this->search);

        return [
            'units' => Unit::active()->orderBy('name')->pluck('name'),
            'categories' => Category::with('parent')->active()->orderBy('name')->get(),
            'options' => ProductOption::active()
                ->with(['values' => fn ($q) => $q->active()])
                ->orderBy('name')->get(),
            'products' => Product::with(['category', 'variants'])
                ->when($term !== '', function ($q) use ($term) {
                    $q->where(function ($q) use ($term) {
                        $q->where('name', 'like', "%{$term}%")
                          ->orWhereHas('variants', fn ($v) => $v
                              ->where('sku', 'like', "%{$term}%")
                              ->orWhere('barcode', 'like', "%{$term}%"));
                    });
                })
                ->orderBy('name')
                ->paginate(15),
                'branches' => Branch::active()->orderByDesc('is_main')->orderBy('name')->get(),
        ];
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()->can('manage-products'), 403);
    }

    private function resetForm(): void
    {
        $this->reset(
            'mode', 'isService', 'tracksBatches', 'wasTracked', 'editingId', 'editingVariantId',
            'name', 'category_id', 'unit', 'tax_class',
            'photo', 'existingImage', 'removePhoto',
            'sku', 'barcode', 'cost_price', 'selling_price', 'stock_quantity',
            'low_stock_threshold', 'optionIds', 'valueIds', 'rows',
            'bulkCost', 'bulkPrice', 'bulkStock','branchIds'
        );
        $this->resetErrorBag();

        foreach (ProductOption::active()->pluck('id') as $id) {
            $this->valueIds[$id] = [];
        }
    }

    public function openCreate(): void
    {
        $this->authorizeManage();
        $this->resetForm();
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function setKind(bool $service): void
    {
        if ($this->editingId) {
            return; // kind is fixed once a product exists
        }
        $this->isService = $service;
        if ($service) {
            $this->mode = 'single';
            $this->tracksBatches = false;
        }
        $this->resetErrorBag();
    }

    public function setMode(string $mode): void
    {
        if ($this->editingId || $this->isService || ! in_array($mode, ['single', 'variable'])) {
            return; // type is fixed once a product exists
        }
        $this->mode = $mode;
        $this->resetErrorBag();
    }

    public function edit(int $id): void
    {
        $this->authorizeManage();
        $product = Product::with('variants')->findOrFail($id); // tenant scope blocks other shops' IDs

        $this->resetForm();
        $this->editingId = $product->id;
        $this->mode = $product->type;
        $this->isService = (bool) $product->is_service;
        $this->tracksBatches = (bool) $product->tracks_batches;
        $this->wasTracked = $this->tracksBatches;
        $this->name = $product->name;
        $this->category_id = $product->category_id;
        $this->unit = $product->unit;
        $this->tax_class = $product->tax_class ?? 'standard';
        $this->branchIds = $product->branches()->pluck('branches.id')->map(fn ($i) => (string) $i)->all();
        $this->existingImage = $product->image_url;

        if ($product->type === 'single') {
            $variant = $product->variants->first();
            abort_unless($variant, 404);

            $this->editingVariantId = $variant->id;
            $this->sku = $variant->sku;
            $this->barcode = $variant->barcode;
            $this->cost_price = $variant->cost_price;
            $this->selling_price = $variant->selling_price;
            $this->stock_quantity = $variant->stock_quantity;
            $this->low_stock_threshold = $variant->low_stock_threshold;
        } else {
            $options = ProductOption::active()->with('values')->get()->keyBy('name');

            foreach ($product->variants as $v) {
                $this->rows[] = [
                    'id' => $v->id,
                    'name' => $v->name,
                    'options' => $v->options ?? [],
                    'sku' => $v->sku,
                    'barcode' => $v->barcode,
                    'cost_price' => $v->cost_price,
                    'selling_price' => $v->selling_price,
                    'stock_quantity' => $v->stock_quantity,
                    'low_stock_threshold' => $v->low_stock_threshold,
                    'is_active' => (bool) $v->is_active,
                ];

                foreach (($v->options ?? []) as $optName => $val) {
                    $opt = $options->get($optName);
                    $value = $opt?->values->firstWhere('value', $val);
                    if (! $value || ! $value->is_active) {
                        continue;
                    }
                    if (! in_array($opt->id, $this->optionIds)) {
                        $this->optionIds[] = $opt->id;
                    }
                    if (! in_array($value->id, $this->valueIds[$opt->id] ?? [])) {
                        $this->valueIds[$opt->id][] = $value->id;
                    }
                }
            }
        }

        $this->showForm = true;
    }

    public function generate(): void
    {
        $this->authorizeManage();
        $this->resetErrorBag('optionIds');

        $ids = array_map('intval', $this->optionIds);

        if (count($ids) < 1 || count($ids) > 3) {
            $this->addError('optionIds', 'Choose between 1 and 3 options.');
            return;
        }

        $groups = [];
        $options = ProductOption::active()
            ->with(['values' => fn ($q) => $q->active()])
            ->whereIn('id', $ids)->orderBy('name')->get();

        foreach ($options as $option) {
            $picked = array_map('intval', $this->valueIds[$option->id] ?? []);
            $values = $option->values->whereIn('id', $picked)->pluck('value')->all();

            if (count($values) === 0) {
                $this->addError('optionIds', "Pick at least one value for {$option->name}.");
                return;
            }
            $groups[$option->name] = $values;
        }

        $combos = [[]];
        foreach ($groups as $optionName => $values) {
            $next = [];
            foreach ($combos as $combo) {
                foreach ($values as $v) {
                    $next[] = $combo + [$optionName => $v];
                }
            }
            $combos = $next;
        }

        if (count($combos) > 100) {
            $this->addError('optionIds', 'That makes more than 100 variants. Pick fewer values.');
            return;
        }

        $existing = collect($this->rows)->pluck('name')->all();

        foreach ($combos as $combo) {
            $variantName = implode(' / ', $combo);
            if (in_array($variantName, $existing)) {
                continue;
            }
            $this->rows[] = [
                'id' => null,
                'name' => $variantName,
                'options' => $combo,
                'sku' => null,
                'barcode' => null,
                'cost_price' => 0,
                'selling_price' => '',
                'stock_quantity' => 0,
                'low_stock_threshold' => 0,
                'is_active' => true,
            ];
        }
    }

    public function applyToNew(): void
    {
        foreach ($this->rows as $i => $row) {
            if ($row['id']) {
                continue;
            }
            if ($this->bulkCost !== '') $this->rows[$i]['cost_price'] = $this->bulkCost;
            if ($this->bulkPrice !== '') $this->rows[$i]['selling_price'] = $this->bulkPrice;
            if ($this->bulkStock !== '' && ! $this->tracksBatches) $this->rows[$i]['stock_quantity'] = $this->bulkStock;
        }
    }

    public function removeRow(int $index): void
    {
        if (isset($this->rows[$index]) && ! $this->rows[$index]['id']) {
            unset($this->rows[$index]);
            $this->rows = array_values($this->rows);
        }
    }

    public function save(): void
    {
        $this->authorizeManage();

        $this->mode === 'variable' ? $this->saveVariable() : $this->saveSingle();
    }

    private function sharedRules(int $businessId): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'category_id' => ['nullable', 'integer',
                Rule::exists('categories', 'id')->where('business_id', $businessId)],
            'unit' => ['required', Rule::exists('units', 'name')
                ->where('business_id', $businessId)->where('is_active', true)],
            'tax_class' => ['required', Rule::in(['standard', 'zero', 'exempt'])],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'tracksBatches' => ['boolean'],
            'branchIds' => ['array'],
            'branchIds.*' => ['integer', Rule::exists('branches', 'id')->where('business_id', $businessId)],
        ];
    }

    private function storePhoto(Product $product): void
    {
        if (($this->removePhoto || $this->photo) && $product->image_path) {
            Storage::disk('public')->delete($product->image_path);
            $product->update(['image_path' => null]);
        }

        if ($this->photo) {
            $path = $this->photo->store('products/' . $product->business_id, 'public');
            $product->update(['image_path' => $path]);
        }
    }

    private function saveSingle(): void
    {
        $businessId = auth()->user()->business_id;

        $this->category_id = $this->category_id ?: null;
        $this->sku = trim((string) $this->sku) === '' ? null : trim($this->sku);
        $this->barcode = trim((string) $this->barcode) === '' ? null : trim($this->barcode);

        $rules = $this->sharedRules($businessId) + [
            'sku' => ['nullable', 'string', 'max:50',
                Rule::unique('product_variants', 'sku')
                    ->where('business_id', $businessId)->ignore($this->editingVariantId)],
            'barcode' => ['nullable', 'string', 'max:50',
                Rule::unique('product_variants', 'barcode')
                    ->where('business_id', $businessId)->ignore($this->editingVariantId)],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'low_stock_threshold' => ['required', 'integer', 'min:0'],
        ];

        if (! $this->editingId) {
            $rules['stock_quantity'] = ['required', 'integer'];
        }

        if ($this->isService) {
            $this->stock_quantity = 0;
            $this->low_stock_threshold = 0;
            $this->tracksBatches = false;
        }

        if ($this->tracksBatches && ! $this->isService && ! $this->editingId) {
            $this->stock_quantity = 0; // tracked stock is received on the Stock screen, with an expiry date
        }

        $this->validate($rules);

        $editing = (bool) $this->editingId;

        DB::transaction(function () {
            if ($this->editingId) {
                $product = Product::findOrFail($this->editingId);
                $product->update([
                    'name' => $this->name,
                    'category_id' => $this->category_id,
                    'unit' => $this->unit,
                    'tax_class' => $this->tax_class,
                ]);
                $product->variants()->findOrFail($this->editingVariantId)->update([
                    'sku' => $this->sku,
                    'barcode' => $this->barcode,
                    'cost_price' => $this->cost_price ?: 0,
                    'selling_price' => $this->selling_price,
                    'low_stock_threshold' => $this->low_stock_threshold,
                ]);

                if ($this->tracksBatches && ! $this->wasTracked && ! $this->isService) {
                    BatchService::enableTracking($product->refresh());
                }
            } else {
                $product = Product::create([
                    'name' => $this->name,
                    'category_id' => $this->category_id,
                    'type' => 'single',
                    'is_service' => $this->isService,
                    'tracks_batches' => $this->tracksBatches && ! $this->isService,
                    'unit' => $this->unit,
                    'tax_class' => $this->tax_class,
                ]);
                $variant = $product->variants()->create([
                    'name' => 'Default',
                    'sku' => $this->sku,
                    'barcode' => $this->barcode,
                    'cost_price' => $this->cost_price ?: 0,
                    'selling_price' => $this->selling_price,
                    'stock_quantity' => $this->stock_quantity,
                    'low_stock_threshold' => $this->low_stock_threshold,
                ]);
                StockService::recordOpening($variant);
            }

            $this->storePhoto($product);
            $this->syncBranches($product);
        });

        $this->dispatch('notify', message: ($this->isService ? 'Service' : 'Product') . ($editing ? ' updated.' : ' added.'));
        $this->closeForm();
    }

    private function saveVariable(): void
    {
        $businessId = auth()->user()->business_id;
        $this->category_id = $this->category_id ?: null;

        foreach ($this->rows as $i => $row) {
            $this->rows[$i]['sku'] = trim((string) $row['sku']) === '' ? null : trim($row['sku']);
            $this->rows[$i]['barcode'] = trim((string) $row['barcode']) === '' ? null : trim($row['barcode']);

            // Tracked stock is received on the Stock screen, with an expiry date
            if ($this->tracksBatches && ! $row['id']) {
                $this->rows[$i]['stock_quantity'] = 0;
            }
        }

        $this->validate($this->sharedRules($businessId) + [
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.sku' => ['nullable', 'string', 'max:50'],
            'rows.*.barcode' => ['nullable', 'string', 'max:50'],
            'rows.*.cost_price' => ['nullable', 'numeric', 'min:0'],
            'rows.*.selling_price' => ['required', 'numeric', 'min:0'],
            'rows.*.stock_quantity' => ['required', 'integer'],
            'rows.*.low_stock_threshold' => ['required', 'integer', 'min:0'],
        ], [
            'rows.required' => 'Generate the variants first.',
            'rows.min' => 'Generate the variants first.',
        ]);

        foreach (['sku', 'barcode'] as $field) {
            $seen = [];
            foreach ($this->rows as $i => $row) {
                $v = $row[$field];
                if ($v === null) continue;

                $dupInForm = in_array($v, $seen);
                $seen[] = $v;

                $dupInDb = ProductVariant::where($field, $v)
                    ->when($row['id'], fn ($q) => $q->where('id', '!=', $row['id']))
                    ->exists();

                if ($dupInForm || $dupInDb) {
                    $this->addError("rows.$i.$field", "This $field is already used.");
                    return;
                }
            }
        }

        $editing = (bool) $this->editingId;

        DB::transaction(function () {
            $data = [
                'name' => $this->name,
                'category_id' => $this->category_id,
                'unit' => $this->unit,
                'tax_class' => $this->tax_class,
            ];

            if ($this->editingId) {
                $product = Product::findOrFail($this->editingId);
                $product->update($data);
            } else {
                $product = Product::create($data + ['type' => 'variable', 'tracks_batches' => $this->tracksBatches]);
            }

            foreach ($this->rows as $row) {
                $attrs = [
                    'sku' => $row['sku'],
                    'barcode' => $row['barcode'],
                    'cost_price' => $row['cost_price'] ?: 0,
                    'selling_price' => $row['selling_price'],
                    'low_stock_threshold' => $row['low_stock_threshold'],
                    'is_active' => (bool) $row['is_active'],
                ];

                if ($row['id']) {
                    $product->variants()->findOrFail($row['id'])->update($attrs);
                } else {
                    $variant = $product->variants()->create($attrs + [
                        'name' => $row['name'],
                        'options' => $row['options'],
                        'stock_quantity' => (int) $row['stock_quantity'],
                    ]);
                    StockService::recordOpening($variant);
                }
            }

            // Turning tracking on for a product that already has stock
            if ($this->editingId && $this->tracksBatches && ! $this->wasTracked) {
                BatchService::enableTracking($product->refresh());
            }

            $this->storePhoto($product);
            $this->syncBranches($product);
        });

        $this->dispatch('notify', message: 'Product' . ($editing ? ' updated.' : ' added.'));
        $this->closeForm();
    }

    public function toggle(int $id): void
    {
        $this->authorizeManage();
        $product = Product::findOrFail($id);
        $product->is_active ? $product->disable() : $product->enable();
    }
    private function syncBranches(Product $product): void
{
    $product->branches()->sync(array_values(array_unique(array_map('intval', $this->branchIds))));
}
}; ?>

<div class="space-y-6">
    {{-- Toolbar --}}
    <div class="flex flex-col sm:flex-row gap-3 sm:items-center">
        <x-text-input wire:model.live.debounce.300ms="search" type="search"
                      placeholder="Search by name, SKU or barcode..." class="block w-full" />
        @can('manage-products')
            <a href="{{ route('products.import') }}" wire:navigate
               class="inline-flex items-center justify-center whitespace-nowrap rounded-lg border border-brand-500 px-4 py-2 text-sm font-semibold text-brand-600 hover:bg-brand-50">Import CSV</a>
        @endcan
        <a href="{{ route('products.export') }}"
           class="inline-flex items-center justify-center whitespace-nowrap rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Export CSV</a>
        @can('manage-products')
            <x-primary-button type="button" wire:click="openCreate" class="whitespace-nowrap">
                + Add product
            </x-primary-button>
        @endcan
    </div>

    {{-- List --}}
    <x-card class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Product</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">SKU</th>
                    @can('view-cost-prices')
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Cost</th>
                    @endcan
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Price</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tax</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Stock</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse ($products as $product)
                    @php
                        $variants = $product->variants;
                        $first = $variants->first();
                        $isSingle = $product->type === 'single';
                        $service = (bool) $product->is_service;
                        $stock = $variants->sum(fn ($v) => $v->view_stock);
                        $low = ! $service && $variants->contains(fn ($v) => $v->view_stock <= $v->low_stock_threshold);
                        $min = $variants->min('selling_price');
                        $max = $variants->max('selling_price');
                        $taxLabel = ['standard' => 'Standard', 'zero' => 'Zero rated', 'exempt' => 'Exempt'][$product->tax_class ?? 'standard'] ?? 'Standard';
                    @endphp
                    <tr wire:key="prod-{{ $product->id }}" class="{{ $product->is_active ? '' : 'opacity-50' }}">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <x-product-image :url="$product->image_url" class="h-10 w-10 shrink-0 rounded-lg" />
                                <span>
                                    {{ $product->name }}
                                    @unless ($isSingle)
                                        <span class="ml-1 text-xs text-gray-500">({{ $variants->count() }} variants)</span>
                                    @endunless
                                    @if ($product->tracks_batches)
                                        <x-badge color="orange" class="ml-1">Batches</x-badge>
                                    @endif
                                </span>
                            </div>
                        </td>
                        <td class="px-4 py-3">{{ $product->category?->name ?? '-' }}</td>
                        <td class="px-4 py-3">{{ $isSingle ? ($first?->sku ?? '-') : '-' }}</td>
                        @can('view-cost-prices')
                            <td class="px-4 py-3 text-right">{{ $isSingle ? number_format($first?->cost_price ?? 0, 2) : '-' }}</td>
                        @endcan
                        <td class="px-4 py-3 text-right">
                            @if ($isSingle)
                                {{ number_format($first?->selling_price ?? 0, 2) }}
                            @else
                                {{ number_format($min, 2) }} - {{ number_format($max, 2) }}
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600">{{ $taxLabel }}</td>
                        <td class="px-4 py-3 text-right {{ $low ? 'text-red-600 font-semibold' : '' }}">
                            @if ($service)
                                <x-badge color="orange">Service</x-badge>
                            @else
                                {{ $stock }} {{ $product->unit }}
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <x-badge :color="$product->is_active ? 'green' : 'red'">{{ $product->is_active ? 'Active' : 'Disabled' }}</x-badge>
                        </td>
                        <td class="px-4 py-3 text-right space-x-3 whitespace-nowrap">
                            @can('manage-products')
                                <button wire:click="edit({{ $product->id }})" class="text-brand-600 hover:underline">
                                    {{ $isSingle ? 'Edit' : 'Edit variants' }}
                                </button>
                                <button wire:click="toggle({{ $product->id }})" class="text-gray-600 hover:underline">
                                    {{ $product->is_active ? 'Disable' : 'Enable' }}
                                </button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-6 text-center text-gray-500">No products found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-card>

    {{ $products->links() }}

    {{-- Slide-over form --}}
    @can('manage-products')
        @if ($showForm)
            <div class="fixed inset-0 z-50 flex justify-end">
                <div class="absolute inset-0 bg-black/40" wire:click="closeForm"></div>

                <div class="relative w-full max-w-4xl h-full overflow-y-auto bg-gray-50 shadow-xl">
                    <form wire:submit="save">
                        <div class="sticky top-0 z-10 flex items-center justify-between border-b border-gray-200 bg-white px-6 py-4">
                            <h3 class="text-lg font-semibold text-gray-900">
                                {{ $editingId ? 'Edit' : 'Add' }} {{ $isService ? 'service' : 'product' }}
                            </h3>
                            <button type="button" wire:click="closeForm" class="text-2xl leading-none text-gray-500 hover:text-gray-800" aria-label="Close">&times;</button>
                        </div>

                        <div class="space-y-6 p-6">
                            {{-- Basics --}}
                            <x-card class="p-6 space-y-4">
                                <div class="inline-flex rounded-lg border border-gray-300 overflow-hidden">
                                    <button type="button" wire:click="setKind(false)" @disabled($editingId)
                                            class="px-4 py-2 text-sm font-medium {{ ! $isService ? 'bg-brand-500 text-white' : 'bg-white text-gray-700' }}">Product</button>
                                    <button type="button" wire:click="setKind(true)" @disabled($editingId)
                                            class="px-4 py-2 text-sm font-medium {{ $isService ? 'bg-brand-500 text-white' : 'bg-white text-gray-700' }}">Service</button>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div class="md:col-span-2">
                                        <x-input-label for="name" value="Name *" />
                                        <x-text-input wire:model="name" id="name" type="text" class="mt-1 block w-full"
                                                      placeholder="e.g. Sugar 1kg, Haircut, or T-Shirt for variants" />
                                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                                    </div>

                                    @unless ($isService)
                                        <div>
                                            <x-input-label value="Does it have variants?" />
                                            <div class="mt-1 inline-flex rounded-lg border border-gray-300 overflow-hidden">
                                                <button type="button" wire:click="setMode('variable')"
                                                        @disabled($editingId)
                                                        class="px-4 py-2 text-sm font-medium {{ $mode === 'variable' ? 'bg-brand-500 text-white' : 'bg-white text-gray-700' }}">Yes</button>
                                                <button type="button" wire:click="setMode('single')"
                                                        @disabled($editingId)
                                                        class="px-4 py-2 text-sm font-medium {{ $mode === 'single' ? 'bg-brand-500 text-white' : 'bg-white text-gray-700' }}">No</button>
                                            </div>
                                            @if ($editingId)
                                                <p class="mt-1 text-xs text-gray-500">Type can't change after saving.</p>
                                            @endif
                                        </div>
                                    @endunless

                                    @if ($branches->count() > 1)
    <div>
        <p class="text-sm font-medium text-gray-700">Sold at</p>
        <p class="text-xs text-gray-500">Leave all unticked to sell it at every branch. Tick some to limit it to those branches.</p>
        <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-3">
            @foreach ($branches as $b)
                <label wire:key="pb-{{ $b->id }}" class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" wire:model="branchIds" value="{{ $b->id }}" class="rounded"> {{ $b->name }}
                </label>
            @endforeach
        </div>
        <x-input-error :messages="collect($errors->get('branchIds.*'))->flatten()->all()" class="mt-2" />
    </div>
@endif

                                    <div>
                                        <x-input-label for="category_id" value="Category" />
                                        <select wire:model="category_id" id="category_id" class="mt-1 block w-full">
                                            <option value="">None</option>
                                            @foreach ($categories as $c)
                                                <option value="{{ $c->id }}">{{ $c->parent ? $c->parent->name . ' → ' . $c->name : $c->name }}</option>
                                            @endforeach
                                        </select>
                                        <x-input-error :messages="$errors->get('category_id')" class="mt-2" />
                                    </div>

                                    <div>
                                        <x-input-label for="unit" value="Unit" />
                                        <select wire:model="unit" id="unit" class="mt-1 block w-full">
                                            @foreach ($units as $u)
                                                <option value="{{ $u }}">{{ $u }}</option>
                                            @endforeach
                                        </select>
                                        <x-input-error :messages="$errors->get('unit')" class="mt-2" />
                                    </div>

                                    <div>
                                        <x-input-label for="tax_class" value="Tax" />
                                        <select wire:model="tax_class" id="tax_class" class="mt-1 block w-full">
                                            <option value="standard">Standard rated</option>
                                            <option value="zero">Zero rated</option>
                                            <option value="exempt">Exempt</option>
                                        </select>
                                        <x-input-error :messages="$errors->get('tax_class')" class="mt-2" />
                                    </div>
                                </div>

                                @unless ($isService)
                                    <div>
                                        <label class="flex items-start gap-2 text-sm text-gray-700">
                                            <input type="checkbox" wire:model.live="tracksBatches" class="mt-1 rounded" @disabled($wasTracked)>
                                            <span>
                                                <span class="font-medium">Track batches and expiry dates</span>
                                                <span class="block text-xs text-gray-500">
                                                    For perishables. Stock is received on the Stock screen with an expiry date, and the soonest-expiring batch is sold first. This cannot be switched off later.
                                                </span>
                                            </span>
                                        </label>
                                        <x-input-error :messages="$errors->get('tracksBatches')" class="mt-2" />
                                    </div>
                                @endunless

                                <div class="flex items-center gap-4">
                                    <div class="h-20 w-20 overflow-hidden rounded-lg border border-gray-200">
                                        @if ($photo)
                                            <img src="{{ $photo->temporaryUrl() }}" class="h-full w-full object-contain">
                                        @elseif ($existingImage && ! $removePhoto)
                                            <img src="{{ $existingImage }}" class="h-full w-full object-contain">
                                        @else
                                            <x-product-image class="h-full w-full" />
                                        @endif
                                    </div>

                                    <div class="space-y-1">
                                        <x-input-label for="photo" value="Photo (optional, max 2 MB)" />
                                        <input wire:model="photo" id="photo" type="file" accept="image/png,image/jpeg,image/webp" class="text-sm">
                                        <div wire:loading wire:target="photo" class="text-xs text-gray-500">Uploading...</div>
                                        @if ($existingImage && ! $photo)
                                            <label class="flex items-center gap-2 text-xs text-gray-600">
                                                <input type="checkbox" wire:model="removePhoto" class="rounded"> Remove current photo
                                            </label>
                                        @endif
                                        <x-input-error :messages="$errors->get('photo')" />
                                    </div>
                                </div>
                            </x-card>

                            @if ($mode === 'single')
                                {{-- Single product / service fields --}}
                                <x-card class="p-6 space-y-4">
                                    <h4 class="font-medium text-gray-900">{{ $isService ? 'Pricing' : 'Pricing & inventory' }}</h4>
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                        <div>
                                            <x-input-label for="sku" value="SKU (optional)" />
                                            <x-text-input wire:model="sku" id="sku" type="text" class="mt-1 block w-full" />
                                            <x-input-error :messages="$errors->get('sku')" class="mt-2" />
                                        </div>
                                        <div>
                                            <x-input-label for="barcode" value="Barcode (optional)" />
                                            <x-text-input wire:model="barcode" id="barcode" type="text" class="mt-1 block w-full" />
                                            <x-input-error :messages="$errors->get('barcode')" class="mt-2" />
                                        </div>
                                        <div>
                                            <x-input-label for="cost_price" value="Cost price" />
                                            <x-text-input wire:model="cost_price" id="cost_price" type="number" step="0.01" min="0" class="mt-1 block w-full" />
                                            <x-input-error :messages="$errors->get('cost_price')" class="mt-2" />
                                        </div>
                                        <div>
                                            <x-input-label for="selling_price" value="Selling price *" />
                                            <x-text-input wire:model="selling_price" id="selling_price" type="number" step="0.01" min="0" class="mt-1 block w-full" />
                                            <x-input-error :messages="$errors->get('selling_price')" class="mt-2" />
                                        </div>
                                        @unless ($isService)
                                            @unless ($tracksBatches)
                                                <div>
                                                    <x-input-label for="stock_quantity" value="{{ $editingId ? 'Stock (use stock adjustment)' : 'Opening stock' }}" />
                                                    <x-text-input wire:model="stock_quantity" id="stock_quantity" type="number" step="1"
                                                                  class="mt-1 block w-full" :disabled="(bool) $editingId" />
                                                    <x-input-error :messages="$errors->get('stock_quantity')" class="mt-2" />
                                                </div>
                                            @endunless
                                            <div>
                                                <x-input-label for="low_stock_threshold" value="Low-stock alert at" />
                                                <x-text-input wire:model="low_stock_threshold" id="low_stock_threshold" type="number" step="1" min="0" class="mt-1 block w-full" />
                                                <x-input-error :messages="$errors->get('low_stock_threshold')" class="mt-2" />
                                            </div>
                                        @endunless
                                    </div>
                                    @if ($tracksBatches && ! $isService)
                                        <p class="text-xs text-gray-500">Stock for batch-tracked products is received on the Stock screen, with an expiry date.</p>
                                    @endif
                                </x-card>
                            @else
                                {{-- Variable product fields --}}
                                <x-card class="p-6 space-y-4">
                                    <h4 class="font-medium text-gray-900">What does it vary by?</h4>
                                    <p class="text-sm text-gray-500">Tick up to 3 options, then the values that apply. Manage the lists in Setup → Options.</p>

                                    @foreach ($options as $option)
                                        @php $ticked = in_array($option->id, array_map('intval', $optionIds)); @endphp
                                        <div wire:key="opt-{{ $option->id }}" class="rounded-lg border border-gray-200 p-4">
                                            <label class="flex items-center gap-2 font-medium text-gray-800">
                                                <input type="checkbox" wire:model.live="optionIds" value="{{ $option->id }}" class="rounded">
                                                {{ $option->name }}
                                            </label>

                                            @if ($ticked)
                                                <div class="mt-3 flex flex-wrap gap-4">
                                                    @forelse ($option->values as $value)
                                                        <label wire:key="val-{{ $value->id }}" class="flex items-center gap-2 text-sm text-gray-700">
                                                            <input type="checkbox" wire:model="valueIds.{{ $option->id }}" value="{{ $value->id }}" class="rounded">
                                                            {{ $value->value }}
                                                        </label>
                                                    @empty
                                                        <p class="text-sm text-gray-500">No active values. Add some in Setup → Options.</p>
                                                    @endforelse
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach

                                    <x-input-error :messages="$errors->get('optionIds')" />

                                    <x-secondary-button type="button" wire:click="generate">Generate variants</x-secondary-button>
                                </x-card>

                                @if (count($rows))
                                    <x-card class="p-6 space-y-4">
                                        <h4 class="font-medium text-gray-900">Variants ({{ count($rows) }})</h4>

                                        <div class="flex flex-wrap items-end gap-3 rounded-lg bg-gray-50 p-3">
                                            <span class="text-sm text-gray-600">Fill all new rows:</span>
                                            <x-text-input wire:model="bulkCost" type="number" step="0.01" min="0" placeholder="Cost" class="w-28" />
                                            <x-text-input wire:model="bulkPrice" type="number" step="0.01" min="0" placeholder="Price" class="w-28" />
                                            @unless ($tracksBatches)
                                                <x-text-input wire:model="bulkStock" type="number" step="1" placeholder="Stock" class="w-28" />
                                            @endunless
                                            <x-secondary-button type="button" wire:click="applyToNew">Apply</x-secondary-button>
                                        </div>

                                        <div class="overflow-x-auto">
                                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                                <thead class="bg-gray-50">
                                                    <tr>
                                                        <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase">Variant</th>
                                                        <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase">SKU</th>
                                                        <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase">Barcode</th>
                                                        <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase">Cost</th>
                                                        <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase">Price</th>
                                                        <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase">Stock</th>
                                                        <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase">Alert at</th>
                                                        <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase">Active</th>
                                                        <th></th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-gray-200">
                                                    @foreach ($rows as $i => $row)
                                                        <tr wire:key="row-{{ md5($row['name']) }}">
                                                            <td class="px-2 py-2 font-medium whitespace-nowrap">{{ $row['name'] }}</td>
                                                            <td class="px-2 py-2">
                                                                <x-text-input wire:model="rows.{{ $i }}.sku" type="text" class="w-28" />
                                                                <x-input-error :messages="$errors->get('rows.' . $i . '.sku')" />
                                                            </td>
                                                            <td class="px-2 py-2">
                                                                <x-text-input wire:model="rows.{{ $i }}.barcode" type="text" class="w-28" />
                                                                <x-input-error :messages="$errors->get('rows.' . $i . '.barcode')" />
                                                            </td>
                                                            <td class="px-2 py-2">
                                                                <x-text-input wire:model="rows.{{ $i }}.cost_price" type="number" step="0.01" min="0" class="w-24" />
                                                            </td>
                                                            <td class="px-2 py-2">
                                                                <x-text-input wire:model="rows.{{ $i }}.selling_price" type="number" step="0.01" min="0" class="w-24" />
                                                                <x-input-error :messages="$errors->get('rows.' . $i . '.selling_price')" />
                                                            </td>
                                                            <td class="px-2 py-2">
                                                                <x-text-input wire:model="rows.{{ $i }}.stock_quantity" type="number" step="1"
                                                                              class="w-20" :disabled="(bool) $row['id'] || $tracksBatches" />
                                                            </td>
                                                            <td class="px-2 py-2">
                                                                <x-text-input wire:model="rows.{{ $i }}.low_stock_threshold" type="number" step="1" min="0" class="w-20" />
                                                            </td>
                                                            <td class="px-2 py-2">
                                                                <input type="checkbox" wire:model="rows.{{ $i }}.is_active" class="rounded">
                                                            </td>
                                                            <td class="px-2 py-2">
                                                                @unless ($row['id'])
                                                                    <button type="button" wire:click="removeRow({{ $i }})" class="text-gray-500 hover:underline">Remove</button>
                                                                @endunless
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                        <x-input-error :messages="$errors->get('rows')" />
                                        <p class="text-xs text-gray-500">Stock for saved variants changes through stock adjustments. Saved variants can be unticked (disabled) but not removed. The tax setting applies to the whole product.</p>
                                    </x-card>
                                @else
                                    <x-input-error :messages="$errors->get('rows')" />
                                @endif
                            @endif
                        </div>

                        <div class="sticky bottom-0 flex gap-3 border-t border-gray-200 bg-white px-6 py-4">
                            <x-primary-button>{{ $editingId ? 'Update' : 'Save' }}</x-primary-button>
                            <x-secondary-button type="button" wire:click="closeForm">Cancel</x-secondary-button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    @endcan
</div>
