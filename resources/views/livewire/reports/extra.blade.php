<?php

use App\Models\Category;
use App\Models\ExpenseCategory;
use App\Services\ReportService;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public const KINDS = [
        'stock-list' => 'stocklist',
        'additions' => 'additions',
        'price-list' => 'pricelist',
        'expenses' => 'expensereport',
        'income' => 'income',
    ];

    #[Locked]
    public string $kind = 'stock-list';

    public string $from = '';
    public string $to = '';
    public $categoryId = '';
    public $expenseCategoryId = '';
    public string $status = 'all';
    public string $search = '';

    public function mount(string $kind): void
    {
        $user = auth()->user();

        abort_unless($user->can('view-reports'), 403);
        abort_unless(array_key_exists($kind, self::KINDS), 404);
        abort_if($kind === 'income' && ! $user->can('view-cost-prices'), 403);

        $this->kind = $kind;
        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->toDateString();
    }

    public function updatedFrom(): void { $this->resetPage(); }
    public function updatedTo(): void { $this->resetPage(); }
    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedStatus(): void { $this->resetPage(); }
    public function updatedCategoryId(): void { $this->resetPage(); }
    public function updatedExpenseCategoryId(): void { $this->resetPage(); }

    public function with(): array
    {
        $canCost = auth()->user()->can('view-cost-prices');
        [$f, $t] = ReportService::range($this->from, $this->to);
        $from = $f->toDateString();
        $to = $t->toDateString();
        $filters = ['category' => $this->categoryId ?: null, 'status' => $this->status, 'search' => $this->search];
        $expcat = $this->expenseCategoryId ? (int) $this->expenseCategoryId : null;

        $data = [
            'canCost' => $canCost,
            'categories' => Category::active()->with('parent')->orderBy('name')->get(),
            'expenseCategories' => ExpenseCategory::orderBy('name')->get(),
            'exportKey' => self::KINDS[$this->kind],
        ];

        return $data + match ($this->kind) {
            'stock-list' => [
                'items' => ReportService::stockList($filters)->paginate(25),
                'totals' => ReportService::stockTotals($filters),
            ],
            'additions' => [
                'items' => ReportService::additions($from, $to, $this->search)->latest('id')->paginate(25),
                'totals' => ReportService::additionTotals($from, $to, $this->search, $canCost),
            ],
            'price-list' => [
                'groups' => ReportService::priceList($filters)->limit(2000)->get()
                    ->groupBy(fn ($v) => $v->product->category?->name ?? 'Uncategorised')->sortKeys(),
            ],
            'expenses' => [
                'items' => ReportService::expenseQuery($f, $t, $expcat)->with('user')
                    ->latest('expense_date')->latest('id')->paginate(20),
                'summary' => ReportService::expenseReport($f, $t, $expcat),
            ],
            'income' => [
                'statement' => ReportService::income($f, $t),
            ],
        };
    }
}; ?>

<div class="space-y-6">
    @php
        $titles = [
            'stock-list' => 'Stock list', 'additions' => 'Stock additions', 'price-list' => 'Price list',
            'expenses' => 'Expense report', 'income' => 'Income statement',
        ];
        $ranged = in_array($kind, ['additions', 'expenses', 'income']);
    @endphp

    {{-- Heading shown only on paper --}}
    <div class="hidden text-center print:block">
        <p class="text-xl font-bold">{{ auth()->user()->business->name }}</p>
        <p class="font-semibold">{{ $titles[$kind] }}</p>
        <p class="text-sm text-gray-600">
            {{ $ranged ? \Carbon\Carbon::parse($from)->format('d M Y') . ' to ' . \Carbon\Carbon::parse($to)->format('d M Y') : 'As at ' . now()->format('d M Y, H:i') }}
        </p>
    </div>

    <x-card class="p-4 print:hidden">
        <div class="flex flex-wrap items-end gap-4">
            @if ($ranged)
                <div>
                    <x-input-label for="x_from" value="From" />
                    <x-text-input wire:model.live="from" id="x_from" type="date" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="x_to" value="To" />
                    <x-text-input wire:model.live="to" id="x_to" type="date" class="mt-1" />
                </div>
            @endif

            @if (in_array($kind, ['stock-list', 'price-list']))
                <div>
                    <x-input-label for="x_cat" value="Category" />
                    <select wire:model.live="categoryId" id="x_cat" class="mt-1 text-sm">
                        <option value="">All</option>
                        @foreach ($categories as $c)
                            <option value="{{ $c->id }}">{{ $c->parent ? $c->parent->name . ' → ' . $c->name : $c->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if ($kind === 'stock-list')
                <div>
                    <x-input-label for="x_status" value="Show" />
                    <select wire:model.live="status" id="x_status" class="mt-1 text-sm">
                        <option value="all">All items</option>
                        <option value="low">Low stock</option>
                        <option value="out">Out of stock</option>
                    </select>
                </div>

            @endif

            @if ($kind === 'expenses')
                <div>
                    <x-input-label for="x_expcat" value="Category" />
                    <select wire:model.live="expenseCategoryId" id="x_expcat" class="mt-1 text-sm">
                        <option value="">All</option>
                        @foreach ($expenseCategories as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if (in_array($kind, ['stock-list', 'additions', 'price-list']))
                <div class="min-w-[12rem] flex-1">
                    <x-input-label for="x_search" value="Search" />
                    <x-text-input wire:model.live.debounce.300ms="search" id="x_search" type="search" class="mt-1 block w-full" placeholder="Name, SKU or barcode" />
                </div>
            @endif

            <div class="ml-auto flex gap-3">
                <button type="button" onclick="window.print()"
                        class="inline-flex items-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Print</button>
                <a href="{{ route('reports.export', ['report' => $exportKey, 'from' => $from, 'to' => $to,
                        'category' => $categoryId ?: null, 'status' => $status, 'search' => $search ?: null,
                        'expcat' => $expenseCategoryId ?: null]) }}"
                   class="inline-flex items-center rounded-lg border border-brand-500 px-4 py-2 text-sm font-semibold text-brand-600 hover:bg-brand-50">Export CSV</a>
            </div>
        </div>
    </x-card>

    {{-- STOCK LIST --}}
    @if ($kind === 'stock-list')
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <x-stat-card label="Items" :value="$totals->items" caption="Matching the filters" />
            <x-stat-card label="Units in stock" :value="number_format($totals->units)" caption="Negative stock counts as 0" />
            @if ($canCost)
                <x-stat-card label="Value at cost" :value="'Kshs ' . number_format($totals->cost_value, 2)" caption="Stock on hand x cost" />
            @endif
            <x-stat-card label="Value at selling price" :value="'Kshs ' . number_format($totals->retail_value, 2)" caption="If all sold at list price" />
        </div>

        <x-card class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Item</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">SKU</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">In stock</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Alert at</th>
                        @if ($canCost)
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Value at cost</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($items as $v)
                        <tr wire:key="sl-{{ $v->id }}">
                            <td class="px-4 py-2">{{ $v->product->name }}@if ($v->name !== 'Default') <span class="text-gray-500">- {{ $v->name }}</span>@endif</td>
                            <td class="px-4 py-2">{{ $v->product->category?->name ?? '-' }}</td>
                            <td class="px-4 py-2">{{ $v->sku ?? '-' }}</td>
                            <td class="px-4 py-2 text-right {{ $v->view_stock <= $v->low_stock_threshold ? 'font-semibold text-red-600' : '' }}">
                                {{ $v->view_stock }} {{ $v->product->unit }}
                            </td>
                            <td class="px-4 py-2 text-right text-gray-500">{{ $v->low_stock_threshold }}</td>
                            @if ($canCost)
                                <td class="px-4 py-2 text-right">{{ number_format(max($v->view_stock, 0) * $v->cost_price, 2) }}</td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">No items match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-card>
        <div class="print:hidden">{{ $items->links() }}</div>
    @endif

    {{-- STOCK ADDITIONS --}}
    @if ($kind === 'additions')
        <div class="grid grid-cols-2 lg:grid-cols-3 gap-4">
            <x-stat-card label="Entries" :value="$totals->entries" caption="Stock received in this period" />
            <x-stat-card label="Units added" :value="number_format($totals->units)" caption="Opening, purchases and count surpluses" />
            @if ($canCost)
                <x-stat-card label="Value at cost" :value="'Kshs ' . number_format($totals->value, 2)" caption="At each item's current cost" />
            @endif
        </div>

        <x-card class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Item</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Batch</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Added</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reason</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">By</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($items as $m)
                        <tr wire:key="ad-{{ $m->id }}">
                            <td class="px-4 py-2 whitespace-nowrap">{{ $m->created_at->format('d M Y, H:i') }}</td>
                            <td class="px-4 py-2">{{ $m->variant->product->name }}@if ($m->variant->name !== 'Default') <span class="text-gray-500">- {{ $m->variant->name }}</span>@endif</td>
                            <td class="px-4 py-2">
                                @if ($m->batch)
                                    {{ $m->batch->batch_number }}
                                    @if ($m->batch->expiry_date) <span class="text-xs text-gray-500">exp {{ $m->batch->expiry_date->format('d M Y') }}</span> @endif
                                @else - @endif
                            </td>
                            <td class="px-4 py-2 text-right font-semibold text-green-700">+{{ $m->quantity_change }}</td>
                            <td class="px-4 py-2">{{ $m->reason ?? ucfirst($m->type) }}@if ($m->note) <span class="text-gray-500">({{ $m->note }})</span>@endif</td>
                            <td class="px-4 py-2">{{ $m->user?->name ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">No stock was added in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-card>
        <div class="print:hidden">{{ $items->links() }}</div>
    @endif

    {{-- PRICE LIST --}}
    @if ($kind === 'price-list')
        @forelse ($groups as $category => $variants)
            <x-card class="overflow-x-auto">
                <div class="border-b border-gray-200 px-4 py-2 font-semibold text-gray-900">{{ $category }}</div>
                <table class="min-w-full text-sm">
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($variants as $v)
                            <tr wire:key="pl-{{ $v->id }}">
                                <td class="px-4 py-2">{{ $v->product->name }}@if ($v->name !== 'Default') <span class="text-gray-500">- {{ $v->name }}</span>@endif</td>
                                <td class="px-4 py-2 text-gray-500">{{ $v->product->unit }}</td>
                                <td class="px-4 py-2 text-right font-semibold">{{ number_format($v->selling_price, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
        @empty
            <p class="py-6 text-center text-gray-500">No items match.</p>
        @endforelse
        <p class="text-xs text-gray-500">Prices are what customers pay at the till. Cost prices are never shown on this list.</p>
    @endif

    {{-- EXPENSE REPORT --}}
    @if ($kind === 'expenses')
        <div class="grid grid-cols-2 gap-4">
            <x-stat-card label="Total expenses" :value="'Kshs ' . number_format($summary['total'], 2)" caption="Voided expenses excluded" />
            <x-stat-card label="Entries" :value="$summary['entries']" caption="In this period" />
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <x-card class="overflow-x-auto">
                <div class="border-b border-gray-200 px-4 py-3 font-medium text-gray-900">By category</div>
                <table class="min-w-full text-sm">
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($summary['byCategory'] as $c)
                            <tr wire:key="ebc-{{ $c->category_name }}">
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
                <div class="border-b border-gray-200 px-4 py-3 font-medium text-gray-900">By person</div>
                <table class="min-w-full text-sm">
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($summary['byUser'] as $u)
                            <tr wire:key="ebu-{{ $u->who }}">
                                <td class="px-4 py-2">{{ $u->who }}</td>
                                <td class="px-4 py-2 text-right text-gray-500">{{ $u->n }} entries</td>
                                <td class="px-4 py-2 text-right font-semibold">{{ number_format($u->total, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td class="px-4 py-6 text-center text-gray-500">Nothing recorded.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-card>
        </div>

        <x-card class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">By</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($items as $e)
                        <tr wire:key="exl-{{ $e->id }}">
                            <td class="px-4 py-2 whitespace-nowrap">{{ $e->expense_date->format('d M Y') }}</td>
                            <td class="px-4 py-2">{{ $e->category_name }}</td>
                            <td class="px-4 py-2">{{ $e->description }}@if ($e->method_name || $e->reference) <span class="text-xs text-gray-500">({{ collect([$e->method_name, $e->reference])->filter()->implode(', ') }})</span>@endif</td>
                            <td class="px-4 py-2">{{ $e->user?->name ?? '-' }}</td>
                            <td class="px-4 py-2 text-right font-semibold">{{ number_format($e->amount, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">No expenses match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-card>
        <div class="print:hidden">{{ $items->links() }}</div>
    @endif

    {{-- INCOME STATEMENT --}}
    @if ($kind === 'income')
        <x-card class="max-w-2xl p-6">
            <table class="min-w-full text-sm">
                <tbody>
                    <tr><td colspan="2" class="pb-1 text-xs font-semibold uppercase text-gray-500">Revenue (after discounts, excluding tax)</td></tr>
                    <tr><td class="py-1 pl-4">Till sales</td><td class="py-1 text-right">{{ number_format($statement['tillRevenue'], 2) }}</td></tr>
                    <tr><td class="py-1 pl-4">Invoiced sales (credit)</td><td class="py-1 text-right">{{ number_format($statement['invoiceRevenue'], 2) }}</td></tr>
                    <tr class="border-t border-gray-200 font-semibold"><td class="py-2">Total revenue</td><td class="py-2 text-right">{{ number_format($statement['revenue'], 2) }}</td></tr>
                    @if ($statement['returns'] > 0)
    <tr><td class="py-1 pl-4 text-gray-600">Included: sales returned</td><td class="py-1 text-right text-gray-600">({{ number_format($statement['returns'], 2) }})</td></tr>
                    @endif
                    <tr><td class="py-1">Cost of goods sold</td><td class="py-1 text-right">({{ number_format($statement['cost'], 2) }})</td></tr>
                    <tr class="border-t border-gray-300 font-bold text-gray-900">
                        <td class="py-2">Gross profit <span class="font-normal text-gray-500">({{ $statement['grossMargin'] }}%)</span></td>
                        <td class="py-2 text-right">{{ number_format($statement['gross'], 2) }}</td>
                    </tr>

                    <tr><td colspan="2" class="pt-4 pb-1 text-xs font-semibold uppercase text-gray-500">Expenses</td></tr>
                    @forelse ($statement['byCategory'] as $c)
                        <tr wire:key="inc-{{ $c->category_name }}"><td class="py-1 pl-4">{{ $c->category_name }}</td><td class="py-1 text-right">{{ number_format($c->total, 2) }}</td></tr>
                    @empty
                        <tr><td class="py-1 pl-4 text-gray-500" colspan="2">No expenses in this period.</td></tr>
                    @endforelse
                    <tr class="border-t border-gray-200 font-semibold"><td class="py-2">Total expenses</td><td class="py-2 text-right">({{ number_format($statement['expenses'], 2) }})</td></tr>

                    <tr class="border-t-2 border-gray-400 text-base font-bold {{ $statement['net'] < 0 ? 'text-red-600' : 'text-gray-900' }}">
                        <td class="py-3">Net profit <span class="font-normal text-gray-500">({{ $statement['netMargin'] }}%)</span></td>
                        <td class="py-3 text-right">{{ number_format($statement['net'], 2) }}</td>
                    </tr>
                </tbody>
            </table>
            <p class="mt-4 text-xs text-gray-500">
                Credit invoices count as revenue on the day they are issued, even if the customer has not paid yet. Tax you charge on top of prices is not revenue.
                Voided sales, invoices and expenses are excluded.
            </p>
        </x-card>
        <livewire:reports.receivables-memo :from="$from" :to="$to" wire:key="memo" />
    @endif
</div>
