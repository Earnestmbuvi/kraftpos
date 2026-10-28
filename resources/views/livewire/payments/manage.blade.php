<?php

use App\Models\PaymentMethod;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component
{
    public string $name = '';
    public string $type = 'other';

    public function with(): array
    {
        return [
            'methods' => PaymentMethod::orderBy('name')->get(),
            'types' => PaymentMethod::TYPES,
        ];
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()->can('manage-settings'), 403);
    }

    public function save(): void
    {
        $this->authorizeManage();
        $this->name = trim($this->name);

        $this->validate([
            'name' => ['required', 'string', 'max:30',
                Rule::unique('payment_methods', 'name')
                    ->where('business_id', auth()->user()->business_id)],
            'type' => ['required', Rule::in(array_keys(PaymentMethod::TYPES))],
        ]);

        PaymentMethod::create(['name' => $this->name, 'type' => $this->type]);
        $this->dispatch('notify', message: 'Payment method added.');
        $this->reset('name');
        $this->type = 'other';
    }

    public function toggle(int $id): void
    {
        $this->authorizeManage();
        $method = PaymentMethod::findOrFail($id);
        $method->is_active ? $method->disable() : $method->enable();
    }
}; ?>

<div class="space-y-6">
    <x-card class="p-6">
        <form wire:submit="save" class="space-y-4">
            <h3 class="text-lg font-medium text-gray-900">Add payment method</h3>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="pm_name" value="Name (e.g. Equitel, Card, Bank transfer)" />
                    <x-text-input wire:model="name" id="pm_name" type="text" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="pm_type" value="Type" />
                    <select wire:model="type" id="pm_type" class="mt-1 block w-full">
                        @foreach ($types as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('type')" class="mt-2" />
                </div>
            </div>

            <p class="text-xs text-gray-500">Only the Cash type gives change at the till.</p>
            <x-primary-button>Add</x-primary-button>
        </form>
    </x-card>

    <x-card class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Method</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @foreach ($methods as $m)
                    <tr wire:key="pm-{{ $m->id }}" class="{{ $m->is_active ? '' : 'opacity-50' }}">
                        <td class="px-4 py-3">{{ $m->name }}</td>
                        <td class="px-4 py-3">{{ $types[$m->type] ?? $m->type }}</td>
                        <td class="px-4 py-3">
                            <x-badge :color="$m->is_active ? 'green' : 'red'">{{ $m->is_active ? 'Active' : 'Disabled' }}</x-badge>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button wire:click="toggle({{ $m->id }})" class="text-gray-600 hover:underline">
                                {{ $m->is_active ? 'Disable' : 'Enable' }}
                            </button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-card>
</div>
