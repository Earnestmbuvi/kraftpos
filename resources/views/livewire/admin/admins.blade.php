<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Livewire\Volt\Component;

new class extends Component
{
    public string $name = '';
    public string $email = '';
    public string $password = '';

    private function guard(): void
    {
        abort_unless(auth()->user()?->is_super_admin, 403);
    }

    public function mount(): void
    {
        $this->guard();
    }

    public function with(): array
    {
        $this->guard();

        return ['admins' => User::where('is_super_admin', true)->orderBy('name')->get()];
    }

    public function add(): void
    {
        $this->guard();

        $this->email = strtolower(trim($this->email));
        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'max:100'],
        ], ['password.min' => 'Use at least 12 characters.']);

        $u = new User();
        $u->forceFill([
            'name' => trim($this->name), 'email' => $this->email, 'password' => $this->password,
            'business_id' => null, 'is_active' => true, 'is_super_admin' => true, 'email_verified_at' => now(),
        ])->save();

        $this->reset('name', 'email', 'password');
        $this->dispatch('notify', message: 'Super admin added. Ask them to change the password after logging in.');
    }

    public function toggle(int $id): void
    {
        $this->guard();
        $u = User::where('is_super_admin', true)->findOrFail($id);

        if ($u->id === auth()->id()) {
            $this->addError('action', 'You cannot disable your own account.');
            return;
        }

        $u->is_active = ! $u->is_active;
        $u->save();

        if (! $u->is_active) {
            DB::table('sessions')->where('user_id', $u->id)->delete();
        }
        $this->resetErrorBag();
    }

    public function sendReset(int $id): void
    {
        $this->guard();
        $u = User::where('is_super_admin', true)->findOrFail($id);

        $sent = Password::sendResetLink(['email' => $u->email]) === Password::RESET_LINK_SENT;
        $this->dispatch('notify', message: $sent ? "Reset link sent to {$u->email}." : 'Could not send the link.', type: $sent ? 'success' : 'error');
    }
}; ?>

<div class="space-y-6">
    <x-card class="p-6">
        <form wire:submit="add" class="space-y-4">
            <h3 class="text-lg font-medium text-gray-900">Add a super admin</h3>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div><x-input-label for="sa_name" value="Name *" /><x-text-input wire:model="name" id="sa_name" type="text" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('name')" class="mt-2" /></div>
                <div><x-input-label for="sa_email" value="Email *" /><x-text-input wire:model="email" id="sa_email" type="email" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('email')" class="mt-2" /></div>
                <div><x-input-label for="sa_pw" value="Temporary password (12+ characters) *" /><x-text-input wire:model="password" id="sa_pw" type="password" autocomplete="new-password" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('password')" class="mt-2" /></div>
            </div>
            <x-primary-button>Add super admin</x-primary-button>
        </form>
    </x-card>

    <x-input-error :messages="$errors->get('action')" />

    <x-card class="divide-y divide-gray-100">
        @foreach ($admins as $a)
            <div wire:key="sa-{{ $a->id }}" class="flex items-center justify-between gap-4 p-4 text-sm {{ $a->is_active ? '' : 'opacity-50' }}">
                <div>
                    <p class="font-semibold">{{ $a->name }}@if ($a->id === auth()->id()) <span class="text-xs text-gray-500">(you)</span>@endif</p>
                    <p class="text-gray-500">{{ $a->email }}</p>
                </div>
                <div class="space-x-3 whitespace-nowrap">
                    <x-badge :color="$a->is_active ? 'green' : 'red'">{{ $a->is_active ? 'Active' : 'Disabled' }}</x-badge>
                    <button wire:click="sendReset({{ $a->id }})" class="text-gray-600 hover:underline">Send reset link</button>
                    @if ($a->id !== auth()->id())
                        <button wire:click="toggle({{ $a->id }})" class="text-gray-600 hover:underline">{{ $a->is_active ? 'Disable' : 'Enable' }}</button>
                    @endif
                </div>
            </div>
        @endforeach
    </x-card>
</div>
