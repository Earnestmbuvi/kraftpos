<?php

use App\Models\PaymentMethod;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleReturnItem;
use App\Services\PricingService;
use App\Services\ReturnService;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component
{
    public string $mode = 'return';
    public string $receipt = '';
    public ?int $saleId = null;
    public array $qtys = [];
    public string $reason = '';
    public $methodId = '';
    public string $reference = '';

    // exchange only
    public string $search = '';
    public array $lines = [];
    public $payAmount = '';
    public $payMethodId = '';
    public string $payRef = '';

    public function mount(string $mode = 'return'): void
    {
        abort_unless(auth()->user()->can('process-returns'), 403);

        $this->mode = $mode === 'exchange' ? 'exchange' : 'return';

        $cash = PaymentMethod::active()->where('type', 'cash')->first() ?? PaymentMethod::active()->first();
        $this->methodId = $this->payMethodId = $cash?->id ?? '';

        if ($id = request()->integer('sale')) {
            $this->saleId = Sale::find($id)?->id;
        }
    }

    public function findSale(): void
    {
        $this->resetErrorBag();
        $n = (int) preg_replace('/\D/', '', $this->receipt);
        $sale = $n ? Sale::where('receipt_number', $n)->first() : null;

        if (! $sale) {
            $this->addError('receipt', 'No sale with that receipt number.');
            return;
        }

        $this->saleId = $sale->id;
        $this->qtys = [];
    }

    public function addLine(int $id): void
    {
        if (ProductVariant::active()->find($id)) {
            $this->lines[$id] = (int) ($this->lines[$id] ?? 0) + 1;
        }
        $this->search = '';
    }

    public function removeLine(int $id): void
    {
        unset($this->lines[$id]);
    }

    public function with(): array
    {
        $sale = $this->saleId ? Sale::with(['items', 'customer'])->find($this->saleId) : null;
        $term = trim($this->search);
        $data = [
            'sale' => $sale, 'returned' => collect(), 'value' => 0.0, 'newTotal' => null,
            'methods' => PaymentMethod::active()->orderBy('name')->get(),
            'variants' => ProductVariant::with('product')->whereIn('id', array_keys($this->lines))->get()->keyBy('id'),
            'results' => $this->mode === 'exchange' && $term !== '' ? ProductVariant::with('product')->active()
                ->whereHas('product', fn ($p) => $p->active())
                ->where(fn ($q) => $q->where('sku', 'like', "%{$term}%")->orWhere('barcode', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%")
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$term}%")))
                ->orderBy('product_id')->limit(8)->get() : collect(),
        ];

        if (! $sale) {
            return $data;
        }

        $returned = SaleReturnItem::whereIn('sale_item_id', $sale->items->pluck('id'))
            ->selectRaw('sale_item_id, SUM(quantity) as q')->groupBy('sale_item_id')->pluck('q', 'sale_item_id');

        $values = PricingService::lineValues(
            $sale->items->map(fn ($i) => ['line_total' => (float) $i->line_total, 'tax_class' => $i->tax_class ?? 'standard'])->all(),
            (float) $sale->discount_amount, (float) $sale->tax_rate, (bool) $sale->tax_inclusive
        );

        $value = 0.0;
        foreach ($sale->items as $idx => $item) {
            $q = min((int) ($this->qtys[$item->id] ?? 0), (int) $item->quantity - (int) ($returned[$item->id] ?? 0));
            if ($q > 0) {
                $value += round($values[$idx]['paid'] * $q / $item->quantity, 2);
            }
        }

        $newTotal = null;
        if ($this->mode === 'exchange' && $this->lines) {
            try {
                $payload = collect($this->lines)->map(fn ($q, $id) => ['variant_id' => (int) $id, 'quantity' => (int) $q])->values()->all();
                $newTotal = PricingService::price($payload, null, auth()->user()->business)['total'];
            } catch (ValidationException $e) {
                $newTotal = null;
            }
        }

        return array_merge($data, ['returned' => $returned, 'value' => round($value, 2), 'newTotal' => $newTotal]);
    }

    public function save(): void
    {
        abort_unless(auth()->user()->can('process-returns'), 403);

        $qtys = array_filter(array_map('intval', $this->qtys));

        if ($this->mode === 'return') {
            $ret = ReturnService::refund((int) $this->saleId, $qtys, $this->reason, (int) $this->methodId, $this->reference);
        } else {
            $lines = collect($this->lines)->map(fn ($q, $id) => ['variant_id' => (int) $id, 'quantity' => (int) $q])->values()->all();
            $pay = round((float) $this->payAmount, 2);
            $payments = $pay > 0 ? [['method_id' => (int) $this->payMethodId, 'amount' => $pay, 'reference' => $this->payRef]] : [];

            [$ret] = ReturnService::exchange((int) $this->saleId, $qtys, $lines, $payments, $this->reason, (int) $this->methodId, $this->reference);
        }

        session()->flash('notify', ($this->mode === 'exchange' ? 'Exchange ' : 'Return ') . $ret->number_label . ' recorded.');
        $this->redirect(route('returns.show', $ret), navigate: true);
    }
}; ?>

<div class="space-y-6">
    <x-card class="p-6 space-y-4">
        <h3 class="text-lg font-medium text-gray-900">{{ $mode === 'exchange' ? 'Exchange' : 'Return' }}</h3>

        @if (! $sale)
            <form wire:submit="findSale" class="flex flex-wrap items-end gap-3">
                <div>
                    <x-input-label for="rc" value="Receipt number of the original sale" />
                    <x-text-input wire:model="receipt" id="rc" type="text" class="mt-1" placeholder="e.g. 12" />
                </div>
                <x-primary-button>Find sale</x-primary-button>
            </form>
            <x-input-error :messages="$errors->get('receipt')" />
        @else
            <p class="text-sm text-gray-600">
                Sale <strong>{{ $sale->receipt_label }}</strong> of {{ $sale->created_at->format('d M Y') }},
                {{ $sale->customer?->name ?? 'walk-in customer' }}, total Kshs {{ number_format($sale->total, 2) }}.
                @if ($sale->status !== 'completed') <span class="font-semibold text-red-600">This sale is voided and cannot be returned.</span> @endif
            </p>

            <table class="min-w-full text-sm">
                <thead><tr class="text-left text-xs uppercase text-gray-500">
                    <th class="py-2">Item</th><th class="py-2 text-right">Sold</th><th class="py-2 text-right">Already returned</th><th class="py-2">Return now</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($sale->items as $item)
                        <tr wire:key="si-{{ $item->id }}">
                            <td class="py-2">{{ $item->product_name }}@if ($item->variant_name) - {{ $item->variant_name }}@endif</td>
                            <td class="py-2 text-right">{{ $item->quantity }}</td>
                            <td class="py-2 text-right">{{ (int) ($returned[$item->id] ?? 0) }}</td>
                            <td class="py-2"><x-text-input wire:model.live.debounce.300ms="qtys.{{ $item->id }}" type="number" min="0" class="w-24" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <x-input-error :messages="$errors->get('qty')" />
            <x-input-error :messages="$errors->get('sale')" />

            <div class="flex justify-between border-t border-gray-200 pt-3 text-base font-semibold">
                <span>Value of goods returned</span><span>Kshs {{ number_format($value, 2) }}</span>
            </div>
        @endif
    </x-card>

    @if ($sale && $sale->status === 'completed')
        @if ($mode === 'exchange')
            <x-card class="p-6 space-y-4">
                <h3 class="text-lg font-medium text-gray-900">Items taken instead</h3>
                <div class="relative">
                    <x-text-input wire:model.live.debounce.300ms="search" type="search" placeholder="Search a product..." class="block w-full" />
                    @if ($results->count())
                        <div class="absolute z-20 mt-1 w-full rounded-lg border border-gray-200 bg-white shadow-lg">
                            @foreach ($results as $r)
                                <button type="button" wire:key="xr-{{ $r->id }}" wire:click="addLine({{ $r->id }})"
                                        class="flex w-full justify-between px-4 py-2 text-left text-sm hover:bg-gray-50">
                                    <span>{{ $r->product->name }}@if ($r->name !== 'Default') - {{ $r->name }}@endif</span>
                                    <span class="font-medium text-brand-600">{{ number_format($r->selling_price, 2) }}</span>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
                <x-input-error :messages="$errors->get('cart')" />

                @foreach ($lines as $id => $qty)
                    @php $v = $variants->get($id); @endphp
                    @continue(! $v)
                    <div wire:key="xl-{{ $id }}" class="flex items-center justify-between gap-3 text-sm">
                        <span>{{ $v->product->name }}@if ($v->name !== 'Default') - {{ $v->name }}@endif ({{ number_format($v->selling_price, 2) }})</span>
                        <span class="flex items-center gap-3">
                            <x-text-input wire:model.live.debounce.300ms="lines.{{ $id }}" type="number" min="1" class="w-24" />
                            <button type="button" wire:click="removeLine({{ $id }})" class="text-gray-500 hover:underline">Remove</button>
                        </span>
                    </div>
                @endforeach

                @if ($newTotal !== null)
                    @php $diff = round($newTotal - $value, 2); @endphp
                    <div class="space-y-1 border-t border-gray-200 pt-3 text-sm">
                        <div class="flex justify-between"><span>New items</span><span>{{ number_format($newTotal, 2) }}</span></div>
                        <div class="flex justify-between"><span>Credit from returned goods</span><span>-{{ number_format(min($value, $newTotal), 2) }}</span></div>
                        <div class="flex justify-between text-base font-bold">
                            @if ($diff > 0) <span>Customer pays</span><span>Kshs {{ number_format($diff, 2) }}</span>
                            @elseif ($diff < 0) <span>Pay back to customer</span><span>Kshs {{ number_format(-$diff, 2) }}</span>
                            @else <span>Even exchange</span><span>Kshs 0.00</span> @endif
                        </div>
                    </div>

                    @if ($diff > 0)
                        @php $payType = $methods->firstWhere('id', (int) $payMethodId)?->type; @endphp
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <x-input-label for="pa" value="Amount paid now" />
                                <x-text-input wire:model="payAmount" id="pa" type="number" step="0.01" min="0" class="mt-1 block w-full" />
                            </div>
                            <div>
                                <x-input-label for="pm" value="Paid with" />
                                <select wire:model.live="payMethodId" id="pm" class="mt-1 block w-full">
                                    @foreach ($methods as $m) <option value="{{ $m->id }}">{{ $m->name }}</option> @endforeach
                                </select>
                            </div>
                            @if ($payType === 'mobile')
                                <div>
                                    <x-input-label for="pr" value="M-Pesa code (last 4 or full)" />
                                    <x-text-input wire:model="payRef" id="pr" type="text" maxlength="12" class="mt-1 block w-full" />
                                </div>
                            @endif
                        </div>
                        <x-input-error :messages="$errors->get('payments')" />
                    @endif
                @endif
            </x-card>
        @endif

        <x-card class="p-6 space-y-4">
            <div>
                <x-input-label for="reason" value="Reason *" />
                <x-text-input wire:model="reason" id="reason" type="text" maxlength="255" class="mt-1 block w-full" placeholder="e.g. Faulty, wrong size" />
                <x-input-error :messages="$errors->get('reason')" class="mt-2" />
            </div>

            @if ($mode === 'return' || ($newTotal !== null && $newTotal < $value))
                @php $refType = $methods->firstWhere('id', (int) $methodId)?->type; @endphp
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="rm" value="Money paid back with" />
                        <select wire:model.live="methodId" id="rm" class="mt-1 block w-full">
                            @foreach ($methods as $m) <option value="{{ $m->id }}">{{ $m->name }}</option> @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('method')" class="mt-2" />
                    </div>
                    @if ($refType === 'mobile')
                        <div>
                            <x-input-label for="rr" value="M-Pesa code (last 4 or full)" />
                            <x-text-input wire:model="reference" id="rr" type="text" maxlength="12" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('reference')" class="mt-2" />
                        </div>
                    @endif
                </div>
            @endif

            <div class="flex gap-3">
                <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled">{{ $mode === 'exchange' ? 'Complete exchange' : 'Complete return' }}</x-primary-button>
                <a href="{{ route('returns') }}" wire:navigate class="inline-flex items-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Cancel</a>
            </div>
        </x-card>
    @endif
</div>
