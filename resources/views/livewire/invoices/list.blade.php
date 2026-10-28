<?php

use App\Models\Invoice;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $status = 'all';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('issue-invoices'), 403);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $open = fn ($q) => $q->where('status', 'issued')->whereColumn('amount_paid', '<', 'total');
        $notOverdue = fn ($q) => $q->where(fn ($d) => $d->whereNull('due_date')->orWhereDate('due_date', '>=', today()));

        return [
            'invoices' => Invoice::with('customer')
                ->when($this->status === 'overdue', fn ($q) => $open($q)->whereDate('due_date', '<', today()))
                ->when($this->status === 'unpaid', fn ($q) => $notOverdue($open($q))->where('amount_paid', 0))
                ->when($this->status === 'partly_paid', fn ($q) => $notOverdue($open($q))->where('amount_paid', '>', 0))
                ->when($this->status === 'paid', fn ($q) => $q->where('status', 'issued')->whereColumn('amount_paid', '>=', 'total')->where('written_off', 0))
                ->when($this->status === 'written_off', fn ($q) => $q->where('status', 'issued')->where('written_off', '>', 0))
                ->latest('id')->paginate(15),
            'outstanding' => (float) Invoice::where('status', 'issued')->sum(\DB::raw('total - amount_paid')),
            'overdueCount' => $open(Invoice::query())->whereDate('due_date', '<', today())->count(),
        ];
    }
}; ?>

<div class="space-y-6">
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <x-stat-card label="Outstanding" :value="'Kshs ' . number_format($outstanding, 2)" caption="Owed on issued invoices" />
        <x-stat-card label="Overdue" :value="$overdueCount" caption="Past their due date" />
    </div>

    <div class="flex flex-col sm:flex-row gap-3 sm:items-center sm:justify-between">
        <select wire:model.live="status" class="sm:w-56 text-sm">
            <option value="all">All invoices</option>
            <option value="unpaid">Unpaid</option>
            <option value="partly_paid">Partly paid</option>
            <option value="overdue">Overdue</option>
            <option value="paid">Paid</option>
            <option value="written_off">Written off</option>
            <option value="voided">Voided</option>
        </select>

        <a href="{{ route('invoices.create') }}" wire:navigate
           class="inline-flex items-center justify-center px-4 py-2 rounded-lg bg-brand-500 text-sm font-semibold text-white hover:bg-brand-600">
            + New invoice
        </a>
    </div>

    <x-card class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Number</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Due</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Balance</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse ($invoices as $inv)
                    @php
                        $ps = $inv->payment_status;
                        $color = ['paid' => 'green', 'partly_paid' => 'orange', 'unpaid' => 'gray', 'overdue' => 'red', 'voided' => 'red'][$ps] ?? 'gray';
                    @endphp
                    <tr wire:key="inv-{{ $inv->id }}" class="{{ $ps === 'voided' ? 'opacity-60' : '' }}">
                        <td class="px-4 py-3 font-medium">{{ $inv->number_label }}</td>
                        <td class="px-4 py-3">{{ $inv->customer?->name ?? '-' }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $inv->created_at->format('d M Y') }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $inv->due_date?->format('d M Y') ?? '-' }}</td>
                        <td class="px-4 py-3 text-right">{{ number_format($inv->total, 2) }}</td>
                        <td class="px-4 py-3 text-right font-semibold">{{ $ps === 'voided' ? '-' : number_format($inv->balance, 2) }}</td>
                        <td class="px-4 py-3">
                            <x-badge :color="$color">{{ ucfirst(str_replace('_', ' ', $ps)) }}</x-badge>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('invoices.show', $inv) }}" wire:navigate class="text-brand-600 hover:underline">
                                {{ $ps === 'voided' || $ps === 'paid' ? 'View' : 'View / pay' }}
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-6 text-center text-gray-500">No invoices yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-card>

    {{ $invoices->links() }}
</div>
