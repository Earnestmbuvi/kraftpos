<?php

use App\Models\DeliveryZone;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component
{
    public ?int $editingId = null;
    public string $name = '';
    public $fee = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('manage-settings'), 403);
    }

    public function with(): array
    {
        return ['zones' => DeliveryZone::orderBy('name')->get()];
    }

    public function edit(int $id): void
    {
        $z = DeliveryZone::findOrFail($id);
        $this->resetErrorBag();
        $this->editingId = $z->id;
        $this->name = $z->name;
        $this->fee = (string) $z->fee;
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'name', 'fee');
        $this->resetErrorBag();
    }

    public function save(): void
    {
        abort_unless(auth()->user()->can('manage-settings'), 403);

        $this->name = trim($this->name);
        $bid = auth()->user()->business_id;

        $this->validate([
            'name' => ['required', 'string', 'max:80',
                Rule::unique('delivery_zones', 'name')->where('business_id', $bid)->ignore($this->editingId)],
            'fee' => ['required', 'numeric', 'min:0', 'max:1000000'],
        ]);

        $this->editingId
            ? DeliveryZone::findOrFail($this->editingId)->update(['name' => $this->name, 'fee' => $this->fee])
            : DeliveryZone::create(['name' => $this->name, 'fee' => $this->fee]);

        $this->dispatch('notify', message: 'Delivery zone saved.');
        $this->cancel();
    }

    public function toggle(int $id): void
    {
        abort_unless(auth()->user()->can('manage-settings'), 403);

        $z = DeliveryZone::findOrFail($id);
        $z->is_active = ! $z->is_active;
        $z->save();
    }
}; ?>

<div class="space-y-6">
    <x-card class="p-6">
        <form wire:submit="save" class="space-y-4">
            <h3 class="text-lg font-medium text-gray-900">{{ $editingId ? 'Edit zone' : 'Add a delivery zone' }}</h3>
            <p class="text-sm text-gray-500">Zones with a fixed fee, such as an estate or town. At the till you can pick one, or type any fee.</p>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div class="md:col-span-2">
                    <x-input-label for="z_name" value="Zone name *" />
                    <x-text-input wire:model="name" id="z_name" type="text" maxlength="80" class="mt-1 block w-full" placeholder="e.g. Westlands" />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="z_fee" value="Fee (Kshs) *" />
                    <x-text-input wire:model="fee" id="z_fee" type="number" step="0.01" min="0" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('fee')" class="mt-2" />
                </div>
            </div>
            <div class="flex gap-3">
                <x-primary-button>{{ $editingId ? 'Update' : 'Add zone' }}</x-primary-button>
                @if ($editingId) <x-secondary-button type="button" wire:click="cancel">Cancel</x-secondary-button> @endif
            </div>
        </form>
    </x-card>

    <x-card class="divide-y divide-gray-100">
        @forelse ($zones as $z)
            <div wire:key="dz-{{ $z->id }}" class="flex items-center justify-between gap-4 p-4 text-sm {{ $z->is_active ? '' : 'opacity-50' }}">
                <p class="font-semibold">{{ $z->name }}</p>
                <div class="space-x-3 whitespace-nowrap">
                    <span class="font-semibold">Kshs {{ number_format($z->fee, 2) }}</span>
                    <button wire:click="edit({{ $z->id }})" class="text-brand-600 hover:underline">Edit</button>
                    <button wire:click="toggle({{ $z->id }})" class="text-gray-600 hover:underline">{{ $z->is_active ? 'Disable' : 'Enable' }}</button>
                </div>
            </div>
        @empty
            <p class="p-6 text-center text-gray-500">No zones yet.</p>
        @endforelse
    </x-card>
</div>
