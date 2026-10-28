<?php

use App\Models\StaffRole;
use App\Support\Grantable;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Spatie\Permission\Models\Role;

new class extends Component
{
    public ?int $editingId = null;
    public string $label = '';
    public array $perms = [];

    public function mount(): void
    {
        abort_unless(auth()->user()->can('manage-settings') && auth()->user()->business_id, 403);
    }

    public function with(): array
    {
        $roles = StaffRole::where('business_id', auth()->user()->business_id)->orderBy('label')->get();

        return [
            'roles' => $roles,
            'granted' => $roles->mapWithKeys(fn ($r) => [$r->id => Role::findByName($r->role_name, 'web')->permissions->pluck('name')->all()]),
            'all' => Grantable::ROLE_LIST,
        ];
    }

    public function copyManager(): void
    {
        $this->perms = array_values(array_intersect(
            Role::findByName('manager', 'web')->permissions->pluck('name')->all(),
            array_keys(Grantable::ROLE_LIST)
        ));
    }

    public function edit(int $id): void
    {
        $r = StaffRole::where('business_id', auth()->user()->business_id)->findOrFail($id);

        $this->resetErrorBag();
        $this->editingId = $r->id;
        $this->label = $r->label;
        $this->perms = Role::findByName($r->role_name, 'web')->permissions->pluck('name')->all();
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'label', 'perms');
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $user = auth()->user();
        abort_unless($user->can('manage-settings') && $user->business_id, 403);

        $this->label = trim($this->label);

        $this->validate([
            'label' => ['required', 'string', 'max:50', Rule::notIn(['Owner', 'Manager', 'Cashier', 'owner', 'manager', 'cashier']),
                Rule::unique('staff_roles', 'label')->where('business_id', $user->business_id)->ignore($this->editingId)],
            'perms' => ['required', 'array', 'min:1'],
            'perms.*' => [Rule::in(array_keys(Grantable::ROLE_LIST))], // managing staff and settings can never be granted
        ], ['perms.required' => 'Tick at least one permission.', 'perms.min' => 'Tick at least one permission.']);

        if ($this->editingId) {
            $r = StaffRole::where('business_id', $user->business_id)->findOrFail($this->editingId);
            $r->label = $this->label;
            $r->save();
        } else {
            $name = 'b' . $user->business_id . '-' . Str::slug($this->label);
            $r = StaffRole::create(['business_id' => $user->business_id, 'label' => $this->label, 'role_name' => $name]);
            Role::findOrCreate($name, 'web');
        }

        Role::findByName($r->role_name, 'web')->syncPermissions($this->perms);

        $this->dispatch('notify', message: $this->editingId ? 'Role updated.' : 'Role created.');
        $this->cancel();
    }
}; ?>

<div class="space-y-6">
    <x-card class="p-6">
        <form wire:submit="save" class="space-y-4">
            <h3 class="text-lg font-medium text-gray-900">{{ $editingId ? 'Edit role' : 'New role' }}</h3>
            <p class="text-sm text-gray-500">
                Create roles such as Accountant or Store keeper, then give them to staff on the Staff screen. Only owners can manage staff and settings, so those cannot be ticked here.
            </p>

            <div class="max-w-sm">
                <x-input-label for="role_label" value="Role name *" />
                <x-text-input wire:model="label" id="role_label" type="text" maxlength="50" class="mt-1 block w-full" placeholder="e.g. Accountant" />
                <x-input-error :messages="$errors->get('label')" class="mt-2" />
            </div>

            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-medium text-gray-700">Permissions</p>
                    <button type="button" wire:click="copyManager" class="text-xs text-brand-600 hover:underline">Start from the manager's permissions</button>
                </div>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    @foreach ($all as $key => $text)
                        <label wire:key="rp-{{ $key }}" class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" wire:model="perms" value="{{ $key }}" class="rounded"> {{ $text }}
                        </label>
                    @endforeach
                </div>
                <x-input-error :messages="collect($errors->get('perms*'))->flatten()->all()" class="mt-2" />
            </div>

            <div class="flex gap-3">
                <x-primary-button>{{ $editingId ? 'Update role' : 'Create role' }}</x-primary-button>
                @if ($editingId) <x-secondary-button type="button" wire:click="cancel">Cancel</x-secondary-button> @endif
            </div>
        </form>
    </x-card>

    <x-card class="divide-y divide-gray-100">
        @forelse ($roles as $r)
            <div wire:key="role-{{ $r->id }}" class="flex items-start justify-between gap-4 p-4 text-sm">
                <div>
                    <p class="font-semibold text-gray-900">{{ $r->label }}</p>
                    <p class="text-gray-500">{{ collect($granted[$r->id] ?? [])->map(fn ($p) => $all[$p] ?? $p)->implode(', ') ?: 'No permissions' }}</p>
                </div>
                <button wire:click="edit({{ $r->id }})" class="text-brand-600 hover:underline">Edit</button>
            </div>
        @empty
            <p class="p-6 text-center text-gray-500">No custom roles yet. Owner, Manager and Cashier are always available.</p>
        @endforelse
    </x-card>
</div>
