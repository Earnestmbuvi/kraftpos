<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold text-gray-900">Setup: {{ $title }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            @if ($tab === 'categories')
                <livewire:categories.manage />
            @elseif ($tab === 'units')
                <livewire:units.manage />
            @elseif ($tab === 'options')
                <livewire:options.manage />
            @elseif ($tab === 'expense-categories')
                <livewire:expense-categories.manage />
            @elseif ($tab === 'payments')
                <livewire:payments.manage />
            @elseif ($tab === 'tax')
                <livewire:settings.tax />
            @elseif ($tab === 'branches')
                <livewire:branches.manage />
            @elseif ($tab === 'receipts')
                <livewire:settings.receipts />
            @elseif ($tab === 'roles')
                <livewire:settings.roles />
            @endif
        </div>
    </div>
</x-app-layout>
