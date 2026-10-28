@php
    $paidToDate = (float) $invoice->payments()->where('id', '<=', $payment->id)->sum('amount');
    $balanceAfter = max(round((float) $invoice->total - $paidToDate, 2), 0);
@endphp

<x-app-layout>
    <div class="py-8">
        <div class="max-w-md mx-auto px-4">
            <div class="mb-4 flex gap-3 print:hidden">
                <button onclick="window.print()" class="inline-flex items-center px-4 py-2 rounded-lg bg-brand-500 text-sm font-semibold text-white hover:bg-brand-600">Print receipt</button>
                <a href="{{ route('invoices.show', $invoice) }}" wire:navigate class="inline-flex items-center px-4 py-2 rounded-lg border border-brand-500 text-sm font-semibold text-brand-600 hover:bg-brand-50">Back to invoice</a>
            </div>

            <x-card class="p-6 text-sm print:border-0 print:p-0">
                <div class="text-center">
                    <p class="text-lg font-bold text-gray-900">{{ $invoice->business->name }}</p>
                    @if ($invoice->business->phone) <p class="text-gray-600">{{ $invoice->business->phone }}</p> @endif
                    <p class="text-gray-600">{{ collect([$invoice->business->address, $invoice->business->county])->filter()->implode(', ') }}</p>
                    @if (\App\Services\BranchService::multi() && $invoice->branch_id)
    <p class="text-gray-600">Branch: {{ \App\Models\Branch::find($invoice->branch_id)?->name }}</p>
@endif
                    <p class="mt-3 font-semibold text-brand-600">PAYMENT RECEIPT</p>
                </div>

                <div class="mt-4 space-y-1 border-t border-dashed border-gray-300 pt-3">
                    <div class="flex justify-between"><span>Receipt no.</span><span class="font-medium">{{ $payment->receipt_label }}</span></div>
                    <div class="flex justify-between"><span>Date</span><span>{{ $payment->created_at->format('d M Y, H:i') }}</span></div>
                    <div class="flex justify-between"><span>Customer</span><span>{{ $invoice->customer->name }}</span></div>
                    <div class="flex justify-between"><span>For invoice</span><span>{{ $invoice->number_label }}</span></div>
                    <div class="flex justify-between"><span>Received by</span><span>{{ $payment->user?->name ?? '-' }}</span></div>
                </div>

                <div class="mt-4 space-y-1 border-t border-dashed border-gray-300 pt-3">
                    <div class="flex justify-between text-base font-bold text-gray-900"><span>Amount received</span><span>Kshs {{ number_format($payment->amount, 2) }}</span></div>
                    <div class="flex justify-between"><span>Paid with</span><span>{{ $payment->method_name }}@if ($payment->reference) ({{ $payment->reference }})@endif</span></div>
                </div>

                <div class="mt-4 space-y-1 border-t border-dashed border-gray-300 pt-3 text-gray-700">
                    <div class="flex justify-between"><span>Invoice total</span><span>{{ number_format($invoice->total, 2) }}</span></div>
                    <div class="flex justify-between"><span>Paid to date</span><span>{{ number_format($paidToDate, 2) }}</span></div>
                    <div class="flex justify-between font-semibold"><span>Balance</span><span>{{ number_format($balanceAfter, 2) }}</span></div>
                </div>

                <p class="mt-5 text-center text-gray-500">Thank you for shopping with us.</p>
            </x-card>
        </div>
    </div>
</x-app-layout>
