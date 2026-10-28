<?php

use App\Models\Branch;
use App\Models\User;
use App\Services\BranchService;
use App\Support\Grantable;
use App\Support\RoleLabel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Spatie\Permission\Models\Role;

new class extends Component
{
    public bool $showForm = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $email = '';
    public string $role = 'cashier';
    public string $password = '';
    public array $extras = [];
    public array $branchIds = [];

    public function mount(): void
    {
        abort_unless(auth()->user()->can('manage-users') && auth()->user()->business_id, 403);
    }

    private function staffQuery()
    {
        // User is not tenant-scoped, so the shop filter is explicit and always applied
        return User::where('business_id', auth()->user()->business_id)->where('is_super_admin', false);
    }

    private function allowedRoles(): array
    {
        return RoleLabel::options(auth()->user()->business_id);
    }

    private function rolePermissions(): array
    {
        return array_key_exists($this->role, $this->allowedRoles())
            ? Role::findByName($this->role, 'web')->permissions->pluck('name')->all()
            : [];
    }

    public function with(): array
    {
        $staff = $this->staffQuery()->with(['roles', 'permissions'])->orderBy('name')->get();

        $names = DB::table('branch_user as bu')
            ->join('branches as b', 'b.id', '=', 'bu.branch_id')
            ->whereIn('bu.user_id', $staff->pluck('id'))
            ->get(['bu.user_id', 'b.name'])
            ->groupBy('user_id')->map(fn ($g) => $g->pluck('name')->implode(', '));

        return [
            'staff' => $staff,
            'branchNames' => $names,
            'branches' => Branch::active()->orderByDesc('is_main')->orderBy('name')->get(),
            'roles' => $this->allowedRoles(),
            'grantable' => Grantable::LIST,
            'rolePerms' => $this->rolePermissions(),
        ];
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()->can('manage-users') && auth()->user()->business_id, 403);
    }

    private function otherActiveOwners(User $target): int
    {
        return $this->staffQuery()->where('is_active', true)
            ->where('id', '!=', $target->id)->role('owner')->count();
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'email', 'password', 'extras', 'branchIds');
        $this->role = 'cashier';
        $this->resetErrorBag();
    }

    public function openCreate(): void
    {
        $this->authorizeManage();
        $this->resetForm();
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function edit(int $id): void
    {
        $this->authorizeManage();
        $u = $this->staffQuery()->findOrFail($id);

        $this->resetForm();
        $this->editingId = $u->id;
        $this->name = $u->name;
        $this->email = $u->email;
        $this->role = $u->getRoleNames()->first() ?? 'cashier';
        $this->extras = $u->getDirectPermissions()->pluck('name')->all();

        $ids = DB::table('branch_user')->where('user_id', $u->id)->pluck('branch_id')->map(fn ($i) => (string) $i)->all();
        $this->branchIds = $ids ?: ($u->branch_id ? [(string) $u->branch_id] : []);

        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorizeManage();

        $this->name = trim($this->name);
        $this->email = strtolower(trim($this->email));

        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($this->editingId)],
            'role' => ['required', Rule::in(array_keys($this->allowedRoles()))],
            'password' => [$this->editingId ? 'nullable' : 'required', 'string', 'min:8', 'max:100'],
            'extras' => ['array'],
            'extras.*' => [Rule::in(array_keys(Grantable::LIST))],
            'branchIds' => ['array'],
            'branchIds.*' => ['integer', Rule::exists('branches', 'id')->where('business_id', auth()->user()->business_id)],
        ]);

        $target = $this->editingId ? $this->staffQuery()->findOrFail($this->editingId) : null;

        if ($target) {
            $currentRole = $target->getRoleNames()->first();

            if ($target->id === auth()->id() && $this->role !== $currentRole) {
                $this->addError('role', 'You cannot change your own role.');
                return;
            }
            if ($currentRole === 'owner' && $this->role !== 'owner' && $this->otherActiveOwners($target) === 0) {
                $this->addError('role', 'The shop needs at least one active owner.');
                return;
            }
        }

        // Only extras that the role does not already include are stored directly
        $extras = array_values(array_diff($this->extras, $this->rolePermissions()));
        $editing = (bool) $target;

        // Owners always have every branch, so no mapping is kept for them
        $branches = $this->role === 'owner' ? [] : array_values(array_unique(array_map('intval', $this->branchIds)));

        DB::transaction(function () use ($target, $extras, $branches) {
            $user = $target ?? new User();
            $user->name = $this->name;
            $user->email = $this->email;

            if ($this->password !== '') {
                $user->password = $this->password; // hashed by the model cast
            }

            if (! $target) {
                $user->business_id = auth()->user()->business_id; // never from the form
                $user->is_active = true;
                $user->email_verified_at = now();
            }

            $user->branch_id = $branches[0] ?? null;
            $user->save();
            $user->syncRoles([$this->role]);
            $user->syncPermissions($extras);

            DB::table('branch_user')->where('user_id', $user->id)->delete();
            foreach ($branches as $bid) {
                DB::table('branch_user')->insert(['user_id' => $user->id, 'branch_id' => $bid]);
            }
        });

        BranchService::forget();
        $this->dispatch('notify', message: $editing ? 'Staff member updated.' : 'Staff member added.');
        $this->closeForm();
    }

    public function sendReset(int $id): void
    {
        $this->authorizeManage();
        $u = $this->staffQuery()->findOrFail($id);

        $sent = Password::sendResetLink(['email' => $u->email]) === Password::RESET_LINK_SENT;

        $this->dispatch('notify',
            message: $sent ? "Reset link sent to {$u->email}." : 'Could not send the link. Wait a minute and try again.',
            type: $sent ? 'success' : 'error');
    }

    public function toggle(int $id): void
    {
        $this->authorizeManage();
        $u = $this->staffQuery()->findOrFail($id);

        if ($u->id === auth()->id()) {
            $this->addError('action', 'You cannot disable your own account.');
            return;
        }

        if ($u->is_active && $u->hasRole('owner') && $this->otherActiveOwners($u) === 0) {
            $this->addError('action', 'The shop needs at least one active owner.');
            return;
        }

        $u->is_active = ! $u->is_active;
        $u->save();
        $this->resetErrorBag();
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row gap-3 sm:items-center sm:justify-between">
        <p class="text-sm text-gray-600">Everyone who can log in to your shop, and what they can do.</p>
        <x-primary-button type="button" wire:click="openCreate" class="whitespace-nowrap">
            + Add staff member
        </x-primary-button>
    </div>

    <x-input-error :messages="$errors->get('action')" />

    <x-card class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Role</th>
                    @if ($branches->count() > 1)
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Branches</th>
                    @endif
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Extras</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @foreach ($staff as $u)
                    @php $role = $u->getRoleNames()->first(); @endphp
                    <tr wire:key="staff-{{ $u->id }}" class="{{ $u->is_active ? '' : 'opacity-50' }}">
                        <td class="px-4 py-3 font-medium">{{ $u->name }}@if ($u->id === auth()->id()) <span class="text-xs text-gray-500">(you)</span>@endif</td>
                        <td class="px-4 py-3">{{ $u->email }}</td>
                        <td class="px-4 py-3"><x-badge color="orange">{{ $role ? \App\Support\RoleLabel::label($role) : 'None' }}</x-badge></td>
                        @if ($branches->count() > 1)
                            <td class="px-4 py-3 text-gray-600">
                                {{ $role === 'owner' ? 'All' : ($branchNames[$u->id] ?? ($role === 'cashier' ? 'Main' : 'All')) }}
                            </td>
                        @endif
                        <td class="px-4 py-3 text-gray-600">{{ $u->permissions->count() ?: '-' }}</td>
                        <td class="px-4 py-3"><x-badge :color="$u->is_active ? 'green' : 'red'">{{ $u->is_active ? 'Active' : 'Disabled' }}</x-badge></td>
                        <td class="px-4 py-3 text-right space-x-3 whitespace-nowrap">
                            <button wire:click="edit({{ $u->id }})" class="text-brand-600 hover:underline">Edit</button>
                            <button wire:click="sendReset({{ $u->id }})" class="text-gray-600 hover:underline">Send reset link</button>
                            @if ($u->id !== auth()->id())
                                <button wire:click="toggle({{ $u->id }})" class="text-gray-600 hover:underline">
                                    {{ $u->is_active ? 'Disable' : 'Enable' }}
                                </button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-card>

    @if ($showForm)
        <x-slide-over :title="$editingId ? 'Edit staff member' : 'Add staff member'" width="max-w-2xl">
            <form wire:submit="save" class="flex flex-1 flex-col">
                <div class="flex-1 space-y-6 p-6">
                    <x-card class="p-6 space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <x-input-label for="u_name" value="Name *" />
                                <x-text-input wire:model="name" id="u_name" type="text" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('name')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="u_email" value="Email (their login) *" />
                                <x-text-input wire:model="email" id="u_email" type="email" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('email')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="u_role" value="Role *" />
                                <select wire:model.live="role" id="u_role" class="mt-1 block w-full">
                                    @foreach ($roles as $value => $label)
                                        <option value="{{ $value }}" @selected($role === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('role')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="u_password" value="{{ $editingId ? 'New password (leave empty to keep)' : 'Temporary password *' }}" />
                                <x-text-input wire:model="password" id="u_password" type="password" autocomplete="new-password" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('password')" class="mt-2" />
                            </div>
                        </div>
                    </x-card>

                    @if ($branches->count() > 1)
                        <x-card class="p-6 space-y-3">
                            <div>
                                <p class="text-sm font-medium text-gray-700">Branches this person can open</p>
                                <p class="text-xs text-gray-500">
                                    @if ($role === 'owner')
                                        Owners can always open every branch and see all branches together.
                                    @else
                                        Tick one or more. They will not be able to open any other branch. Leave all unticked: reporting roles can open every branch, others work at the main branch.
                                    @endif
                                </p>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                @foreach ($branches as $b)
                                    <label wire:key="ub-{{ $b->id }}" class="flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" wire:model="branchIds" value="{{ $b->id }}" class="rounded">
                                        {{ $b->name }}@if ($b->is_main) <span class="text-xs text-gray-500">(main)</span>@endif
                                    </label>
                                @endforeach
                            </div>
                            <x-input-error :messages="collect($errors->get('branchIds.*'))->flatten()->all()" class="mt-2" />
                        </x-card>
                    @endif

                    <x-card class="p-6 space-y-3">
                        <div>
                            <p class="text-sm font-medium text-gray-700">Extra permissions</p>
                            <p class="text-xs text-gray-500">Ticked and greyed boxes already come with the role. Tick others to give this person more.</p>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            @foreach ($grantable as $key => $label)
                                @php $inRole = in_array($key, $rolePerms); @endphp
                                <label wire:key="perm-{{ $key }}" class="flex items-center gap-2 text-sm {{ $inRole ? 'text-gray-400' : 'text-gray-700' }}">
                                    <input type="checkbox" class="rounded"
                                           @if ($inRole) checked disabled @else wire:model="extras" value="{{ $key }}" @endif>
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                        <x-input-error :messages="collect($errors->get('extras.*'))->flatten()->all()" class="mt-2" />
                    </x-card>
                </div>

                <div class="sticky bottom-0 flex gap-3 border-t border-gray-200 bg-white px-6 py-4">
                    <x-primary-button>{{ $editingId ? 'Update' : 'Add staff member' }}</x-primary-button>
                    <x-secondary-button type="button" wire:click="closeForm">Cancel</x-secondary-button>
                </div>
            </form>
        </x-slide-over>
    @endif
</div>
