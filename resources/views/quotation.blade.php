<x-app-layout>
    <div class="py-8">
        <div class="max-w-3xl mx-auto px-4">
            <div class="mb-4 flex gap-3 print:hidden">
                <button onclick="window.print()"
                        class="inline-flex items-center px-4 py-2 rounded-lg bg-brand-500 text-sm font-semibold text-white hover:bg-brand-600">
                    Print quotation
                </button>
                <a href="{{ route('quotations') }}" wire:navigate
                   class="inline-flex items-center px-4 py-2 rounded-lg border border-brand-500 text-sm font-semibold text-brand-600 hover:bg-brand-50">
                    All quotations
                </a>
            </div>

            <x-card class="p-8 text-sm print:border-0 print:p-0">
                <div class="flex justify-between gap-6">
                    <x-receipt-header :business="$quotation->business" :branch-id="$quotation->branch_id" class="text-left" />
                    <div class="text-right">
                        <p class="text-lg font-bold text-brand-600">QUOTATION</p>
                        <p class="font-semibold">{{ $quotation->number_label }}</p>
                        <p class="text-gray-600">Date: {{ $quotation->created_at->format('d M Y') }}</p>
                        <p class="text-gray-600">Valid until: {{ $quotation->valid_until->format('d M Y') }}</p>
                    </div>
                </div>

                <div class="my-6 rounded-lg bg-gray-50 p-4 print:bg-white print:border print:border-gray-200">
                    <p class="text-xs uppercase text-gray-500">Prepared for</p>
                    <p class="font-semibold text-gray-900">{{ $quotation->customer->name }}</p>
                    @if ($quotation->customer->phone) <p class="text-gray-600">{{ $quotation->customer->phone }}</p> @endif
                    @if ($quotation->customer->address) <p class="text-gray-600">{{ $quotation->customer->address }}</p> @endif
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
                        @foreach ($quotation->items as $item)
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
                    <div class="flex justify-between"><span>Subtotal</span><span>{{ number_format($quotation->subtotal, 2) }}</span></div>
                    @if ($quotation->discount_amount > 0)
                        <div class="flex justify-between"><span>Discount</span><span>-{{ number_format($quotation->discount_amount, 2) }}</span></div>
                    @endif
                    @if ($quotation->tax_amount > 0)
                        <div class="flex justify-between">
                            <span>Tax ({{ rtrim(rtrim(number_format($quotation->tax_rate, 2), '0'), '.') }}%{{ $quotation->tax_inclusive ? ', included' : '' }})</span>
                            <span>{{ number_format($quotation->tax_amount, 2) }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between text-base font-bold text-gray-900">
                        <span>Total</span><span>Kshs {{ number_format($quotation->total, 2) }}</span>
                    </div>
                </div>

                @if ($quotation->notes)
                    <p class="mt-6 text-gray-700"><span class="font-semibold">Notes:</span> {{ $quotation->notes }}</p>
                @endif

                <x-document-terms type="quotation" :business="$quotation->business" />

                <p class="mt-6 text-xs text-gray-500">
                    Prices are as at {{ $quotation->created_at->format('d M Y') }} and may change after the valid-until date.
                </p>
            </x-card>
        </div>
    </div>
</x-app-layout>
