@php
    $s = \App\Support\ReceiptSettings::for($sale->business);
    $biz = $sale->business;
    $branch = $sale->branch_id ? \App\Models\Branch::find($sale->branch_id) : null;
    $paid = (float) $sale->payments->sum('amount');
    $taxable = ($sale->tax_amount > 0 && $sale->tax_rate > 0) ? round($sale->tax_amount / ($sale->tax_rate / 100), 2) : null;
    $thermal = $s['paper'] !== 'a4';
@endphp

<x-app-layout>
    <x-print-styles :paper="$thermal ? 'thermal' : 'a4'" />


    <div class="py-8">
        <div class="mx-auto px-4 print:max-w-none print:px-0 {{ $thermal ? 'max-w-sm' : 'max-w-3xl' }}">
            <div class="mb-4 flex flex-wrap gap-3 print:hidden">
                <button onclick="window.print()" class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600">Print receipt</button>
                @if ($sale->status !== 'voided')
                    <a href="{{ route('sales.delivery-note', $sale) }}" target="_blank" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Delivery note</a>
                @endif
                <a href="{{ route('sell') }}" wire:navigate class="rounded-lg border border-brand-500 px-4 py-2 text-sm font-semibold text-brand-600 hover:bg-brand-50">New sale</a>
            </div>

            <x-card class="p-6 font-mono text-[13px] text-gray-900 print:border-0 print:p-0">
                @if ($sale->status === 'voided')
                    <p class="mb-3 border border-red-300 px-2 py-1 text-center font-bold text-red-700">VOIDED{{ $sale->void_reason ? ': ' . $sale->void_reason : '' }}</p>
                @endif

                <div class="text-center">
                    <x-business-logo :business="$biz" />
                    <p class="text-base font-bold">{{ $biz->name }}</p>
                    @if ($s['show_address']) <p>{{ collect([$biz->address, $biz->county])->filter()->implode(', ') }}</p> @endif
                    @if ($s['header_text']) <p>{{ $s['header_text'] }}</p> @endif
                    <p class="font-bold">RECEIPT</p>
                    @if ($s['show_branch'] && \App\Services\BranchService::multi() && $branch) <p>Branch: {{ $branch->name }}</p> @endif
                    @if ($s['show_email'] && $biz->email) <p>{{ $biz->email }}</p> @endif
                    @if ($s['show_phone'] && $biz->phone) <p>{{ $biz->phone }}</p> @endif
                </div>

                <div class="my-3 border-t border-dashed border-gray-500"></div>
                <div class="space-y-1">
                    <div class="flex justify-between"><span>Date</span><span>{{ $sale->created_at->format('d/m/Y H:i:s') }}</span></div>
                    <div class="flex justify-between"><span>Order No.</span><span class="font-bold">{{ $sale->receipt_label }}</span></div>
                    @if ($s['show_customer'])
                        <div class="flex justify-between"><span>Customer</span><span>{{ $sale->customer?->name ?? 'Walk-in Customer' }}</span></div>
                    @endif
                </div>

                <div class="my-3 border-t border-dashed border-gray-500"></div>
                <p class="mb-2 font-bold">ITEMS</p>
                <div class="space-y-2">
                    @foreach ($sale->items as $item)
                        <div>
                            <p>{{ $item->product_name }}@if ($item->variant_name) - {{ $item->variant_name }}@endif</p>
                            <div class="flex justify-between">
                                <span>{{ $item->quantity }} &times; {{ number_format($item->unit_price, 2) }}</span>
                                <span>{{ number_format($item->line_total, 2) }}</span>
                            </div>
                            @if ($s['show_discounts'] && $item->discount_amount > 0)
                                <p class="text-xs">Discount -{{ number_format($item->discount_amount, 2) }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>

                <div class="my-3 border-t border-dashed border-gray-500"></div>
                <p class="mb-2 font-bold">SUMMARY</p>
                <div class="space-y-1">
                    <div class="flex justify-between"><span>Subtotal</span><span>{{ number_format($sale->subtotal, 2) }}</span></div>
                    @if ($s['show_discounts'] && $sale->discount_amount > 0)
                        <div class="flex justify-between"><span>Discount</span><span>-{{ number_format($sale->discount_amount, 2) }}</span></div>
                    @endif
                    @if ($sale->credit_applied > 0)
                        <div class="flex justify-between"><span>Exchange credit</span><span>-{{ number_format($sale->credit_applied, 2) }}</span></div>
                    @endif
                    @if ($s['show_tax'])
                        @if ($taxable !== null) <div class="flex justify-between"><span>Taxable amount</span><span>{{ number_format($taxable, 2) }}</span></div> @endif
                        <div class="flex justify-between"><span>Tax{{ $sale->tax_rate > 0 ? ' (' . rtrim(rtrim(number_format($sale->tax_rate, 2), '0'), '.') . '%' . ($sale->tax_inclusive ? ', incl.' : '') . ')' : '' }}</span><span>{{ number_format($sale->tax_amount, 2) }}</span></div>
                    @endif
                    @if ($sale->delivery_charge > 0)
                        <div class="flex justify-between"><span>{{ $sale->delivery_label ?: 'Delivery' }}</span><span>{{ number_format($sale->delivery_charge, 2) }}</span></div>
                    @endif
                </div>

                <div class="my-3 border-t-2 border-gray-900"></div>
                <div class="flex justify-between text-base font-bold"><span>TOTAL:</span><span>Kshs {{ number_format($sale->total, 2) }}</span></div>
                <div class="my-3 border-t border-dashed border-gray-500"></div>

                @if ($s['show_payments'])
                    <p class="mb-2 font-bold">PAYMENT</p>
                    <div class="space-y-1">
                        <div class="flex justify-between"><span>PAID:</span><span>Kshs {{ number_format($paid, 2) }}</span></div>
                        <div class="flex justify-between"><span>CHANGE:</span><span>Kshs {{ number_format($sale->change_given, 2) }}</span></div>
                    </div>
                    <div class="my-3 border-t-2 border-gray-900"></div>
                    <div class="space-y-1">
                        <div class="flex justify-between gap-3"><span>Payment Method:</span>
                            <span class="text-right">{{ $sale->payments->map(fn ($p) => $p->method_name . ($p->reference ? ' (' . $p->reference . ')' : ''))->implode(', ') }}</span></div>
                        <div class="flex items-center justify-between"><span>Status:</span>
                            <span class="rounded-full border-2 border-gray-900 px-3 py-0.5 text-xs font-bold">{{ $sale->status === 'voided' ? 'VOIDED' : 'COMPLETE' }}</span></div>
                    </div>
                    <div class="my-3 border-t-2 border-gray-900"></div>
                @endif

                <div class="space-y-1 text-center text-xs">
                    @if ($s['show_cashier'] && $sale->cashier) <p class="font-bold">Posted By: {{ $sale->cashier->name }}</p> @endif
                    @if ($s['show_till'] && $branch?->till_number) <p class="text-sm font-bold">Till: {{ $branch->till_number }}</p> @endif
                    @if ($s['show_pay_info'] && $s['pay_info']) <p class="font-bold">{{ $s['pay_info'] }}</p> @endif
                    @if ($s['footer_text']) <p class="whitespace-pre-line pt-1">{{ $s['footer_text'] }}</p> @endif
                    @if ($s['show_powered']) <p class="pt-1 font-bold">Powered by KraftPOS</p> @endif
                </div>
            </x-card>
        </div>
    </div>
</x-app-layout>
