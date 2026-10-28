<?php

use App\Models\Branch;
use App\Models\ProductVariant;
use App\Models\StockTransfer;
use App\Services\BranchService;
use App\Services\TransferService;
use Livewire\Volt\Component;

new class extends Component
{
    public $toId = '';
    public string $note = '';
    public string $search = '';
    public array $lines = []; // [variantId => qty]

    public function mount(): void
    {
        abort_unless(auth()->user()->can('transfer-stock'), 403);
    }

    public function with(): array
    {
        $term = trim($this->search);
        $current = BranchService::currentId();

        return [
            'here' => Branch::find($current),
            'others' => Branch::active()->where('id', '!=', $current)->orderBy('name')->get(),

            'results' => $term === '' ? collect() : ProductVariant::with('product')->active()
             ->whereHas('product', fn ($p) => $p->active()
                 ->where('is_service', false)
                 ->where('tracks_batches', false)
                 ->forBranch(BranchService::currentId())
                     )

                ->where(fn ($q) => $q->where('sku', 'like', "%{$term}%")->orWhere('barcode', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%")
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$term}%")))
                ->orderBy('product_id')->limit(8)->get(),
            'variants' => ProductVariant::with('product')->whereIn('id', array_keys($this->lines))->get()->keyBy('id'),
            'recent' => StockTransfer::with(['from', 'to', 'user', 'items'])->latest('id')->limit(10)->get(),
        ];
    }

    public function addLine(int $id): void
    {
        if (ProductVariant::active()->find($id)) {
            $this->lines[$id] = (int) ($this->lines[$id] ?? 0) + 1;
        }
        $this->search = '';
    }

    public function removeLine(int $id): void
    {
        unset($this->lines[$id]);
    }

    public function save(): void
    {
        $payload = [];
        foreach ($this->lines as $id => $qty) {
            $payload[] = ['variant_id' => (int) $id, 'quantity' => (int) $qty];
        }

        $t = TransferService::create((int) $this->toId, $payload, $this->note);

        $this->reset('toId', 'note', 'lines');
        $this->dispatch('notify', message: "Transfer {$t->number_label} done.");
    }
}; ?>

<div class="space-y-6">
    <x-card class="p-6">
        <form wire:submit="save" class="space-y-4">
            <h3 class="text-lg font-medium text-gray-900">Transfer stock</h3>
            @if ($others->isEmpty())
                <p class="text-sm text-gray-500">You need at least two active branches. Add one under Setup, Branches.</p>
            @else
                <p class="text-sm text-gray-600">
                    From: <strong>{{ $here?->name }}</strong> (the branch you are working in). To switch, use the branch selector at the top.
                </p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="to" value="To branch *" />
                        <select wire:model="toId" id="to" class="mt-1 block w-full">
                            <option value="">Choose...</option>
                            @foreach ($others as $b) <option value="{{ $b->id }}">{{ $b->name }}</option> @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('to')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="note" value="Note" />
                        <x-text-input wire:model="note" id="note" type="text" maxlength="255" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('note')" class="mt-2" />
                    </div>
                </div>

                <div class="relative">
                    <x-text-input wire:model.live.debounce.300ms="search" type="search" placeholder="Search an item to move..." class="block w-full" />
                    @if ($results->count())
                        <div class="absolute z-20 mt-1 w-full rounded-lg border border-gray-200 bg-white shadow-lg">
                            @foreach ($results as $r)
                                <button type="button" wire:key="tr-{{ $r->id }}" wire:click="addLine({{ $r->id }})"
                                        class="flex w-full justify-between px-4 py-2 text-left text-sm hover:bg-gray-50">
                                    <span>{{ $r->product->name }}@if ($r->name !== 'Default') - {{ $r->name }}@endif</span>
                                    <span class="text-gray-500">{{ $r->branch_stock }} here</span>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>

                <x-input-error :messages="$errors->get('cart')" />

                @if (count($lines))
                    <table class="min-w-full text-sm">
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($lines as $id => $qty)
                                @php $v = $variants->get($id); @endphp
                                @continue(! $v)
                                <tr wire:key="tl-{{ $id }}">
                                    <td class="py-2">{{ $v->product->name }}@if ($v->name !== 'Default') - {{ $v->name }}@endif
                                        <span class="text-xs text-gray-500">({{ $v->branch_stock }} here)</span></td>
                                    <td class="py-2"><x-text-input wire:model="lines.{{ $id }}" type="number" min="1" class="w-24" /></td>
                                    <td class="py-2 text-right"><button type="button" wire:click="removeLine({{ $id }})" class="text-gray-500 hover:underline">Remove</button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                <x-primary-button>Transfer stock</x-primary-button>
            @endif
        </form>
    </x-card>

    <x-card class="overflow-x-auto">
        <div class="border-b border-gray-200 px-4 py-3 font-medium text-gray-900">Recent transfers</div>
        <table class="min-w-full text-sm">
            <tbody class="divide-y divide-gray-100">
                @forelse ($recent as $t)
                    <tr wire:key="rt-{{ $t->id }}">
                        <td class="px-4 py-2 font-medium">{{ $t->number_label }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $t->created_at->format('d M Y, H:i') }}</td>
                        <td class="px-4 py-2">{{ $t->from?->name }} &rarr; {{ $t->to?->name }}</td>
                        <td class="px-4 py-2 text-gray-600">{{ $t->items->map(fn ($i) => $i->quantity . ' x ' . $i->product_name)->implode(', ') }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $t->user?->name }}</td>
                    </tr>
                @empty
                    <tr><td class="px-4 py-6 text-center text-gray-500">No transfers yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-card>
</div>
