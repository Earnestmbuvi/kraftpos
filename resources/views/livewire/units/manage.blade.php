<?php

use App\Models\Unit;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component
{
    public string $name = '';

    public function with(): array
    {
        return ['units' => Unit::orderBy('name')->get()];
    }

    public function save(): void
    {
        abort_unless(auth()->user()->can('manage-products'), 403);

        $this->name = trim($this->name);

        $this->validate([
            'name' => [
                'required', 'string', 'max:30',
                Rule::unique('units', 'name')
                    ->where('business_id', auth()->user()->business_id),
            ],
        ]);

        Unit::create(['name' => $this->name]);
        $this->dispatch('notify', message: 'Unit added.');
        $this->reset('name');
    }

    public function toggle(int $id): void
    {
        abort_unless(auth()->user()->can('manage-products'), 403);

        $unit = Unit::findOrFail($id); // tenant scope blocks other shops' IDs
        $unit->is_active ? $unit->disable() : $unit->enable();
    }
}; ?>

<div class="space-y-6">
    <form wire:submit="save" class="bg-white border border-gray-200 rounded-xl p-6 space-y-4">
        <h3 class="text-lg font-medium text-gray-900">Add unit</h3>

        <div>
            <x-input-label for="unit_name" value="Unit name (e.g. crate, tray, bundle)" />
            <x-text-input wire:model="name" id="unit_name" type="text" class="mt-1 block w-full" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <x-primary-button>Add</x-primary-button>
    </form>

    <div class="bg-white border border-gray-200 rounded-xl overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Unit</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse ($units as $unit)
                    <tr wire:key="unit-{{ $unit->id }}" class="{{ $unit->is_active ? '' : 'opacity-50' }}">
                        <td class="px-4 py-3">{{ $unit->name }}</td>
                        <td class="px-4 py-3">{{ $unit->is_active ? 'Active' : 'Disabled' }}</td>
                        <td class="px-4 py-3 text-right">
                            <button wire:click="toggle({{ $unit->id }})" class="text-gray-600 hover:underline">
                                {{ $unit->is_active ? 'Disable' : 'Enable' }}
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-6 text-center text-gray-500">No units yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
