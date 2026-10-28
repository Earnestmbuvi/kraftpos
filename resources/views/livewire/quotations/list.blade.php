<?php

use App\Models\HeldCart;
use App\Models\Quotation;
use Illuminate\Support\Str;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $status = 'all';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('make-sales'), 403);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $user = auth()->user();

        return [
            'quotations' => Quotation::with(['customer', 'user'])->withCount('items')
                // Cashiers only see their own quotations
                ->when(! $user->can('view-reports'), fn ($q) => $q->where('user_id', $user->id))
                ->when($this->status === 'expired', fn ($q) => $q->where('status', 'open')->whereDate('valid_until', '<', today()))
                ->when($this->status === 'open', fn ($q) => $q->where('status', 'open')->whereDate('valid_until', '>=', today()))
                ->when(in_array($this->status, ['accepted', 'declined', 'converted']), fn ($q) => $q->where('status', $this->status))
                ->latest('id')->paginate(15),
        ];
    }

    private function find(int $id): Quotation
    {
        $q = Quotation::with('items')->findOrFail($id); // tenant scope applies
        abort_unless(auth()->user()->can('view-reports') || $q->user_id === auth()->id(), 403);

        return $q;
    }

    public function setStatus(int $id, string $status): void
    {
        abort_unless(auth()->user()->can('make-sales'), 403);

        $q = $this->find($id);

        if (! in_array($status, ['accepted', 'declined']) || $q->status !== 'open') {
            $this->addError('action', 'Only an open quotation can be accepted or declined.');
            return;
        }

        $q->status = $status;
        $q->save();
        $this->resetErrorBag();
    }

    // Puts the quoted items in a parked cart so the till can complete the sale at current prices
    public function convert(int $id): void
    {
        abort_unless(auth()->user()->can('make-sales'), 403);

        $q = $this->find($id);

        if (! in_array($q->status, ['open', 'accepted']) || $q->display_status === 'expired') {
            $this->addError('action', 'This quotation cannot be converted. It is expired, declined or already converted.');
            return;
        }

        $cart = [];
        foreach ($q->items as $item) {
            $key = $item->product_variant_id;
            if (isset($cart[$key])) {
                $cart[$key]['qty'] += $item->quantity;
                continue;
            }
            $cart[$key] = [
                'qty' => $item->quantity,
                'dtype' => $item->discount_type ?? '',
                'dvalue' => $item->discount_value > 0 ? (string) $item->discount_value : '',
            ];
        }

        HeldCart::create([
            'user_id' => auth()->id(),
            'label' => Str::limit($q->number_label . ' - ' . $q->customer->name, 60, ''),
            'cart' => $cart,
            'cart_discount_type' => $q->discount_type,
            'cart_discount_value' => $q->discount_value,
            'customer_id' => $q->customer_id,
        ]);

        $q->status = 'converted';
        $q->save();

        $this->redirect(route('sell', ['parked' => 1]), navigate: true);
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row gap-3 sm:items-center sm:justify-between">
        <select wire:model.live="status" class="sm:w-56 text-sm">
            <option value="all">All quotations</option>
            <option value="open">Open</option>
            <option value="accepted">Accepted</option>
            <option value="declined">Declined</option>
            <option value="converted">Converted to sale</option>
            <option value="expired">Expired</option>
        </select>

        <a href="{{ route('quotations.create') }}" wire:navigate
           class="inline-flex items-center justify-center px-4 py-2 rounded-lg bg-brand-500 text-sm font-semibold text-white hover:bg-brand-600">
            + New quotation
        </a>
    </div>

    <x-input-error :messages="$errors->get('action')" />

    <x-card class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Number</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Valid until</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">By</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse ($quotations as $q)
                    @php
                        $display = $q->display_status;
                        $color = ['open' => 'orange', 'accepted' => 'green', 'declined' => 'red', 'expired' => 'red', 'converted' => 'gray'][$display] ?? 'gray';
                    @endphp
                    <tr wire:key="quote-{{ $q->id }}">
                        <td class="px-4 py-3 font-medium">{{ $q->number_label }}</td>
                        <td class="px-4 py-3">{{ $q->customer?->name ?? '-' }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $q->created_at->format('d M Y') }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $q->valid_until->format('d M Y') }}</td>
                        <td class="px-4 py-3">{{ $q->user?->name ?? '-' }}</td>
                        <td class="px-4 py-3 text-right font-semibold">{{ number_format($q->total, 2) }}</td>
                        <td class="px-4 py-3"><x-badge :color="$color">{{ ucfirst($display) }}</x-badge></td>
                        <td class="px-4 py-3 text-right space-x-3 whitespace-nowrap">
                            <a href="{{ route('quotations.show', $q) }}" wire:navigate class="text-brand-600 hover:underline">View</a>
                            @if (in_array($q->status, ['open', 'accepted']))
                            <a href="{{ route('quotations.edit', $q) }}" wire:navigate class="text-gray-700 hover:underline">Edit</a>
                             @endif
                            @if ($q->status === 'open' && $display !== 'expired')
                                <button wire:click="setStatus({{ $q->id }}, 'accepted')" class="text-green-700 hover:underline">Accept</button>
                                <button wire:click="setStatus({{ $q->id }}, 'declined')" class="text-gray-600 hover:underline">Decline</button>
                            @endif
                            @if (in_array($q->status, ['open', 'accepted']) && $display !== 'expired')
                                <button wire:click="convert({{ $q->id }})" class="text-brand-600 hover:underline">Convert to sale</button>
                                @can('issue-invoices')
    <a href="{{ route('invoices.create', ['quotation' => $q->id]) }}" wire:navigate class="text-brand-600 hover:underline">Convert to invoice</a>
@endcan
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-6 text-center text-gray-500">No quotations yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-card>

    {{ $quotations->links() }}
</div>
