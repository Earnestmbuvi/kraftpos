<?php

use App\Models\ExpenseCategory;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component
{
    public string $name = '';

    public function with(): array
    {
        return ['items' => ExpenseCategory::orderBy('name')->get()];
    }

    public function save(): void
    {
        abort_unless(auth()->user()->can('manage-categories'), 403);

        $this->name = trim($this->name);

        $this->validate([
            'name' => ['required', 'string', 'max:50',
                Rule::unique('expense_categories', 'name')->where('business_id', auth()->user()->business_id)],
        ]);

        ExpenseCategory::create(['name' => $this->name]);
        $this->dispatch('notify', message: 'Expense category added.');
        $this->reset('name');
    }

    public function toggle(int $id): void
    {
        abort_unless(auth()->user()->can('manage-categories'), 403);

        $c = ExpenseCategory::findOrFail($id);
        $c->is_active ? $c->disable() : $c->enable();
    }
}; ?>

<div class="space-y-6">
    <x-card class="p-6">
        <form wire:submit="save" class="space-y-4">
            <h3 class="text-lg font-medium text-gray-900">Add expense category</h3>
            <div>
                <x-input-label for="ec_name" value="Name (e.g. Insurance, Licences)" />
                <x-text-input wire:model="name" id="ec_name" type="text" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>
            <x-primary-button>Add</x-primary-button>
        </form>
    </x-card>

    <x-card class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <tbody class="divide-y divide-gray-200">
                @foreach ($items as $c)
                    <tr wire:key="ec-{{ $c->id }}" class="{{ $c->is_active ? '' : 'opacity-50' }}">
                        <td class="px-4 py-3">{{ $c->name }}</td>
                        <td class="px-4 py-3"><x-badge :color="$c->is_active ? 'green' : 'red'">{{ $c->is_active ? 'Active' : 'Disabled' }}</x-badge></td>
                        <td class="px-4 py-3 text-right">
                            <button wire:click="toggle({{ $c->id }})" class="text-gray-600 hover:underline">{{ $c->is_active ? 'Disable' : 'Enable' }}</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-card>
</div>
