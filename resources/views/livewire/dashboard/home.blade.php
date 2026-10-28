<?php

use App\Models\Branch;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockBatch;
use App\Services\BranchService;
use App\Services\ReportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Component;

new class extends Component
{
    public string $period = 'today'; // today | week | month

    public function setPeriod(string $p): void
    {
        if (in_array($p, ['today', 'week', 'month'], true)) {
            $this->period = $p;
        }
    }

    private function shortcuts($u): array
    {
        $quote = Route::has('quotations.create') ? 'quotations.create' : 'quotations';

        $all = [
            ['Sell', 'sell', 'make-sales', 'bg-brand-500', 'M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 00-16.536-1.84M7.5 14.25L5.106 5.272M6 20.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm12.75 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z'],
            ['Invoice', 'invoices.create', 'issue-invoices', 'bg-indigo-500', 'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08M15.75 18.75v-1.875a3.375 3.375 0 00-3.375-3.375h-1.5a1.125 1.125 0 01-1.125-1.125v-1.5A3.375 3.375 0 006.375 7.5H5.25m11.9-3.664A2.251 2.251 0 0015 2.25h-1.5a2.251 2.251 0 00-2.15 1.586m5.8 0c.065.21.1.433.1.664v.75h-6V4.5c0-.231.035-.454.1-.664M6.75 7.5H4.875c-.621 0-1.125.504-1.125 1.125v12c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V16.5a9 9 0 00-9-9z'],
            ['Quotation', $quote, 'make-sales', 'bg-sky-500', 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z'],
            ['Add product', 'products', 'manage-products', 'bg-emerald-500', 'M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9'],
            ['Customer', 'customers', 'manage-customers', 'bg-pink-500', 'M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM3 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 019.374 21c-2.331 0-4.512-.645-6.374-1.766z'],
            ['Expense', 'expenses', 'record-expenses', 'bg-amber-500', 'M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
            ['Stock take', 'stock.take', 'adjust-stock', 'bg-teal-500', 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
            ['Returns', 'returns.create', 'process-returns', 'bg-rose-500', 'M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3'],
            ['Reports', 'reports', 'view-reports', 'bg-violet-500', 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z'],
        ];

        return collect($all)
            ->filter(fn ($s) => $u->can($s[2]) && Route::has($s[1]))
            ->map(fn ($s) => ['label' => $s[0], 'url' => route($s[1]), 'color' => $s[3], 'icon' => $s[4]])
            ->values()->all();
    }

    public function with(): array
    {
        $u = auth()->user();
        $own = ! $u->can('view-reports');
        $canCost = $u->can('view-reports') && $u->can('view-cost-prices');

        [$f, $t] = match ($this->period) {
            'week' => [now()->startOfWeek(), now()->endOfDay()],
            'month' => [now()->startOfMonth(), now()->endOfDay()],
            default => [now()->startOfDay(), now()->endOfDay()],
        };

        $mine = fn ($q) => $q->when($own, fn ($w) => $w->where('user_id', $u->id));
        $sales = fn () => $mine(Sale::where('status', 'completed')->whereBetween('created_at', [$f, $t]));
        $invs = fn () => $mine(Invoice::where('status', 'issued')->whereBetween('created_at', [$f, $t]));

       $count = $sales()->count() + $invs()->count();
        $gross = (float) $sales()->sum('total') + (float) $invs()->sum('total');
        $discount = (float) $sales()->sum('discount_amount') + (float) $invs()->sum('discount_amount')
    + (float) \App\Models\SaleItem::whereIn('sale_id', $sales()->select('id'))->sum('discount_amount')
    + (float) \App\Models\InvoiceItem::whereIn('invoice_id', $invs()->select('id'))->sum('discount_amount');
        $tax = (float) $sales()->sum('tax_amount') + (float) $invs()->sum('tax_amount');
        $returns = (float) $mine(SaleReturn::whereBetween('created_at', [$f, $t]))->sum('value');

        // Trend: hourly for today, daily otherwise
        $rows = $sales()->get(['created_at', 'total'])->concat($invs()->get(['created_at', 'total']));
        $buckets = [];
        if ($this->period === 'today') {
            for ($h = 0; $h < 24; $h++) {
                $buckets[sprintf('%02d', $h)] = 0.0;
            }
            foreach ($rows as $r) {
                $buckets[$r->created_at->format('H')] += (float) $r->total;
            }
            $labels = fn ($k) => $k . ':00';
        } else {
            for ($d = $f->copy(); $d->lte($t); $d->addDay()) {
                $buckets[$d->toDateString()] = 0.0;
            }
            foreach ($rows as $r) {
                $k = $r->created_at->toDateString();
                if (isset($buckets[$k])) {
                    $buckets[$k] += (float) $r->total;
                }
            }
            $labels = fn ($k) => \Carbon\Carbon::parse($k)->format('d M');
        }

        $keys = array_keys($buckets);
        $vals = array_values($buckets);
        $max = max($vals) > 0 ? max($vals) : 1;
        $n = count($vals);
        $pts = [];
        foreach ($vals as $i => $v) {
            $x = 10 + ($n > 1 ? $i / ($n - 1) * 580 : 290);
            $y = 170 - ($v / $max) * 150;
            $pts[] = round($x, 1) . ',' . round($y, 1);
        }
        $line = implode(' ', $pts);
        $area = $pts ? '10,170 ' . $line . ' ' . round(10 + ($n > 1 ? 580 : 290), 1) . ',170' : '';

        // Stock
        $variants = ProductVariant::with('product')->active()
            ->whereHas('product', fn ($q) => $q->active()->where('is_service', false))->get();
        $levels = $variants->map(fn ($v) => ['name' => $v->product->name . ($v->name !== 'Default' ? ' - ' . $v->name : ''),
            'unit' => $v->product->unit, 'qty' => $v->view_stock, 'low' => $v->low_stock_threshold, 'cost' => $v->cost_price]);
        $lowList = $levels->filter(fn ($l) => $l['qty'] <= $l['low'])->sortBy('qty')->take(6)->values();

        $tracking = Product::where('tracks_batches', true)->exists();
        $warn = (int) config('inventory.expiry_warn_days');
        $batches = fn () => StockBatch::where('quantity', '>', 0)->whereNotNull('expiry_date');

        // Receivables
        $open = fn () => Invoice::where('status', 'issued')->whereColumn('amount_paid', '<', 'total');
        $owedAll = (float) $open()->sum(DB::raw('total - amount_paid'));
        $owedLate = (float) $open()->whereDate('due_date', '<', today())->sum(DB::raw('total - amount_paid'));

        $profit = $canCost ? ReportService::profit($f, $t) : null;
        $methods = $u->can('view-reports') ? ReportService::sales($f, $t)['methods'] : collect();
        $top = $u->can('view-reports') ? ReportService::products($f, $t)['top']->take(5) : collect();

        $hour = (int) now()->format('G');

        return [
            'name' => explode(' ', trim($u->name))[0],
            'greeting' => $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening'),
            'branch' => Branch::find(BranchService::viewing() ?? 0)?->name ?? (BranchService::multi() ? 'All branches' : null),
            'shortcuts' => $this->shortcuts($u),
            'count' => $count, 'gross' => $gross, 'net' => $gross - $returns, 'avg' => $count ? $gross / $count : 0,
            'discount' => $discount, 'tax' => $tax, 'returns' => $returns,
            'expenses' => $u->can('view-reports') ? (float) Expense::where('status', 'recorded')
                ->whereDate('expense_date', '>=', $f->toDateString())->whereDate('expense_date', '<=', $t->toDateString())->sum('amount') : null,
            'profit' => $profit,
            'line' => $line, 'area' => $area, 'max' => $max,
            'firstLabel' => $labels($keys[0]), 'midLabel' => $labels($keys[intdiv(count($keys), 2)]), 'lastLabel' => $labels(end($keys)),
            'showOwed' => $u->can('issue-invoices'), 'owedAll' => $owedAll, 'owedLate' => $owedLate,
            'methods' => $methods, 'top' => $top,
            'recent' => $mine(Sale::with('customer'))->where('status', 'completed')->latest('id')->limit(6)->get(),
            'inStock' => $levels->where('qty', '>', 0)->count(),
            'outOfStock' => $levels->where('qty', '<=', 0)->count(),
            'lowCount' => $levels->filter(fn ($l) => $l['qty'] > 0 && $l['qty'] <= $l['low'])->count(),
            'stockValue' => $u->can('view-cost-prices') ? $levels->sum(fn ($l) => max($l['qty'], 0) * $l['cost']) : null,
            'lowList' => $lowList,
            'tracking' => $tracking, 'warn' => $warn,
            'expired' => $tracking ? $batches()->whereDate('expiry_date', '<', today()->toDateString())->count() : 0,
            'expiring' => $tracking ? $batches()->whereDate('expiry_date', '>=', today()->toDateString())
                ->whereDate('expiry_date', '<=', today()->addDays($warn)->toDateString())->count() : 0,
            'periodLabel' => ['today' => 'Today', 'week' => 'This week', 'month' => 'This month'][$this->period],
        ];
    }
}; ?>

<div class="space-y-6">
    {{-- Greeting and period --}}
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold text-gray-900">{{ $greeting }}, {{ $name }}</h2>
            <p class="text-sm text-gray-500">
                {{ now()->format('l, d F Y') }}@if ($branch) &middot; {{ $branch }}@endif
            </p>
        </div>
        <div class="inline-flex rounded-full bg-white p-1 shadow-sm ring-1 ring-gray-200">
            @foreach (['today' => 'Today', 'week' => 'This week', 'month' => 'This month'] as $key => $label)
                <button type="button" wire:click="setPeriod('{{ $key }}')"
                        class="rounded-full px-4 py-1.5 text-sm font-medium {{ $period === $key ? 'bg-brand-500 text-white' : 'text-gray-600 hover:bg-gray-100' }}">{{ $label }}</button>
            @endforeach
        </div>
    </div>

    {{-- Shortcuts --}}
    @if (count($shortcuts))
        <div class="grid grid-cols-3 gap-3 sm:grid-cols-5 lg:grid-cols-9">
            @foreach ($shortcuts as $s)
                <a href="{{ $s['url'] }}" wire:navigate wire:key="sc-{{ $s['label'] }}"
                   class="group flex flex-col items-center gap-2 rounded-2xl bg-white p-3 text-center shadow-sm ring-1 ring-gray-200 transition hover:-translate-y-0.5 hover:shadow-md">
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl text-white {{ $s['color'] }}">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $s['icon'] }}" />
                        </svg>
                    </span>
                    <span class="text-xs font-semibold text-gray-700 group-hover:text-brand-600">{{ $s['label'] }}</span>
                </a>
            @endforeach
        </div>
    @endif

    {{-- Headline cards --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div class="rounded-2xl bg-gradient-to-br from-brand-500 to-orange-400 p-5 text-white shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide opacity-90">Sales {{ strtolower($periodLabel) }}</p>
            <p class="mt-2 text-2xl font-bold">Kshs {{ number_format($gross, 2) }}</p>
            <p class="mt-1 text-xs opacity-90">{{ $count }} sale(s) and invoice(s)</p>
        </div>
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Net sales</p>
            <p class="mt-2 text-2xl font-bold text-gray-900">Kshs {{ number_format($net, 2) }}</p>
            <p class="mt-1 text-xs text-gray-500">After returns of {{ number_format($returns, 2) }}</p>
        </div>
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Average sale</p>
            <p class="mt-2 text-2xl font-bold text-gray-900">Kshs {{ number_format($avg, 2) }}</p>
            <p class="mt-1 text-xs text-gray-500">Per transaction</p>
        </div>
        @if ($profit)
            <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Net profit</p>
                <p class="mt-2 text-2xl font-bold {{ $profit['netProfit'] < 0 ? 'text-red-600' : 'text-green-700' }}">Kshs {{ number_format($profit['netProfit'], 2) }}</p>
                <p class="mt-1 text-xs text-gray-500">Gross {{ number_format($profit['profit'], 2) }} less expenses</p>
            </div>
        @else
            <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Discounts given</p>
                <p class="mt-2 text-2xl font-bold text-gray-900">Kshs {{ number_format($discount, 2) }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ $periodLabel }}</p>
            </div>
        @endif
    </div>

    {{-- Receivables --}}
    @if ($showOwed)
        @php $late = $owedAll > 0 ? round($owedLate / $owedAll * 100) : 0; @endphp
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <div>
                    <p class="font-semibold text-gray-900">Money owed to you</p>
                    <p class="text-sm text-gray-500">Unpaid invoices: Kshs {{ number_format($owedAll, 2) }}</p>
                </div>
                <a href="{{ route('invoices') }}" wire:navigate class="text-sm font-medium text-brand-600 hover:underline">View invoices</a>
            </div>
            <div class="mt-3 flex h-2.5 overflow-hidden rounded-full bg-gray-100">
                <div class="bg-sky-500" style="width: {{ 100 - $late }}%"></div>
                <div class="bg-orange-500" style="width: {{ $late }}%"></div>
            </div>
            <div class="mt-2 flex justify-between text-xs text-gray-600">
                <span><span class="mr-1 inline-block h-2 w-2 rounded-full bg-sky-500"></span>Current Kshs {{ number_format($owedAll - $owedLate, 2) }}</span>
                <span><span class="mr-1 inline-block h-2 w-2 rounded-full bg-orange-500"></span>Overdue Kshs {{ number_format($owedLate, 2) }} ({{ $late }}%)</span>
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Trend --}}
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200 lg:col-span-2">
            <div class="flex items-center justify-between">
                <p class="font-semibold text-gray-900">Sales trend</p>
                <span class="rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-600">Peak: Kshs {{ number_format($max > 1 ? $max : 0, 2) }}</span>
            </div>
            <svg viewBox="0 0 600 200" class="mt-3 h-56 w-full">
                @foreach ([20, 70, 120, 170] as $y)
                    <line x1="10" y1="{{ $y }}" x2="590" y2="{{ $y }}" stroke="#e5e7eb" stroke-dasharray="3 3" />
                @endforeach
                @if ($area)
                    <polygon points="{{ $area }}" fill="rgba(244,106,50,0.15)" />
                    <polyline points="{{ $line }}" fill="none" stroke="#f46a32" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" />
                @endif
                <text x="10" y="195" font-size="11" fill="#6b7280">{{ $firstLabel }}</text>
                <text x="300" y="195" font-size="11" fill="#6b7280" text-anchor="middle">{{ $midLabel }}</text>
                <text x="590" y="195" font-size="11" fill="#6b7280" text-anchor="end">{{ $lastLabel }}</text>
            </svg>
        </div>

        {{-- Summary --}}
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
            <p class="font-semibold text-gray-900">Sales summary</p>
            <dl class="mt-3 divide-y divide-gray-100 text-sm">
                <div class="flex justify-between py-2"><dt class="text-gray-600">Total discounts</dt><dd class="font-semibold">Kshs {{ number_format($discount, 2) }}</dd></div>
                <div class="flex justify-between py-2"><dt class="text-gray-600">Tax</dt><dd class="font-semibold">Kshs {{ number_format($tax, 2) }}</dd></div>
                <div class="flex justify-between py-2"><dt class="text-gray-600">Returns</dt><dd class="font-semibold">Kshs {{ number_format($returns, 2) }}</dd></div>
                @if ($expenses !== null)
                    <div class="flex justify-between py-2"><dt class="text-gray-600">Expenses</dt><dd class="font-semibold">Kshs {{ number_format($expenses, 2) }}</dd></div>
                @endif
                @if ($profit)
                    <div class="flex justify-between py-2"><dt class="text-gray-600">Cost of items sold</dt><dd class="font-semibold">Kshs {{ number_format($profit['cost'], 2) }}</dd></div>
                @endif
            </dl>

            @if ($methods->count())
                <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-gray-500">Money received</p>
                <div class="mt-2 space-y-1 text-sm">
                    @foreach ($methods as $m)
                        <div wire:key="dm-{{ $m->method_name }}" class="flex justify-between"><span class="text-gray-600">{{ $m->method_name }}</span><span class="font-medium">{{ number_format($m->net, 2) }}</span></div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Recent sales --}}
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
            <div class="flex items-center justify-between">
                <p class="font-semibold text-gray-900">Recent sales</p>
                <a href="{{ route('sales') }}" wire:navigate class="text-sm font-medium text-brand-600 hover:underline">All</a>
            </div>
            <div class="mt-3 divide-y divide-gray-100 text-sm">
                @forelse ($recent as $s)
                    <a href="{{ route('sales.receipt', $s) }}" target="_blank" wire:key="rs-{{ $s->id }}" class="flex items-center justify-between py-2 hover:bg-gray-50">
                        <span>
                            <span class="font-medium text-gray-900">{{ $s->receipt_label }}</span>
                            <span class="block text-xs text-gray-500">{{ $s->customer?->name ?? 'Walk-in' }}, {{ $s->created_at->format('d M H:i') }}</span>
                        </span>
                        <span class="font-semibold">{{ number_format($s->total, 2) }}</span>
                    </a>
                @empty
                    <p class="py-6 text-center text-gray-500">No sales yet.</p>
                @endforelse
            </div>
        </div>

        {{-- Top products --}}
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
            <p class="font-semibold text-gray-900">Top products {{ strtolower($periodLabel) }}</p>
            @php $topMax = max(1, (int) $top->max('qty')); @endphp
            <div class="mt-3 space-y-3 text-sm">
                @forelse ($top as $i => $p)
                    <div wire:key="tp-{{ $i }}">
                        <div class="flex justify-between"><span class="text-gray-800">{{ $p->product_name }}@if ($p->variant_name) - {{ $p->variant_name }}@endif</span><span class="text-gray-500">{{ $p->qty }} sold</span></div>
                        <div class="mt-1 h-1.5 rounded-full bg-gray-100"><div class="h-1.5 rounded-full bg-brand-500" style="width: {{ round($p->qty / $topMax * 100) }}%"></div></div>
                    </div>
                @empty
                    <p class="py-6 text-center text-gray-500">{{ auth()->user()->can('view-reports') ? 'Nothing sold yet.' : 'Visible to managers.' }}</p>
                @endforelse
            </div>
        </div>

        {{-- Stock --}}
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
            <p class="font-semibold text-gray-900">Stock</p>
            <div class="mt-3 grid grid-cols-3 gap-2 text-center">
                <div class="rounded-xl bg-green-50 p-2"><p class="text-lg font-bold text-green-700">{{ $inStock }}</p><p class="text-[11px] text-gray-600">In stock</p></div>
                <div class="rounded-xl bg-orange-50 p-2"><p class="text-lg font-bold text-orange-600">{{ $lowCount }}</p><p class="text-[11px] text-gray-600">Low</p></div>
                <div class="rounded-xl bg-red-50 p-2"><p class="text-lg font-bold text-red-600">{{ $outOfStock }}</p><p class="text-[11px] text-gray-600">Out</p></div>
            </div>
            @if ($stockValue !== null)
                <p class="mt-3 text-sm text-gray-600">Stock value at cost: <span class="font-semibold text-gray-900">Kshs {{ number_format($stockValue, 2) }}</span></p>
            @endif
            @if ($tracking)
                <div class="mt-3 flex gap-2 text-xs">
                    <span class="rounded-full bg-red-100 px-3 py-1 font-medium text-red-700">{{ $expired }} expired</span>
                    <span class="rounded-full bg-orange-100 px-3 py-1 font-medium text-orange-700">{{ $expiring }} expiring in {{ $warn }} days</span>
                </div>
            @endif
            <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-gray-500">Needs restocking</p>
            <div class="mt-2 space-y-1 text-sm">
                @forelse ($lowList as $i => $l)
                    <div wire:key="ll-{{ $i }}" class="flex justify-between"><span class="text-gray-700">{{ $l['name'] }}</span><span class="font-semibold {{ $l['qty'] <= 0 ? 'text-red-600' : 'text-orange-600' }}">{{ $l['qty'] }} {{ $l['unit'] }}</span></div>
                @empty
                    <p class="text-gray-500">Everything is stocked.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
