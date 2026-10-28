@props(['label', 'value', 'caption' => null])

<x-card class="p-5">
    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $label }}</p>
    <p class="mt-2 text-2xl font-bold text-gray-900">{{ $value }}</p>
    @if ($caption)
        <p class="mt-1 text-xs text-gray-500">{{ $caption }}</p>
    @endif
</x-card>
