<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold text-gray-900">New quotation</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <livewire:quotations.form />
        </div>
    </div>
</x-app-layout>
