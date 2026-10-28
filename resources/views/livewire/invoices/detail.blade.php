<?php

use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Services\InvoiceService;
use App\Services\WriteOffService;
use Livewire\Volt\Component;

new class extends Component
{
    public int $invoiceId;
    public $methodId = '';
    public $amount = '';
    public string $reference = '';
    public bool $voiding = false;
    public string $reason = '';
    public bool $writingOff = false;
    public $writeOffAmount = '';
    public string $writeOffReason = '';

    public function mount(int $invoiceId): void
    {
        abort_unless(auth()->user()->can('issue-invoices'), 403);

        $this->invoiceId = $invoiceId;

        $cash = PaymentMethod::active()->where('type', 'cash')->first() ?? PaymentMethod::active()->first();
        $this->methodId = $cash?->id ?? '';
    }

    public function with(): array
    {
        return [
            'invoice' => Invoice::with(['items', 'payments', 'customer', 'user', 'business'])
                ->findOrFail($this->invoiceId), // tenant scope applies
            'methods' => PaymentMethod::active()->orderBy('name')->get(),
        ];
    }

    public function fillBalance(): void
    {
        $this->amount = (string) Invoice::findOrFail($this->invoiceId)->balance;
    }

    public function recordPayment(): void
    {
        $invoice = Invoice::findOrFail($this->invoiceId);

        InvoiceService::recordPayment($invoice, (int) $this->methodId, (float) $this->amount, $this->reference);

        $this->reset('amount', 'reference');
        $this->resetErrorBag();
        $this->dispatch('notify', message: 'Payment recorded.');
    }

    public function startVoid(): void
    {
        abort_unless(auth()->user()->can('void-sales'), 403);

        $this->resetErrorBag();
        $this->reason = '';
        $this->writingOff = false;
        $this->voiding = true;
    }

    public function cancelVoid(): void
    {
        $this->reset('voiding', 'reason');
        $this->resetErrorBag();
    }

    public function confirmVoid(): void
    {
        InvoiceService::void(Invoice::findOrFail($this->invoiceId), $this->reason);

        $this->cancelVoid();
    }

    public function startWriteOff(): void
    {
        abort_unless(auth()->user()->can('void-sales'), 403);

        $this->resetErrorBag();
        $this->voiding = false;
        $this->writeOffReason = '';
        $this->writeOffAmount = (string) Invoice::findOrFail($this->invoiceId)->balance;
        $this->writingOff = true;
    }

    public function cancelWriteOff(): void
    {
        $this->reset('writingOff', 'writeOffAmount', 'writeOffReason');
        $this->resetErrorBag();
    }

    public function confirmWriteOff(): void
    {
        WriteOffService::run(Invoice::findOrFail($this->invoiceId), (float) $this->writeOffAmount, $this->writeOffReason);

        $this->cancelWriteOff();
        $this->dispatch('notify', message: 'Written off and recorded as a bad debt.');
    }
}; ?>

<div class="space-y-6">
    @php
        $ps = $invoice->payment_status;
        $color = ['paid' => 'green', 'partly_paid' => 'orange', 'unpaid' => 'gray', 'overdue' => 'red', 'voided' => 'red', 'written_off' => 'gray'][$ps] ?? 'gray';
        $selectedType = $methods->firstWhere('id', (int) $methodId)?->type;
    @endphp

    <div class="flex gap-3 print:hidden">
        <button onclick="window.print()"
                class="inline-flex items-center px-4 py-2 rounded-lg bg-brand-500 text-sm font-semibold text-white hover:bg-brand-600">
            Print invoice
        </button>
        <a href="{{ route('invoices') }}" wire:navigate
           class="inline-flex items-center px-4 py-2 rounded-lg border border-brand-500 text-sm font-semibold text-brand-600 hover:bg-brand-50">
            All invoices
        </a>
    </div>

    <x-card class="p-8 text-sm print:border-0 print:p-0">
        @if ($invoice->status === 'voided')
            <p class="mb-4 rounded-lg bg-red-100 px-3 py-2 text-center font-semibold text-red-700">
                VOIDED{{ $invoice->void_reason ? ': ' . $invoice->void_reason : '' }}
            </p>
        @endif

        <div class="flex justify-between">
            <div>
                <p class="text-xl font-bold text-gray-900">{{ $invoice->business->name }}</p>
                @if ($invoice->business->phone) <p class="text-gray-600">{{ $invoice->business->phone }}</p> @endif
                <p class="text-gray-600">{{ collect([$invoice->business->address, $invoice->business->county])->filter()->implode(', ') }}</p>
                @if (\App\Services\BranchService::multi() && $invoice->branch_id)
    <p class="text-gray-600">Branch: {{ \App\Models\Branch::find($invoice->branch_id)?->name }}</p>
@endif
            </div>
            <div class="text-right">
                <p class="text-lg font-bold text-brand-600">INVOICE</p>
                <p class="font-semibold">{{ $invoice->number_label }}</p>
                <p class="text-gray-600">Date: {{ $invoice->created_at->format('d M Y') }}</p>
                @if ($invoice->due_date)
                    <p class="text-gray-600">Due: {{ $invoice->due_date->format('d M Y') }}</p>
                @endif
                <p class="mt-1 print:hidden"><x-badge :color="$color">{{ ucfirst(str_replace('_', ' ', $ps)) }}</x-badge></p>
            </div>
        </div>

        <div class="my-6 rounded-lg bg-gray-50 p-4 print:bg-white print:border print:border-gray-200">
            <p class="text-xs uppercase text-gray-500">Billed to</p>
            <p class="font-semibold text-gray-900">{{ $invoice->customer->name }}</p>
            @if ($invoice->customer->phone) <p class="text-gray-600">{{ $invoice->customer->phone }}</p> @endif
            @if ($invoice->customer->address) <p class="text-gray-600">{{ $invoice->customer->address }}</p> @endif
        </div>

        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr>
                    <th class="py-2 text-left text-xs font-medium text-gray-500 uppercase">Item</th>
                    <th class="py-2 text-right text-xs font-medium text-gray-500 uppercase">Qty</th>
                    <th class="py-2 text-right text-xs font-medium text-gray-500 uppercase">Price</th>
                    <th class="py-2 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($invoice->items as $item)
                    <tr>
                        <td class="py-2">
                            {{ $item->product_name }}@if ($item->variant_name) - {{ $item->variant_name }}@endif
                            @if ($item->discount_amount > 0)
                                <span class="block text-xs text-gray-500">Discount -{{ number_format($item->discount_amount, 2) }}</span>
                            @endif
                        </td>
                        <td class="py-2 text-right">{{ $item->quantity }}</td>
                        <td class="py-2 text-right">{{ number_format($item->unit_price, 2) }}</td>
                        <td class="py-2 text-right">{{ number_format($item->line_total, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="ml-auto mt-4 w-full max-w-xs space-y-1 border-t border-gray-200 pt-3">
            <div class="flex justify-between"><span>Subtotal</span><span>{{ number_format($invoice->subtotal, 2) }}</span></div>
            @if ($invoice->discount_amount > 0)
                <div class="flex justify-between"><span>Discount</span><span>-{{ number_format($invoice->discount_amount, 2) }}</span></div>
            @endif
            @if ($invoice->tax_rate > 0)
                <div class="flex justify-between">
                    <span>Tax ({{ rtrim(rtrim(number_format($invoice->tax_rate, 2), '0'), '.') }}%{{ $invoice->tax_inclusive ? ', included' : '' }})</span>
                    <span>{{ number_format($invoice->tax_amount, 2) }}</span>
                </div>
            @endif
            <div class="flex justify-between text-base font-bold text-gray-900">
                <span>Total</span><span>Kshs {{ number_format($invoice->total, 2) }}</span>
            </div>
            <div class="flex justify-between"><span>Paid</span><span>{{ number_format($invoice->cash_paid, 2) }}</span></div>
            @if ($invoice->written_off > 0)
                <div class="flex justify-between text-gray-600"><span>Written off</span><span>{{ number_format($invoice->written_off, 2) }}</span></div>
            @endif
            <div class="flex justify-between text-base font-bold {{ $invoice->balance > 0 && $invoice->status === 'issued' ? 'text-red-600' : 'text-gray-900' }}">
                <span>Balance due</span><span>Kshs {{ number_format($invoice->status === 'voided' ? 0 : $invoice->balance, 2) }}</span>
            </div>
        </div>

        @if ($invoice->payments->count())
            <div class="mt-6">
                <p class="mb-1 text-xs font-medium uppercase text-gray-500">Payments received</p>
                @foreach ($invoice->payments as $p)
                    <div wire:key="ipay-{{ $p->id }}" class="flex justify-between text-gray-700">
                        @if ($p->type === 'writeoff')
                            <span class="text-gray-500">{{ $p->created_at->format('d M Y') }}, written off (not received)</span>
                            <span class="text-gray-500">{{ number_format($p->amount, 2) }}</span>
                        @else
                            <span>
                                {{ $p->created_at->format('d M Y') }}, {{ $p->method_name }}@if ($p->reference) ({{ $p->reference }})@endif
                            </span>
                            <span>
                                {{ number_format($p->amount, 2) }}
                                <a href="{{ route('invoices.payment-receipt', [$invoice, $p->id]) }}" target="_blank"
                                   class="ml-3 text-brand-600 hover:underline print:hidden">{{ $p->receipt_label }}</a>
                            </span>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        @if ($invoice->notes)
            <p class="mt-6 text-gray-700"><span class="font-semibold">Notes:</span> {{ $invoice->notes }}</p>
        @endif
        <x-document-terms type="invoice" :business="$invoice->business" />
    </x-card>

    @if ($invoice->status === 'issued' && $invoice->balance > 0)
        <x-card class="p-6 space-y-4 print:hidden">
            <form wire:submit="recordPayment" class="space-y-4">
                <h3 class="text-lg font-medium text-gray-900">Record a payment</h3>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <x-input-label for="method" value="Method" />
                        <select wire:model.live="methodId" id="method" class="mt-1 block w-full">
                            @foreach ($methods as $m)
                                <option value="{{ $m->id }}">{{ $m->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('method')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="amount" value="Amount" />
                        <x-text-input wire:model="amount" id="amount" type="number" step="0.01" min="0" class="mt-1 block w-full" />
                        <button type="button" wire:click="fillBalance" class="mt-1 text-xs text-brand-600 hover:underline">
                            Fill balance ({{ number_format($invoice->balance, 2) }})
                        </button>
                        <x-input-error :messages="$errors->get('amount')" class="mt-2" />
                    </div>
                    @if ($selectedType && $selectedType !== 'cash')
                        <div>
                            <x-input-label for="reference" value="Reference (M-Pesa: last 4 characters or the full code)" />
                            <x-text-input wire:model="reference" id="reference" type="text" maxlength="50" class="mt-1 block w-full" placeholder="e.g. M-Pesa code" />
                            <x-input-error :messages="$errors->get('reference')" class="mt-2" />
                        </div>
                    @endif
                </div>

                <x-primary-button>Record payment</x-primary-button>
            </form>
        </x-card>
    @endif

    @can('void-sales')
        @if ($invoice->status === 'issued')
            <div class="space-x-6 print:hidden">
                @if ((float) $invoice->amount_paid == 0 && ! $voiding)
                    <button type="button" wire:click="startVoid" class="text-sm text-red-600 hover:underline">Void this invoice</button>
                @endif
                @if ($invoice->balance > 0 && ! $writingOff)
                    <button type="button" wire:click="startWriteOff" class="text-sm text-gray-600 hover:underline">Write off the balance as a bad debt</button>
                @endif
            </div>

            @if ($voiding)
                <x-card class="p-6 space-y-4 print:hidden">
                    <h3 class="text-lg font-semibold text-gray-900">Void this invoice?</h3>
                    <p class="text-sm text-gray-600">It stays on record, marked as voided, and the items go back into stock. This can't be undone.</p>
                    <div>
                        <x-input-label for="reason" value="Reason *" />
                        <x-text-input wire:model="reason" id="reason" type="text" maxlength="255" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('reason')" class="mt-2" />
                    </div>
                    <div class="flex gap-3">
                        <button type="button" wire:click="confirmVoid" wire:loading.attr="disabled"
                                class="inline-flex items-center px-4 py-2 rounded-lg bg-red-600 text-sm font-semibold text-white hover:bg-red-700">
                            Void invoice
                        </button>
                        <x-secondary-button type="button" wire:click="cancelVoid">Keep invoice</x-secondary-button>
                    </div>
                </x-card>
            @endif

            @if ($writingOff)
                <x-card class="p-6 space-y-4 print:hidden">
                    <h3 class="text-lg font-semibold text-gray-900">Write off as a bad debt?</h3>
                    <p class="text-sm text-gray-600">
                        The customer is no longer expected to pay this amount. The invoice shows it as written off, and the same amount is
                        recorded as a "Bad debts" expense today, which lowers your profit for this period. This can't be undone.
                    </p>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <x-input-label for="wo_amount" value="Amount to write off *" />
                            <x-text-input wire:model="writeOffAmount" id="wo_amount" type="number" step="0.01" min="0" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('writeOffAmount')" class="mt-2" />
                        </div>
                        <div class="md:col-span-2">
                            <x-input-label for="wo_reason" value="Reason *" />
                            <x-text-input wire:model="writeOffReason" id="wo_reason" type="text" maxlength="200" class="mt-1 block w-full" placeholder="e.g. Customer cannot be traced" />
                            <x-input-error :messages="$errors->get('writeOffReason')" class="mt-2" />
                        </div>
                    </div>
                    <div class="flex gap-3">
                        <button type="button" wire:click="confirmWriteOff" wire:loading.attr="disabled"
                                class="inline-flex items-center px-4 py-2 rounded-lg bg-red-600 text-sm font-semibold text-white hover:bg-red-700">
                            Write off
                        </button>
                        <x-secondary-button type="button" wire:click="cancelWriteOff">Cancel</x-secondary-button>
                    </div>
                </x-card>
            @endif
        @endif
    @endcan
</div>
