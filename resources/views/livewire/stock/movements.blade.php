<?php

use App\Services\MovementQuery;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $from = '';
    public string $to = '';
    public string $type = 'all';
    public string $search = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('adjust-stock'), 403);

        $this->from = now()->subDays(29)->toDateString();
        $this->to = now()->toDateString();
    }

    public function updatedFrom(): void { $this->resetPage(); }
    public function updatedTo(): void { $this->resetPage(); }
    public function updatedType(): void { $this->resetPage(); }
    public function updatedSearch(): void { $this->resetPage(); }

    public function with(): array
    {
        return [
            'movements' => MovementQuery::build($this->from, $this->to, $this->type, $this->search)
                ->latest('id')->paginate(20),
            'types' => MovementQuery::TYPES,
        ];
    }
}; ?>

<div class="space-y-6">
    <x-card class="p-4">
        <div class="flex flex-wrap items-end gap-4">
            <div>
                <x-input-label for="m_from" value="From" />
                <x-text-input wire:model.live="from" id="m_from" type="date" class="mt-1" />
            </div>
            <div>
                <x-input-label for="m_to" value="To" />
                <x-text-input wire:model.live="to" id="m_to" type="date" class="mt-1" />
            </div>
            <div>
                <x-input-label for="m_type" value="Type" />
                <select wire:model.live="type" id="m_type" class="mt-1 text-sm">
                    <option value="all">All</option>
                    @foreach ($types as $t)
                        <option value="{{ $t }}">{{ $t === 'stocktake' ? 'Stock take' : ucfirst($t) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="min-w-[12rem] flex-1">
                <x-input-label for="m_search" value="Search item" />
                <x-text-input wire:model.live.debounce.300ms="search" id="m_search" type="search" class="mt-1 block w-full" placeholder="Name, SKU or barcode" />
            </div>
            <a href="{{ route('stock.movements.export', ['from' => $from, 'to' => $to, 'type' => $type, 'search' => $search]) }}"
               class="inline-flex items-center rounded-lg border border-brand-500 px-4 py-2 text-sm font-semibold text-brand-600 hover:bg-brand-50">Export CSV</a>
        </div>
    </x-card>

    <x-card class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">When</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Item</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Branch</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Change</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">After</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reason</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">By</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse ($movements as $m)
                    <tr wire:key="mv-{{ $m->id }}">
                        <td class="px-4 py-2 whitespace-nowrap">{{ $m->created_at->format('d M Y, H:i') }}</td>
                        <td class="px-4 py-2">
                            {{ $m->variant->product->name }}
                            @if ($m->variant->name !== 'Default') <span class="text-gray-500">- {{ $m->variant->name }}</span> @endif
                            @if ($m->batch) <span class="text-xs text-gray-500">[batch {{ $m->batch->batch_number ?? 'no number' }}]</span> @endif
                        </td>
                        <td class="px-4 py-2">{{ $m->type === 'stocktake' ? 'Stock take' : ucfirst($m->type) }}</td>
                        <td class="px-4 py-2">{{ $m->branch?->name ?? '-' }}</td>
                        <td class="px-4 py-2 text-right font-medium {{ $m->quantity_change > 0 ? 'text-green-700' : 'text-red-600' }}">
                            {{ $m->quantity_change > 0 ? '+' : '' }}{{ $m->quantity_change }}
                        </td>
                        <td class="px-4 py-2 text-right">{{ $m->quantity_after }}</td>
                        <td class="px-4 py-2">
                            {{ $m->reason ?? '-' }}
                            @if ($m->note) <span class="text-gray-500">({{ $m->note }})</span> @endif
                        </td>
                        <td class="px-4 py-2">{{ $m->user?->name ?? '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-6 text-center text-gray-500">No movements match.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-card>

    {{ $movements->links() }}
</div>
