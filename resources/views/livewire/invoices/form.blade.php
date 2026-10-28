<?php

use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Services\InvoiceService;
use App\Services\PricingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;
use App\Services\BranchService;

new class extends Component
{
    public $customerId = '';
    public string $dueDate = '';
    public string $notes = '';
    public string $search = '';
    public array $lines = [];   // [variantId => ['qty' => 1, 'dtype' => '', 'dvalue' => '']]
    public string $cartDType = '';
    public $cartDValue = '';
    public ?int $quotationId = null;
    public bool $fromTill = false;

    // payment taken now (optional)
    public $payNow = '';
    public $payMethodId = '';
    public string $payReference = '';

    public function mount(): void
    {
        $user = auth()->user();
        abort_unless($user->can('issue-invoices'), 403);

        $this->dueDate = now()->addDays(30)->toDateString();

        $cash = PaymentMethod::active()->where('type', 'cash')->first() ?? PaymentMethod::active()->first();
        $this->payMethodId = $cash?->id ?? '';

        $canDiscount = $user->can('give-discounts') && $user->business->discounts_enabled;

        // Arriving from a quotation: pre-fill it
        $qid = request()->integer('quotation');
        if ($qid) {
            $q = Quotation::with('items')->find($qid); // tenant scope applies

            if ($q && in_array($q->status, ['open', 'accepted']) && $q->display_status !== 'expired'
                && ($user->can('view-reports') || $q->user_id === $user->id)) {
                $this->quotationId = $q->id;
                $this->customerId = $q->customer_id;
                $this->notes = (string) $q->notes;

                foreach ($q->items as $item) {
                    $id = $item->product_variant_id;
                    if (isset($this->lines[$id])) {
                        $this->lines[$id]['qty'] += $item->quantity;
                        continue;
                    }
                    $this->lines[$id] = [
                        'qty' => $item->quantity,
                        'dtype' => $canDiscount ? ($item->discount_type ?? '') : '',
                        'dvalue' => $canDiscount && $item->discount_value > 0 ? (string) $item->discount_value : '',
                    ];
                }

                if ($canDiscount) {
                    $this->cartDType = $q->discount_type ?? '';
                    $this->cartDValue = $q->discount_value > 0 ? $q->discount_value : '';
                }
            }
        }

        // Arriving from the till: "Sell on credit" hands the cart over
        if (request()->query('from') === 'till') {
            $cart = session()->pull('credit_cart');

            if (is_array($cart)) {
                foreach ((array) ($cart['lines'] ?? []) as $id => $row) {
                    if (! ProductVariant::active()->find((int) $id)) {
                        continue;
                    }
                    $this->lines[(int) $id] = [
                        'qty' => max(1, (int) ($row['qty'] ?? $row['quantity'] ?? 1)),
                        'dtype' => $canDiscount ? (string) ($row['dtype'] ?? '') : '',
                        'dvalue' => $canDiscount ? (string) ($row['dvalue'] ?? '') : '',
                    ];
                }

                if (! empty($cart['customer']) && Customer::active()->find($cart['customer'])) {
                    $this->customerId = (int) $cart['customer'];
                }
                if ($canDiscount) {
                    $this->cartDType = (string) ($cart['dtype'] ?? '');
                    $this->cartDValue = $cart['dvalue'] ?? '';
                }
                $this->fromTill = true;
            }
        }
    }

    private function payload(): array
    {
        $lines = [];
        foreach ($this->lines as $id => $row) {
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

        return [$lines, $discount];
    }

    public function with(): array
    {
        $term = trim($this->search);
        [$lines, $discount] = $this->payload();

        // Display only. InvoiceService prices everything again on save.
        try {
            $priced = PricingService::price($lines, $discount, auth()->user()->business);
        } catch (ValidationException $e) {
            $priced = null;
        }

        return [
            'discountsOn' => (bool) auth()->user()->business->discounts_enabled,
            'customers' => Customer::active()->orderBy('name')->get(['id', 'name', 'phone']),
            'methods' => PaymentMethod::active()->orderBy('name')->get(),

            'results' => $term === '' ? collect() : ProductVariant::with('product')->active()
                     ->whereHas('product', fn ($q) =>
                          $q->active()->forBranch(BranchService::currentId())
                            )

                ->where(function ($q) use ($term) {
                    $q->where('sku', 'like', "%{$term}%")
                      ->orWhere('barcode', 'like', "%{$term}%")
                      ->orWhere('name', 'like', "%{$term}%")
                      ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$term}%"));
                })
                ->orderBy('product_id')->limit(8)->get(),
            'variants' => ProductVariant::with('product')->whereIn('id', array_keys($this->lines))->get()->keyBy('id'),
            'priced' => $priced,
        ];
    }

   public function addLine(int $id): void
{
    $v = ProductVariant::with('product')->active()->find($id); // tenant scope applies
    if (! $v || ! $v->product->is_active) {
        $this->addError('cart', 'That item is not available.');
        return;
    }

    $next = (int) ($this->lines[$id]['qty'] ?? 0) + 1;

    if ($message = \App\Services\StockService::cartMessage($v, $next)) {
        $this->dispatch('notify', message: $message, type: 'error');
        return;
    }

    $this->resetErrorBag('cart');

    if (isset($this->lines[$id])) {
        $this->lines[$id]['qty'] = $next;
    } else {
        $this->lines[$id] = ['qty' => 1, 'dtype' => '', 'dvalue' => ''];
    }

    $this->search = '';
}
    public function updatedLines($value, $key): void
{
    [$id, $field] = array_pad(explode('.', (string) $key, 2), 2, null);

    if ($field !== 'qty' || ! isset($this->lines[$id]) || $value === '' || $value === null) {
        return;
    }

    $qty = (int) $value;
    if ($qty < 1) {
        $this->lines[$id]['qty'] = 1;
        return;
    }

    $v = ProductVariant::with('product')->active()->find((int) $id);
    if (! $v) {
        unset($this->lines[$id]);
        return;
    }

    if ($message = \App\Services\StockService::cartMessage($v, $qty)) {
        $v->loadMissing('batches');
        $max = $v->product->tracks_batches
            ? $v->sellableQuantity()
            : max(\App\Services\BranchService::quantity($v, \App\Services\BranchService::currentId()), 0);

        if ($max <= 0) {
            unset($this->lines[$id]);
            $message .= ' It was removed from the list.';
        } else {
            $this->lines[$id]['qty'] = $max;
            $message .= " Quantity set to {$max}.";
        }

        $this->dispatch('notify', message: $message, type: 'error');
    }
}
    public function removeLine(int $id): void
    {
        unset($this->lines[$id]);
    }

    public function save(): void
    {
        [$lines, $discount] = $this->payload();
        $pay = round((float) $this->payNow, 2);

        // Issue and take the payment together: if the payment is refused, nothing is issued
        $invoice = DB::transaction(function () use ($lines, $discount, $pay) {
            $invoice = InvoiceService::issue(
                (int) $this->customerId, $this->dueDate ?: null, $this->notes,
                $lines, $discount, $this->quotationId
            );

            if ($pay > 0) {
                InvoiceService::recordPayment($invoice, (int) $this->payMethodId, $pay, $this->payReference);
            }

            return $invoice;
        });

        session()->flash('notify', $pay > 0 ? 'Invoice issued and payment recorded.' : 'Invoice issued.');
        $this->redirect(route('invoices.show', $invoice), navigate: true);
    }
}; ?>

<form wire:submit="save" class="space-y-6">
    <x-card class="p-6 space-y-4">
        <h3 class="text-lg font-medium text-gray-900">New invoice (credit sale)</h3>
        @if ($quotationId)
            <p class="rounded-lg bg-brand-50 px-3 py-2 text-sm text-brand-700">Created from a quotation. Items are re-priced at today's prices when you issue it.</p>
        @endif
        @if ($fromTill)
            <p class="rounded-lg bg-brand-50 px-3 py-2 text-sm text-brand-700">Cart from the till. Choose the customer and a due date, then issue it. The customer can pay later.</p>
        @endif

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="md:col-span-2">
                <x-input-label for="customer" value="Customer *" />
                <select wire:model="customerId" id="customer" class="mt-1 block w-full">
                    <option value="">Choose a customer...</option>
                    @foreach ($customers as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}@if ($c->phone) ({{ $c->phone }})@endif</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('customer')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="due_date" value="Due date (optional)" />
                <x-text-input wire:model="dueDate" id="due_date" type="date" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('due_date')" class="mt-2" />
            </div>
        </div>

        <div>
            <x-input-label for="notes" value="Notes (shown on the invoice)" />
            <x-text-input wire:model="notes" id="notes" type="text" maxlength="255" class="mt-1 block w-full"
                          placeholder="e.g. Payment by bank transfer or M-Pesa" />
            <x-input-error :messages="$errors->get('notes')" class="mt-2" />
        </div>
    </x-card>

    <x-card class="p-6 space-y-4">
        <h3 class="text-lg font-medium text-gray-900">Items</h3>
        <p class="text-sm text-gray-500">Stock is taken out when the invoice is issued.</p>

        <div class="relative">
            <x-text-input wire:model.live.debounce.300ms="search" type="search"
                          placeholder="Search a product or service to add..." class="block w-full" />

            @if ($results->count())
                <div class="absolute z-20 mt-1 w-full rounded-lg border border-gray-200 bg-white shadow-lg">
                    @foreach ($results as $r)
                        <button type="button" wire:key="res-{{ $r->id }}" wire:click="addLine({{ $r->id }})"
                                class="flex w-full items-center justify-between px-4 py-2 text-left text-sm hover:bg-gray-50">
                            <span>{{ $r->product->name }}@if ($r->name !== 'Default') - {{ $r->name }}@endif</span>
                            <span class="font-medium text-brand-600">{{ number_format($r->selling_price, 2) }}</span>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        <x-input-error :messages="$errors->get('cart')" />
        <x-input-error :messages="$errors->get('quotation')" />

        @if (count($lines))
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Item</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Price</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Qty</th>
                            @if ($discountsOn && auth()->user()->can('give-discounts'))
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Discount</th>
                            @endif
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach ($lines as $id => $row)
                            @php $v = $variants->get($id); @endphp
                            @continue(! $v)
                            <tr wire:key="iline-{{ $id }}">
                                <td class="px-3 py-2">{{ $v->product->name }}@if ($v->name !== 'Default') - {{ $v->name }}@endif</td>
                                <td class="px-3 py-2 text-right">{{ number_format($v->selling_price, 2) }}</td>
                                <td class="px-3 py-2">
                                    <x-text-input wire:model.live.debounce.400ms="lines.{{ $id }}.qty" type="number" min="1" class="w-20" />
                                </td>
                                @if ($discountsOn && auth()->user()->can('give-discounts'))
                                    <td class="px-3 py-2">
                                        <div class="flex gap-2">
                                            <select wire:model.live="lines.{{ $id }}.dtype" class="text-sm">
                                                <option value="">None</option>
                                                <option value="percent">%</option>
                                                <option value="fixed">Amount</option>
                                            </select>
                                            <x-text-input wire:model.live.debounce.400ms="lines.{{ $id }}.dvalue" type="number" step="0.01" min="0" class="w-24" />
                                        </div>
                                    </td>
                                @endif
                                <td class="px-3 py-2 text-right">
                                    <button type="button" wire:click="removeLine({{ $id }})" class="text-gray-500 hover:underline">Remove</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($discountsOn && auth()->user()->can('give-discounts'))
                <div class="flex items-center gap-2">
                    <span class="text-sm text-gray-600">Whole-invoice discount</span>
                    <select wire:model.live="cartDType" class="text-sm">
                        <option value="">None</option>
                        <option value="percent">%</option>
                        <option value="fixed">Amount</option>
                    </select>
                    <x-text-input wire:model.live.debounce.400ms="cartDValue" type="number" step="0.01" min="0" class="w-24" />
                </div>
            @endif

            @if ($priced)
                <div class="ml-auto w-full max-w-xs space-y-1 border-t border-gray-200 pt-3 text-sm">
                    <div class="flex justify-between"><span class="text-gray-600">Subtotal</span><span>{{ number_format($priced['subtotal'], 2) }}</span></div>
                    @if ($priced['discount_amount'] > 0)
                        <div class="flex justify-between"><span class="text-gray-600">Discount</span><span>-{{ number_format($priced['discount_amount'], 2) }}</span></div>
                    @endif
                    @if ($priced['tax_rate'] > 0)
                        <div class="flex justify-between">
                            <span class="text-gray-600">Tax ({{ rtrim(rtrim(number_format($priced['tax_rate'], 2), '0'), '.') }}%{{ $priced['tax_inclusive'] ? ', included' : '' }})</span>
                            <span>{{ number_format($priced['tax_amount'], 2) }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between pt-1 text-lg font-bold text-gray-900">
                        <span>Total</span><span>Kshs {{ number_format($priced['total'], 2) }}</span>
                    </div>
                </div>
            @else
                <p class="text-sm text-red-600">Check the quantities and discounts.</p>
            @endif
        @else
            <p class="py-4 text-center text-sm text-gray-500">Search above to add items.</p>
        @endif
    </x-card>

    <x-card class="p-6 space-y-4">
        <h3 class="text-lg font-medium text-gray-900">Payment now (optional)</h3>
        <p class="text-sm text-gray-500">
            Leave empty for a full credit sale: the customer pays later against the invoice, and each payment gets its own receipt.
            Enter an amount to take a deposit, or the whole amount, right now.
        </p>

        @php $payType = $methods->firstWhere('id', (int) $payMethodId)?->type; @endphp
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <x-input-label for="pay_now" value="Amount paid now (Kshs)" />
                <x-text-input wire:model="payNow" id="pay_now" type="number" step="0.01" min="0" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('amount')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="pay_method" value="Paid with" />
                <select wire:model.live="payMethodId" id="pay_method" class="mt-1 block w-full">
                    @foreach ($methods as $m)
                        <option value="{{ $m->id }}">{{ $m->name }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('method')" class="mt-2" />
            </div>
            @if ($payType && $payType !== 'cash')
                <div>
                    <x-input-label for="pay_ref" value="Code (M-Pesa: last 4 characters or full code)" />
                    <x-text-input wire:model="payReference" id="pay_ref" type="text" maxlength="50" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('reference')" class="mt-2" />
                </div>
            @endif
        </div>
    </x-card>

    <div class="flex gap-3">
        <x-primary-button wire:loading.attr="disabled">Issue invoice</x-primary-button>
        <a href="{{ route('invoices') }}" wire:navigate
           class="inline-flex items-center px-4 py-2 rounded-lg border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50">Cancel</a>
    </div>
</form>
