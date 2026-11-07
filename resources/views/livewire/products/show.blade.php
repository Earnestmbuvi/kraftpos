<?php

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\Scopes\BranchViewScope;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\BranchService;
use App\Services\ItemCodes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component
{
    use WithFileUploads, WithPagination;

    public int $productId;

    #[Url]
    public string $tab = 'summary';

    // update form
    public string $name = '';
    public ?string $description = null;
    public $category_id = null;
    public string $unit = 'pcs';
    public string $tax_class = 'standard';
    public array $branchIds = [];
    public array $rows = [];
    public $photo = null;
    public bool $removePhoto = false;

    public function mount(int $productId): void
    {
        abort_unless(auth()->user()->can('view-products'), 403);

        $this->productId = $productId;
        Product::findOrFail($productId); // tenant scope blocks other shops' products
        $this->loadForm();
    }

    private function canAudit(): bool
    {
        $u = auth()->user();

        return $u->can('adjust-stock') || $u->can('view-reports');
    }

    private function allowed(string $tab): bool
    {
        return match ($tab) {
            'summary' => true,
            'update' => auth()->user()->can('manage-products'),
            'audit' => $this->canAudit(),
            default => false,
        };
    }

    public function setTab(string $tab): void
    {
        if ($this->allowed($tab)) {
            $this->tab = $tab;
            $this->resetPage('mp');
            $this->resetPage('ap');
        }
    }

    private function loadForm(): void
    {
        $p = Product::with(['variants', 'branches'])->findOrFail($this->productId);

        $this->name = $p->name;
        $this->description = $p->description;
        $this->category_id = $p->category_id;
        $this->unit = $p->unit;
        $this->tax_class = $p->tax_class ?? 'standard';
        $this->branchIds = $p->branches->pluck('id')->map(fn ($i) => (string) $i)->all();
        $this->rows = $p->variants->map(fn ($v) => [
            'id' => $v->id, 'name' => $v->name, 'sku' => $v->sku, 'barcode' => $v->barcode,
            'cost_price' => $v->cost_price, 'selling_price' => $v->selling_price,
            'low_stock_threshold' => $v->low_stock_threshold, 'is_active' => (bool) $v->is_active,
        ])->values()->all();
        $this->photo = null;
        $this->removePhoto = false;
        $this->resetErrorBag();
    }

    public function with(): array
    {
        if (! $this->allowed($this->tab)) {
            $this->tab = 'summary';
        }

        $bid = auth()->user()->business_id;
        $p = Product::with(['category.parent', 'variants', 'branches'])->findOrFail($this->productId);
        $ids = $p->variants->pluck('id')->all();

        $data = [
            'p' => $p,
            'branchList' => Branch::active()->orderByDesc('is_main')->orderBy('name')->get(),
            'multi' => BranchService::multi(),
            'here' => BranchService::currentId(),
            'canCost' => auth()->user()->can('view-cost-prices'),
            'canEdit' => auth()->user()->can('manage-products'),
            'canAudit' => $this->canAudit(),
            'categories' => Category::with('parent')->active()->orderBy('name')->get(),
            'units' => Unit::active()->orderBy('name')->pluck('name'),
            'sold' => 0,
        ];

        // Units sold to date: till sales and invoices that are not voided
        $data['sold'] = (int) DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                ->where('sales.status', 'completed')->where('sale_items.business_id', $bid)
                ->whereIn('sale_items.product_variant_id', $ids)->sum('sale_items.quantity')
            + (int) DB::table('invoice_items')->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
                ->where('invoices.status', 'issued')->where('invoice_items.business_id', $bid)
                ->whereIn('invoice_items.product_variant_id', $ids)->sum('invoice_items.quantity');

        if ($this->tab === 'audit' && $data['canAudit']) {
            $moves = StockMovement::withoutGlobalScope(BranchViewScope::class)
                ->whereIn('product_variant_id', $ids)->latest('id')->paginate(15, ['*'], 'mp');

            $data['moves'] = $moves;
            $data['totals'] = StockMovement::withoutGlobalScope(BranchViewScope::class)
                ->whereIn('product_variant_id', $ids)
                ->selectRaw('type, COUNT(*) as n, SUM(quantity_change) as qty')->groupBy('type')->get();
            $data['users'] = User::whereIn('id', $moves->pluck('user_id')->filter())->pluck('name', 'id');
            $data['batches'] = StockBatch::withoutGlobalScope(BranchViewScope::class)
                ->whereIn('id', $moves->pluck('stock_batch_id')->filter())->pluck('batch_number', 'id');
            $data['variantNames'] = $p->variants->pluck('name', 'id');
            $data['branchNames'] = $data['branchList']->pluck('name', 'id');
            $data['log'] = ActivityLog::where('business_id', $bid)
                ->where(function ($q) use ($p, $ids) {
                    $q->where(fn ($w) => $w->where('subject_type', 'Product')->where('subject_id', $p->id))
                      ->orWhere(fn ($w) => $w->where('subject_type', 'ProductVariant')->whereIn('subject_id', $ids));
                })->latest('id')->paginate(15, ['*'], 'ap');
        }

        return $data;
    }

    public function toggleStatus(): void
    {
        abort_unless(auth()->user()->can('manage-products'), 403);

        $p = Product::findOrFail($this->productId);
        $p->is_active ? $p->disable() : $p->enable();
    }

    public function cancelUpdate(): void
    {
        $this->loadForm();
        $this->tab = 'summary';
    }

    public function save(): void
    {
        $u = auth()->user();
        abort_unless($u->can('manage-products'), 403);

        $bid = $u->business_id;
        $product = Product::with('variants')->findOrFail($this->productId);
        $mine = $product->variants->pluck('id')->all();

        foreach ($this->rows as $i => $r) {
            abort_unless(in_array((int) $r['id'], $mine, true), 403);
            $this->rows[$i]['sku'] = trim((string) ($r['sku'] ?? '')) === '' ? null : trim($r['sku']);
            $this->rows[$i]['barcode'] = trim((string) ($r['barcode'] ?? '')) === '' ? null : trim($r['barcode']);
        }
        $this->category_id = $this->category_id ?: null;

        $rules = [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('business_id', $bid)],
            'unit' => ['required', Rule::exists('units', 'name')->where('business_id', $bid)->where('is_active', true)],
            'tax_class' => ['required', Rule::in(['standard', 'zero', 'exempt'])],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'branchIds' => ['array'],
            'branchIds.*' => ['integer', Rule::exists('branches', 'id')->where('business_id', $bid)],
            'rows.*.cost_price' => ['required', 'numeric', 'min:0'],
            'rows.*.selling_price' => ['required', 'numeric', 'min:0'],
            'rows.*.low_stock_threshold' => ['required', 'integer', 'min:0'],
        ];
        foreach ($this->rows as $i => $r) {
            foreach (['sku', 'barcode'] as $f) {
                $rules["rows.$i.$f"] = ['nullable', 'string', 'max:50',
                    Rule::unique('product_variants', $f)->where('business_id', $bid)->ignore($r['id'])];
            }
        }

        $this->validate($rules, ['rows.*.cost_price.required' => 'Enter the cost price (use 0 if there is none).']);

        foreach (['sku', 'barcode'] as $f) {
            $seen = [];
            foreach ($this->rows as $i => $r) {
                if ($r[$f] === null) {
                    continue;
                }
                if (in_array(mb_strtolower($r[$f]), $seen, true)) {
                    $this->addError("rows.$i.$f", "This $f is used twice here.");
                    return;
                }
                $seen[] = mb_strtolower($r[$f]);
            }
        }

        DB::transaction(function () use ($product) {
            $product->update([
                'name' => $this->name,
                'description' => trim((string) $this->description) ?: null,
                'category_id' => $this->category_id,
                'unit' => $this->unit,
                'tax_class' => $this->tax_class,
            ]);

            foreach ($this->rows as $r) {
                $product->variants()->findOrFail($r['id'])->update([
                    'sku' => $r['sku'], 'barcode' => $r['barcode'],
                    'cost_price' => $r['cost_price'], 'selling_price' => $r['selling_price'],
                    'low_stock_threshold' => $r['low_stock_threshold'], 'is_active' => (bool) $r['is_active'],
                ]);
            }

            if (($this->removePhoto || $this->photo) && $product->image_path) {
                Storage::disk('public')->delete($product->image_path);
                $product->update(['image_path' => null]);
            }
            if ($this->photo) {
                $product->update(['image_path' => $this->photo->store('products/' . $product->business_id, 'public')]);
            }

            $product->branches()->sync(array_values(array_unique(array_map('intval', $this->branchIds))));
        });

        $this->loadForm();
        $this->tab = 'summary';
        $this->dispatch('notify', message: 'Saved.');
    }
}; ?>

<div class="space-y-6">
    @php
        $service = (bool) $p->is_service;
        $typeLabel = $service ? 'Service' : ($p->type === 'variable' ? 'Product with variants' : 'Product');
    @endphp

    <div>
        <a href="{{ route($service ? 'services' : 'products') }}" wire:navigate class="text-sm font-medium text-brand-600 hover:underline">&larr; {{ $service ? 'Services' : 'Products' }}</a>
        <h2 class="mt-2 text-2xl font-bold text-gray-900">{{ $p->name }}</h2>
        <p class="mt-1 flex items-center gap-2 text-sm {{ $p->is_active ? 'text-green-700' : 'text-red-600' }}">
            <span class="inline-block h-2 w-2 rounded-full {{ $p->is_active ? 'bg-green-600' : 'bg-red-500' }}"></span>
            {{ $p->is_active ? ($service ? 'Available' : 'For sale') : 'Disabled' }}
        </p>
        <p class="mt-3 text-xs text-gray-500">Created {{ $p->created_at->format('M j, Y g:i A') }} &middot; Updated {{ $p->updated_at->format('M j, Y g:i A') }}</p>
    </div>

    <div class="flex gap-6 border-b border-gray-200 text-sm font-medium">
        @foreach (['summary' => 'Summary', 'update' => 'Update', 'audit' => 'Product audit'] as $key => $label)
            @continue(($key === 'update' && ! $canEdit) || ($key === 'audit' && ! $canAudit))
            <button type="button" wire:click="setTab('{{ $key }}')" wire:key="pt-{{ $key }}"
                    class="-mb-px border-b-2 pb-3 {{ $tab === $key ? 'border-brand-500 text-brand-600' : 'border-transparent text-gray-500 hover:text-gray-800' }}">{{ $label }}</button>
        @endforeach
    </div>

    {{-- SUMMARY --}}
    @if ($tab === 'summary')
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <x-card class="p-6 lg:col-span-2">
                <h3 class="text-lg font-semibold text-gray-900">General information</h3>
                <div class="mt-4 flex gap-5">
                    <x-product-image :url="$p->image_url" class="h-24 w-24 shrink-0 rounded-lg" />
                    <dl class="grid flex-1 grid-cols-1 gap-x-8 gap-y-4 text-sm sm:grid-cols-2">
                        <div><dt class="text-gray-500">Type</dt><dd class="font-medium text-gray-900">{{ $typeLabel }}</dd></div>
                        <div><dt class="text-gray-500">Display name</dt><dd class="font-medium text-gray-900">{{ $p->name }}</dd></div>
                        <div class="sm:col-span-2"><dt class="text-gray-500">Description</dt><dd class="font-medium text-gray-900">{{ $p->description ?: '-' }}</dd></div>
                        <div><dt class="text-gray-500">Category</dt><dd class="font-medium text-gray-900">{{ $p->category?->parent?->name ?? $p->category?->name ?? '-' }}</dd></div>
                        <div><dt class="text-gray-500">Sub-category</dt><dd class="font-medium text-gray-900">{{ $p->category?->parent ? $p->category->name : '-' }}</dd></div>
                        <div><dt class="text-gray-500">Unit</dt><dd class="font-medium text-gray-900">{{ $p->unit }}</dd></div>
                        <div><dt class="text-gray-500">Tax</dt><dd class="font-medium text-gray-900">{{ ['standard' => 'Standard rated', 'zero' => 'Zero rated', 'exempt' => 'Exempt'][$p->tax_class ?? 'standard'] ?? 'Standard rated' }}</dd></div>
                        @unless ($service)
                            <div><dt class="text-gray-500">Stock in all branches</dt><dd class="font-medium text-gray-900">{{ $p->variants->sum('stock_quantity') }} {{ $p->unit }}</dd></div>
                            <div><dt class="text-gray-500">Sold to date</dt><dd class="font-medium text-gray-900">{{ $sold }}</dd></div>
                            <div><dt class="text-gray-500">Batches and expiry</dt><dd class="font-medium text-gray-900">{{ $p->tracks_batches ? 'Tracked' : 'Not tracked' }}</dd></div>
                        @else
                            <div><dt class="text-gray-500">Sold to date</dt><dd class="font-medium text-gray-900">{{ $sold }}</dd></div>
                        @endunless
                        @if ($multi)
                            <div class="sm:col-span-2"><dt class="text-gray-500">Sold at</dt><dd class="font-medium text-gray-900">{{ $p->branches->isEmpty() ? 'All branches' : $p->branches->pluck('name')->implode(', ') }}</dd></div>
                        @endif
                    </dl>
                </div>
            </x-card>

            <x-card class="space-y-3 p-6">
                <h3 class="text-lg font-semibold text-gray-900">Status</h3>
                <p class="flex items-center gap-2 text-sm"><span class="inline-block h-2 w-2 rounded-full {{ $p->is_active ? 'bg-green-600' : 'bg-red-500' }}"></span>{{ $p->is_active ? 'Enabled' : 'Disabled' }}</p>
                @if ($canEdit)
                    <button type="button" wire:click="toggleStatus" class="w-full rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600">
                        {{ $p->is_active ? 'Disable' : 'Enable' }}
                    </button>
                    <button type="button" wire:click="setTab('update')" class="w-full rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Edit details</button>
                @endif
            </x-card>
        </div>

        <x-card class="overflow-x-auto">
            <div class="border-b border-gray-200 px-6 py-4 text-lg font-semibold text-gray-900">{{ $p->type === 'variable' ? 'Variants' : 'Pricing and codes' }}</div>
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50"><tr>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Variant</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">SKU</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Barcode</th>
                    @if ($canCost) <th class="px-4 py-3 text-right text-xs font-medium uppercase text-gray-500">Cost</th> @endif
                    <th class="px-4 py-3 text-right text-xs font-medium uppercase text-gray-500">Price</th>
                    @unless ($service)
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase text-gray-500">{{ $multi ? 'Stock here' : 'Stock' }}</th>
                        @if ($multi) @foreach ($branchList as $b) <th class="px-4 py-3 text-right text-xs font-medium uppercase text-gray-500">{{ $b->name }}</th> @endforeach @endif
                    @endunless
                    <th class="px-4 py-3"></th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($p->variants as $v)
                        <tr wire:key="sv-{{ $v->id }}" class="{{ $v->is_active ? '' : 'opacity-50' }}">
                            <td class="px-4 py-3 font-medium">{{ $v->name === 'Default' ? $p->name : $v->name }}</td>
                            <td class="px-4 py-3">{{ $v->sku ?? '-' }}</td>
                            <td class="px-4 py-3">
                                @if ($v->barcode)
                                    <div class="w-36">{!! \App\Services\ItemCodes::svg((string) $v->barcode, 30) !!}</div>
                                    <div class="text-xs text-gray-500">{{ $v->barcode }}</div>
                                @else - @endif
                            </td>
                            @if ($canCost) <td class="px-4 py-3 text-right">{{ number_format($v->cost_price, 2) }}</td> @endif
                            <td class="px-4 py-3 text-right">{{ number_format($v->selling_price, 2) }}</td>
                            @unless ($service)
                                <td class="px-4 py-3 text-right {{ $v->branch_stock <= $v->low_stock_threshold ? 'font-semibold text-red-600' : '' }}">{{ $v->branch_stock }}</td>
                                @if ($multi) @foreach ($branchList as $b) <td class="px-4 py-3 text-right text-gray-600">{{ \App\Services\BranchService::quantity($v, $b->id) }}</td> @endforeach @endif
                            @endunless
                            <td class="px-4 py-3 text-right">
                                @can('manage-products')
                                    <a href="{{ route('labels', ['variant' => $v->id]) }}" class="text-brand-600 hover:underline">Print label</a>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-card>
    @endif

    {{-- UPDATE --}}
    @if ($tab === 'update' && $canEdit)
        <form wire:submit="save" class="space-y-6">
            <x-card class="space-y-4 p-6">
                <h3 class="text-lg font-semibold text-gray-900">Details</h3>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div class="md:col-span-3">
                        <x-input-label for="u_name" value="Name *" />
                        <x-text-input wire:model="name" id="u_name" type="text" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>
                    <div class="md:col-span-3">
                        <x-input-label for="u_desc" value="Description (optional)" />
                        <textarea wire:model="description" id="u_desc" rows="3" maxlength="1000" class="mt-1 block w-full rounded-lg border-gray-300 text-sm"></textarea>
                        <x-input-error :messages="$errors->get('description')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="u_cat" value="Category" />
                        <select wire:model="category_id" id="u_cat" class="mt-1 block w-full">
                            <option value="">None</option>
                            @foreach ($categories as $c) <option value="{{ $c->id }}">{{ $c->parent ? $c->parent->name . ' → ' . $c->name : $c->name }}</option> @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('category_id')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="u_unit" value="Unit" />
                        <select wire:model="unit" id="u_unit" class="mt-1 block w-full">
                            @foreach ($units as $un) <option value="{{ $un }}">{{ $un }}</option> @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('unit')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="u_tax" value="Tax" />
                        <select wire:model="tax_class" id="u_tax" class="mt-1 block w-full">
                            <option value="standard">Standard rated</option><option value="zero">Zero rated</option><option value="exempt">Exempt</option>
                        </select>
                    </div>
                </div>

                @if ($multi)
                    <div>
                        <p class="text-sm font-medium text-gray-700">Sold at</p>
                        <p class="text-xs text-gray-500">Leave all unticked to sell it at every branch.</p>
                        <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-3">
                            @foreach ($branchList as $b)
                                <label wire:key="ub-{{ $b->id }}" class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="branchIds" value="{{ $b->id }}" class="rounded"> {{ $b->name }}</label>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="flex items-center gap-4">
                    <div class="h-20 w-20 overflow-hidden rounded-lg border border-gray-200">
                        @if ($photo) <img src="{{ $photo->temporaryUrl() }}" class="h-full w-full object-contain">
                        @elseif ($p->image_url && ! $removePhoto) <img src="{{ $p->image_url }}" class="h-full w-full object-contain">
                        @else <x-product-image class="h-full w-full" /> @endif
                    </div>
                    <div class="space-y-1">
                        <x-input-label for="u_photo" value="Photo (optional, max 2 MB)" />
                        <input wire:model="photo" id="u_photo" type="file" accept="image/png,image/jpeg,image/webp" class="text-sm">
                        <div wire:loading wire:target="photo" class="text-xs text-gray-500">Uploading...</div>
                        @if ($p->image_url && ! $photo) <label class="flex items-center gap-2 text-xs text-gray-600"><input type="checkbox" wire:model="removePhoto" class="rounded"> Remove current photo</label> @endif
                        <x-input-error :messages="$errors->get('photo')" />
                    </div>
                </div>
            </x-card>

            <x-card class="space-y-4 p-6">
                <h3 class="text-lg font-semibold text-gray-900">{{ $p->type === 'variable' ? 'Variants' : 'Pricing and codes' }}</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50"><tr>
                            <th class="px-2 py-2 text-left text-xs font-medium uppercase text-gray-500">Variant</th>
                            <th class="px-2 py-2 text-left text-xs font-medium uppercase text-gray-500">SKU</th>
                            <th class="px-2 py-2 text-left text-xs font-medium uppercase text-gray-500">Barcode</th>
                            <th class="px-2 py-2 text-left text-xs font-medium uppercase text-gray-500">Cost *</th>
                            <th class="px-2 py-2 text-left text-xs font-medium uppercase text-gray-500">Price *</th>
                            @unless ($service) <th class="px-2 py-2 text-left text-xs font-medium uppercase text-gray-500">Alert at</th> @endunless
                            <th class="px-2 py-2 text-left text-xs font-medium uppercase text-gray-500">Active</th>
                        </tr></thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach ($rows as $i => $r)
                                <tr wire:key="ur-{{ $r['id'] }}">
                                    <td class="px-2 py-2 font-medium whitespace-nowrap">{{ $r['name'] === 'Default' ? 'Default' : $r['name'] }}</td>
                                    <td class="px-2 py-2"><x-text-input wire:model="rows.{{ $i }}.sku" type="text" placeholder="Leave empty to generate" class="w-36" /><x-input-error :messages="$errors->get('rows.' . $i . '.sku')" /></td>
                                    <td class="px-2 py-2"><x-text-input wire:model="rows.{{ $i }}.barcode" type="text" placeholder="Leave empty to generate" class="w-40" /><x-input-error :messages="$errors->get('rows.' . $i . '.barcode')" /></td>
                                    <td class="px-2 py-2"><x-text-input wire:model="rows.{{ $i }}.cost_price" type="number" step="0.01" min="0" class="w-24" /><x-input-error :messages="$errors->get('rows.' . $i . '.cost_price')" /></td>
                                    <td class="px-2 py-2"><x-text-input wire:model="rows.{{ $i }}.selling_price" type="number" step="0.01" min="0" class="w-24" /><x-input-error :messages="$errors->get('rows.' . $i . '.selling_price')" /></td>
                                    @unless ($service) <td class="px-2 py-2"><x-text-input wire:model="rows.{{ $i }}.low_stock_threshold" type="number" step="1" min="0" class="w-20" /></td> @endunless
                                    <td class="px-2 py-2"><input type="checkbox" wire:model="rows.{{ $i }}.is_active" class="rounded"></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-gray-500">Stock quantities change through Stock, Adjustment, so every change is recorded. Batch tracking is switched on from the Products list.</p>
            </x-card>

            <div class="flex gap-3">
                <x-primary-button wire:loading.attr="disabled">Save changes</x-primary-button>
                <x-secondary-button type="button" wire:click="cancelUpdate">Cancel</x-secondary-button>
            </div>
        </form>
    @endif

    {{-- AUDIT --}}
    @if ($tab === 'audit' && $canAudit)
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            @foreach ($totals as $t)
                <div wire:key="tt-{{ $t->type }}" class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200">
                    <p class="text-xs uppercase tracking-wide text-gray-500">{{ \Illuminate\Support\Str::headline($t->type) }}</p>
                    <p class="mt-1 text-xl font-bold {{ $t->qty < 0 ? 'text-red-600' : 'text-green-700' }}">{{ $t->qty > 0 ? '+' : '' }}{{ $t->qty }}</p>
                    <p class="text-xs text-gray-500">{{ $t->n }} movement(s)</p>
                </div>
            @endforeach
        </div>

        <x-card class="overflow-x-auto">
            <div class="border-b border-gray-200 px-6 py-4 text-lg font-semibold text-gray-900">Stock movements</div>
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50"><tr>
                    @foreach (['Date', 'Type', 'Variant', 'Branch', 'Change', 'After', 'Reason', 'Batch', 'By'] as $h)
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ $h }}</th>
                    @endforeach
                </tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($moves as $m)
                        <tr wire:key="mv-{{ $m->id }}">
                            <td class="whitespace-nowrap px-4 py-2">{{ $m->created_at->format('d M Y H:i') }}</td>
                            <td class="px-4 py-2">{{ \Illuminate\Support\Str::headline($m->type) }}</td>
                            <td class="px-4 py-2">{{ ($variantNames[$m->product_variant_id] ?? '-') === 'Default' ? '-' : ($variantNames[$m->product_variant_id] ?? '-') }}</td>
                            <td class="px-4 py-2">{{ $branchNames[$m->branch_id] ?? '-' }}</td>
                            <td class="px-4 py-2 font-semibold {{ $m->quantity_change < 0 ? 'text-red-600' : 'text-green-700' }}">{{ $m->quantity_change > 0 ? '+' : '' }}{{ $m->quantity_change }}</td>
                            <td class="px-4 py-2">{{ $m->quantity_after }}</td>
                            <td class="px-4 py-2">{{ $m->reason }}@if ($m->note) <span class="block text-xs text-gray-500">{{ $m->note }}</span>@endif</td>
                            <td class="px-4 py-2">{{ $batches[$m->stock_batch_id] ?? '-' }}</td>
                            <td class="px-4 py-2">{{ $users[$m->user_id] ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-4 py-6 text-center text-gray-500">No stock movements yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="px-4 py-3">{{ $moves->links() }}</div>
        </x-card>

        <x-card class="overflow-x-auto">
            <div class="border-b border-gray-200 px-6 py-4 text-lg font-semibold text-gray-900">Activity on this {{ $service ? 'service' : 'product' }}</div>
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <tbody class="divide-y divide-gray-100">
                    @forelse ($log as $a)
                        <tr wire:key="al-{{ $a->id }}">
                            <td class="whitespace-nowrap px-4 py-2 align-top text-gray-500">{{ $a->created_at->format('d M Y H:i') }}</td>
                            <td class="px-4 py-2 align-top">{{ $a->user_name ?? 'System' }}</td>
                            <td class="px-4 py-2">
                                {{ $a->description }}
                                @foreach (($a->changes ?? []) as $field => [$old, $new])
                                    @continue($field === 'cost_price' && ! $canCost)
                                    <span class="block text-xs text-gray-500">{{ \Illuminate\Support\Str::headline($field) }}: {{ $old === null || $old === '' ? '(empty)' : $old }} &rarr; {{ $new === null || $new === '' ? '(empty)' : $new }}</span>
                                @endforeach
                            </td>
                        </tr>
                    @empty
                        <tr><td class="px-4 py-6 text-center text-gray-500">No activity recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="px-4 py-3">{{ $log->links() }}</div>
        </x-card>
    @endif
</div>
