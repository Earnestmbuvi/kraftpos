<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Scopes\BranchViewScope;
use App\Services\BranchService;
use App\Services\ReportService;
use App\Services\SalesDetail;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;

new class extends Component
{
    public string $from = '';
    public string $to = '';
    public string $search = '';
    public ?int $customerId = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('view-reports'), 403);
        $this->from = now()->startOfYear()->toDateString();
        $this->to = now()->toDateString();
    }

    public function pick(int $id): void
    {
        $this->customerId = Customer::findOrFail($id)->id;
    }

    public function back(): void
    {
        $this->customerId = null;
    }

    public function with(): array
    {
        $bid = auth()->user()->business_id;
        [$f, $t] = ReportService::range($this->from, $this->to);

        $viewing = BranchService::viewing();
        $br = fn ($q) => $viewing !== null ? BranchService::limit($q, $viewing, 'branch_id') : $q;

        $agg = fn (string $table, string $ok) => $br(DB::table($table)->where('business_id', $bid)->where('status', $ok))
            ->whereNotNull('customer_id')->whereBetween('created_at', [$f, $t])
            ->selectRaw('customer_id, COUNT(*) as n, SUM(total) as total')->groupBy('customer_id')->get();

        $totals = $agg('sales', 'completed')->concat($agg('invoices', 'issued'))->groupBy('customer_id')
            ->map(fn ($g, $id) => (object) ['id' => (int) $id, 'n' => (int) $g->sum('n'), 'total' => (float) $g->sum('total')])
            ->sortByDesc('total')->values();

        $names = Customer::pluck('name', 'id');
        $phones = Customer::pluck('phone', 'id');
        $term = mb_strtolower(trim($this->search));
        $list = $totals->filter(fn ($r) => $term === '' || str_contains(mb_strtolower($names[$r->id] ?? ''), $term))->take(200);

        // What a customer owes is across all branches
        $owed = Invoice::withoutGlobalScope(BranchViewScope::class)->where('status', 'issued')
            ->selectRaw('customer_id, SUM(total - amount_paid) as o')
            ->groupBy('customer_id')->pluck('o', 'customer_id');

        $data = ['list' => $list, 'names' => $names, 'phones' => $phones, 'owed' => $owed, 'selected' => null];

        if ($this->customerId) {
            $docs = $br(DB::table('sales')->where('business_id', $bid)->where('customer_id', $this->customerId)->whereBetween('created_at', [$f, $t]))
                ->selectRaw("id, 'Till' as kind, receipt_number as number, created_at, total, status = 'completed' as ok")
                ->unionAll($br(DB::table('invoices')->where('business_id', $bid)->where('customer_id', $this->customerId)->whereBetween('created_at', [$f, $t]))
                    ->selectRaw("id, 'Invoice' as kind, invoice_number as number, created_at, total, status = 'issued' as ok"));

            $data['selected'] = Customer::find($this->customerId);
            $data['docs'] = DB::query()->fromSub($docs, 'd')->orderByDesc('created_at')->limit(100)->get();
            $data['bought'] = DB::query()->fromSub(SalesDetail::lines($f, $t, ['customer' => $this->customerId]), 'x')
                ->selectRaw('product_name, variant_name, SUM(quantity) as qty, SUM(line_total) as total')
                ->groupBy('product_name', 'variant_name')->orderByDesc('total')->get();
        }

        return $data;
    }
}; ?>

<div class="space-y-6">
    <x-card class="p-4">
        <div class="flex flex-wrap items-end gap-4">
            <div><x-input-label for="c_from" value="From" /><x-text-input wire:model.live="from" id="c_from" type="date" class="mt-1" /></div>
            <div><x-input-label for="c_to" value="To" /><x-text-input wire:model.live="to" id="c_to" type="date" class="mt-1" /></div>
            @if (! $selected)
                <div class="min-w-[12rem] flex-1">
                    <x-input-label for="c_search" value="Search customer" />
                    <x-text-input wire:model.live.debounce.300ms="search" id="c_search" type="search" class="mt-1 block w-full" />
                </div>
            @else
                <button type="button" wire:click="back" class="pb-2 text-sm text-brand-600 hover:underline">&larr; All customers</button>
                <a href="{{ route('reports.detail-export', ['kind' => 'customer', 'customer' => $selected->id, 'from' => $from, 'to' => $to]) }}"
                   class="ml-auto inline-flex items-center rounded-lg border border-brand-500 px-4 py-2 text-sm font-semibold text-brand-600 hover:bg-brand-50">Export CSV</a>
            @endif
        </div>
    </x-card>

    @if (! $selected)
        <x-card class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50"><tr>
                    @foreach (['Customer', 'Phone', 'Purchases', 'Spent', 'Owes', ''] as $h)
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ $h }}</th>
                    @endforeach
                </tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($list as $r)
                        <tr wire:key="cr-{{ $r->id }}">
                            <td class="px-4 py-2 font-medium">{{ $names[$r->id] ?? '-' }}</td>
                            <td class="px-4 py-2">{{ $phones[$r->id] ?? '-' }}</td>
                            <td class="px-4 py-2">{{ $r->n }}</td>
                            <td class="px-4 py-2 font-semibold">{{ number_format($r->total, 2) }}</td>
                            <td class="px-4 py-2 {{ ($owed[$r->id] ?? 0) > 0 ? 'text-red-600 font-semibold' : 'text-gray-400' }}">{{ ($owed[$r->id] ?? 0) > 0 ? number_format($owed[$r->id], 2) : '-' }}</td>
                            <td class="px-4 py-2 text-right"><button wire:click="pick({{ $r->id }})" class="text-brand-600 hover:underline">Open</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">No purchases linked to a customer in this period. Sales made without picking a customer are walk-ins.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-card>
    @else
        <x-card class="p-4">
            <p class="text-lg font-semibold text-gray-900">{{ $selected->name }}</p>
            <p class="text-sm text-gray-500">{{ $selected->phone }} {{ $selected->address }}</p>
        </x-card>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <x-card class="overflow-x-auto">
                <div class="border-b border-gray-200 px-4 py-3 font-medium text-gray-900">What they bought</div>
                <table class="min-w-full text-sm"><tbody class="divide-y divide-gray-100">
                    @forelse ($bought as $i => $b)
                        <tr wire:key="bt-{{ $i }}">
                            <td class="px-4 py-2">{{ $b->product_name }}@if ($b->variant_name) - {{ $b->variant_name }}@endif</td>
                            <td class="px-4 py-2 text-right text-gray-500">{{ $b->qty }}</td>
                            <td class="px-4 py-2 text-right font-semibold">{{ number_format($b->total, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td class="px-4 py-6 text-center text-gray-500">Nothing in this period.</td></tr>
                    @endforelse
                </tbody></table>
            </x-card>

            <x-card class="overflow-x-auto">
                <div class="border-b border-gray-200 px-4 py-3 font-medium text-gray-900">Sales and invoices</div>
                <table class="min-w-full text-sm"><tbody class="divide-y divide-gray-100">
                    @forelse ($docs as $d)
                        <tr wire:key="dc-{{ $d->kind }}-{{ $d->id }}" class="{{ $d->ok ? '' : 'opacity-60' }}">
                            <td class="px-4 py-2 font-medium">{{ \App\Services\SalesDetail::label($d->kind, $d->number) }}</td>
                            <td class="px-4 py-2">{{ \Carbon\Carbon::parse($d->created_at)->format('d M Y') }}</td>
                            <td class="px-4 py-2 text-right">{{ number_format($d->total, 2) }}@unless ($d->ok) <span class="text-xs text-red-600">voided</span>@endunless</td>
                        </tr>
                    @empty
                        <tr><td class="px-4 py-6 text-center text-gray-500">Nothing in this period.</td></tr>
                    @endforelse
                </tbody></table>
            </x-card>
        </div>
    @endif
</div>
