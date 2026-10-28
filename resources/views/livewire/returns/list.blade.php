<?php

use App\Models\SaleReturn;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('process-returns'), 403);
    }

    public function with(): array
    {
        return ['returns' => SaleReturn::with(['sale', 'user'])->latest('id')->paginate(15)];
    }
}; ?>

<div class="space-y-6">
    <div class="flex gap-3 sm:justify-end">
        <a href="{{ route('exchanges.create') }}" wire:navigate class="inline-flex items-center rounded-lg border border-brand-500 px-4 py-2 text-sm font-semibold text-brand-600 hover:bg-brand-50">+ Exchange</a>
        <a href="{{ route('returns.create') }}" wire:navigate class="inline-flex items-center rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600">+ Return</a>
    </div>

    <x-card class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50"><tr>
                @foreach (['Number', 'Date', 'Type', 'Sale', 'Value', 'Refunded', 'By', ''] as $h)
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ $h }}</th>
                @endforeach
            </tr></thead>
            <tbody class="divide-y divide-gray-200">
                @forelse ($returns as $r)
                    <tr wire:key="ret-{{ $r->id }}">
                        <td class="px-4 py-3 font-medium">{{ $r->number_label }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $r->created_at->format('d M Y, H:i') }}</td>
                        <td class="px-4 py-3"><x-badge :color="$r->type === 'exchange' ? 'orange' : 'gray'">{{ ucfirst($r->type) }}</x-badge></td>
                        <td class="px-4 py-3">{{ $r->sale?->receipt_label }}</td>
                        <td class="px-4 py-3">{{ number_format($r->value, 2) }}</td>
                        <td class="px-4 py-3">{{ $r->refund_total > 0 ? number_format($r->refund_total, 2) : '-' }}</td>
                        <td class="px-4 py-3">{{ $r->user?->name }}</td>
                        <td class="px-4 py-3 text-right"><a href="{{ route('returns.show', $r) }}" class="text-brand-600 hover:underline">View</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-6 text-center text-gray-500">No returns or exchanges yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-card>
    {{ $returns->links() }}
</div>
