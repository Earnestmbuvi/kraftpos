<?php

use App\Models\Business;
use App\Models\PlatformItem;
use App\Services\PlatformDocumentService;
use Livewire\Volt\Component;

new class extends Component
{
    public string $type = 'quotation';
    public $businessId = '';
    public string $due = '';
    public $taxRate = 0;
    public string $notes = '';
    public array $lines = [];
    public $pick = '';

    public function mount(string $type = 'quotation'): void
    {
        abort_unless(auth()->user()?->is_super_admin, 403);

        $this->type = $type === 'invoice' ? 'invoice' : 'quotation';
        $this->taxRate = (float) config('kraftpos.company.tax_rate');
        $this->due = today()->addDays($this->type === 'invoice' ? (int) config('kraftpos.company.due_days') : 14)->toDateString();
    }

    public function with(): array
    {
        abort_unless(auth()->user()?->is_super_admin, 403);

        $sub = collect($this->lines)->sum(fn ($l) => max((int) ($l['quantity'] ?? 0), 0) * max((float) ($l['unit_price'] ?? 0), 0));
        $tax = round($sub * min(max((float) $this->taxRate, 0), 100) / 100, 2);

        return [
            'merchants' => Business::orderBy('name')->get(['id', 'name']),
            'items' => PlatformItem::where('is_active', true)->orderBy('name')->get(),
            'subtotal' => round($sub, 2), 'tax' => $tax, 'total' => round($sub + $tax, 2),
        ];
    }

    public function updatedPick($id): void
    {
        $item = PlatformItem::where('is_active', true)->find((int) $id);
        $this->pick = '';

        if ($item) {
            $this->lines[] = ['item_id' => $item->id, 'name' => $item->name, 'description' => (string) $item->description,
                'quantity' => 1, 'unit_price' => (string) $item->price];
        }
    }

    public function addCustom(): void
    {
        $this->lines[] = ['item_id' => null, 'name' => '', 'description' => '', 'quantity' => 1, 'unit_price' => ''];
    }

    public function removeLine(int $i): void
    {
        unset($this->lines[$i]);
        $this->lines = array_values($this->lines);
    }

    public function save(): void
    {
        abort_unless(auth()->user()?->is_super_admin, 403);

        $doc = PlatformDocumentService::create(
            $this->type, (int) $this->businessId, $this->lines, $this->due ?: null, $this->notes, (float) $this->taxRate
        );

        session()->flash('notify', ucfirst($this->type) . ' ' . $doc->number_label . ' created.');
        $this->redirect(route('admin.document', $doc->id), navigate: true);
    }
}; ?>

<form wire:submit="save" class="space-y-6">
    <x-card class="p-6 space-y-4">
        <h3 class="text-lg font-medium text-gray-900">New {{ $type }}</h3>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div class="md:col-span-1">
                <x-input-label for="d_biz" value="Merchant *" />
                <select wire:model="businessId" id="d_biz" class="mt-1 block w-full">
                    <option value="">Choose...</option>
                    @foreach ($merchants as $m) <option value="{{ $m->id }}">{{ $m->name }}</option> @endforeach
                </select>
                <x-input-error :messages="$errors->get('businessId')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="d_due" :value="$type === 'invoice' ? 'Payment due' : 'Valid until'" />
                <x-text-input wire:model="due" id="d_due" type="date" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('due')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="d_tax" value="Tax (%)" />
                <x-text-input wire:model.live.debounce.400ms="taxRate" id="d_tax" type="number" step="0.01" min="0" max="100" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('taxRate')" class="mt-2" />
            </div>
        </div>
    </x-card>

    <x-card class="p-6 space-y-4">
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-[14rem]">
                <x-input-label for="d_pick" value="Add from your catalogue" />
                <select wire:model.live="pick" id="d_pick" class="mt-1 block w-full">
                    <option value="">Choose an item...</option>
                    @foreach ($items as $it) <option value="{{ $it->id }}">{{ $it->name }} (Kshs {{ number_format($it->price, 2) }})</option> @endforeach
                </select>
            </div>
            <x-secondary-button type="button" wire:click="addCustom">+ Custom line</x-secondary-button>
        </div>

        @foreach ($lines as $i => $l)
            <div wire:key="dl-{{ $i }}" class="grid grid-cols-12 items-start gap-3 rounded-lg bg-gray-50 p-3">
                <div class="col-span-12 md:col-span-5"><x-text-input wire:model="lines.{{ $i }}.name" type="text" placeholder="Item" class="block w-full" /></div>
                <div class="col-span-12 md:col-span-3"><x-text-input wire:model="lines.{{ $i }}.description" type="text" placeholder="Note (optional)" class="block w-full" /></div>
                <div class="col-span-4 md:col-span-1"><x-text-input wire:model.live.debounce.400ms="lines.{{ $i }}.quantity" type="number" min="1" class="block w-full" /></div>
                <div class="col-span-6 md:col-span-2"><x-text-input wire:model.live.debounce.400ms="lines.{{ $i }}.unit_price" type="number" step="0.01" min="0" placeholder="Price" class="block w-full" /></div>
                <button type="button" wire:click="removeLine({{ $i }})" class="col-span-2 text-sm text-gray-500 hover:underline md:col-span-1">Remove</button>
            </div>
        @endforeach
        <x-input-error :messages="$errors->get('lines')" />

        <div class="ml-auto w-full max-w-xs space-y-1 border-t border-gray-200 pt-3 text-sm">
            <div class="flex justify-between"><span>Subtotal</span><span>{{ number_format($subtotal, 2) }}</span></div>
            <div class="flex justify-between"><span>Tax</span><span>{{ number_format($tax, 2) }}</span></div>
            <div class="flex justify-between text-base font-bold"><span>Total</span><span>Kshs {{ number_format($total, 2) }}</span></div>
        </div>
    </x-card>

    <x-card class="p-6 space-y-4">
        <div><x-input-label for="d_notes" value="Notes (optional)" /><x-text-input wire:model="notes" id="d_notes" type="text" maxlength="255" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('notes')" class="mt-2" /></div>
        <x-primary-button>Create {{ $type }}</x-primary-button>
    </x-card>
</form>
