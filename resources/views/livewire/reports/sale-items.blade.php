<?php

use App\Models\Customer;
use App\Models\User;
use App\Services\ReportService;
use App\Services\SalesDetail;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $from = '';
    public string $to = '';
    public string $kind = 'all';
    public $userId = '';
    public string $search = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('make-sales'), 403);
        $this->from = $this->to = now()->toDateString();
    }

    public function updatedFrom(): void { $this->resetPage(); }
    public function updatedTo(): void { $this->resetPage(); }
    public function updatedKind(): void { $this->resetPage(); }
    public function updatedUserId(): void { $this->resetPage(); }
    public function updatedSearch(): void { $this->resetPage(); }

    public function with(): array
    {
        $user = auth()->user();
        [$f, $t] = ReportService::range($this->from, $this->to);
        $q = SalesDetail::lines($f, $t, ['kind' => $this->kind, 'user' => $this->userId, 'search' => $this->search]);

        $totals = (clone $q)->selectRaw(
            'COUNT(*) as n, COALESCE(SUM(quantity),0) as qty, COALESCE(SUM(line_total),0) as total, COALESCE(SUM(unit_cost * quantity),0) as cost'
        )->first();

        $rows = (clone $q)->orderByDesc('created_at')->orderByDesc('doc_id')->paginate(30);

        return [
            'rows' => $rows, 'totals' => $totals,
            'customers' => Customer::whereIn('id', collect($rows->items())->pluck('customer_id')->filter())->pluck('name', 'id'),
            'people' => User::whereIn('id', collect($rows->items())->pluck('user_id'))->pluck('name', 'id'),
            'staff' => $user->can('view-reports') ? User::where('business_id', $user->business_id)->orderBy('name')->get(['id', 'name']) : collect(),
            'canCost' => $user->can('view-cost-prices'),
        ];
    }
}; ?>

<div class="space-y-6">
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <x-stat-card label="Lines" :value="$totals->n" caption="Product lines sold" />
        <x-stat-card label="Units" :value="number_format($totals->qty)" caption="Quantity sold" />
        <x-stat-card label="Value" :value="'Kshs ' . number_format($totals->total, 2)" caption="Line totals, before cart discount and tax" />
        @if ($canCost)
            <x-stat-card label="Cost" :value="'Kshs ' . number_format($totals->cost, 2)" caption="Cost of those items" />
        @endif
    </div>

    <x-card class="p-4">
        <div class="flex flex-wrap items-end gap-4">
            <div><x-input-label for="s_from" value="From" /><x-text-input wire:model.live="from" id="s_from" type="date" class="mt-1" /></div>
            <div><x-input-label for="s_to" value="To" /><x-text-input wire:model.live="to" id="s_to" type="date" class="mt-1" /></div>
            <div>
                <x-input-label for="s_kind" value="Show" />
                <select wire:model.live="kind" id="s_kind" class="mt-1 text-sm">
                    <option value="all">Sales and invoices</option><option value="sale">Till sales</option><option value="invoice">Invoices</option>
                </select>
            </div>
            @if ($staff->isNotEmpty())
                <div>
                    <x-input-label for="s_user" value="Served by" />
                    <select wire:model.live="userId" id="s_user" class="mt-1 text-sm">
                        <option value="">Everyone</option>
                        @foreach ($staff as $s) <option value="{{ $s->id }}">{{ $s->name }}</option> @endforeach
                    </select>
                </div>
            @endif
            <div class="min-w-[10rem] flex-1">
                <x-input-label for="s_search" value="Product" />
                <x-text-input wire:model.live.debounce.300ms="search" id="s_search" type="search" class="mt-1 block w-full" />
            </div>
            <a href="{{ route('reports.detail-export', ['kind' => 'items', 'from' => $from, 'to' => $to, 'show' => $kind, 'user' => $userId ?: null, 'search' => $search ?: null]) }}"
               class="inline-flex items-center rounded-lg border border-brand-500 px-4 py-2 text-sm font-semibold text-brand-600 hover:bg-brand-50">Export CSV</a>
        </div>
    </x-card>

    <x-card class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50"><tr>
                @foreach (['Date', 'Number', 'Product', 'Qty', 'Price', 'Discount', 'Total', 'Customer', 'Served by'] as $h)
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ $h }}</th>
                @endforeach
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $i => $r)
                    <tr wire:key="sl-{{ $r->kind }}-{{ $r->doc_id }}-{{ $i }}">
                        <td class="px-4 py-2 whitespace-nowrap">{{ \Carbon\Carbon::parse($r->created_at)->format('d M, H:i') }}</td>
                        <td class="px-4 py-2">{{ \App\Services\SalesDetail::label($r->kind, $r->number) }}</td>
                        <td class="px-4 py-2">{{ $r->product_name }}@if ($r->variant_name) <span class="text-gray-500">- {{ $r->variant_name }}</span>@endif</td>
                        <td class="px-4 py-2">{{ $r->quantity }}</td>
                        <td class="px-4 py-2">{{ number_format($r->unit_price, 2) }}</td>
                        <td class="px-4 py-2">{{ $r->discount_amount > 0 ? number_format($r->discount_amount, 2) : '-' }}</td>
                        <td class="px-4 py-2 font-semibold">{{ number_format($r->line_total, 2) }}</td>
                        <td class="px-4 py-2">{{ $customers[$r->customer_id] ?? 'Walk-in' }}</td>
                        <td class="px-4 py-2">{{ $people[$r->user_id] ?? '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-6 text-center text-gray-500">Nothing in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-card>
    {{ $rows->links() }}
</div>
