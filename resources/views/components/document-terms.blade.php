@props(['type', 'business'])
@php $terms = $type === 'invoice' ? $business->invoice_terms : $business->quotation_terms; @endphp

@if (filled($terms))
    <div class="mt-8 border-t border-gray-200 pt-4 text-xs text-gray-600">
        <p class="mb-1 font-semibold uppercase tracking-wide text-gray-500">Terms and conditions</p>
        <p class="whitespace-pre-line">{{ $terms }}</p>
    </div>
@endif
