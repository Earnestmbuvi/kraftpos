<?php

use App\Models\Business;
use App\Models\User;
use App\Services\BusinessSetup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    private const ALLOWED = [
        'activate' => ['pending', 'suspended', 'rejected'],
        'reject' => ['pending'],
        'suspend' => ['active'],
    ];

    private const FIELDS = [
        'name', 'business_type', 'category', 'phone', 'email', 'address', 'county',
        'expected_branches', 'notes', 'owner_name', 'owner_id_number', 'owner_phone',
    ];

    public string $status = 'all';
    public string $search = '';
    public bool $showForm = false;
    public ?int $editingId = null;

    // business
    public string $name = '';
    public string $business_type = 'sole_proprietor';
    public string $category = '';
    public string $phone = '';
    public string $email = '';
    public string $address = '';
    public string $county = '';
    public $expected_branches = 1;
    public ?string $notes = null;

    // owner details (KYC)
    public string $owner_name = '';
    public string $owner_id_number = '';
    public string $owner_phone = '';
    public string $owner_dob = '';

    // owner login (create only)
    public string $login_email = '';
    public string $password = '';
    public bool $activate_now = true;

    // review
    public ?int $reviewId = null;
    public string $reviewAction = '';
    public string $reason = '';

    public function mount(): void
    {
        $this->guard();
    }

    private function guard(): void
    {
        abort_unless(auth()->user()?->is_super_admin, 403);
    }

    public function updatedStatus(): void { $this->resetPage(); }
    public function updatedSearch(): void { $this->resetPage(); }

    public function with(): array
    {
        $term = trim($this->search);

        return [
            'businesses' => Business::withCount('users')
                ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
                ->when($term !== '', fn ($q) => $q->where(function ($q) use ($term) {
                    $q->where('name', 'like', "%{$term}%")
                      ->orWhere('phone', 'like', "%{$term}%")
                      ->orWhere('owner_name', 'like', "%{$term}%")
                      ->orWhere('owner_id_number', 'like', "%{$term}%");
                }))
                ->latest('id')->paginate(15),
            'counts' => Business::selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status'),
        ];
    }

    private function resetForm(): void
    {
        $this->reset(
            'editingId', 'name', 'category', 'phone', 'email', 'address', 'county', 'notes',
            'owner_name', 'owner_id_number', 'owner_phone', 'owner_dob', 'login_email', 'password'
        );
        $this->business_type = 'sole_proprietor';
        $this->expected_branches = 1;
        $this->activate_now = true;
        $this->resetErrorBag();
    }

    public function openCreate(): void
    {
        $this->guard();
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
        $this->guard();
        $b = Business::findOrFail($id);

        $this->resetForm();
        $this->editingId = $b->id;

        // Older merchants may have blanks; show them as empty so they get filled in
        foreach (self::FIELDS as $f) {
            $this->$f = $f === 'notes' ? $b->$f : ($b->$f ?? ($f === 'expected_branches' ? 1 : ''));
        }
        $this->owner_dob = $b->owner_dob?->toDateString() ?? '';
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->guard();

        foreach (['name', 'category', 'phone', 'email', 'address', 'county', 'owner_name',
                  'owner_id_number', 'owner_phone'] as $f) {
            $this->$f = trim((string) $this->$f);
        }
        $this->notes = trim((string) $this->notes) === '' ? null : trim($this->notes);
        $this->login_email = strtolower(trim($this->login_email));

        $rules = [
            'name' => ['required', 'string', 'max:150'],
            'business_type' => ['required', Rule::in(['sole_proprietor', 'partnership', 'limited_company'])],
            'category' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['required', 'email', 'max:150'],
            'address' => ['required', 'string', 'max:255'],
            'county' => ['required', 'string', 'max:100'],
            'expected_branches' => ['required', 'integer', 'min:1', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'owner_name' => ['required', 'string', 'max:100'],
            'owner_id_number' => ['required', 'string', 'max:30'],
            'owner_phone' => ['required', 'string', 'max:20'],
            'owner_dob' => ['required', 'date', 'before:today'],
        ];

        if (! $this->editingId) {
            $rules['login_email'] = ['required', 'email', 'max:150', Rule::unique('users', 'email')];
            $rules['password'] = ['required', 'string', 'min:8', 'max:100'];
        }

        $this->validate($rules);

        $data = [];
        foreach (self::FIELDS as $f) {
            $data[$f] = $this->$f;
        }
        $data['owner_dob'] = $this->owner_dob;

        if ($this->editingId) {
            Business::findOrFail($this->editingId)->update($data);
            $this->dispatch('notify', message: $this->editingId ? 'Merchant updated.' : 'Merchant created.');
            $this->closeForm();
            return;
        }

        DB::transaction(function () use ($data) {
            $business = Business::create($data);

            $business->status = $this->activate_now ? 'active' : 'pending';
            if ($this->activate_now) {
                $business->reviewed_by = auth()->id();
                $business->reviewed_at = now();
            }
            $business->subscription_ends_at = today()->addDays((int) config('billing.trial_days'));
            $business->save();

            BusinessSetup::run($business); // units, options, payment methods

            $owner = new User();
            $owner->name = $this->owner_name;
            $owner->email = $this->login_email;
            $owner->password = $this->password; // hashed by the model cast
            $owner->business_id = $business->id;
            $owner->is_active = true;
            $owner->email_verified_at = now();
            $owner->save();
            $owner->assignRole('owner');
        });
        $this->dispatch('notify', message: $this->editingId ? 'Merchant updated.' : 'Merchant created.');
        $this->closeForm();
    }

    private function transition(Business $b, string $action, ?string $reason): void
    {
        if (! in_array($b->status, self::ALLOWED[$action])) {
            $this->addError('action', 'That merchant cannot be moved that way from its current status.');
            return;
        }

        $b->status = ['activate' => 'active', 'reject' => 'rejected', 'suspend' => 'suspended'][$action];
        $b->billing_suspended = false; // a hand decision replaces any automatic suspension
        $b->status_reason = $reason;
        $b->reviewed_by = auth()->id();
        $b->reviewed_at = now();
        $b->save();

        $this->resetErrorBag();
    }

    public function activate(int $id): void
    {
        $this->guard();
        $this->transition(Business::findOrFail($id), 'activate', null);
    }

    public function startReview(int $id, string $action): void
    {
        $this->guard();
        abort_unless(in_array($action, ['reject', 'suspend']), 422);

        $b = Business::findOrFail($id);
        if (! in_array($b->status, self::ALLOWED[$action])) {
            $this->addError('action', 'That merchant cannot be moved that way from its current status.');
            return;
        }

        $this->resetErrorBag();
        $this->reviewId = $b->id;
        $this->reviewAction = $action;
        $this->reason = '';
    }

    public function cancelReview(): void
    {
        $this->reset('reviewId', 'reviewAction', 'reason');
        $this->resetErrorBag();
    }

    public function confirmReview(): void
    {
        $this->guard();

        $this->validate(['reason' => ['required', 'string', 'max:255']], [
            'reason.required' => 'Please give a reason.',
        ]);

        $this->transition(Business::findOrFail($this->reviewId), $this->reviewAction, trim($this->reason));
        $this->cancelReview();
    }
}; ?>

<div class="space-y-6">
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach (['pending' => 'Pending review', 'active' => 'Active', 'suspended' => 'Suspended', 'rejected' => 'Rejected'] as $key => $label)
            <x-stat-card :label="$label" :value="$counts[$key] ?? 0" caption="Merchants" />
        @endforeach
    </div>

    <div class="flex flex-col sm:flex-row gap-3 sm:items-center">
        <x-text-input wire:model.live.debounce.300ms="search" type="search"
                      placeholder="Search by business, phone, owner or ID number..." class="block w-full" />
        <select wire:model.live="status" class="sm:w-48 text-sm">
            <option value="all">All statuses</option>
            <option value="pending">Pending</option>
            <option value="active">Active</option>
            <option value="suspended">Suspended</option>
            <option value="rejected">Rejected</option>
        </select>
        <x-primary-button type="button" wire:click="openCreate" class="whitespace-nowrap">+ Add merchant</x-primary-button>
    </div>

    <x-input-error :messages="$errors->get('action')" />

    @if ($showForm)
        <x-card class="p-6">
            <form wire:submit="save" class="space-y-6">

                <h3 class="text-lg font-medium text-gray-900">{{ $editingId ? 'Edit merchant details' : 'Add a merchant' }}</h3>

                <div>
                    <p class="mb-3 text-sm font-semibold text-gray-700">Business</p>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <x-input-label for="b_name" value="Business name *" />
                            <x-text-input wire:model="name" id="b_name" type="text" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="b_type" value="Business type *" />
                            <select wire:model="business_type" id="b_type" class="mt-1 block w-full">
                                <option value="sole_proprietor">Sole proprietor</option>
                                <option value="partnership">Partnership</option>
                                <option value="limited_company">Limited company</option>
                            </select>
                            <x-input-error :messages="$errors->get('business_type')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="b_cat" value="Category (e.g. pharmacy) *" />
                            <x-text-input wire:model="category" id="b_cat" type="text" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('category')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="b_phone" value="Phone *" />
                            <x-text-input wire:model="phone" id="b_phone" type="text" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('phone')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="b_email" value="Business email *" />
                            <x-text-input wire:model="email" id="b_email" type="email" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('email')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="b_branches" value="Expected branches / tills *" />
                            <x-text-input wire:model="expected_branches" id="b_branches" type="number" min="1" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('expected_branches')" class="mt-2" />
                        </div>
                        <div class="md:col-span-2">
                            <x-input-label for="b_address" value="Address *" />
                            <x-text-input wire:model="address" id="b_address" type="text" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('address')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="b_county" value="County *" />
                            <x-text-input wire:model="county" id="b_county" type="text" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('county')" class="mt-2" />
                        </div>
                        <div class="md:col-span-3">
                            <x-input-label for="b_notes" value="Internal notes (optional, merchants never see these)" />
                            <x-text-input wire:model="notes" id="b_notes" type="text" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                        </div>
                    </div>
                </div>

                <div>
                    <p class="mb-3 text-sm font-semibold text-gray-700">Owner (KYC)</p>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <x-input-label for="o_name" value="Full name *" />
                            <x-text-input wire:model="owner_name" id="o_name" type="text" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('owner_name')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="o_id" value="National ID / passport *" />
                            <x-text-input wire:model="owner_id_number" id="o_id" type="text" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('owner_id_number')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="o_phone" value="Owner phone *" />
                            <x-text-input wire:model="owner_phone" id="o_phone" type="text" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('owner_phone')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="o_dob" value="Date of birth *" />
                            <x-text-input wire:model="owner_dob" id="o_dob" type="date" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('owner_dob')" class="mt-2" />
                        </div>
                    </div>
                </div>

                @unless ($editingId)
                    <div>
                        <p class="mb-3 text-sm font-semibold text-gray-700">Owner's CraftPOS login</p>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <x-input-label for="l_email" value="Login email *" />
                                <x-text-input wire:model="login_email" id="l_email" type="email" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('login_email')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="l_pass" value="Temporary password *" />
                                <x-text-input wire:model="password" id="l_pass" type="password" autocomplete="new-password" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('password')" class="mt-2" />
                            </div>
                            <label class="flex items-center gap-2 pt-7 text-sm text-gray-700">
                                <input type="checkbox" wire:model="activate_now" class="rounded">
                                Activate now (KYC checked)
                            </label>
                        </div>
                    </div>
                @endunless

                <div class="flex gap-3">
                    <x-primary-button>{{ $editingId ? 'Save changes' : 'Create merchant' }}</x-primary-button>
                    <x-secondary-button type="button" wire:click="closeForm">Cancel</x-secondary-button>
                </div>
            </form>
        </x-card>
    @endif

    <x-card class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Merchant</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Owner</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Phone</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Users</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Joined</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse ($businesses as $b)
                    @php $color = ['active' => 'green', 'pending' => 'orange', 'suspended' => 'red', 'rejected' => 'gray'][$b->status] ?? 'gray'; @endphp
                    <tr wire:key="biz-{{ $b->id }}">
                        <td class="px-4 py-3">
                            <p class="font-medium text-gray-900">{{ $b->name }}</p>
                            <p class="text-xs text-gray-500">{{ collect([$b->category, $b->county])->filter()->implode(', ') }}</p>
                        </td>
                        <td class="px-4 py-3">
                            {{ $b->owner_name }}
                            <p class="text-xs text-gray-500">ID {{ $b->owner_id_number }}</p>
                        </td>
                        <td class="px-4 py-3">{{ $b->phone }}</td>
                        <td class="px-4 py-3 text-right">{{ $b->users_count }}</td>
                        <td class="px-4 py-3">
                            <x-badge :color="$color">{{ ucfirst($b->status) }}</x-badge>
                            @if ($b->status_reason)
                                <p class="mt-1 max-w-xs text-xs text-gray-500">{{ $b->status_reason }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $b->created_at->format('d M Y') }}</td>
                        <td class="px-4 py-3 text-right space-x-3 whitespace-nowrap">
                            <a href="{{ route('admin.merchant', $b) }}" wire:navigate class="text-gray-700 hover:underline">Open</a>
                            <button wire:click="edit({{ $b->id }})" class="text-brand-700 hover:underline">Edit</button>
                            @if (in_array($b->status, ['pending', 'suspended', 'rejected']))
                                <button wire:click="activate({{ $b->id }})" class="text-green-700 hover:underline">
                                    {{ $b->status === 'pending' ? 'Activate' : 'Reactivate' }}
                                </button>
                            @endif
                            @if ($b->status === 'pending')
                                <button wire:click="startReview({{ $b->id }}, 'reject')" class="text-gray-600 hover:underline">Reject</button>
                            @endif
                            @if ($b->status === 'active')
                                <button wire:click="startReview({{ $b->id }}, 'suspend')" class="text-red-600 hover:underline">Suspend</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-6 text-center text-gray-500">No merchants found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-card>

    {{ $businesses->links() }}

    @if ($reviewId)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/40" wire:click="cancelReview"></div>

            <x-card class="relative w-full max-w-md p-6 space-y-4 shadow-xl">
                <h3 class="text-lg font-semibold text-gray-900">{{ $reviewAction === 'suspend' ? 'Suspend this merchant?' : 'Reject this application?' }}</h3>
                <p class="text-sm text-gray-600">
                    @if ($reviewAction === 'suspend')
                        Everyone in the shop is logged out on their next click. Their data stays safe, and you can reactivate them at any time.
                    @else
                        They stay unable to log in. You can still reactivate them later.
                    @endif
                </p>
                <div>
                    <x-input-label for="reason" value="Reason *" />
                    <x-text-input wire:model="reason" id="reason" type="text" maxlength="255" class="mt-1 block w-full"
                                  placeholder="e.g. Unpaid subscription for October" />
                    <x-input-error :messages="$errors->get('reason')" class="mt-2" />
                </div>
                <div class="flex gap-3">
                    <button type="button" wire:click="confirmReview"
                            class="inline-flex items-center px-4 py-2 rounded-lg bg-red-600 text-sm font-semibold text-white hover:bg-red-700">
                        {{ $reviewAction === 'suspend' ? 'Suspend' : 'Reject' }}
                    </button>
                    <x-secondary-button type="button" wire:click="cancelReview">Cancel</x-secondary-button>
                </div>
            </x-card>
        </div>
    @endif
</div>
