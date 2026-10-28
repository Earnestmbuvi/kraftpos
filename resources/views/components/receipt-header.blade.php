@props(['business', 'branchId' => null])
@php
    $s = \App\Support\ReceiptSettings::for($business);
    $branch = $branchId ? \App\Models\Branch::find($branchId) : null;
    $multi = \App\Services\BranchService::multi();
@endphp

<div {{ $attributes->merge(['class' => 'text-center']) }}>
    <p class="text-lg font-bold text-gray-900">{{ $business->name }}</p>
    @if ($s['header_text']) <p class="text-sm text-gray-600">{{ $s['header_text'] }}</p> @endif
    @if ($s['show_phone'] && $business->phone) <p class="text-sm text-gray-600">{{ $business->phone }}</p> @endif
    @if ($s['show_email'] && $business->email) <p class="text-sm text-gray-600">{{ $business->email }}</p> @endif
    @if ($s['show_address'])
        <p class="text-sm text-gray-600">{{ collect([$business->address, $business->county])->filter()->implode(', ') }}</p>
    @endif
    @if ($s['show_branch'] && $multi && $branch) <p class="text-sm text-gray-600">Branch: {{ $branch->name }}</p> @endif
    @if ($s['show_till'] && $branch?->till_number) <p class="text-sm text-gray-600">{{ $branch->till_number }}</p> @endif
</div>
