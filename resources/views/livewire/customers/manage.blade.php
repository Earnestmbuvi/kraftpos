<?php

use App\Models\Customer;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use App\Models\Invoice;

new class extends Component
{
    use WithPagination;

    public string $search = '';
    public bool $showForm = false;
    public ?int $editingId = null;

    public string $name = '';
    public ?string $phone = null;
    public ?string $email = null;
    public ?string $address = null;
    public ?string $notes = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('manage-customers'), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $term = trim($this->search);

        return [
            'customers' => Customer::query()
                ->when($term !== '', fn ($q) => $q->where(function ($q) use ($term) {
                    $q->where('name', 'like', "%{$term}%")
                      ->orWhere('phone', 'like', "%{$term}%")
                      ->orWhere('email', 'like', "%{$term}%");
                }))
                ->orderBy('name')->paginate(15),
                'owed' => Invoice::withoutGlobalScope(\App\Models\Scopes\BranchViewScope::class)->where('status', 'issued')
    ->selectRaw('customer_id, SUM(total - amount_paid) as owed')
    ->groupBy('customer_id')->pluck('owed', 'customer_id'),
        ];
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()->can('manage-customers'), 403);
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'phone', 'email', 'address', 'notes');
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
        $c = Customer::findOrFail($id); // tenant scope blocks other shops' IDs

        $this->resetForm();
        $this->editingId = $c->id;
        $this->name = $c->name;
        $this->phone = $c->phone;
        $this->email = $c->email;
        $this->address = $c->address;
        $this->notes = $c->notes;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorizeManage();

        foreach (['phone', 'email', 'address', 'notes'] as $f) {
            $this->$f = trim((string) $this->$f) === '' ? null : trim($this->$f);
        }
        $this->name = trim($this->name);

        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:20',
                Rule::unique('customers', 'phone')
                    ->where('business_id', auth()->user()->business_id)
                    ->ignore($this->editingId)],
            'email' => ['nullable', 'email', 'max:100'],
            'address' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $data = [
            'name' => $this->name, 'phone' => $this->phone, 'email' => $this->email,
            'address' => $this->address, 'notes' => $this->notes,
        ];

        $editing = (bool) $this->editingId;

        $editing
            ? Customer::findOrFail($this->editingId)->update($data)
            : Customer::create($data);

        $this->dispatch('notify', message: $editing ? 'Customer updated.' : 'Customer added.');
        $this->closeForm();
    }

    public function toggle(int $id): void
    {
        $this->authorizeManage();
        $c = Customer::findOrFail($id);
        $c->is_active ? $c->disable() : $c->enable();
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row gap-3 sm:items-center">
        <x-text-input wire:model.live.debounce.300ms="search" type="search"
                      placeholder="Search by name, phone or email..." class="block w-full" />
        <x-primary-button type="button" wire:click="openCreate" class="whitespace-nowrap">
            + Add customer
        </x-primary-button>
    </div>

    <x-card class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Phone</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Owes</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse ($customers as $c)
                    <tr wire:key="cust-{{ $c->id }}" class="{{ $c->is_active ? '' : 'opacity-50' }}">
                        <td class="px-4 py-3 font-medium">{{ $c->name }}</td>
                        <td class="px-4 py-3">{{ $c->phone ?? '-' }}</td>
                        <td class="px-4 py-3">{{ $c->email ?? '-' }}</td>
                        @php $o = (float) ($owed[$c->id] ?? 0); @endphp
                        <td class="px-4 py-3 text-right {{ $o > 0 ? 'font-semibold text-red-600' : 'text-gray-400' }}">{{ $o > 0 ? number_format($o, 2) : '-' }}</td>
                        <td class="px-4 py-3">
                            <x-badge :color="$c->is_active ? 'green' : 'red'">{{ $c->is_active ? 'Active' : 'Disabled' }}</x-badge>
                        </td>
                        <td class="px-4 py-3 text-right space-x-3 whitespace-nowrap">
                            <button wire:click="edit({{ $c->id }})" class="text-brand-600 hover:underline">Edit</button>
                            <button wire:click="toggle({{ $c->id }})" class="text-gray-600 hover:underline">
                                {{ $c->is_active ? 'Disable' : 'Enable' }}
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">No customers yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-card>

    {{ $customers->links() }}

    @if ($showForm)
        <x-slide-over :title="$editingId ? 'Edit customer' : 'Add customer'" width="max-w-xl">
            <form wire:submit="save" class="flex flex-1 flex-col">
                <div class="flex-1 space-y-4 p-6">
                    <x-card class="p-6 space-y-4">
                        <div>
                            <x-input-label for="c_name" value="Name *" />
                            <x-text-input wire:model="name" id="c_name" type="text" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="c_phone" value="Phone *" />
                            <x-text-input wire:model="phone" id="c_phone" type="text" class="mt-1 block w-full" placeholder="07..." />
                            <x-input-error :messages="$errors->get('phone')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="c_email" value="Email" />
                            <x-text-input wire:model="email" id="c_email" type="email" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('email')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="c_address" value="Address *" />
                            <x-text-input wire:model="address" id="c_address" type="text" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('address')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="c_notes" value="Notes" />
                            <x-text-input wire:model="notes" id="c_notes" type="text" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                        </div>
                    </x-card>
                </div>

                <div class="sticky bottom-0 flex gap-3 border-t border-gray-200 bg-white px-6 py-4">
                    <x-primary-button>{{ $editingId ? 'Update' : 'Save' }}</x-primary-button>
                    <x-secondary-button type="button" wire:click="closeForm">Cancel</x-secondary-button>
                </div>
            </form>
        </x-slide-over>
    @endif
</div>
