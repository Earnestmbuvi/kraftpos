<?php

use App\Services\ReportService;
use Livewire\Volt\Component;

new class extends Component
{
    public string $from = '';
    public string $to = '';
    public string $tab = 'sales';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('view-reports'), 403);

        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->toDateString();
    }

    public function setTab(string $tab): void
    {
        if (array_key_exists($tab, $this->tabs())) {
            $this->tab = $tab;
        }
    }

    private function tabs(): array
    {
        $tabs = ['sales' => 'Sales', 'products' => 'Top products'];

        if (auth()->user()->can('view-cost-prices')) {
            $tabs['profit'] = 'Profit';
        }

        $extra = \App\Models\Product::where('tracks_batches', true)->exists() ? ['expiry' => 'Expiry'] : [];

return $tabs + ['expenses' => 'Expenses'] + $extra + ['stock' => 'Low stock', 'owed' => 'Owed to you'];
    }

    public function with(): array
    {
        $tabs = $this->tabs();

        // A tampered request must not reach a tab this person may not see
        abort_unless(array_key_exists($this->tab, $tabs), 403);

        [$f, $t] = ReportService::range($this->from, $this->to);

        return ['tabs' => $tabs] + match ($this->tab) {
            'sales' => ReportService::sales($f, $t),
            'products' => ReportService::products($f, $t),
            'profit' => ReportService::profit($f, $t),
            'expenses' => ReportService::expenses($f, $t),
            'stock' => ReportService::stock(),
            'owed' => ReportService::owed(),
            'expiry' => ReportService::expiry(),
        };
    }
}; ?>

<div class="space-y-6">
    {{-- Date range (stock and owed are "right now", so they ignore it) --}}
    <x-card class="p-4">
        <div class="flex flex-wrap items-end gap-4">
            <div>
                <x-input-label for="r_from" value="From" />
                <x-text-input wire:model.live="from" id="r_from" type="date" class="mt-1" />
            </div>
            <div>
                <x-input-label for="r_to" value="To" />
                <x-text-input wire:model.live="to" id="r_to" type="date" class="mt-1" />
            </div>
            <div class="flex gap-2 text-sm">
                <button type="button" wire:click="$set('from', '{{ today()->toDateString() }}'); $set('to', '{{ today()->toDateString() }}')" class="text-brand-600 hover:underline">Today</button>
                <button type="button" wire:click="$set('from', '{{ now()->startOfWeek()->toDateString() }}'); $set('to', '{{ today()->toDateString() }}')" class="text-brand-600 hover:underline">This week</button>
                <button type="button" wire:click="$set('from', '{{ now()->startOfMonth()->toDateString() }}'); $set('to', '{{ today()->toDateString() }}')" class="text-brand-600 hover:underline">This month</button>
            </div>
            <a href="{{ route('reports.export', ['report' => $tab, 'from' => $from, 'to' => $to]) }}"
   class="ml-auto inline-flex items-center px-4 py-2 rounded-lg border border-brand-500 text-sm font-semibold text-brand-600 hover:bg-brand-50">
    Export CSV
</a>
        </div>
    </x-card>

    <div class="border-b border-gray-200 overflow-x-auto">
        <nav class="-mb-px flex space-x-8">
            @foreach ($tabs as $key => $label)
                <button wire:key="rtab-{{ $key }}" wire:click="setTab('{{ $key }}')"
                        class="py-3 px-1 border-b-2 text-sm font-medium whitespace-nowrap {{ $tab === $key ? 'border-brand-500 text-brand-600' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
                    {{ $label }}
                </button>
            @endforeach
        </nav>
    </div>

    @if ($tab === 'sales')
       <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
    <x-stat-card label="Sales" :value="$summary->n" caption="Till sales and invoices" />
    <x-stat-card label="Total" :value="'Kshs ' . number_format($summary->total, 2)"
                 :caption="'Till ' . number_format($summary->till_total, 2) . ' + invoices ' . number_format($summary->invoice_total, 2)" />
    <x-stat-card label="Discounts" :value="'Kshs ' . number_format($summary->discount, 2)" caption="Line and cart discounts" />
    <x-stat-card label="Tax" :value="'Kshs ' . number_format($summary->tax, 2)" caption="Charged or included" />
    <x-stat-card label="Credit unpaid" :value="'Kshs ' . number_format($summary->credit_open, 2)" caption="On invoices issued in this period" />
    <x-stat-card label="Returns" :value="'Kshs ' . number_format($summary->returns, 2)" caption="Refunds and exchange credit" />
</div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <x-card class="overflow-x-auto">
                <div class="px-4 py-3 border-b border-gray-200 font-medium text-gray-900">By day</div>
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($daily as $d)
                            <tr wire:key="day-{{ $d->day }}">
                                <td class="px-4 py-2">{{ \Carbon\Carbon::parse($d->day)->format('D d M') }}</td>
                                <td class="px-4 py-2 text-right text-gray-500">{{ $d->n }} sales</td>
                                <td class="px-4 py-2 text-right font-semibold">{{ number_format($d->total, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td class="px-4 py-6 text-center text-gray-500">No sales in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-card>

            <div class="space-y-6">
                <x-card class="overflow-x-auto">
                    <div class="px-4 py-3 border-b border-gray-200 font-medium text-gray-900">By payment method</div>
                    <table class="min-w-full text-sm">
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($methods as $m)
                                <tr wire:key="meth-{{ $m->method_name }}">
                                    <td class="px-4 py-2">{{ $m->method_name }}</td>
                                    <td class="px-4 py-2 text-right font-semibold">{{ number_format($m->net, 2) }}</td>
                                </tr>
                            @empty
                                <tr><td class="px-4 py-6 text-center text-gray-500">No payments.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    <p class="px-4 pb-3 text-xs text-gray-500">Cash is net of change given.</p>
                </x-card>

                <x-card class="overflow-x-auto">
                    <div class="px-4 py-3 border-b border-gray-200 font-medium text-gray-900">By cashier</div>
                    <table class="min-w-full text-sm">
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($cashiers as $c)
                                <tr wire:key="csh-{{ $c->cashier }}">
                                    <td class="px-4 py-2">{{ $c->cashier }}</td>
                                    <td class="px-4 py-2 text-right text-gray-500">{{ $c->n }} sales</td>
                                    <td class="px-4 py-2 text-right font-semibold">{{ number_format($c->total, 2) }}</td>
                                </tr>
                            @empty
                                <tr><td class="px-4 py-6 text-center text-gray-500">No sales.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </x-card>
            </div>
        </div>
    @endif

    @if ($tab === 'products')
        <x-card class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Product</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Quantity sold</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Revenue</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($top as $i => $p)
                        <tr wire:key="top-{{ $i }}">
                            <td class="px-4 py-2">{{ $p->product_name }}@if ($p->variant_name) - {{ $p->variant_name }}@endif</td>
                            <td class="px-4 py-2 text-right">{{ $p->qty }}</td>
                            <td class="px-4 py-2 text-right font-semibold">{{ number_format($p->revenue, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-6 text-center text-gray-500">No sales in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-card>
    @endif

    @if ($tab === 'profit')
       <div class="grid grid-cols-2 lg:grid-cols-3 gap-4">
    <x-stat-card label="Net revenue" :value="'Kshs ' . number_format($net, 2)" caption="After discounts, excluding tax" />
    <x-stat-card label="Cost of goods" :value="'Kshs ' . number_format($cost, 2)" caption="Cost saved on each sale" />
    <x-stat-card label="Gross profit" :value="'Kshs ' . number_format($profit, 2)" caption="Net revenue minus cost" />
    <x-stat-card label="Gross margin" :value="$margin . '%'" caption="Gross profit / net revenue" />
    <x-stat-card label="Expenses" :value="'Kshs ' . number_format($expenses, 2)" caption="Voided expenses excluded" />
    <x-stat-card label="Net profit" :value="'Kshs ' . number_format($netProfit, 2)" caption="Gross profit minus expenses" />
</div>

        <x-card class="overflow-x-auto">
            <div class="px-4 py-3 border-b border-gray-200 font-medium text-gray-900">Most profitable products</div>
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <tbody class="divide-y divide-gray-100">
                    @forelse ($byProduct as $i => $p)
                        <tr wire:key="prof-{{ $i }}">
                            <td class="px-4 py-2">{{ $p->product_name }}@if ($p->variant_name) - {{ $p->variant_name }}@endif</td>
                            <td class="px-4 py-2 text-right text-gray-500">{{ $p->qty }} sold</td>
                            <td class="px-4 py-2 text-right font-semibold">{{ number_format($p->profit, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td class="px-4 py-6 text-center text-gray-500">No sales in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <p class="px-4 pb-3 text-xs text-gray-500">Per-product profit is before whole-cart discounts. Invoices are not included here.</p>
        </x-card>
    @endif
        @if ($tab === 'expenses')
    <x-stat-card label="Total expenses" :value="'Kshs ' . number_format($expenseTotal, 2)" caption="In this period, voided excluded" />

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <x-card class="overflow-x-auto">
            <div class="px-4 py-3 border-b border-gray-200 font-medium text-gray-900">By category</div>
            <table class="min-w-full text-sm">
                <tbody class="divide-y divide-gray-100">
                    @forelse ($byCategory as $c)
                        <tr wire:key="xc-{{ $c->category_name }}">
                            <td class="px-4 py-2">{{ $c->category_name }}</td>
                            <td class="px-4 py-2 text-right text-gray-500">{{ $c->n }} entries</td>
                            <td class="px-4 py-2 text-right font-semibold">{{ number_format($c->total, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td class="px-4 py-6 text-center text-gray-500">No expenses in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-card>

        <x-card class="overflow-x-auto">
            <div class="px-4 py-3 border-b border-gray-200 font-medium text-gray-900">Latest entries</div>
            <table class="min-w-full text-sm">
                <tbody class="divide-y divide-gray-100">
                    @forelse ($list as $e)
                        <tr wire:key="xl-{{ $e->id }}">
                            <td class="px-4 py-2 whitespace-nowrap">{{ $e->expense_date->format('d M') }}</td>
                            <td class="px-4 py-2">{{ $e->description }} <span class="text-xs text-gray-500">({{ $e->category_name }})</span></td>
                            <td class="px-4 py-2 text-right font-semibold">{{ number_format($e->amount, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td class="px-4 py-6 text-center text-gray-500">Nothing recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-card>
    </div>
@endif
    @if ($tab === 'stock')
        <x-card class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Item</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">In stock</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Alert at</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($low as $v)
                        <tr wire:key="low-{{ $v->id }}">
                            <td class="px-4 py-2">{{ $v->product->name }}@if ($v->name !== 'Default') - {{ $v->name }}@endif</td>
                            <td class="px-4 py-2 text-right font-semibold {{ $v->view_stock <= 0 ? 'text-red-600' : 'text-orange-600' }}">{{ $v->view_stock }} {{ $v->product->unit }}</td>
                            <td class="px-4 py-2 text-right text-gray-500">{{ $v->low_stock_threshold }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-6 text-center text-gray-500">Nothing is running low.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-card>
    @endif

    @if ($tab === 'owed')
        <x-stat-card label="Total owed" :value="'Kshs ' . number_format($owedTotal, 2)" caption="On issued invoices" />

        <x-card class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Due</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Balance</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($owed as $inv)
                        <tr wire:key="owed-{{ $inv->id }}">
                            <td class="px-4 py-2 font-medium">{{ $inv->number_label }}</td>
                            <td class="px-4 py-2">{{ $inv->customer?->name }}@if ($inv->customer?->phone) <span class="text-gray-500">({{ $inv->customer->phone }})</span>@endif</td>
                            <td class="px-4 py-2">
                                {{ $inv->due_date?->format('d M Y') ?? '-' }}
                                @if ($inv->payment_status === 'overdue') <x-badge color="red">Overdue</x-badge> @endif
                            </td>
                            <td class="px-4 py-2 text-right font-semibold">{{ number_format($inv->balance, 2) }}</td>
                            <td class="px-4 py-2 text-right">
                                <a href="{{ route('invoices.show', $inv) }}" wire:navigate class="text-brand-600 hover:underline">Open</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">Nobody owes you anything.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-card>
    @endif
    @if ($tab === 'expiry')
    <div class="grid grid-cols-2 gap-4">
        <x-stat-card label="Expired" :value="$expiredCount" caption="Batches still on the shelf" />
        <x-stat-card label="Expiring soon" :value="$batches->count() - $expiredCount" caption="Within {{ $warnDays }} days" />
    </div>

    <x-card class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Item</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Batch</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Expires</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Quantity</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($batches as $b)
                    @php $days = (int) today()->diffInDays($b->expiry_date, false); @endphp
                    <tr wire:key="exb-{{ $b->id }}">
                        <td class="px-4 py-2">{{ $b->variant->product->name }}@if ($b->variant->name !== 'Default') - {{ $b->variant->name }}@endif</td>
                        <td class="px-4 py-2">{{ $b->batch_number ?? '-' }}</td>
                        <td class="px-4 py-2">
                            {{ $b->expiry_date->format('d M Y') }}
                            <x-badge :color="$days < 0 ? 'red' : 'orange'">{{ $days < 0 ? 'Expired' : $days . ' days left' }}</x-badge>
                        </td>
                        <td class="px-4 py-2 text-right font-semibold">{{ $b->quantity }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-gray-500">Nothing is expiring soon.</td></tr>
                @endforelse
            </tbody>
        </table>
        <p class="px-4 pb-3 text-xs text-gray-500">Write expired stock off on the Stock screen, choosing its batch and the reason "Expired".</p>
    </x-card>
@endif
</div>
