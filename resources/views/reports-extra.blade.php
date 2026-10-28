@php
    $titles = [
        'stock-list' => 'Stock list', 'additions' => 'Stock additions', 'price-list' => 'Price list',
        'expenses' => 'Expense report', 'income' => 'Income statement',
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold text-gray-900">{{ $titles[$kind] }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
            <livewire:reports.extra :kind="$kind" />
        </div>
    </div>
</x-app-layout>
