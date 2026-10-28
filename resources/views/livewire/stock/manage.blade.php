<?php

use App\Models\ProductVariant;
use App\Models\StockBatch;
use App\Services\BatchService;
use App\Services\BranchService;
use App\Services\StockService;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public const REASONS = [
        'Purchase received', 'Stock-take correction', 'Damaged',
        'Expired', 'Lost or stolen', 'Returned by customer', 'Other',
    ];

    public string $search = '';
    public ?int $variantId = null;
    public string $mode = 'add'; // add | remove | set
    public $quantity = '';
    public string $reason = 'Purchase received';
    public string $note = '';
    public string $batchNumber = '';
    public string $expiry = '';
    public $batchId = '';



    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $term = trim($this->search);
        $selected = $this->variantId ? ProductVariant::with('product')->find($this->variantId) : null;
        $here = BranchService::currentId();

        return [
            'reasons' => self::REASONS,
            'branchName' => \App\Models\Branch::find($here)?->name,
            'variants' => ProductVariant::with(['product', 'batches'])
                ->whereHas('product', fn ($p) =>
    $p->where('is_service', false)
      ->forBranch(BranchService::currentId())
)
                ->when($term !== '', fn ($q) => $q->where(function ($q) use ($term) {
                    $q->where('sku', 'like', "%{$term}%")
                      ->orWhere('barcode', 'like', "%{$term}%")
                      ->orWhere('name', 'like', "%{$term}%")
                      ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$term}%"));
                }))
                ->orderBy('product_id')->orderBy('name')
                ->paginate(10),
            'selected' => $selected,
            'selectedBatches' => $selected && $selected->product->tracks_batches
                ? BranchService::limit(
                    StockBatch::withoutGlobalScope(\App\Models\Scopes\BranchViewScope::class)->where('product_variant_id', $selected->id),
                    $here, 'branch_id')
                    ->where('quantity', '>', 0)->orderByRaw('expiry_date IS NULL')->orderBy('expiry_date')->get()
                : collect(),
        ];
    }

    private function authorizeAdjust(): void
    {
        abort_unless(auth()->user()->can('adjust-stock'), 403);
    }

    public function select(int $id): void
    {
        $this->authorizeAdjust();
        $variant = ProductVariant::with('product')->findOrFail($id); // tenant scope blocks other shops' IDs
        abort_if($variant->product->is_service, 404);

        $this->reset('quantity', 'note', 'batchNumber', 'expiry', 'batchId');
        $this->resetErrorBag();
        $this->mode = 'add';
        $this->reason = 'Purchase received';
        $this->variantId = $variant->id;
    }

    public function cancel(): void
    {
        $this->reset('variantId', 'quantity', 'note', 'batchNumber', 'expiry', 'batchId');
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->authorizeAdjust();

        $variant = ProductVariant::with('product')->findOrFail($this->variantId);
        abort_if($variant->product->is_service, 404);

        $this->validate([
            'mode' => ['required', Rule::in(['add', 'remove', 'set'])],
            'quantity' => ['required', 'integer', 'max:1000000', $this->mode === 'set' ? 'min:0' : 'min:1'],
            'reason' => ['required', Rule::in(self::REASONS)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $qty = (int) $this->quantity;
        $here = BranchService::currentId();

        // Tracked products work per batch, at this branch
        if ($variant->product->tracks_batches) {
            if ($this->mode === 'add') {
                BatchService::receive(
                    $variant, $qty, trim($this->batchNumber) ?: null, $this->expiry ?: null,
                    trim($this->note) ?: null, $this->reason
                );
            } else {
                $batch = BranchService::limit(
                    StockBatch::withoutGlobalScope(\App\Models\Scopes\BranchViewScope::class)->where('product_variant_id', $variant->id),
                    $here, 'branch_id')->find($this->batchId);

                if (! $batch) {
                    $this->addError('batchId', 'Choose a batch.');
                    return;
                }
                BatchService::adjust($batch, $this->mode, $qty, $this->reason, trim($this->note) ?: null);
            }

            $this->dispatch('notify', message: 'Stock updated.');
            $this->cancel();
            return;
        }

        $current = BranchService::quantity($variant, $here);

        $change = match ($this->mode) {
            'add' => $qty,
            'remove' => -$qty,
            'set' => $qty - $current,
        };

        if ($change === 0) {
            $this->addError('quantity', 'That is already the current stock.');
            return;
        }

        if ($current + $change < 0) {
            $this->addError('quantity', 'This would take stock below zero. Current stock here is ' . $current . '.');
            return;
        }

        StockService::record($variant, $change, 'adjustment', $this->reason, trim($this->note) ?: null, null, $here);

        $this->dispatch('notify', message: 'Stock updated.');
        $this->cancel();
    }
}; ?>

<div class="space-y-6">
    <p class="text-sm text-gray-600">Stock at <strong>{{ $branchName }}</strong>. Use the branch selector at the top to work in another branch.</p>

    <x-text-input wire:model.live.debounce.300ms="search" type="search"
                  placeholder="Search by product, variant, SKU or barcode..." class="block w-full" />

    @if ($selected)
        @php $tracked = $selected->product->tracks_batches; @endphp
        <x-card class="p-6 space-y-4">
            <form wire:submit="save" class="space-y-4">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="text-lg font-medium text-gray-900">
                            {{ $selected->product->name }}
                            @if ($selected->name !== 'Default')
                                <span class="text-gray-500">- {{ $selected->name }}</span>
                            @endif
                        </h3>
                        <p class="text-sm text-gray-500">
                            Current stock at {{ $branchName }}: <span class="font-semibold text-gray-900">{{ $selected->branch_stock }} {{ $selected->product->unit }}</span>
                            @if ($tracked) <x-badge color="orange" class="ml-1">Batches</x-badge> @endif
                        </p>
                    </div>
                    <button type="button" wire:click="cancel" class="text-2xl leading-none text-gray-500 hover:text-gray-800" aria-label="Close">&times;</button>
                </div>

                <div class="inline-flex rounded-lg border border-gray-300 overflow-hidden">
                    @foreach (['add' => 'Add stock', 'remove' => 'Remove stock', 'set' => 'Set to counted'] as $key => $label)
                        <button type="button" wire:click="$set('mode', '{{ $key }}')"
                                class="px-4 py-2 text-sm font-medium {{ $mode === $key ? 'bg-brand-500 text-white' : 'bg-white text-gray-700' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    @if ($tracked && $mode === 'add')
                        <div>
                            <x-input-label for="batch_number" value="Batch number *" />
                            <x-text-input wire:model="batchNumber" id="batch_number" type="text" maxlength="50" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('batchNumber')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="expiry" value="Expiry date *" />
                            <x-text-input wire:model="expiry" id="expiry" type="date" min="{{ today()->toDateString() }}" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('expiry')" class="mt-2" />
                        </div>
                        <div class="hidden md:block"></div>
                    @elseif ($tracked)
                        <div class="md:col-span-3">
                            <x-input-label for="batch_id" value="Which batch? *" />
                            <select wire:model="batchId" id="batch_id" class="mt-1 block w-full">
                                <option value="">Choose a batch...</option>
                                @foreach ($selectedBatches as $b)
                                    <option value="{{ $b->id }}">
                                        {{ $b->batch_number ?? 'No batch number' }},
                                        {{ $b->expiry_date ? 'expires ' . $b->expiry_date->format('d M Y') : 'no expiry' }}{{ $b->is_expired ? ' (EXPIRED)' : '' }},
                                        {{ $b->quantity }} left
                                    </option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('batchId')" class="mt-2" />
                        </div>
                    @endif

                    <div>
                        <x-input-label for="quantity" value="{{ $mode === 'set' ? 'Counted quantity' : 'Quantity' }}" />
                        <x-text-input wire:model="quantity" id="quantity" type="number" step="1" min="0" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('quantity')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="reason" value="Reason" />
                        <select wire:model="reason" id="reason" class="mt-1 block w-full">
                            @foreach ($reasons as $r)
                                <option value="{{ $r }}">{{ $r }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('reason')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="note" value="Note (optional)" />
                        <x-text-input wire:model="note" id="note" type="text" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('note')" class="mt-2" />
                    </div>
                </div>

                @if ($tracked && $mode !== 'add')
                    <p class="text-xs text-gray-500">Write off expired stock by choosing its batch, Remove stock, and the reason "Expired".</p>
                @endif

                <div class="flex gap-3">
                    <x-primary-button>Save adjustment</x-primary-button>
                    <x-secondary-button type="button" wire:click="cancel">Cancel</x-secondary-button>
                </div>
            </form>
        </x-card>
    @endif

    <x-card class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Product</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Variant</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">SKU</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Stock here</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Expiry</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse ($variants as $v)
                    @php
                        $qty = $v->branch_stock;
                        $low = $qty <= $v->low_stock_threshold;
                        $next = $v->product->tracks_batches
                            ? $v->batches->where('quantity', '>', 0)->whereNotNull('expiry_date')->sortBy('expiry_date')->first()
                            : null;
                    @endphp
                    <tr wire:key="var-{{ $v->id }}" class="{{ $v->is_active && $v->product->is_active ? '' : 'opacity-50' }}">
                        <td class="px-4 py-3">{{ $v->product->name }}</td>
                        <td class="px-4 py-3">{{ $v->name === 'Default' ? '-' : $v->name }}</td>
                        <td class="px-4 py-3">{{ $v->sku ?? '-' }}</td>
                        <td class="px-4 py-3 text-right {{ $low ? 'text-red-600 font-semibold' : '' }}">
                            {{ $qty }} {{ $v->product->unit }}
                        </td>
                        <td class="px-4 py-3 text-sm">
                            @if ($next)
                                @php $days = (int) today()->diffInDays($next->expiry_date, false); @endphp
                                <span class="{{ $days < 0 ? 'text-red-600 font-semibold' : ($days <= config('inventory.expiry_warn_days') ? 'text-orange-600' : 'text-gray-600') }}">
                                    {{ $next->expiry_date->format('d M Y') }}{{ $days < 0 ? ' (expired)' : '' }}
                                </span>
                            @elseif ($v->product->tracks_batches)
                                <span class="text-gray-400">-</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button wire:click="select({{ $v->id }})" class="text-brand-600 hover:underline">Adjust</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">No items found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-card>

    {{ $variants->links() }}
</div>
