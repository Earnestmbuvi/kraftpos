{{-- return-note.blade.php --}}
<x-app-layout>
    <div class="py-8"><div class="max-w-md mx-auto px-4">
        <div class="mb-4 flex gap-3 print:hidden">
            <button onclick="window.print()" class="inline-flex items-center px-4 py-2 rounded-lg bg-brand-500 text-sm font-semibold text-white hover:bg-brand-600">Print</button>
            <a href="{{ route('returns') }}" wire:navigate class="inline-flex items-center px-4 py-2 rounded-lg border border-brand-500 text-sm font-semibold text-brand-600">All returns</a>
        </div>
        <x-card class="p-6 text-sm print:border-0 print:p-0">
            <div class="text-center">
                <p class="text-lg font-bold text-gray-900">{{ auth()->user()->business->name }}</p>
                <p class="mt-2 font-semibold text-brand-600">{{ strtoupper($ret->type) }} NOTE</p>
            </div>
            <div class="mt-4 space-y-1 border-t border-dashed border-gray-300 pt-3">
                <div class="flex justify-between"><span>Number</span><span class="font-medium">{{ $ret->number_label }}</span></div>
                <div class="flex justify-between"><span>Date</span><span>{{ $ret->created_at->format('d M Y, H:i') }}</span></div>
                <div class="flex justify-between"><span>Original sale</span><span>{{ $ret->sale?->receipt_label }}</span></div>
                @if ($ret->exchangeSale) <div class="flex justify-between"><span>New sale</span><span>{{ $ret->exchangeSale->receipt_label }}</span></div> @endif
                <div class="flex justify-between"><span>Handled by</span><span>{{ $ret->user?->name }}</span></div>
                <div class="flex justify-between"><span>Reason</span><span>{{ $ret->reason }}</span></div>
            </div>
            <div class="mt-4 space-y-1 border-t border-dashed border-gray-300 pt-3">
                @foreach ($ret->items as $i)
                    <div class="flex justify-between"><span>{{ $i->quantity }} x {{ $i->product_name }}@if ($i->variant_name) - {{ $i->variant_name }}@endif</span><span>{{ number_format($i->amount, 2) }}</span></div>
                @endforeach
                <div class="flex justify-between border-t border-gray-200 pt-1 font-semibold"><span>Value returned</span><span>Kshs {{ number_format($ret->value, 2) }}</span></div>
                @if ($ret->refund_total > 0)
                    <div class="flex justify-between text-base font-bold"><span>Paid back ({{ $ret->refund_method_name }}@if ($ret->reference), {{ $ret->reference }}@endif)</span><span>Kshs {{ number_format($ret->refund_total, 2) }}</span></div>
                @endif
            </div>
        </x-card>
    </div></div>
</x-app-layout>
