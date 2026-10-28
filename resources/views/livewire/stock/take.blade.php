<?php

use App\Models\Branch;
use App\Models\Category;
use App\Models\ProductVariant;
use App\Services\BranchService;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';
    public $categoryId = '';
    public bool $onlyDifferences = false;
    public array $counts = [];    // [variantId => counted]
    public array $expected = [];  // [variantId => stock at this branch when it was counted]
    public string $note = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('adjust-stock'), 403);
    }

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedCategoryId(): void { $this->resetPage(); }
    public function updatedOnlyDifferences(): void { $this->resetPage(); }

    public function updatedCounts($value, $key): void
    {
        if ($value === '' || $value === null) {
            unset($this->counts[$key], $this->expected[$key]);
            return;
        }

        if (! isset($this->expected[$key])) {
            $v = ProductVariant::find((int) $key); // tenant scope applies
            if (! $v) {
                unset($this->counts[$key]);
                return;
            }
            $this->expected[$key] = (int) $v->branch_stock;
        }
    }

    // Batch-tracked items are counted per batch, and services have no stock
    private function eligible()
    {
        return ProductVariant::with('product')->active()
            ->whereHas('product', fn ($p) => $p->active()->where('is_service', false)->where('tracks_batches', false)
->forBranch(BranchService::currentId()));
    }

    private function listQuery()
    {
        $term = trim($this->search);
        $diffIds = array_keys(array_filter($this->diffs(), fn ($d) => $d !== 0));

        return $this->eligible()
            ->when($this->categoryId, function ($q) {
                $ids = Category::where('id', (int) $this->categoryId)->orWhere('parent_id', (int) $this->categoryId)->pluck('id');
                $q->whereHas('product', fn ($p) => $p->whereIn('category_id', $ids));
            })
            ->when($term !== '', fn ($q) => $q->where(function ($q) use ($term) {
                $q->where('sku', 'like', "%{$term}%")
                  ->orWhere('barcode', 'like', "%{$term}%")
                  ->orWhere('name', 'like', "%{$term}%")
                  ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$term}%"));
            }))
            ->when($this->onlyDifferences, fn ($q) => $q->whereIn('id', $diffIds ?: [0]))
            ->orderBy('product_id')->orderBy('name');
    }

    private function isValid($c): bool
    {
        return $c !== '' && $c !== null && preg_match('/^\d+$/', (string) $c) === 1;
    }

    // [variantId => counted - expected] for valid counts
    private function diffs(): array
    {
        $out = [];
        foreach ($this->counts as $id => $c) {
            if ($this->isValid($c)) {
                $out[(int) $id] = (int) $c - (int) ($this->expected[$id] ?? 0);
            }
        }

        return $out;
    }

    private function summary(): array
    {
        $diffs = $this->diffs();
        $invalid = collect($this->counts)->filter(fn ($c) => ! $this->isValid($c))->count();

        $value = null;
        if (auth()->user()->can('view-cost-prices') && $diffs) {
            $costs = ProductVariant::whereIn('id', array_keys($diffs))->pluck('cost_price', 'id');
            $value = collect($diffs)->map(fn ($d, $id) => $d * (float) ($costs[$id] ?? 0))->sum();
        }

        return [
            'counted' => count($diffs),
            'differences' => count(array_filter($diffs, fn ($d) => $d !== 0)),
            'surplus' => array_sum(array_filter($diffs, fn ($d) => $d > 0)),
            'shortage' => abs(array_sum(array_filter($diffs, fn ($d) => $d < 0))),
            'invalid' => $invalid,
            'value' => $value,
        ];
    }

    public function with(): array
    {
        return [
            'branchName' => Branch::find(BranchService::currentId())?->name,
            'variants' => $this->listQuery()->paginate(50),
            'categories' => Category::active()->with('parent')->orderBy('name')->get(),
            'summary' => $this->summary(),
        ];
    }

    public function fillAll(): void
    {
        abort_unless(auth()->user()->can('adjust-stock'), 403);

        foreach ($this->listQuery()->limit(2000)->get() as $v) {
            if (! array_key_exists($v->id, $this->counts)) {
                $this->counts[$v->id] = (string) $v->branch_stock;
                $this->expected[$v->id] = (int) $v->branch_stock;
            }
        }
    }

    public function clear(): void
    {
        $this->reset('counts', 'expected', 'note');
        $this->resetErrorBag();
    }

    public function apply(): void
    {
        abort_unless(auth()->user()->can('adjust-stock'), 403);

        $this->resetErrorBag();
        $note = trim($this->note);

        if (mb_strlen($note) > 200) {
            $this->addError('note', 'The note is too long (200 characters at most).');
            return;
        }
        if (! count($this->counts)) {
            $this->addError('counts', 'Enter at least one counted quantity.');
            return;
        }
        if ($this->summary()['invalid'] > 0) {
            $this->addError('counts', 'Counts must be whole numbers of 0 or more. Fix the rows marked in red.');
            return;
        }

        $branch = BranchService::currentId();
        $changed = 0;
        $same = 0;

        DB::transaction(function () use ($note, $branch, &$changed, &$same) {
            $variants = ProductVariant::with('product')
                ->whereIn('id', array_keys($this->counts))
                ->whereHas('product', fn ($p) => $p->where('is_service', false)->where('tracks_batches', false))
                ->lockForUpdate()->get();

            foreach ($variants as $v) {
                BranchService::forget();
                $now = BranchService::quantity($v, $branch);
                $change = (int) $this->counts[$v->id] - (int) ($this->expected[$v->id] ?? $now);

                if ($change === 0) {
                    $same++;
                    continue;
                }

                // Sales made after the count are kept: the difference is applied to today's stock at this branch
                if ($now + $change < 0) {
                    throw ValidationException::withMessages([
                        'counts' => "{$v->product->name} sold more since you counted it than your count allows. Recount that item.",
                    ]);
                }

                StockService::record($v, $change, 'stocktake', 'Stock take', $note ?: null, null, $branch);
                $changed++;
            }
        });

        $this->dispatch('notify', message: "Stock take applied: {$changed} item(s) adjusted, {$same} matched.");
        $this->reset('counts', 'expected', 'note');
    }
}; ?>

<div class="space-y-6">
    <p class="text-sm text-gray-600">Counting stock at <strong>{{ $branchName }}</strong>. Use the branch selector at the top to count another branch.</p>

    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
        <x-stat-card label="Counted" :value="$summary['counted']" caption="Items with a count" />
        <x-stat-card label="Differences" :value="$summary['differences']" caption="Items that do not match" />
        <x-stat-card label="Surplus" :value="'+' . $summary['surplus']" caption="Units more than expected" />
        <x-stat-card label="Shortage" :value="'-' . $summary['shortage']" caption="Units fewer than expected" />
        @if ($summary['value'] !== null)
            <x-stat-card label="Value of difference" :value="'Kshs ' . number_format($summary['value'], 2)" caption="At cost price" />
        @endif
    </div>

    <x-card class="p-4">
        <div class="flex flex-wrap items-end gap-4">
            <div class="min-w-[12rem] flex-1">
                <x-input-label for="t_search" value="Search" />
                <x-text-input wire:model.live.debounce.300ms="search" id="t_search" type="search" class="mt-1 block w-full" placeholder="Name, SKU or barcode" />
            </div>
            <div>
                <x-input-label for="t_cat" value="Category" />
                <select wire:model.live="categoryId" id="t_cat" class="mt-1 text-sm">
                    <option value="">All</option>
                    @foreach ($categories as $c)
                        <option value="{{ $c->id }}">{{ $c->parent ? $c->parent->name . ' → ' . $c->name : $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <label class="flex items-center gap-2 pb-2 text-sm text-gray-700">
                <input type="checkbox" wire:model.live="onlyDifferences" class="rounded"> Only differences
            </label>
            <div class="flex gap-3 pb-2 text-sm">
                <button type="button" wire:click="fillAll" class="text-brand-600 hover:underline">Fill empty rows with system quantity</button>
                <button type="button" wire:click="clear" wire:confirm="Clear everything you have counted?" class="text-gray-500 hover:underline">Clear</button>
            </div>
        </div>
        <p class="mt-3 text-xs text-gray-500">
            Type what you actually counted. Rows you leave empty are not touched. Items that track batches are counted per batch on the Adjustment screen, and services have no stock.
        </p>
    </x-card>

    <x-input-error :messages="$errors->get('counts')" />

    <x-card class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Item</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">SKU</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">System</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Counted</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Difference</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse ($variants as $v)
                    @php
                        $raw = $counts[$v->id] ?? '';
                        $has = array_key_exists($v->id, $counts) && $raw !== '' && $raw !== null;
                        $valid = $has && preg_match('/^\d+$/', (string) $raw);
                        $sys = $expected[$v->id] ?? $v->branch_stock;
                        $diff = $valid ? (int) $raw - (int) $sys : null;
                    @endphp
                    <tr wire:key="take-{{ $v->id }}">
                        <td class="px-4 py-2">
                            {{ $v->product->name }}@if ($v->name !== 'Default') <span class="text-gray-500">- {{ $v->name }}</span>@endif
                        </td>
                        <td class="px-4 py-2">{{ $v->sku ?? '-' }}</td>
                        <td class="px-4 py-2 text-right">{{ $sys }} {{ $v->product->unit }}</td>
                        <td class="px-4 py-2">
                            <x-text-input wire:model.live.debounce.500ms="counts.{{ $v->id }}" type="number" step="1" min="0"
                                          class="w-28 {{ $has && ! $valid ? 'border-red-500' : '' }}" />
                        </td>
                        <td class="px-4 py-2 text-right font-semibold">
                            @if ($has && ! $valid)
                                <span class="text-red-600">Whole number</span>
                            @elseif ($diff === null)
                                <span class="text-gray-300">-</span>
                            @else
                                <span class="{{ $diff > 0 ? 'text-green-700' : ($diff < 0 ? 'text-red-600' : 'text-gray-500') }}">
                                    {{ $diff > 0 ? '+' : '' }}{{ $diff }}
                                </span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">No items to count.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-card>

    {{ $variants->links() }}

    <x-card class="p-4">
        <div class="flex flex-wrap items-end gap-4">
            <div class="min-w-[14rem] flex-1">
                <x-input-label for="t_note" value="Note (optional, saved on every adjustment)" />
                <x-text-input wire:model="note" id="t_note" type="text" maxlength="200" class="mt-1 block w-full" placeholder="e.g. October end-of-month count" />
                <x-input-error :messages="$errors->get('note')" class="mt-2" />
            </div>
            <x-primary-button type="button" wire:click="apply" wire:loading.attr="disabled"
                              wire:confirm="Apply this stock take? Stock will be changed for every item that differs.">
                Apply stock take
            </x-primary-button>
        </div>
    </x-card>
</div>
