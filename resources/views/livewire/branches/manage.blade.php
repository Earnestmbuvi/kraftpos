<?php

use App\Models\Branch;
use App\Models\BranchStock;
use App\Services\BranchService;
use Livewire\Volt\Component;

new class extends Component
{
    public string $name = '';
    public ?int $renamingId = null;
    public string $renameTo = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('manage-settings'), 403);
        BranchService::mainId();
    }

    public function with(): array
    {
        return [
            'branches' => Branch::orderByDesc('is_main')->orderBy('name')->get(),
            'cap' => max(1, (int) auth()->user()->business->expected_branches),
        ];
    }

    public function save(): void
    {
        BranchService::create($this->name);
        $this->reset('name');
        $this->dispatch('notify', message: 'Branch added.');
    }

    public function startRename(int $id): void
    {
        abort_unless(auth()->user()->can('manage-settings'), 403);

        $this->resetErrorBag();
        $this->renamingId = $id;
        $this->renameTo = Branch::findOrFail($id)->name;
    }

    public function cancelRename(): void
    {
        $this->reset('renamingId', 'renameTo');
        $this->resetErrorBag();
    }

    public function saveRename(): void
    {
        BranchService::rename((int) $this->renamingId, $this->renameTo);

        $this->cancelRename();
        $this->dispatch('notify', message: 'Branch renamed.');
    }

    public function toggle(int $id): void
    {
        abort_unless(auth()->user()->can('manage-settings'), 403);

        $b = Branch::findOrFail($id);

        if ($b->is_main) {
            $this->addError('action', 'The main branch cannot be disabled.');
            return;
        }
        if ($b->is_active && BranchStock::where('branch_id', $b->id)->where('quantity', '!=', 0)->exists()) {
            $this->addError('action', 'Transfer this branch\'s stock to another branch first.');
            return;
        }

        $b->is_active ? $b->disable() : $b->enable();
        BranchService::forget();
        $this->resetErrorBag();
    }
}; ?>

<div class="space-y-6">
    <x-card class="p-6">
        <form wire:submit="save" class="space-y-4">
            <h3 class="text-lg font-medium text-gray-900">Branches and tills</h3>
            <p class="text-sm text-gray-500">
                Each branch keeps its own stock, sales, quotations, invoices, returns, parked carts and expenses. Your shop is set up for {{ $cap }} branch(es), counting the main one.
                Assign staff to a branch on the Staff screen, and move stock between branches under Stock, Transfers.
            </p>
            <div class="max-w-sm">
                <x-input-label for="b_name" value="New branch name" />
                <x-text-input wire:model="name" id="b_name" type="text" class="mt-1 block w-full" placeholder="e.g. Westlands" />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>
            <x-primary-button>Add branch</x-primary-button>
        </form>
    </x-card>

    <x-input-error :messages="$errors->get('action')" />

    <x-card class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <tbody class="divide-y divide-gray-200">
                @foreach ($branches as $b)
                    <tr wire:key="br-{{ $b->id }}" class="{{ $b->is_active ? '' : 'opacity-50' }}">
                        <td class="px-4 py-3 font-medium">
                            @if ($renamingId === $b->id)
                                <form wire:submit="saveRename" class="flex items-center gap-2">
                                    <x-text-input wire:model="renameTo" type="text" maxlength="80" class="w-56" />
                                    <x-primary-button>Save</x-primary-button>
                                    <x-secondary-button type="button" wire:click="cancelRename">Cancel</x-secondary-button>
                                </form>
                                <x-input-error :messages="$errors->get('name')" class="mt-1" />
                            @else
                                {{ $b->name }} @if ($b->is_main) <x-badge color="orange">Main</x-badge> @endif
                            @endif
                        </td>
                        <td class="px-4 py-3"><x-badge :color="$b->is_active ? 'green' : 'red'">{{ $b->is_active ? 'Active' : 'Disabled' }}</x-badge></td>
                        <td class="px-4 py-3 text-right space-x-3">
                            <button wire:click="startRename({{ $b->id }})" class="text-brand-600 hover:underline">Rename</button>
                            @unless ($b->is_main)
                                <button wire:click="toggle({{ $b->id }})" class="text-gray-600 hover:underline">{{ $b->is_active ? 'Disable' : 'Enable' }}</button>
                            @endunless
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-card>
</div>
