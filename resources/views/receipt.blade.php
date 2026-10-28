@php
    $s = \App\Support\ReceiptSettings::for($sale->business);
    $width = $s['paper'] === 'a4' ? 'max-w-3xl' : 'max-w-sm';
@endphp

<x-app-layout>
    <div class="py-8">
        <div class="{{ $width }} mx-auto px-4">
            <div class="mb-4 flex gap-3 print:hidden">
                <button onclick="window.print()" class="inline-flex items-center rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600">Print receipt</button>
                <a href="{{ route('sell') }}" wire:navigate class="inline-flex items-center rounded-lg border border-brand-500 px-4 py-2 text-sm font-semibold text-brand-600 hover:bg-brand-50">New sale</a>
            </div>

            <x-card class="p-6 text-sm print:border-0 print:p-0">
                @if ($sale->status === 'voided')
                    <p class="mb-3 rounded-lg bg-red-100 px-3 py-2 text-center font-semibold text-red-700">
                        VOIDED{{ $sale->void_reason ? ': ' . $sale->void_reason : '' }}
                    </p>
                @endif

                <x-receipt-header :business="$sale->business" :branch-id="$sale->branch_id" />

                <div class="mt-4 space-y-1 border-t border-dashed border-gray-300 pt-3">
                    <div class="flex justify-between"><span>Receipt no.</span><span class="font-medium">{{ $sale->receipt_label }}</span></div>
                    <div class="flex justify-between"><span>Date</span><span>{{ $sale->created_at->format('d M Y, H:i') }}</span></div>
                    @if ($s['show_cashier'] && $sale->cashier)
                        <div class="flex justify-between"><span>Served by</span><span>{{ $sale->cashier->name }}</span></div>
                    @endif
                    @if ($s['show_customer'] && $sale->customer)
                        <div class="flex justify-between"><span>Customer</span><span>{{ $sale->customer->name }}</span></div>
                    @endif
                </div>

                <div class="mt-3 space-y-2 border-t border-dashed border-gray-300 pt-3">
                    @foreach ($sale->items as $item)
                        <div>
                            <div class="flex justify-between gap-2">
                                <span>{{ $item->product_name }}@if ($item->variant_name) - {{ $item->variant_name }}@endif</span>
                                <span class="whitespace-nowrap">{{ number_format($item->line_total, 2) }}</span>
                            </div>
                            <div class="text-xs text-gray-500">
                                {{ $item->quantity }} x {{ number_format($item->unit_price, 2) }}
                                @if ($s['show_discounts'] && $item->discount_amount > 0) (discount -{{ number_format($item->discount_amount, 2) }}) @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-3 space-y-1 border-t border-dashed border-gray-300 pt-3">
                    <div class="flex justify-between"><span>Subtotal</span><span>{{ number_format($sale->subtotal, 2) }}</span></div>
                    @if ($s['show_discounts'] && $sale->discount_amount > 0)
                        <div class="flex justify-between"><span>Discount</span><span>-{{ number_format($sale->discount_amount, 2) }}</span></div>
                    @endif
                    @if ($s['show_tax'] && $sale->tax_amount > 0)
                        <div class="flex justify-between">
                            <span>Tax ({{ rtrim(rtrim(number_format($sale->tax_rate, 2), '0'), '.') }}%{{ $sale->tax_inclusive ? ', included' : '' }})</span>
                            <span>{{ number_format($sale->tax_amount, 2) }}</span>
                        </div>
                    @endif
                    @if ($sale->credit_applied > 0)
                        <div class="flex justify-between"><span>Exchange credit</span><span>-{{ number_format($sale->credit_applied, 2) }}</span></div>
                    @endif
                    <div class="flex justify-between text-base font-bold text-gray-900"><span>Total</span><span>Kshs {{ number_format($sale->total, 2) }}</span></div>
                </div>

                @if ($s['show_payments'])
                    <div class="mt-3 space-y-1 border-t border-dashed border-gray-300 pt-3">
                        @foreach ($sale->payments as $p)
                            <div class="flex justify-between">
                                <span>{{ $p->method_name }}@if ($p->reference) ({{ $p->reference }})@endif</span>
                                <span>{{ number_format($p->amount, 2) }}</span>
                            </div>
                        @endforeach
                        @if ($sale->change_given > 0)
                            <div class="flex justify-between font-semibold"><span>Change</span><span>{{ number_format($sale->change_given, 2) }}</span></div>
                        @endif
                    </div>
                @endif

                <div class="mt-5 text-center text-xs text-gray-500">
                    @if ($s['footer_text']) <p class="whitespace-pre-line">{{ $s['footer_text'] }}</p> @endif
                    @if ($s['show_powered']) <p class="mt-1">Powered by KraftPOS</p> @endif
                </div>
            </x-card>
        </div>
    </div>
</x-app-layout>
