<?php

use App\Models\AdminAuditLog;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\AdminService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Volt\Component;

new class extends Component
{
    public int $businessId;
    public ?int $resetId = null;
    public string $password = '';
    public bool $closing = false;
    public string $closeReason = '';

    public function mount(int $businessId): void
    {
        abort_unless(auth()->user()?->is_super_admin, 403);

        Business::findOrFail($businessId);
        $this->businessId = $businessId;
    }

    public function with(): array
    {
        abort_unless(auth()->user()?->is_super_admin, 403);

        $id = $this->businessId;
        $users = User::where('business_id', $id)->with('roles')->orderBy('name')->get();

        $sessions = DB::table('sessions')->whereIn('user_id', $users->pluck('id'))
            ->selectRaw('user_id, MAX(last_activity) as last')->groupBy('user_id')->pluck('last', 'user_id');

        $sales30 = Sale::where('business_id', $id)->where('status', 'completed')
            ->where('created_at', '>=', now()->subDays(30));

        return [
            'business' => Business::with('subscriptionPayments')->findOrFail($id),
            'users' => $users,
            'sessions' => $sessions,
            'stats' => [
                'sales' => (clone $sales30)->count(),
                'revenue' => (float) (clone $sales30)->sum('total'),
                'lastSale' => Sale::where('business_id', $id)->where('status', 'completed')->max('created_at'),
                'products' => Product::where('business_id', $id)->count(),
                'customers' => Customer::where('business_id', $id)->count(),
                'owed' => (float) Invoice::where('business_id', $id)->where('status', 'issued')
                    ->sum(DB::raw('total - amount_paid')),
                'expenses' => (float) Expense::where('business_id', $id)->where('status', 'recorded')
                    ->whereDate('expense_date', '>=', now()->startOfMonth()->toDateString())->sum('amount'),
            ],
            'logs' => AdminAuditLog::with(['admin', 'target'])->where('business_id', $id)->latest('id')->limit(10)->get(),
        ];
    }

    public function startReset(int $userId): void
    {
        abort_unless(auth()->user()?->is_super_admin, 403);

        User::where('business_id', $this->businessId)->where('is_super_admin', false)->findOrFail($userId);

        $this->resetErrorBag();
        $this->resetId = $userId;
        $this->password = '';
    }

    public function generate(): void
    {
        $this->password = Str::password(12, symbols: false);
    }

    public function cancelReset(): void
    {
        $this->reset('resetId', 'password');
        $this->resetErrorBag();
    }

    public function confirmReset(): void
    {
        AdminService::resetPassword(Business::findOrFail($this->businessId), (int) $this->resetId, $this->password);

        $this->cancelReset();
        session()->flash('done', 'Password reset. Pass the temporary password on securely; they were signed out everywhere.');
    }

    public function toggleUser(int $userId): void
    {
        AdminService::toggleUser(Business::findOrFail($this->businessId), $userId);
    }
    public function sendReset(int $userId): void
{
    abort_unless(auth()->user()?->is_super_admin, 403);

    $u = \App\Models\User::where('business_id', $this->businessId)->where('is_super_admin', false)->findOrFail($userId);

    $sent = \Illuminate\Support\Facades\Password::sendResetLink(['email' => $u->email])
        === \Illuminate\Support\Facades\Password::RESET_LINK_SENT;

    $this->dispatch('notify',
        message: $sent ? "Reset link sent to {$u->email}." : 'Could not send the link.',
        type: $sent ? 'success' : 'error');
}
public function closeAccount(): void
{
    abort_unless(auth()->user()?->is_super_admin, 403);

    $reason = trim($this->closeReason);
    if ($reason === '' || mb_strlen($reason) > 255) {
        $this->addError('closeReason', 'Give a reason (255 characters at most).');
        return;
    }

    \Illuminate\Support\Facades\DB::transaction(function () use ($reason) {
        $b = \App\Models\Business::findOrFail($this->businessId);

        $b->forceFill(['status' => 'suspended', 'billing_suspended' => false])->save();

        $ids = \App\Models\User::where('business_id', $b->id)->where('is_super_admin', false)->pluck('id');
        \App\Models\User::whereIn('id', $ids)->update(['is_active' => false]);
        \Illuminate\Support\Facades\DB::table('sessions')->whereIn('user_id', $ids)->delete();

        \App\Models\AdminAuditLog::create([
            'user_id' => auth()->id(), 'business_id' => $b->id,
            'action' => 'account_closed', 'detail' => $reason,
        ]);
    });

    $this->reset('closing', 'closeReason');
    $this->resetErrorBag();
    $this->dispatch('notify', message: 'Account closed. All its users are disabled.');
}

public function reopenAccount(): void
{
    abort_unless(auth()->user()?->is_super_admin, 403);

    \Illuminate\Support\Facades\DB::transaction(function () {
        $b = \App\Models\Business::findOrFail($this->businessId);
        $b->forceFill(['status' => 'active'])->save();
        \App\Models\User::where('business_id', $b->id)->where('is_super_admin', false)->update(['is_active' => true]);

        \App\Models\AdminAuditLog::create([
            'user_id' => auth()->id(), 'business_id' => $b->id,
            'action' => 'account_reopened', 'detail' => 'Reopened',
        ]);
    });

    $this->dispatch('notify', message: 'Account reopened. Disable individual users again if some should stay off.');
}
}; ?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <a href="{{ route('admin.businesses') }}" wire:navigate class="text-sm text-brand-600 hover:underline">&larr; All merchants</a>
        <x-badge :color="['active' => 'green', 'pending' => 'orange', 'suspended' => 'red', 'rejected' => 'gray'][$business->status] ?? 'gray'">{{ ucfirst($business->status) }}</x-badge>
    </div>

    @if (session('done'))
        <p class="rounded-lg bg-green-50 px-4 py-2 text-sm text-green-800">{{ session('done') }}</p>
    @endif

    <x-card class="p-6 space-y-3 border-red-200">
    <h4 class="font-medium text-red-700">Close this account</h4>
    <p class="text-sm text-gray-600">
        Locks the shop out and disables every user, and ends their sessions. Sales, stock and invoices are kept for the records, and you can reopen the account later.
    </p>

    @if (! $closing)
        <button type="button" wire:click="$set('closing', true)" class="rounded-lg border border-red-500 px-4 py-2 text-sm font-semibold text-red-600 hover:bg-red-50">Close account</button>
        <button type="button" wire:click="reopenAccount" wire:confirm="Reopen this account and re-enable all its users?" class="ml-2 text-sm text-gray-600 hover:underline">Reopen account</button>
    @else
        <x-text-input wire:model="closeReason" type="text" maxlength="255" placeholder="Reason" class="block w-full" />
        <x-input-error :messages="$errors->get('closeReason')" />
        <div class="flex gap-3">
            <button type="button" wire:click="closeAccount" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">Confirm close</button>
            <x-secondary-button type="button" wire:click="$set('closing', false)">Cancel</x-secondary-button>
        </div>
    @endif
</x-card>
    <x-card class="p-6">
        <h3 class="text-xl font-semibold text-gray-900">{{ $business->name }}</h3>
        <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
            <div>
                <p class="text-xs uppercase text-gray-500">Business</p>
                <p>{{ ucwords(str_replace('_', ' ', $business->business_type)) }}, {{ $business->category }}</p>
                <p>{{ $business->phone }} {{ $business->email }}</p>
                <p>{{ collect([$business->address, $business->county])->filter()->implode(', ') }}</p>
            </div>
            <div>
                <p class="text-xs uppercase text-gray-500">Owner (KYC)</p>
                <p>{{ $business->owner_name }}</p>
                <p>ID {{ $business->owner_id_number }}</p>
                <p>{{ $business->owner_phone }}, born {{ $business->owner_dob?->format('d M Y') ?? '-' }}</p>
            </div>
            <div>
                <p class="text-xs uppercase text-gray-500">Subscription</p>
                <p>Fee: {{ (float) $business->monthly_fee > 0 ? 'Kshs ' . number_format($business->monthly_fee, 2) . '/month' : 'none' }}</p>
                <p>Paid until: {{ $business->subscription_ends_at?->format('d M Y') ?? 'no billing' }}</p>
                @if ($business->status_reason)
                    <p class="text-red-600">{{ $business->status_reason }}</p>
                @endif
            </div>
        </div>
    </x-card>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <x-stat-card label="Sales (30 days)" :value="$stats['sales']" :caption="'Kshs ' . number_format($stats['revenue'], 2)" />
        <x-stat-card label="Last sale" :value="$stats['lastSale'] ? \Carbon\Carbon::parse($stats['lastSale'])->diffForHumans() : 'Never'" caption="Completed sales only" />
        <x-stat-card label="Products" :value="$stats['products']" caption="Including disabled" />
        <x-stat-card label="Customers" :value="$stats['customers']" caption="Including disabled" />
        <x-stat-card label="Owed to them" :value="'Kshs ' . number_format($stats['owed'], 2)" caption="Open invoices" />
        <x-stat-card label="Expenses (month)" :value="'Kshs ' . number_format($stats['expenses'], 2)" caption="Voided excluded" />
    </div>

    <x-card class="overflow-x-auto">
        <div class="px-4 py-3 border-b border-gray-200 font-medium text-gray-900">Staff</div>
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <tbody class="divide-y divide-gray-100">
                @foreach ($users as $u)
                    <tr wire:key="mu-{{ $u->id }}" class="{{ $u->is_active ? '' : 'opacity-60' }}">
                        <td class="px-4 py-3 font-medium">{{ $u->name }}</td>
                        <td class="px-4 py-3">{{ $u->email }}</td>
                        <td class="px-4 py-3"><x-badge color="orange">{{ ucfirst($u->getRoleNames()->first() ?? 'none') }}</x-badge></td>
                        <td class="px-4 py-3 text-gray-500">
                            {{ isset($sessions[$u->id]) ? 'Active ' . \Carbon\Carbon::createFromTimestamp($sessions[$u->id])->diffForHumans() : 'No recent session' }}
                        </td>
                        <td class="px-4 py-3"><x-badge :color="$u->is_active ? 'green' : 'red'">{{ $u->is_active ? 'Active' : 'Disabled' }}</x-badge></td>
                        <td class="px-4 py-3 text-right space-x-3 whitespace-nowrap">
                            @unless ($u->is_super_admin)
                                <button wire:click="startReset({{ $u->id }})" class="text-brand-600 hover:underline">Reset password</button>
                                <button wire:click="sendReset({{ $u->id }})" class="text-gray-600 hover:underline">Send reset link</button>
                                <button wire:click="toggleUser({{ $u->id }})" wire:confirm="{{ $u->is_active ? 'Disable' : 'Enable' }} {{ $u->name }}?"
                                        class="text-gray-600 hover:underline">{{ $u->is_active ? 'Disable' : 'Enable' }}</button>
                            @endunless
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-card>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <x-card class="overflow-x-auto">
            <div class="px-4 py-3 border-b border-gray-200 font-medium text-gray-900">Subscription payments</div>
            <table class="min-w-full text-sm">
                <tbody class="divide-y divide-gray-100">
                    @forelse ($business->subscriptionPayments->sortByDesc('id')->take(5) as $p)
                        <tr wire:key="msp-{{ $p->id }}">
                            <td class="px-4 py-2">{{ $p->created_at->format('d M Y') }}</td>
                            <td class="px-4 py-2 text-gray-500">{{ $p->months }} month(s), {{ $p->method }}</td>
                            <td class="px-4 py-2 text-right font-semibold">{{ number_format($p->amount, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td class="px-4 py-6 text-center text-gray-500">No payments yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-card>

        <x-card class="overflow-x-auto">
            <div class="px-4 py-3 border-b border-gray-200 font-medium text-gray-900">Admin actions on this merchant</div>
            <table class="min-w-full text-sm">
                <tbody class="divide-y divide-gray-100">
                    @forelse ($logs as $l)
                        <tr wire:key="log-{{ $l->id }}">
                            <td class="px-4 py-2 whitespace-nowrap">{{ $l->created_at->format('d M, H:i') }}</td>
                            <td class="px-4 py-2">{{ $l->detail }}</td>
                            <td class="px-4 py-2 text-gray-500">{{ $l->admin?->name }}</td>
                        </tr>
                    @empty
                        <tr><td class="px-4 py-6 text-center text-gray-500">Nothing yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-card>
    </div>

    @if ($resetId)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/40" wire:click="cancelReset"></div>

            <x-card class="relative w-full max-w-md p-6 space-y-4 shadow-xl">
                <h3 class="text-lg font-semibold text-gray-900">Reset password</h3>
                <p class="text-sm text-gray-600">They are signed out everywhere and must use this temporary password. Ask them to change it under Profile.</p>
                <div>
                    <x-input-label for="rp" value="Temporary password *" />
                    <div class="mt-1 flex gap-2">
                        <x-text-input wire:model="password" id="rp" type="text" autocomplete="off" class="block w-full" />
                        <x-secondary-button type="button" wire:click="generate">Generate</x-secondary-button>
                    </div>
                    <x-input-error :messages="$errors->get('password')" class="mt-2" />
                </div>
                <div class="flex gap-3">
                    <x-primary-button type="button" wire:click="confirmReset">Reset password</x-primary-button>
                    <x-secondary-button type="button" wire:click="cancelReset">Cancel</x-secondary-button>
                </div>
            </x-card>
        </div>
    @endif
</div>
