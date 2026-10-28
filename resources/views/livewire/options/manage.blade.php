<?php

use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component
{
    public string $name = '';
    public array $newValues = [];

    public function with(): array
    {
        return ['options' => ProductOption::with('values')->orderBy('name')->get()];
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()->can('manage-products'), 403);
    }

    public function saveOption(): void
    {
        $this->authorizeManage();
        $this->name = trim($this->name);

        $this->validate([
            'name' => [
                'required', 'string', 'max:30',
                Rule::unique('product_options', 'name')
                    ->where('business_id', auth()->user()->business_id),
            ],
        ]);

        ProductOption::create(['name' => $this->name]);
        $this->dispatch('notify', message: 'Option added.');
        $this->reset('name');
    }

    public function addValue(int $optionId): void
    {
        $this->authorizeManage();
        $option = ProductOption::findOrFail($optionId); // tenant scope blocks other shops' IDs

        $value = trim($this->newValues[$optionId] ?? '');
        $this->newValues[$optionId] = $value;

        $this->validate([
            "newValues.$optionId" => [
                'required', 'string', 'max:30',
                Rule::unique('product_option_values', 'value')
                    ->where('product_option_id', $option->id),
            ],
        ], [], ["newValues.$optionId" => 'value']);

        ProductOptionValue::create([
            'product_option_id' => $option->id,
            'value' => $value,
        ]);
        $this->dispatch('notify', message: 'Value added.');
        unset($this->newValues[$optionId]);
    }

    public function toggleOption(int $id): void
    {
        $this->authorizeManage();
        $option = ProductOption::findOrFail($id);
        $option->is_active ? $option->disable() : $option->enable();
    }

    public function toggleValue(int $id): void
    {
        $this->authorizeManage();
        $value = ProductOptionValue::findOrFail($id);
        $value->is_active ? $value->disable() : $value->enable();
    }
}; ?>

<div class="space-y-6">
    <x-card class="p-6">
        <form wire:submit="saveOption" class="space-y-4">
            <h3 class="text-lg font-medium text-gray-900">Add option</h3>

            <div>
                <x-input-label for="option_name" value="Option name (e.g. Material, Flavour)" />
                <x-text-input wire:model="name" id="option_name" type="text" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <x-primary-button>Add option</x-primary-button>
        </form>
    </x-card>

    @forelse ($options as $option)
        <x-card class="p-6 space-y-4 {{ $option->is_active ? '' : 'opacity-60' }}" wire:key="option-{{ $option->id }}">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-medium text-gray-900">
                    {{ $option->name }}
                    <x-badge :color="$option->is_active ? 'green' : 'red'" class="ml-2">
                        {{ $option->is_active ? 'Active' : 'Disabled' }}
                    </x-badge>
                </h3>
                <button wire:click="toggleOption({{ $option->id }})" class="text-sm text-gray-600 hover:underline">
                    {{ $option->is_active ? 'Disable' : 'Enable' }}
                </button>
            </div>

            <div class="flex flex-wrap gap-2">
                @forelse ($option->values as $value)
                    <span wire:key="value-{{ $value->id }}"
                          class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-sm {{ $value->is_active ? 'border-gray-300 text-gray-800' : 'border-gray-200 text-gray-400 line-through' }}">
                        {{ $value->value }}
                        <button wire:click="toggleValue({{ $value->id }})"
                                class="text-xs text-brand-600 hover:underline no-underline"
                                title="{{ $value->is_active ? 'Disable' : 'Enable' }}">
                            {{ $value->is_active ? 'Disable' : 'Enable' }}
                        </button>
                    </span>
                @empty
                    <p class="text-sm text-gray-500">No values yet.</p>
                @endforelse
            </div>

            <form wire:submit="addValue({{ $option->id }})" class="flex gap-3">
                <x-text-input wire:model="newValues.{{ $option->id }}" type="text"
                              placeholder="Add a value, e.g. XXL" class="block w-full" />
                <x-secondary-button type="submit">Add value</x-secondary-button>
            </form>
            <x-input-error :messages="$errors->get('newValues.' . $option->id)" />
        </x-card>
    @empty
        <x-card class="p-6 text-center text-gray-500">No options yet.</x-card>
    @endforelse
</div>
