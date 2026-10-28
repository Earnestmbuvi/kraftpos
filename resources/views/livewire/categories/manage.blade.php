<?php

use App\Models\Category;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component
{
    public ?int $editingId = null;
    public string $name = '';
    public $parent_id = null; // untyped on purpose: a select sends '' for "none"

    public function with(): array
    {
        return [
            'categories' => Category::with('parent')->orderBy('name')->get(),
            'parents' => Category::whereNull('parent_id')
                ->active()
                ->where('id', '!=', $this->editingId ?? 0)
                ->orderBy('name')
                ->get(),
        ];
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()->can('manage-categories'), 403);
    }

    public function edit(int $id): void
    {
        $this->authorizeManage();
        $category = Category::findOrFail($id); // the tenant scope blocks other shops' IDs

        $this->editingId = $category->id;
        $this->name = $category->name;
        $this->parent_id = $category->parent_id;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'name', 'parent_id');
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->authorizeManage();
        $this->parent_id = $this->parent_id ?: null;

        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')
                    ->where('business_id', auth()->user()->business_id)
                    ->whereNull('parent_id'), // parent must be top-level: two levels only
            ],
        ]);

        if ($this->editingId && $this->parent_id == $this->editingId) {
            $this->addError('parent_id', 'A category cannot be its own parent.');
            return;
        }

        if ($this->editingId && $this->parent_id
            && Category::where('parent_id', $this->editingId)->exists()) {
            $this->addError('parent_id', 'This category has subcategories, so it must stay top-level.');
            return;
        }

        $duplicate = Category::where('name', $this->name)
            ->where('parent_id', $this->parent_id)
            ->when($this->editingId, fn ($q) => $q->where('id', '!=', $this->editingId))
            ->exists();

        if ($duplicate) {
            $this->addError('name', 'That category already exists here.');
            return;
        }

       $editing = (bool) $this->editingId;

if ($editing) {
    Category::findOrFail($this->editingId)
        ->update(['name' => $this->name, 'parent_id' => $this->parent_id]);
} else {
    Category::create(['name' => $this->name, 'parent_id' => $this->parent_id]);
}

$this->dispatch('notify', message: $editing ? 'Category updated.' : 'Category added.');
$this->cancel();
}
    public function toggle(int $id): void
    {
        $this->authorizeManage();
        $category = Category::findOrFail($id);
        $category->is_active ? $category->disable() : $category->enable();
    }
}; ?>

<div class="space-y-6">
    <form wire:submit="save" class="bg-white border border-gray-200 rounded-xl p-6 space-y-4">
        <h3 class="text-lg font-medium text-gray-900">
            {{ $editingId ? 'Edit category' : 'Add category' }}
        </h3>

        <div>
            <x-input-label for="name" value="Name" />
            <x-text-input wire:model="name" id="name" type="text" class="mt-1 block w-full" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="parent_id" value="Parent category (optional)" />
            <select wire:model="parent_id" id="parent_id"
                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                <option value="">None (top-level)</option>
                @foreach ($parents as $parent)
                    <option value="{{ $parent->id }}">{{ $parent->name }}</option>
                @endforeach
            </select>
            <x-input-error :messages="$errors->get('parent_id')" class="mt-2" />
        </div>

        <div class="flex gap-3">
            <x-primary-button>{{ $editingId ? 'Update' : 'Add' }}</x-primary-button>
            @if ($editingId)
                <x-secondary-button type="button" wire:click="cancel">Cancel</x-secondary-button>
            @endif
        </div>
    </form>

    <div class="bg-white border border-gray-200 rounded-xl overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Parent</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse ($categories as $category)
                    <tr wire:key="cat-{{ $category->id }}" class="{{ $category->is_active ? '' : 'opacity-50' }}">
                        <td class="px-4 py-3">{{ $category->name }}</td>
                        <td class="px-4 py-3">{{ $category->parent?->name ?? '-' }}</td>
                        <td class="px-4 py-3">{{ $category->is_active ? 'Active' : 'Disabled' }}</td>
                        <td class="px-4 py-3 text-right space-x-3">
                            <button wire:click="edit({{ $category->id }})" class="text-indigo-600 hover:underline">Edit</button>
                            <button wire:click="toggle({{ $category->id }})" class="text-gray-600 hover:underline">
                                {{ $category->is_active ? 'Disable' : 'Enable' }}
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-gray-500">No categories yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
