@props(['title', 'close' => 'closeForm', 'width' => 'max-w-2xl'])

<div class="fixed inset-0 z-50 flex justify-end">
    <div class="absolute inset-0 bg-black/40" wire:click="{{ $close }}"></div>

    <div class="relative flex h-full w-full {{ $width }} flex-col overflow-y-auto bg-gray-50 shadow-xl">
        <div class="sticky top-0 z-10 flex items-center justify-between border-b border-gray-200 bg-white px-6 py-4">
            <h3 class="text-lg font-semibold text-gray-900">{{ $title }}</h3>
            <button type="button" wire:click="{{ $close }}" class="text-2xl leading-none text-gray-500 hover:text-gray-800" aria-label="Close">&times;</button>
        </div>

        {{ $slot }}
    </div>
</div>
