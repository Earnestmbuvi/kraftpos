@props(['color' => 'gray'])

@php
    $colors = [
        'green' => 'bg-green-100 text-green-700',
        'red' => 'bg-red-100 text-red-700',
        'orange' => 'bg-brand-100 text-brand-700',
        'gray' => 'bg-gray-100 text-gray-600',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium ' . ($colors[$color] ?? $colors['gray'])]) }}>
    {{ $slot }}
</span>
