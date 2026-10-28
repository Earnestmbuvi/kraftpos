<?php

use App\Models\PlatformDocument;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $type = 'quotation';

    public function mount(string $type = 'quotation'): void
    {
        abort_unless(auth()->user()?->is_super_admin, 403);
        $this->type = $type === 'invoice' ? 'invoice' : 'quotation';
    }

    public function with(): array
    {
        abort_unless(auth()->user()?->is_super_admin, 403);

        return ['docs' => PlatformDocument::with('business')->where('type', $this->type)->latest('id')->paginate(15)];
    }
}; ?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <p class="text-sm text-gray-600">{{ $type === 'invoice' ? 'Invoices' : 'Quotations' }} you have sent to merchants.</p>
        <a href="{{ route('admin.document.create', $type) }}" wire:navigate class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600">+ New {{ $type }}</a>
    </div>

    <x-card class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50"><tr>
                @foreach (['Number', 'Merchant', 'Date', 'Total', $type === 'invoice' ? 'Balance' : 'Valid until', 'Status', ''] as $h)
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ $h }}</th>
                @endforeach
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($docs as $d)
                    @php $st = $d->display_status; @endphp
                    <tr wire:key="pd-{{ $d->id }}">
                        <td class="px-4 py-2 font-medium">{{ $d->number_label }}</td>
                        <td class="px-4 py-2">{{ $d->business?->name }}</td>
                        <td class="px-4 py-2">{{ $d->issue_date->format('d M Y') }}</td>
                        <td class="px-4 py-2">{{ number_format($d->total, 2) }}</td>
                        <td class="px-4 py-2">{{ $type === 'invoice' ? number_format($d->balance, 2) : $d->due_date?->format('d M Y') }}</td>
                        <td class="px-4 py-2"><x-badge :color="in_array($st, ['paid', 'converted']) ? 'green' : (in_array($st, ['overdue', 'voided', 'declined', 'expired']) ? 'red' : 'orange')">{{ ucfirst(str_replace('_', ' ', $st)) }}</x-badge></td>
                        <td class="px-4 py-2 text-right"><a href="{{ route('admin.document', $d->id) }}" wire:navigate class="text-brand-600 hover:underline">Open</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-6 text-center text-gray-500">None yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-card>

    {{ $docs->links() }}
</div>
