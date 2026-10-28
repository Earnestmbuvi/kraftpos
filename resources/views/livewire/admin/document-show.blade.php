<?php

use App\Models\PlatformDocument;
use App\Services\PlatformDocumentService as Docs;
use Livewire\Volt\Component;

new class extends Component
{
    public int $documentId;
    public string $method = 'Cash';
    public $amount = '';
    public string $reference = '';
    public string $reason = '';
    public bool $voiding = false;

    public function mount(int $documentId): void
    {
        abort_unless(auth()->user()?->is_super_admin, 403);
        $this->documentId = $documentId;
    }

    public function with(): array
    {
        abort_unless(auth()->user()?->is_super_admin, 403);

        return ['d' => PlatformDocument::with(['items', 'payments', 'business'])->findOrFail($this->documentId), 'methods' => Docs::METHODS];
    }

    public function convert(): void
    {
        $invoice = Docs::convert(PlatformDocument::findOrFail($this->documentId));
        session()->flash('notify', 'Converted to ' . $invoice->number_label . '.');
        $this->redirect(route('admin.document', $invoice->id), navigate: true);
    }

    public function decline(): void
    {
        Docs::decline(PlatformDocument::findOrFail($this->documentId));
        $this->dispatch('notify', message: 'Quotation declined.');
    }

    public function pay(): void
    {
        Docs::pay(PlatformDocument::findOrFail($this->documentId), $this->method, (float) $this->amount, $this->reference);
        $this->reset('amount', 'reference');
        $this->resetErrorBag();
        $this->dispatch('notify', message: 'Payment recorded.');
    }

    public function confirmVoid(): void
    {
        Docs::void(PlatformDocument::findOrFail($this->documentId), $this->reason);
        $this->reset('voiding', 'reason');
        $this->resetErrorBag();
    }
}; ?>

<div class="space-y-6">
    @php
        $co = config('kraftpos.company');
        $st = $d->display_status;
        $isInvoice = $d->type === 'invoice';
    @endphp

    <div class="flex flex-wrap gap-3 print:hidden">
        <button onclick="window.print()" class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600">Print</button>
        <a href="{{ route($isInvoice ? 'admin.invoices' : 'admin.quotations') }}" wire:navigate class="rounded-lg border border-brand-500 px-4 py-2 text-sm font-semibold text-brand-600 hover:bg-brand-50">All {{ $isInvoice ? 'invoices' : 'quotations' }}</a>
        @if (! $isInvoice && $d->status === 'open')
            <button wire:click="convert" wire:confirm="Convert this quotation to an invoice?" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Convert to invoice</button>
            <button wire:click="decline" wire:confirm="Mark this quotation as declined?" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Mark declined</button>
        @endif
    </div>
    <x-input-error :messages="$errors->get('status')" />

    <x-card class="p-8 text-sm print:border-0 print:p-0">
        <div class="flex justify-between gap-6">
            <div>
                <p class="text-xl font-bold text-gray-900">{{ $co['name'] }}</p>
                <p class="text-gray-600">{{ $co['address'] }}</p>
                @if ($co['phone']) <p class="text-gray-600">{{ $co['phone'] }}</p> @endif
                <p class="text-gray-600">{{ $co['email'] }}</p>
                @if ($co['pin']) <p class="text-gray-600">PIN: {{ $co['pin'] }}</p> @endif
            </div>
            <div class="text-right">
                <p class="text-lg font-bold text-brand-600">{{ strtoupper($d->type) }}</p>
                <p class="font-semibold">{{ $d->number_label }}</p>
                <p class="text-gray-600">Date: {{ $d->issue_date->format('d M Y') }}</p>
                @if ($d->due_date) <p class="text-gray-600">{{ $isInvoice ? 'Due' : 'Valid until' }}: {{ $d->due_date->format('d M Y') }}</p> @endif
                <p class="mt-1 print:hidden"><x-badge :color="in_array($st, ['paid', 'converted']) ? 'green' : (in_array($st, ['overdue', 'voided', 'declined', 'expired']) ? 'red' : 'orange')">{{ ucfirst(str_replace('_', ' ', $st)) }}</x-badge></p>
            </div>
        </div>

        <div class="my-6 rounded-lg bg-gray-50 p-4 print:border print:border-gray-200 print:bg-white">
            <p class="text-xs uppercase text-gray-500">{{ $isInvoice ? 'Billed to' : 'Prepared for' }}</p>
            <p class="font-semibold text-gray-900">{{ $d->business->name }}</p>
            @if ($d->business->phone) <p class="text-gray-600">{{ $d->business->phone }}</p> @endif
            <p class="text-gray-600">{{ collect([$d->business->address, $d->business->county])->filter()->implode(', ') }}</p>
        </div>

        <table class="min-w-full divide-y divide-gray-200">
            <thead><tr>
                <th class="py-2 text-left text-xs font-medium uppercase text-gray-500">Item</th>
                <th class="py-2 text-right text-xs font-medium uppercase text-gray-500">Qty</th>
                <th class="py-2 text-right text-xs font-medium uppercase text-gray-500">Price</th>
                <th class="py-2 text-right text-xs font-medium uppercase text-gray-500">Amount</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($d->items as $it)
                    <tr>
                        <td class="py-2">{{ $it->name }}@if ($it->description) <span class="block text-xs text-gray-500">{{ $it->description }}</span>@endif</td>
                        <td class="py-2 text-right">{{ $it->quantity }}</td>
                        <td class="py-2 text-right">{{ number_format($it->unit_price, 2) }}</td>
                        <td class="py-2 text-right">{{ number_format($it->line_total, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="ml-auto mt-4 w-full max-w-xs space-y-1 border-t border-gray-200 pt-3">
            <div class="flex justify-between"><span>Subtotal</span><span>{{ number_format($d->subtotal, 2) }}</span></div>
            @if ($d->tax_amount > 0)
                <div class="flex justify-between"><span>Tax ({{ rtrim(rtrim(number_format($d->tax_rate, 2), '0'), '.') }}%)</span><span>{{ number_format($d->tax_amount, 2) }}</span></div>
            @endif
            <div class="flex justify-between text-base font-bold text-gray-900"><span>Total</span><span>Kshs {{ number_format($d->total, 2) }}</span></div>
            @if ($isInvoice)
                <div class="flex justify-between"><span>Paid</span><span>{{ number_format($d->amount_paid, 2) }}</span></div>
                <div class="flex justify-between text-base font-bold {{ $d->balance > 0 && $d->status === 'issued' ? 'text-red-600' : 'text-gray-900' }}"><span>Balance due</span><span>Kshs {{ number_format($d->status === 'voided' ? 0 : $d->balance, 2) }}</span></div>
            @endif
        </div>

        @if ($d->payments->count())
            <div class="mt-6">
                <p class="mb-1 text-xs font-medium uppercase text-gray-500">Payments received</p>
                @foreach ($d->payments as $p)
                    <div wire:key="pp-{{ $p->id }}" class="flex justify-between text-gray-700">
                        <span>{{ $p->created_at->format('d M Y') }}, {{ $p->method }}@if ($p->reference) ({{ $p->reference }})@endif</span><span>{{ number_format($p->amount, 2) }}</span>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($d->notes) <p class="mt-6 text-gray-700"><span class="font-semibold">Notes:</span> {{ $d->notes }}</p> @endif
        @if ($isInvoice && $co['payment_details'])
            <div class="mt-6 rounded-lg bg-gray-50 p-3 text-gray-700 print:border print:border-gray-200 print:bg-white"><span class="font-semibold">How to pay:</span> {{ $co['payment_details'] }}</div>
        @endif
        @if ($d->status === 'voided') <p class="mt-4 font-semibold text-red-700">VOIDED: {{ $d->close_reason }}</p> @endif
    </x-card>

    @if ($isInvoice && $d->status === 'issued')
        @if ($d->balance > 0)
            <x-card class="p-6 space-y-4 print:hidden">
                <form wire:submit="pay" class="space-y-4">
                    <h3 class="text-lg font-medium text-gray-900">Record a payment</h3>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <div><x-input-label for="pm" value="Method" /><select wire:model.live="method" id="pm" class="mt-1 block w-full">@foreach ($methods as $m) <option value="{{ $m }}">{{ $m }}</option> @endforeach</select><x-input-error :messages="$errors->get('method')" class="mt-2" /></div>
                        <div><x-input-label for="pa" value="Amount" /><x-text-input wire:model="amount" id="pa" type="number" step="0.01" min="0" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('amount')" class="mt-2" /></div>
                        @if ($method !== 'Cash')
                            <div><x-input-label for="pr" value="Transaction code or cheque no." /><x-text-input wire:model="reference" id="pr" type="text" maxlength="50" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('reference')" class="mt-2" /></div>
                        @endif
                    </div>
                    <x-primary-button>Record payment</x-primary-button>
                </form>
            </x-card>
        @endif

        @if ((float) $d->amount_paid == 0)
            <div class="print:hidden">
                @if (! $voiding)
                    <button wire:click="$set('voiding', true)" class="text-sm text-red-600 hover:underline">Void this invoice</button>
                @else
                    <x-card class="p-6 space-y-4">
                        <x-input-label for="vr" value="Reason *" />
                        <x-text-input wire:model="reason" id="vr" type="text" maxlength="255" class="block w-full" />
                        <x-input-error :messages="$errors->get('reason')" />
                        <div class="flex gap-3">
                            <button wire:click="confirmVoid" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">Void invoice</button>
                            <x-secondary-button type="button" wire:click="$set('voiding', false)">Keep invoice</x-secondary-button>
                        </div>
                    </x-card>
                @endif
            </div>
        @endif
    @endif
</div>
