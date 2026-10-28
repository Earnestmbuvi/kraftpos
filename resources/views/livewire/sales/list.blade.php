<?php

use App\Models\Sale;
use App\Services\SaleService;
use Carbon\Carbon;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $from = '';
    public string $to = '';
    public string $status = 'all';
    public ?int $voidingId = null;
    public string $reason = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('make-sales'), 403);

        $this->from = now()->toDateString();
        $this->to = now()->toDateString();
    }

    public function updatedFrom(): void { $this->resetPage(); }
    public function updatedTo(): void { $this->resetPage(); }
    public function updatedStatus(): void { $this->resetPage(); }

    private function date(string $value, bool $end): Carbon
    {
        try {
            $d = Carbon::createFromFormat('Y-m-d', $value);
        } catch (\Throwable $e) {
            $d = now();
        }

        return $end ? $d->endOfDay() : $d->startOfDay();
    }

    private function baseQuery()
    {
        $user = auth()->user();

        return Sale::query()
            ->whereBetween('created_at', [$this->date($this->from, false), $this->date($this->to, true)])
            // Cashiers only see their own sales
            ->when(! $user->can('view-reports'), fn ($q) => $q->where('user_id', $user->id));
    }

    public function with(): array
    {
        $base = $this->baseQuery();

        return [
            'sales' => (clone $base)
                ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
                ->with(['cashier', 'payments', 'customer'])->withCount('items')
                ->latest('id')->paginate(15),
            'summary' => [
                'count' => (clone $base)->where('status', 'completed')->count(),
                'total' => (clone $base)->where('status', 'completed')->sum('total'),
                'voided' => (clone $base)->where('status', 'voided')->count(),
            ],
        ];
    }

    public function startVoid(int $id): void
    {
        abort_unless(auth()->user()->can('void-sales'), 403);

        $sale = Sale::findOrFail($id);
        if ($sale->status !== 'completed') {
            return;
        }

        $this->resetErrorBag();
        $this->reason = '';
        $this->voidingId = $sale->id;
    }

    public function cancelVoid(): void
    {
        $this->reset('voidingId', 'reason');
        $this->resetErrorBag();
    }

    public function confirmVoid(): void
    {
        abort_unless(auth()->user()->can('void-sales'), 403);

        SaleService::void(Sale::findOrFail($this->voidingId), $this->reason);

        $this->cancelVoid();
    }
}; ?>

<div class="space-y-6">
    {{-- Filters --}}
    <x-card class="p-4">
        <div class="flex flex-wrap items-end gap-4">
            <div>
                <x-input-label for="from" value="From" />
                <x-text-input wire:model.live="from" id="from" type="date" class="mt-1" />
            </div>
            <div>
                <x-input-label for="to" value="To" />
                <x-text-input wire:model.live="to" id="to" type="date" class="mt-1" />
            </div>
            <div>
                <x-input-label for="status" value="Status" />
                <select wire:model.live="status" id="status" class="mt-1 text-sm">
                    <option value="all">All</option>
                    <option value="completed">Completed</option>
                    <option value="voided">Voided</option>
                </select>
            </div>
        </div>
    </x-card>

    {{-- Summary --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <x-stat-card label="Sales" :value="$summary['count']" caption="Completed in this period" />
        <x-stat-card label="Total" :value="'Kshs ' . number_format($summary['total'], 2)" caption="Excluding voided sales" />
        <x-stat-card label="Voided" :value="$summary['voided']" caption="Cancelled in this period" />
    </div>

    {{-- List --}}
    <x-card class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Receipt</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Served by</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Items</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Paid with</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse ($sales as $sale)
                    <tr wire:key="sale-{{ $sale->id }}" class="{{ $sale->status === 'voided' ? 'opacity-60' : '' }}">
                        <td class="px-4 py-3 font-medium">#{{ $sale->receipt_label }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $sale->created_at->format('d M Y, H:i') }}</td>
                        <td class="px-4 py-3">{{ $sale->cashier?->name ?? '-' }}</td>
                        <td class="px-4 py-3">{{ $sale->customer?->name ?? 'Walk-in' }}</td>
                        <td class="px-4 py-3 text-right">{{ $sale->items_count }}</td>
                        <td class="px-4 py-3">{{ $sale->payments->pluck('method_name')->unique()->implode(', ') }}</td>
                        <td class="px-4 py-3 text-right font-semibold">{{ number_format($sale->total, 2) }}</td>
                        <td class="px-4 py-3">
                            <x-badge :color="$sale->status === 'completed' ? 'green' : 'red'">{{ ucfirst($sale->status) }}</x-badge>
                        </td>
                        <td class="px-4 py-3 text-right space-x-3 whitespace-nowrap">
                            <a href="{{ route('sales.receipt', $sale) }}" wire:navigate class="text-brand-600 hover:underline">Receipt</a>
                            @can('void-sales')
                                @if ($sale->status === 'completed')
                                    <button wire:click="startVoid({{ $sale->id }})" class="text-red-600 hover:underline">Void</button>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-6 text-center text-gray-500">No sales in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-card>

    {{ $sales->links() }}

    {{-- Void confirmation --}}
    @if ($voidingId)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/40" wire:click="cancelVoid"></div>

            <x-card class="relative w-full max-w-md p-6 space-y-4 shadow-xl">
                <h3 class="text-lg font-semibold text-gray-900">Void this sale?</h3>
                <p class="text-sm text-gray-600">
                    The sale stays on record, marked as voided, and the items go back into stock. This can't be undone.
                </p>

                <div>
                    <x-input-label for="reason" value="Reason *" />
                    <x-text-input wire:model="reason" id="reason" type="text" maxlength="255" class="mt-1 block w-full"
                                  placeholder="e.g. Wrong item rung up" />
                    <x-input-error :messages="$errors->get('reason')" class="mt-2" />
                </div>

                <div class="flex gap-3">
                    <button type="button" wire:click="confirmVoid" wire:loading.attr="disabled"
                            class="inline-flex items-center px-4 py-2 rounded-lg bg-red-600 text-sm font-semibold text-white hover:bg-red-700">
                        Void sale
                    </button>
                    <x-secondary-button type="button" wire:click="cancelVoid">Keep sale</x-secondary-button>
                </div>
            </x-card>
        </div>
    @endif
</div>
