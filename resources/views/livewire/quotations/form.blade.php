<?php

use App\Models\Customer;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Services\PricingService;
use App\Services\QuotationService;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component
{
    public ?int $quotationId = null;
    public ?string $numberLabel = null;
    public $customerId = '';
    public string $validUntil = '';
    public string $notes = '';
    public string $search = '';
    public array $lines = [];   // [variantId => ['qty' => 1, 'dtype' => '', 'dvalue' => '']]
    public string $cartDType = '';
    public $cartDValue = '';
    public bool $discountsDropped = false;

    public function mount(?int $quotationId = null): void
    {
        $user = auth()->user();
        abort_unless($user->can('make-sales'), 403);

        $this->validUntil = now()->addDays(14)->toDateString();

        if (! $quotationId) {
            return;
        }

        $q = Quotation::with('items')->findOrFail($quotationId); // tenant scope applies
        abort_unless($user->can('view-reports') || $q->user_id === $user->id, 403);
        abort_unless(in_array($q->status, ['open', 'accepted'], true), 403, 'This quotation can no longer be edited.');

        $canDiscount = $user->can('give-discounts') && $user->business->discounts_enabled;

        $this->quotationId = $q->id;
        $this->numberLabel = $q->number_label;
        $this->customerId = $q->customer_id;
        $this->notes = (string) $q->notes;
        $this->validUntil = $q->valid_until->gte(today()) ? $q->valid_until->toDateString() : now()->addDays(14)->toDateString();

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
        } elseif ($q->discount_amount > 0 || $q->items->contains(fn ($i) => $i->discount_amount > 0)) {
            $this->discountsDropped = true;
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

        // Display only. QuotationService prices everything again on save.
        try {
            $priced = PricingService::price($lines, $discount, auth()->user()->business);
        } catch (ValidationException $e) {
            $priced = null;
        }

        return [
            'discountsOn' => (bool) auth()->user()->business->discounts_enabled,
            'customers' => Customer::active()->orderBy('name')->get(['id', 'name', 'phone']),
            'results' => $term === '' ? collect() : ProductVariant::with('product')->active()
                ->whereHas('product', fn ($q) => $q->active())
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

        $this->resetErrorBag('cart');

        if (isset($this->lines[$id])) {
            $this->lines[$id]['qty'] = (int) $this->lines[$id]['qty'] + 1;
        } else {
            $this->lines[$id] = ['qty' => 1, 'dtype' => '', 'dvalue' => ''];
        }

        $this->search = '';
    }

    public function removeLine(int $id): void
    {
        unset($this->lines[$id]);
    }

    public function save(): void
    {
        [$lines, $discount] = $this->payload();

        if ($this->quotationId) {
            $quotation = QuotationService::update(
                Quotation::findOrFail($this->quotationId),
                (int) $this->customerId, $this->validUntil, $this->notes, $lines, $discount
            );
            session()->flash('notify', 'Quotation updated.');
        } else {
            $quotation = QuotationService::create(
                (int) $this->customerId, $this->validUntil, $this->notes, $lines, $discount
            );
            session()->flash('notify', 'Quotation saved.');
        }

        $this->redirect(route('quotations.show', $quotation), navigate: true);
    }
}; ?>

<form wire:submit="save" class="space-y-6">
    <x-card class="p-6 space-y-4">
        <h3 class="text-lg font-medium text-gray-900">{{ $quotationId ? 'Edit quotation ' . $numberLabel : 'New quotation' }}</h3>

        @if ($quotationId)
            <p class="rounded-lg bg-brand-50 px-3 py-2 text-sm text-brand-700">
                Saving re-prices every item at today's prices. If the customer had already accepted it, it goes back to "open" so they can accept the changes.
            </p>
        @endif
        @if ($discountsDropped)
            <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
                This quotation had discounts that you cannot give, so they were removed here. Cancel to keep the original.
            </p>
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
                @if ($customers->isEmpty())
                    <p class="mt-1 text-xs text-gray-500">No customers yet. Add one under Customers.</p>
                @endif
            </div>
            <div>
                <x-input-label for="valid_until" value="Valid until" />
                <x-text-input wire:model="validUntil" id="valid_until" type="date" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('valid_until')" class="mt-2" />
            </div>
        </div>

        <div>
            <x-input-label for="notes" value="Notes (shown on the quotation)" />
            <x-text-input wire:model="notes" id="notes" type="text" maxlength="255" class="mt-1 block w-full"
                          placeholder="e.g. Delivery included within Nairobi" />
            <x-input-error :messages="$errors->get('notes')" class="mt-2" />
        </div>
    </x-card>

    <x-card class="p-6 space-y-4">
        <h3 class="text-lg font-medium text-gray-900">Items</h3>

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
                            <tr wire:key="qline-{{ $id }}">
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
                    <span class="text-sm text-gray-600">Whole-quotation discount</span>
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

    <div class="flex gap-3">
        <x-primary-button wire:loading.attr="disabled">{{ $quotationId ? 'Update quotation' : 'Save quotation' }}</x-primary-button>
        <a href="{{ route('quotations') }}" wire:navigate
           class="inline-flex items-center px-4 py-2 rounded-lg border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50">Cancel</a>
    </div>
</form>
