<?php

use App\Models\PlatformItem;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component
{
    public ?int $editingId = null;
    public string $name = '';
    public string $kind = 'service';
    public string $description = '';
    public $price = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_super_admin, 403);
    }

    public function with(): array
    {
        abort_unless(auth()->user()?->is_super_admin, 403);

        return ['items' => PlatformItem::orderBy('name')->get()];
    }

    public function edit(int $id): void
    {
        $i = PlatformItem::findOrFail($id);
        $this->resetErrorBag();
        $this->editingId = $i->id;
        $this->name = $i->name;
        $this->kind = $i->kind;
        $this->description = (string) $i->description;
        $this->price = (string) $i->price;
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'name', 'description', 'price');
        $this->kind = 'service';
        $this->resetErrorBag();
    }

    public function save(): void
    {
        abort_unless(auth()->user()?->is_super_admin, 403);

        $this->name = trim($this->name);
        $this->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('platform_items', 'name')->ignore($this->editingId)],
            'kind' => ['required', 'in:service,product'],
            'description' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0', 'max:100000000'],
        ]);

        $data = ['name' => $this->name, 'kind' => $this->kind, 'description' => trim($this->description) ?: null, 'price' => $this->price];

        $this->editingId ? PlatformItem::findOrFail($this->editingId)->update($data) : PlatformItem::create($data);

        $this->dispatch('notify', message: 'Saved.');
        $this->cancel();
    }

    public function toggle(int $id): void
    {
        abort_unless(auth()->user()?->is_super_admin, 403);

        $i = PlatformItem::findOrFail($id);
        $i->is_active = ! $i->is_active;
        $i->save();
    }
}; ?>

<div class="space-y-6">
    <x-card class="p-6">
        <form wire:submit="save" class="space-y-4">
            <h3 class="text-lg font-medium text-gray-900">{{ $editingId ? 'Edit item' : 'Add a service or product you offer' }}</h3>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                <div class="md:col-span-2"><x-input-label for="ci_name" value="Name *" /><x-text-input wire:model="name" id="ci_name" type="text" class="mt-1 block w-full" placeholder="e.g. Monthly subscription, Setup and training" /><x-input-error :messages="$errors->get('name')" class="mt-2" /></div>
                <div><x-input-label for="ci_kind" value="Type" /><select wire:model="kind" id="ci_kind" class="mt-1 block w-full"><option value="service">Service</option><option value="product">Product</option></select></div>
                <div><x-input-label for="ci_price" value="Price (Kshs) *" /><x-text-input wire:model="price" id="ci_price" type="number" step="0.01" min="0" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('price')" class="mt-2" /></div>
                <div class="md:col-span-4"><x-input-label for="ci_desc" value="Description (optional)" /><x-text-input wire:model="description" id="ci_desc" type="text" maxlength="255" class="mt-1 block w-full" /></div>
            </div>
            <div class="flex gap-3">
                <x-primary-button>{{ $editingId ? 'Update' : 'Add' }}</x-primary-button>
                @if ($editingId) <x-secondary-button type="button" wire:click="cancel">Cancel</x-secondary-button> @endif
            </div>
        </form>
    </x-card>

    <x-card class="divide-y divide-gray-100">
        @forelse ($items as $i)
            <div wire:key="pi-{{ $i->id }}" class="flex items-center justify-between gap-4 p-4 text-sm {{ $i->is_active ? '' : 'opacity-50' }}">
                <div>
                    <p class="font-semibold">{{ $i->name }} <x-badge color="orange">{{ ucfirst($i->kind) }}</x-badge></p>
                    @if ($i->description) <p class="text-gray-500">{{ $i->description }}</p> @endif
                </div>
                <div class="space-x-3 whitespace-nowrap">
                    <span class="font-semibold">Kshs {{ number_format($i->price, 2) }}</span>
                    <button wire:click="edit({{ $i->id }})" class="text-brand-600 hover:underline">Edit</button>
                    <button wire:click="toggle({{ $i->id }})" class="text-gray-600 hover:underline">{{ $i->is_active ? 'Disable' : 'Enable' }}</button>
                </div>
            </div>
        @empty
            <p class="p-6 text-center text-gray-500">Nothing yet. Add what you sell to merchants, then use it on quotations and invoices.</p>
        @endforelse
    </x-card>
</div>
