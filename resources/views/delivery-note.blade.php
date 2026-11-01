@php
    $isInvoice = $kind === 'invoice';
    $ref = $isInvoice ? $doc->number_label : $doc->receipt_label;
    $biz = $doc->business;
    $cust = $doc->customer;
    $init = ['address' => (string) $cust?->address, 'driver' => '', 'vehicle' => '', 'note' => ''];
    $backUrl = $isInvoice ? route('invoices.show', $doc) : route('sales.receipt', $doc);
@endphp

<x-app-layout>
    <x-print-styles paper="a4" />

    <div class="py-8" x-data="{ f: {{ \Illuminate\Support\Js::from($init) }} }">
        <div class="max-w-3xl mx-auto px-4">
            <div class="mb-4 flex flex-wrap gap-3 print:hidden">
                <button type="button" onclick="window.print()"
                        class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600">Print delivery note</button>
                {{-- Opened in a new tab, so Close ends the tab. If it was opened directly, go back instead. --}}
                <button type="button"
                        onclick="window.close(); setTimeout(() => { location.href = '{{ $backUrl }}' }, 200)"
                        class="rounded-lg border border-brand-500 px-4 py-2 text-sm font-semibold text-brand-600 hover:bg-brand-50">Close</button>
            </div>

            <x-card class="mb-4 grid gap-3 p-4 text-sm sm:grid-cols-2 print:hidden">
                <div class="sm:col-span-2"><label class="text-xs font-medium text-gray-600">Delivery address</label>
                    <input x-model="f.address" type="text" class="mt-1 block w-full rounded-lg border-gray-300 text-sm"></div>
                <div><label class="text-xs font-medium text-gray-600">Driver / delivered by</label>
                    <input x-model="f.driver" type="text" class="mt-1 block w-full rounded-lg border-gray-300 text-sm"></div>
                <div><label class="text-xs font-medium text-gray-600">Vehicle</label>
                    <input x-model="f.vehicle" type="text" class="mt-1 block w-full rounded-lg border-gray-300 text-sm"></div>
                <div class="sm:col-span-2"><label class="text-xs font-medium text-gray-600">Note</label>
                    <input x-model="f.note" type="text" class="mt-1 block w-full rounded-lg border-gray-300 text-sm"></div>
                <p class="text-xs text-gray-500 sm:col-span-2">These details print on the note but are not saved.</p>
            </x-card>

            <x-card class="p-8 text-sm print:border-0 print:p-0">
                <div class="flex justify-between gap-6">
                    <x-receipt-header :business="$biz" :branch-id="$doc->branch_id" class="text-left" />
                    <div class="text-right">
                        <p class="text-lg font-bold text-brand-600">DELIVERY NOTE</p>
                        <p class="font-semibold">For {{ $isInvoice ? 'invoice' : 'receipt' }} {{ $ref }}</p>
                        <p class="text-gray-600">Date: {{ now()->format('d M Y') }}</p>
                    </div>
                </div>

                <div class="my-6 grid gap-4 sm:grid-cols-2">
                    <div class="rounded-lg bg-gray-50 p-4 print:border print:border-gray-300 print:bg-white">
                        <p class="text-xs uppercase text-gray-500">Deliver to</p>
                        <p class="font-semibold text-gray-900">{{ $cust?->name ?? 'Walk-in customer' }}</p>
                        @if ($cust?->phone) <p class="text-gray-600">{{ $cust->phone }}</p> @endif
                        <p class="whitespace-pre-line text-gray-600" x-show="f.address" x-text="f.address"></p>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-4 print:border print:border-gray-300 print:bg-white" x-show="f.driver || f.vehicle">
                        <p class="text-xs uppercase text-gray-500">Delivery</p>
                        <p x-show="f.driver">Delivered by: <span x-text="f.driver"></span></p>
                        <p x-show="f.vehicle">Vehicle: <span x-text="f.vehicle"></span></p>
                    </div>
                </div>

                <table class="min-w-full divide-y divide-gray-300">
                    <thead><tr>
                        <th class="py-2 text-left text-xs font-medium uppercase text-gray-500">#</th>
                        <th class="py-2 text-left text-xs font-medium uppercase text-gray-500">Item</th>
                        <th class="py-2 text-right text-xs font-medium uppercase text-gray-500">Quantity</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($doc->items as $i => $item)
                            <tr>
                                <td class="py-2 text-gray-500">{{ $i + 1 }}</td>
                                <td class="py-2">{{ $item->product_name }}@if ($item->variant_name) - {{ $item->variant_name }}@endif</td>
                                <td class="py-2 text-right font-semibold">{{ $item->quantity }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot><tr class="border-t-2 border-gray-300">
                        <td colspan="2" class="py-2 font-semibold">Total items</td>
                        <td class="py-2 text-right font-bold">{{ $doc->items->sum('quantity') }}</td>
                    </tr></tfoot>
                </table>

                <p class="mt-4 text-gray-700" x-show="f.note"><span class="font-semibold">Note:</span> <span x-text="f.note"></span></p>

                <p class="mt-8 text-xs text-gray-500">Please check the goods on delivery and report any shortage or damage to the driver before signing.</p>

                <div class="mt-12 grid gap-10 sm:grid-cols-2">
                    <div><div class="border-b border-gray-500"></div><p class="mt-1 text-xs text-gray-600">Delivered by (name, signature, date)</p></div>
                    <div><div class="border-b border-gray-500"></div><p class="mt-1 text-xs text-gray-600">Received by (name, signature, date)</p></div>
                </div>
            </x-card>
        </div>
    </div>
</x-app-layout>
