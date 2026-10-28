<?php

use App\Models\Category;
use App\Models\Customer;
use App\Models\HeldCart;
use App\Models\PaymentMethod;
use App\Models\ProductVariant;
use App\Services\BatchService;
use App\Services\BranchService;
use App\Services\PricingService;
use App\Services\SaleService;
use App\Services\StockService;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component
{
    public string $search = '';
    public array $cart = [];      // [variantId => ['qty' => 1, 'dtype' => '', 'dvalue' => '']]
    public string $cartDType = '';
    public $cartDValue = '';
    public array $payments = [];
    public $categoryId = null;
    public $subId = null;
    public string $holdLabel = '';
    public bool $showHolds = false;
    public bool $showPay = false;
    public $customerId = null;
    public string $customerSearch = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('make-sales'), 403);

        $cash = PaymentMethod::active()->where('type', 'cash')->first()
            ?? PaymentMethod::active()->first();

        $this->payments = [['method_id' => $cash?->id, 'amount' => '', 'reference' => '']];
        $this->showHolds = request()->boolean('parked');
    }

    private function currentTotals(): array
    {
        $lines = ProductVariant::with('product')
            ->whereIn('id', array_keys($this->cart))->get()->keyBy('id');

        return [$lines, $this->totals($lines)];
    }

    // Display only. SaleService prices everything again when the sale completes,
    // using the same PricingService, so zero-rated and exempt items are not taxed here either.
    private function totals($lines): array
    {
        $business = auth()->user()->business;

        $payload = [];
        foreach ($this->cart as $id => $row) {
            if (! $lines->has($id)) {
                continue;
            }
            $payload[] = [
                'variant_id' => (int) $id,
                'quantity' => max(1, (int) ($row['qty'] ?? 1)),
                'discount_type' => ($row['dtype'] ?? '') ?: null,
                'discount_value' => (float) (($row['dvalue'] ?? 0) ?: 0),
            ];
        }

        $discount = ($this->cartDType !== '' && (float) $this->cartDValue > 0)
            ? ['type' => $this->cartDType, 'value' => (float) $this->cartDValue]
            : null;

        if ($payload) {
            try {
                $p = PricingService::price($payload, $discount, $business);

                $out = [];
                foreach ($p['items'] as $item) {
                    $out[$item['variant']->id] = ['net' => (float) $item['attrs']['line_total']];
                }

                return [
                    'lines' => $out, 'subtotal' => (float) $p['subtotal'], 'cartDiscount' => (float) $p['discount_amount'],
                    'tax' => (float) $p['tax_amount'], 'total' => (float) $p['total'],
                    'rate' => (float) $p['tax_rate'], 'inclusive' => (bool) $p['tax_inclusive'],
                ];
            } catch (ValidationException $e) {
                // e.g. discounts switched off: show the plain prices until it is corrected
            }
        }

        $out = [];
        $sum = 0.0;
        foreach ($payload as $l) {
            $v = $lines->get($l['variant_id']);
            $net = round((float) $v->selling_price * $l['quantity'], 2);
            $out[$l['variant_id']] = ['net' => $net];
            $sum += $net;
        }

        return [
            'lines' => $out, 'subtotal' => round($sum, 2), 'cartDiscount' => 0.0,
            'tax' => 0.0, 'total' => round($sum, 2), 'rate' => 0.0, 'inclusive' => false,
        ];
    }

    public function setCategory($id): void
    {
        $this->categoryId = $id ? (int) $id : null;
        $this->subId = null;
    }

    public function setSub($id): void
    {
        $this->subId = $id ? (int) $id : null;
    }

    // Used by the dropdown: one list holding both categories and subcategories
    public function pickFromList($id): void
    {
        $id = $id ? (int) $id : null;

        if (! $id) {
            $this->categoryId = null;
            $this->subId = null;
            return;
        }

        $cat = Category::active()->find($id); // tenant scope applies
        if (! $cat) {
            return;
        }

        if ($cat->parent_id) {
            $this->categoryId = $cat->parent_id;
            $this->subId = $cat->id;
        } else {
            $this->categoryId = $cat->id;
            $this->subId = null;
        }
    }

    public function hold(): void
    {
        abort_unless(auth()->user()->can('make-sales'), 403);

        if (! count($this->cart)) {
            $this->addError('cart', 'There is nothing to park.');
            return;
        }

        $this->validate(['holdLabel' => ['nullable', 'string', 'max:60']]);

        HeldCart::create([
            'user_id' => auth()->id(),
            'label' => trim($this->holdLabel) ?: null,
            'cart' => $this->cart,
            'cart_discount_type' => $this->cartDType ?: null,
            'cart_discount_value' => (float) ($this->cartDValue ?: 0),
            'customer_id' => $this->customerId ?: null,
        ]);
        $this->dispatch('notify', message: 'Cart parked.');
        $this->reset('cart', 'cartDType', 'cartDValue', 'holdLabel', 'customerId', 'showPay');
        $this->resetErrorBag();
    }

    public function resume(int $id): void
    {
        abort_unless(auth()->user()->can('make-sales'), 403);

        if (count($this->cart)) {
            $this->addError('cart', 'Finish or park the current cart before resuming another.');
            return;
        }

        $held = HeldCart::findOrFail($id); // tenant scope applies
        $canDiscount = auth()->user()->can('give-discounts') && auth()->user()->business->discounts_enabled;

        $available = ProductVariant::active()
           ->whereHas('product', fn ($q) =>
    $q->active()->forBranch(BranchService::currentId())
)
            ->whereIn('id', array_keys($held->cart))->pluck('id')->all();

        $cart = [];
        foreach ($held->cart as $variantId => $row) {
            if (! in_array((int) $variantId, $available)) {
                continue;
            }
            $cart[(int) $variantId] = [
                'qty' => max(1, (int) ($row['qty'] ?? 1)),
                'dtype' => $canDiscount ? ($row['dtype'] ?? '') : '',
                'dvalue' => $canDiscount ? ($row['dvalue'] ?? '') : '',
            ];
        }

        $dropped = count($held->cart) - count($cart);

        $this->cart = $cart;
        $this->cartDType = $canDiscount ? ($held->cart_discount_type ?? '') : '';
        $this->cartDValue = $canDiscount && $held->cart_discount_value > 0 ? $held->cart_discount_value : '';
        $this->customerId = $held->customer_id && Customer::active()->find($held->customer_id) ? $held->customer_id : null;
        $held->delete();
        $this->showHolds = false;
        $this->resetErrorBag();

        if ($dropped > 0) {
            $this->addError('cart', "$dropped item(s) were no longer available and were removed.");
        }
    }

    public function discardHold(int $id): void
    {
        abort_unless(auth()->user()->can('make-sales'), 403);

        HeldCart::findOrFail($id)->delete();
    }

    public function with(): array
    {
        $term = trim($this->search);
        [$lines, $totals] = $this->currentTotals();

        $paid = round(collect($this->payments)->sum(fn ($p) => (float) ($p['amount'] ?? 0)), 2);
        $custTerm = trim($this->customerSearch);



        return [
            'here' => BranchService::currentId(),
            'main' => BranchService::mainId(),
            'discountsOn' => (bool) auth()->user()->business->discounts_enabled,
            'customer' => $this->customerId ? Customer::find($this->customerId) : null,
            'customerResults' => $custTerm === '' ? collect()
                : Customer::active()
                    ->where(fn ($q) => $q->where('name', 'like', "%{$custTerm}%")->orWhere('phone', 'like', "%{$custTerm}%"))
                    ->orderBy('name')->limit(6)->get(['id', 'name', 'phone']),
            'holds' => HeldCart::with('user')->latest('id')->get(),
            'categories' => Category::active()->whereNull('parent_id')
                ->with(['children' => fn ($q) => $q->active()->orderBy('name')])
                ->orderBy('name')->get(),
            'allCategories' => Category::active()->with('parent')->orderBy('name')->get()
                ->sortBy(fn ($c) => ($c->parent?->name ?? $c->name) . '|' . ($c->parent_id ? '1' : '0') . $c->name)
                ->values(),
            'variants' => ProductVariant::with(['product', 'batches'])->active()
                ->whereHas('product', fn ($q) => $q->active()->forBranch(BranchService::currentId()))

                ->when($this->subId, fn ($q) => $q->whereHas('product',
                    fn ($p) => $p->where('category_id', $this->subId)))
                ->when(! $this->subId && $this->categoryId, fn ($q) => $q->whereHas('product',
                    fn ($p) => $p->whereIn('category_id',
                        Category::where('id', $this->categoryId)->orWhere('parent_id', $this->categoryId)->pluck('id'))))
                ->when($term !== '', fn ($q) => $q->where(function ($q) use ($term) {
                    $q->where('sku', 'like', "%{$term}%")
                      ->orWhere('barcode', 'like', "%{$term}%")
                      ->orWhere('name', 'like', "%{$term}%")
                      ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$term}%"));
                }))
                ->orderBy('product_id')->orderBy('name')->limit(40)->get(),
            'lines' => $lines,
            'totals' => $totals,
            'methods' => PaymentMethod::active()->orderBy('name')->get(),
            'paid' => $paid,
            'change' => max(round($paid - $totals['total'], 2), 0),
            'balance' => max(round($totals['total'] - $paid, 2), 0),
        ];
    }

    public function addVariant(int $id): void
    {
        $v = ProductVariant::with('product')->active()->find($id); // tenant scope applies
        if (! $v || ! $v->product->is_active) {
            $this->addError('cart', 'That item is not available.');
            return;
        }

        $next = (int) ($this->cart[$id]['qty'] ?? 0) + 1;

        // Tell them now, not when they press Complete
        if ($message = StockService::cartMessage($v, $next)) {
            $this->dispatch('notify', message: $message, type: 'error');
            return;
        }

        $this->resetErrorBag('cart');

        if (isset($this->cart[$id])) {
            $this->cart[$id]['qty'] = $next;
        } else {
            $this->cart[$id] = ['qty' => 1, 'dtype' => '', 'dvalue' => ''];
        }
    }

    public function updatedCart($value, $key): void
    {
        [$id, $field] = array_pad(explode('.', (string) $key, 2), 2, null);

        // Only quantity changes are checked here, not discounts
        if ($field !== 'qty' || ! isset($this->cart[$id]) || $value === '' || $value === null) {
            return;
        }

        $qty = (int) $value;
        if ($qty < 1) {
            $this->cart[$id]['qty'] = 1;
            return;
        }

        $v = ProductVariant::with('product')->active()->find((int) $id); // tenant scope applies
        if (! $v) {
            unset($this->cart[$id]);
            return;
        }

        if ($message = StockService::cartMessage($v, $qty)) {
            $max = $this->maxFor($v);

            if ($max <= 0) {
                unset($this->cart[$id]);
                $message .= ' It was removed from the cart.';
            } else {
                $this->cart[$id]['qty'] = $max;
                $message .= " Quantity set to {$max}.";
            }

            $this->dispatch('notify', message: $message, type: 'error');
        }
    }

    private function maxFor(ProductVariant $v): int
    {
        $v->loadMissing('product');

        return $v->product->tracks_batches
            ? BatchService::sellable($v)
            : max(BranchService::quantity($v, BranchService::currentId()), 0);
    }

    // A barcode scanner types the code and presses Enter
    public function addFromSearch(string $value): void
    {
        $code = trim($value);
        if ($code === '') return;

        $v = ProductVariant::active()
            ->whereHas('product', fn ($q) => $q->active())
            ->where(fn ($q) => $q->where('barcode', $code)->orWhere('sku', $code))
            ->first();

        if (! $v) {
            $this->addError('search', 'No item with that barcode or SKU.');
            return;
        }

        $this->resetErrorBag('search');
        $this->addVariant($v->id);
        $this->search = '';
    }

    public function increment(int $id): void
    {
        if (isset($this->cart[$id])) {
            $this->cart[$id]['qty'] = (int) $this->cart[$id]['qty'] + 1;
            $this->updatedCart($this->cart[$id]['qty'], $id . '.qty');
        }
    }

    public function decrement(int $id): void
    {
        if (isset($this->cart[$id])) {
            $this->cart[$id]['qty'] = max(1, (int) $this->cart[$id]['qty'] - 1);
        }
    }

    public function removeLine(int $id): void
    {
        unset($this->cart[$id]);
    }

    public function clearCart(): void
    {
        $this->reset('cart', 'cartDType', 'cartDValue', 'showPay');
        $this->resetErrorBag();
    }

    // ---- payment popup ----

    public function openPayment(): void
    {
        abort_unless(auth()->user()->can('make-sales'), 403);

        if (! count($this->cart)) {
            $this->addError('cart', 'Add items to the cart first.');
            return;
        }

        [, $totals] = $this->currentTotals();

        // The amount starts as the whole bill
        if (count($this->payments) === 1) {
            $this->payments[0]['amount'] = (string) $totals['total'];
        }

        $this->resetErrorBag();
        $this->showPay = true;
    }

    public function closePayment(): void
    {
        $this->showPay = false;
        $this->resetErrorBag();
    }

    public function setMethod(int $i, int $methodId): void
    {
        $m = PaymentMethod::active()->find($methodId); // tenant scope applies
        if (! $m || ! isset($this->payments[$i])) {
            return;
        }

        $this->payments[$i]['method_id'] = $m->id;
        if ($m->type !== 'mobile') {
            $this->payments[$i]['reference'] = '';
        }
    }

    public function addPayment(): void
    {
        $this->payments[] = ['method_id' => null, 'amount' => '', 'reference' => ''];
    }

    public function removePayment(int $i): void
    {
        if (count($this->payments) > 1) {
            unset($this->payments[$i]);
            $this->payments = array_values($this->payments);
        }
    }

    public function fillRemaining(int $i): void
    {
        [, $totals] = $this->currentTotals();

        $others = collect($this->payments)->except($i)->sum(fn ($p) => (float) ($p['amount'] ?? 0));
        $this->payments[$i]['amount'] = (string) max(round($totals['total'] - $others, 2), 0);
    }

    public function complete(): void
    {
        abort_unless(auth()->user()->can('make-sales'), 403);

        $lines = [];
        foreach ($this->cart as $id => $row) {
            $lines[] = [
                'variant_id' => (int) $id,
                'quantity' => (int) ($row['qty'] ?? 0),
                'discount_type' => ($row['dtype'] ?? '') ?: null,
                'discount_value' => (float) (($row['dvalue'] ?? 0) ?: 0),
            ];
        }

        $discount = ($this->cartDType !== '' && (float) $this->cartDValue > 0)
            ? ['type' => $this->cartDType, 'value' => (float) $this->cartDValue]
            : null;

        $payments = collect($this->payments)
            ->filter(fn ($p) => (float) ($p['amount'] ?? 0) > 0 && ! empty($p['method_id']))
            ->map(fn ($p) => [
                'method_id' => (int) $p['method_id'],
                'amount' => (float) $p['amount'],
                'reference' => trim($p['reference'] ?? ''),
            ])->values()->all();

        // Validation errors from the service show up under 'cart' and 'payments'
        $sale = SaleService::complete($lines, $discount, $payments, $this->customerId ? (int) $this->customerId : null);
        session()->flash('notify', 'Sale completed.');
        $this->redirect(route('sales.receipt', $sale), navigate: true);
    }

    public function pickCustomer(int $id): void
    {
        $c = Customer::active()->find($id); // tenant scope applies
        if (! $c) {
            return;
        }
        $this->customerId = $c->id;
        $this->customerSearch = '';
    }

    public function clearCustomer(): void
    {
        $this->reset('customerId', 'customerSearch');
    }

    public function creditSale(): void
    {
        abort_unless(auth()->user()->can('issue-invoices'), 403);

        if (! count($this->cart)) {
            $this->addError('cart', 'Add items to the cart first.');
            return;
        }

        session()->put('credit_cart', [
            'customer' => $this->customerId,
            'lines' => $this->cart,
            'dtype' => $this->cartDType,
            'dvalue' => $this->cartDValue,
        ]);

        $this->redirect(route('invoices.create', ['from' => 'till']), navigate: true);
    }
}; ?>

<div class="grid grid-cols-1 lg:grid-cols-5 gap-6 lg:h-[calc(100vh-11rem)]">
    {{-- Products --}}
    <div class="lg:col-span-3 lg:overflow-y-auto lg:pr-2 space-y-4">
        <div class="sticky top-0 z-10 bg-gray-50 pb-2 space-y-4">
            <div class="flex flex-col sm:flex-row gap-3">
                <x-text-input wire:model.live.debounce.300ms="search"
                              wire:keydown.enter="addFromSearch($event.target.value)"
                              type="search" autofocus
                              placeholder="Scan a barcode or search by name or SKU..." class="block w-full" />

                <select wire:change="pickFromList($event.target.value)" class="sm:w-60 text-sm">
                    <option value="" @selected(! $categoryId)>All categories</option>
                    @foreach ($allCategories as $c)
                        @php $selected = $subId ? $subId === $c->id : $categoryId === $c->id; @endphp
                        <option value="{{ $c->id }}" @selected($selected)>
                            {{ $c->parent ? $c->parent->name . ' → ' . $c->name : $c->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <x-input-error :messages="$errors->get('search')" />

            {{-- Category tabs (first 8, plus the selected one) --}}
            @php
                $tabCats = $categories->take(8);
                $active = $categories->firstWhere('id', $categoryId);
                if ($active && ! $tabCats->contains('id', $active->id)) {
                    $tabCats = $tabCats->push($active);
                }
            @endphp
            <div class="flex gap-2 overflow-x-auto pb-1">
                <button type="button" wire:click="setCategory(null)"
                        class="whitespace-nowrap rounded-full px-4 py-1.5 text-sm font-medium {{ ! $categoryId ? 'bg-brand-500 text-white' : 'bg-white border border-gray-200 text-gray-700 hover:border-brand-500' }}">
                    All
                </button>
                @foreach ($tabCats as $c)
                    <button type="button" wire:key="cat-{{ $c->id }}" wire:click="setCategory({{ $c->id }})"
                            class="whitespace-nowrap rounded-full px-4 py-1.5 text-sm font-medium {{ $categoryId === $c->id ? 'bg-brand-500 text-white' : 'bg-white border border-gray-200 text-gray-700 hover:border-brand-500' }}">
                        {{ $c->name }}
                    </button>
                @endforeach
            </div>

            {{-- Subcategory chips --}}
            @php $current = $categories->firstWhere('id', $categoryId); @endphp
            @if ($current && $current->children->count())
                <div class="flex gap-2 overflow-x-auto pb-1">
                    @foreach ($current->children as $child)
                        <button type="button" wire:key="sub-{{ $child->id }}" wire:click="setSub({{ $subId === $child->id ? 'null' : $child->id }})"
                                class="whitespace-nowrap rounded-full px-3 py-1 text-xs font-medium {{ $subId === $child->id ? 'bg-brand-100 text-brand-700 border border-brand-500' : 'bg-white border border-gray-200 text-gray-600' }}">
                            {{ $child->name }}
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-3">
            @forelse ($variants as $v)
                <button type="button" wire:key="tile-{{ $v->id }}" wire:click="addVariant({{ $v->id }})"
                        class="overflow-hidden rounded-xl border border-gray-200 bg-white text-left transition hover:border-brand-500">
                    <x-product-image :url="$v->product->image_url" :alt="$v->product->name" class="h-24 w-full" />
                    <div class="p-3">
                        <p class="text-sm font-medium text-gray-900 leading-tight">{{ $v->product->name }}</p>
                        @if ($v->name !== 'Default')
                            <p class="text-xs text-gray-500">{{ $v->name }}</p>
                        @endif
                        <div class="mt-2 flex flex-col items-start">
                            <p class="font-semibold text-brand-600">{{ number_format($v->selling_price, 2) }}</p>

                            <div class="text-left">
                                @if ($v->product->is_service)
                                    <p class="text-xs text-gray-500">Service</p>
                                @elseif ($v->product->tracks_batches)
                                    @php
                                        // Only the batches of the branch being sold from
                                        $mine = $v->batches->filter(fn ($b) => ($b->branch_id ?? $main) === $here && $b->quantity > 0);
                                        $sellable = BatchService::sellable($v, $here);
                                        $live = $mine->whereNotNull('expiry_date')
                                            ->filter(fn ($b) => $b->expiry_date->gte(today()))->sortBy('expiry_date')->first();
                                        $hasExpired = $mine->contains(fn ($b) => $b->is_expired);
                                    @endphp
                                    @if ($sellable <= 0 && $hasExpired)
                                        <p class="text-xs font-semibold text-red-600">Expired</p>
                                    @else
                                        <p class="text-xs {{ $sellable <= $v->low_stock_threshold ? 'text-red-600 font-semibold' : 'text-green-700' }}">
                                            {{ $sellable }} in stock
                                        </p>
                                        @if ($live)
                                            <p class="text-[11px] text-gray-500">Exp {{ $live->expiry_date->format('d M Y') }}</p>
                                        @endif
                                        @if ($hasExpired)
                                            <p class="text-[11px] font-semibold text-red-600">Some expired</p>
                                        @endif
                                    @endif
                                @else
                                    <p class="text-xs {{ $v->branch_stock <= $v->low_stock_threshold ? 'text-red-600 font-semibold' : 'text-green-700' }}">
                                        {{ $v->branch_stock }} in stock
                                    </p>
                                @endif
                            </div>
                        </div>
                    </div>
                </button>
            @empty
                <p class="col-span-full py-8 text-center text-gray-500">No items found.</p>
            @endforelse
        </div>
        <p class="text-xs text-gray-500">Showing up to 40 items. Use the categories or search to narrow down.</p>
    </div>

    {{-- Cart --}}
    <div class="lg:col-span-2 lg:overflow-y-auto">
        <x-card class="p-5 space-y-4">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-900">Cart</h3>
                <div class="flex items-center gap-3 text-sm">
                    <button type="button" wire:click="$toggle('showHolds')" class="text-brand-600 hover:underline">
                        Parked ({{ $holds->count() }})
                    </button>
                    @if (count($cart))
                        <button type="button" wire:click="clearCart" class="text-gray-500 hover:underline">Clear</button>
                    @endif
                </div>
            </div>

            @if ($showHolds)
                <div class="space-y-2 rounded-lg border border-gray-200 bg-gray-50 p-3">
                    @forelse ($holds as $h)
                        <div wire:key="hold-{{ $h->id }}" class="flex items-center justify-between gap-2 text-sm">
                            <div>
                                <p class="font-medium text-gray-900">{{ $h->label ?? 'Parked cart' }}</p>
                                <p class="text-xs text-gray-500">
                                    {{ count($h->cart) }} item(s), {{ $h->created_at->format('H:i') }}, {{ $h->user?->name ?? '-' }}
                                </p>
                            </div>
                            <div class="flex gap-3">
                                <button type="button" wire:click="resume({{ $h->id }})" class="text-brand-600 hover:underline">Resume</button>
                                <button type="button" wire:click="discardHold({{ $h->id }})"
                                        wire:confirm="Discard this parked cart?" class="text-gray-500 hover:underline">Discard</button>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">No parked carts.</p>
                    @endforelse
                </div>
            @endif

            @if ($customer)
                <p class="rounded-lg bg-brand-50 px-3 py-2 text-sm text-brand-700">Customer: <strong>{{ $customer->name }}</strong></p>
            @endif

            <div class="divide-y divide-gray-100 max-h-72 overflow-y-auto">
                @foreach ($cart as $id => $row)
                    @php $v = $lines->get($id); @endphp
                    @continue(! $v)
                    <div wire:key="line-{{ $id }}" class="py-3 space-y-2">
                        <div class="flex justify-between gap-2">
                            <div>
                                <p class="text-sm font-medium text-gray-900">
                                    {{ $v->product->name }}@if ($v->name !== 'Default') - {{ $v->name }}@endif
                                    @if (($v->product->tax_class ?? 'standard') !== 'standard')
                                        <span class="ml-1 text-[11px] font-normal text-gray-500">({{ $v->product->tax_class === 'zero' ? 'zero rated' : 'exempt' }})</span>
                                    @endif
                                </p>
                                <p class="text-xs text-gray-500">{{ number_format($v->selling_price, 2) }} each</p>
                            </div>
                            <p class="text-sm font-semibold">{{ number_format($totals['lines'][$id]['net'] ?? 0, 2) }}</p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" wire:click="decrement({{ $id }})" class="h-8 w-8 rounded-lg border border-gray-300">-</button>
                            <x-text-input wire:model.live.debounce.400ms="cart.{{ $id }}.qty" type="number" min="1" class="w-16 text-center" />
                            <button type="button" wire:click="increment({{ $id }})" class="h-8 w-8 rounded-lg border border-gray-300">+</button>

                            @if ($discountsOn && auth()->user()->can('give-discounts'))
                                <select wire:model.live="cart.{{ $id }}.dtype" class="text-sm">
                                    <option value="">No discount</option>
                                    <option value="percent">%</option>
                                    <option value="fixed">Amount</option>
                                </select>
                                <x-text-input wire:model.live.debounce.400ms="cart.{{ $id }}.dvalue" type="number" step="0.01" min="0" class="w-20" />
                            @endif

                            <button type="button" wire:click="removeLine({{ $id }})" class="ml-auto text-sm text-gray-500 hover:underline">Remove</button>
                        </div>
                    </div>
                @endforeach

                @if (! count($cart))
                    <p class="py-6 text-center text-sm text-gray-500">Tap an item to add it.</p>
                @endif
            </div>

            @unless ($showPay)
                <x-input-error :messages="$errors->get('cart')" />
            @endunless

            @if ($discountsOn && auth()->user()->can('give-discounts'))
                <div class="flex items-center gap-2">
                    <span class="text-sm text-gray-600">Cart discount</span>
                    <select wire:model.live="cartDType" class="text-sm">
                        <option value="">None</option>
                        <option value="percent">%</option>
                        <option value="fixed">Amount</option>
                    </select>
                    <x-text-input wire:model.live.debounce.400ms="cartDValue" type="number" step="0.01" min="0" class="w-24" />
                </div>
            @endif

            <div class="space-y-1 border-t border-gray-200 pt-3 text-sm">
                <div class="flex justify-between"><span class="text-gray-600">Subtotal</span><span>{{ number_format($totals['subtotal'], 2) }}</span></div>
                @if ($totals['cartDiscount'] > 0)
                    <div class="flex justify-between"><span class="text-gray-600">Discount</span><span>-{{ number_format($totals['cartDiscount'], 2) }}</span></div>
                @endif
                @if ($totals['rate'] > 0 && $totals['tax'] > 0)
                    <div class="flex justify-between">
                        <span class="text-gray-600">Tax ({{ rtrim(rtrim(number_format($totals['rate'], 2), '0'), '.') }}%{{ $totals['inclusive'] ? ', included' : '' }}, standard-rated items only)</span>
                        <span>{{ number_format($totals['tax'], 2) }}</span>
                    </div>
                @endif
                <div class="flex justify-between pt-1 text-xl font-bold text-gray-900">
                    <span>Total</span><span>Kshs {{ number_format($totals['total'], 2) }}</span>
                </div>
            </div>

            @if (count($cart))
                <div class="flex gap-2">
                    <x-text-input wire:model="holdLabel" type="text" maxlength="60"
                                  placeholder="Label (optional), e.g. Blue shirt lady" class="block w-full text-sm" />
                    <x-secondary-button type="button" wire:click="hold" class="whitespace-nowrap">Park</x-secondary-button>
                </div>
            @endif

            @can('issue-invoices')
                <button type="button" wire:click="creditSale"
                        class="w-full rounded-lg border border-brand-500 px-3 py-2 text-sm font-semibold text-brand-600 hover:bg-brand-50">
                    Sell on credit (invoice)
                </button>
            @endcan

            <x-primary-button type="button" wire:click="openPayment" class="w-full py-3 text-base" :disabled="! count($cart)">
                Proceed to payment
            </x-primary-button>
        </x-card>
    </div>

    {{-- Payment popup --}}
    @if ($showPay)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/50" wire:click="closePayment"></div>

            <div class="relative flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-white shadow-xl">
                <div class="flex items-center justify-between bg-brand-500 px-6 py-4 text-white">
                    <h3 class="text-lg font-semibold">Complete sale</h3>
                    <button type="button" wire:click="closePayment" class="text-2xl leading-none" aria-label="Close">&times;</button>
                </div>

                <div class="grid flex-1 grid-cols-1 gap-6 overflow-y-auto p-6 md:grid-cols-5">
                    <div class="space-y-5 md:col-span-3">
                        {{-- Customer --}}
                        <div class="space-y-2">
                            <p class="text-sm font-medium text-gray-700">Customer (optional)</p>
                            @if ($customer)
                                <div class="flex items-center justify-between rounded-lg bg-brand-50 px-3 py-2 text-sm">
                                    <span class="text-brand-700"><strong>{{ $customer->name }}</strong>@if ($customer->phone) ({{ $customer->phone }})@endif</span>
                                    <button type="button" wire:click="clearCustomer" class="text-gray-500 hover:underline">Walk-in instead</button>
                                </div>
                            @else
                                <div class="relative">
                                    <x-text-input wire:model.live.debounce.300ms="customerSearch" type="search"
                                                  placeholder="Walk-in customer. Enter a name or phone number to search..." class="block w-full text-sm" />
                                    @if ($customerResults->count())
                                        <div class="absolute z-20 mt-1 w-full rounded-lg border border-gray-200 bg-white shadow-lg">
                                            @foreach ($customerResults as $c)
                                                <button type="button" wire:key="cr-{{ $c->id }}" wire:click="pickCustomer({{ $c->id }})"
                                                        class="flex w-full justify-between px-3 py-2 text-left text-sm hover:bg-gray-50">
                                                    <span>{{ $c->name }}</span><span class="text-gray-500">{{ $c->phone }}</span>
                                                </button>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endif
                            <x-input-error :messages="$errors->get('customer')" />
                        </div>

                        {{-- Payments --}}
                        <div class="space-y-3">
                            <p class="text-sm font-medium text-gray-700">Payment</p>

                            @foreach ($payments as $i => $p)
                                @php $type = $methods->firstWhere('id', (int) ($p['method_id'] ?? 0))?->type; @endphp
                                <div wire:key="pay-{{ $i }}" class="space-y-3 rounded-xl bg-gray-50 p-4">
                                    <div class="flex flex-wrap gap-2">
                                        @foreach ($methods as $m)
                                            <button type="button" wire:key="pm-{{ $i }}-{{ $m->id }}" wire:click="setMethod({{ $i }}, {{ $m->id }})"
                                                    class="rounded-lg border px-4 py-2 text-sm font-medium {{ (int) ($p['method_id'] ?? 0) === $m->id ? 'border-brand-600 bg-brand-600 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-brand-500' }}">
                                                {{ $m->name }}
                                            </button>
                                        @endforeach
                                    </div>

                                    <div>
                                        <x-input-label value="Amount received (Kshs)" />
                                        <x-text-input wire:model.live.debounce.400ms="payments.{{ $i }}.amount" type="number" step="0.01" min="0" class="mt-1 block w-full text-lg font-semibold" />
                                    </div>

                                    @if ($type === 'mobile')
                                        <div>
                                            <x-input-label value="Transaction code (last 4 characters or the full code) *" />
                                            <x-text-input wire:model="payments.{{ $i }}.reference" type="text" maxlength="12" class="mt-1 block w-full" />
                                        </div>
                                    @elseif ($type && $type !== 'cash')
                                        <x-text-input wire:model="payments.{{ $i }}.reference" type="text" placeholder="Reference (optional)" class="block w-full" />
                                    @endif

                                    <div class="flex gap-4 text-xs">
                                        <button type="button" wire:click="fillRemaining({{ $i }})" class="text-brand-600 hover:underline">Fill remaining</button>
                                        @if (count($payments) > 1)
                                            <button type="button" wire:click="removePayment({{ $i }})" class="text-gray-500 hover:underline">Remove</button>
                                        @endif
                                    </div>
                                </div>
                            @endforeach

                            <button type="button" wire:click="addPayment" class="text-sm text-brand-600 hover:underline">+ Split payment</button>
                        </div>

                        <x-input-error :messages="$errors->get('payments')" />
                        <x-input-error :messages="$errors->get('cart')" />
                    </div>

                    {{-- Summary --}}
                    <div class="md:col-span-2">
                        <div class="space-y-2 rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Payment summary</p>
                            <div class="flex justify-between"><span class="text-gray-600">Subtotal</span><span>{{ number_format($totals['subtotal'], 2) }}</span></div>
                            @if ($totals['cartDiscount'] > 0)
                                <div class="flex justify-between"><span class="text-gray-600">Discount</span><span>-{{ number_format($totals['cartDiscount'], 2) }}</span></div>
                            @endif
                            @if ($totals['tax'] > 0)
                                <div class="flex justify-between"><span class="text-gray-600">Tax</span><span>{{ number_format($totals['tax'], 2) }}</span></div>
                            @endif
                            <div class="flex justify-between border-t border-gray-200 pt-2 text-lg font-bold text-green-700">
                                <span>Total</span><span>Kshs {{ number_format($totals['total'], 2) }}</span>
                            </div>
                            <div class="flex justify-between pt-1"><span class="text-gray-600">Paid</span><span>{{ number_format($paid, 2) }}</span></div>
                            @if ($change > 0)
                                <div class="flex justify-between font-semibold text-green-700"><span>Change</span><span>{{ number_format($change, 2) }}</span></div>
                            @elseif ($balance > 0 && $paid > 0)
                                <div class="flex justify-between font-semibold text-red-600"><span>Short</span><span>{{ number_format($balance, 2) }}</span></div>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-3 border-t border-gray-200 bg-white px-6 py-4">
                    <x-secondary-button type="button" wire:click="closePayment">Cancel</x-secondary-button>
                    <x-primary-button type="button" wire:click="complete" wire:loading.attr="disabled">Complete sale</x-primary-button>
                </div>
            </div>
        </div>
    @endif
</div>
