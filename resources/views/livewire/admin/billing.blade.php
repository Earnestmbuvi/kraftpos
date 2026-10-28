<?php

use App\Models\Business;
use App\Models\SubscriptionPayment;
use App\Services\BillingService;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';
    public string $mode = '';          // payment | terms
    public ?int $businessId = null;

    public $months = 1;
    public $amount = '';
    public string $method = 'M-Pesa';
    public string $reference = '';
    public string $note = '';

    public $monthly_fee = 0;
    public string $ends_at = '';

    public function mount(): void
    {
        $this->guard();
    }

    private function guard(): void
    {
        abort_unless(auth()->user()?->is_super_admin, 403);
    }

    public function updatedSearch(): void { $this->resetPage(); }

    public function updatedMonths(): void
    {
        $fee = (float) Business::find($this->businessId)?->monthly_fee;
        if ($fee > 0 && (int) $this->months > 0) {
            $this->amount = (string) round($fee * (int) $this->months, 2);
        }
    }

    public function with(): array
    {
        $term = trim($this->search);

        return [
            'methods' => BillingService::METHODS,
            'selected' => $this->businessId ? Business::find($this->businessId) : null,
            'businesses' => Business::query()
                ->when($term !== '', fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('owner_name', 'like', "%{$term}%"))
                ->orderByRaw('subscription_ends_at IS NULL')->orderBy('subscription_ends_at')
                ->paginate(15),
            'monthlyIncome' => (float) Business::where('status', 'active')->sum('monthly_fee'),
            'dueSoon' => Business::where('status', 'active')->whereNotNull('subscription_ends_at')
                ->whereDate('subscription_ends_at', '<=', today()->addDays((int) config('billing.warn_days')))->count(),
            'billingSuspended' => Business::where('billing_suspended', true)->count(),
            'recent' => SubscriptionPayment::with(['business', 'recorder'])->latest('id')->limit(10)->get(),
        ];
    }

    public function openPayment(int $id): void
    {
        $this->guard();
        $b = Business::findOrFail($id);

        $this->resetErrorBag();
        $this->reset('reference', 'note');
        $this->businessId = $b->id;
        $this->mode = 'payment';
        $this->months = 1;
        $this->method = 'M-Pesa';
        $this->amount = (float) $b->monthly_fee > 0 ? (string) $b->monthly_fee : '';
    }

    public function openTerms(int $id): void
    {
        $this->guard();
        $b = Business::findOrFail($id);

        $this->resetErrorBag();
        $this->businessId = $b->id;
        $this->mode = 'terms';
        $this->monthly_fee = $b->monthly_fee;
        $this->ends_at = $b->subscription_ends_at?->toDateString() ?? '';
    }

    public function close(): void
    {
        $this->reset('mode', 'businessId', 'amount', 'reference', 'note', 'ends_at');
        $this->months = 1;
        $this->resetErrorBag();
    }

    public function savePayment(): void
    {
        $this->guard();

        BillingService::recordPayment(
            Business::findOrFail($this->businessId),
            (int) $this->months, (float) $this->amount, $this->method, $this->reference, $this->note
        );
        $this->dispatch('notify', message: 'Payment recorded.');
        $this->close();
    }

    public function saveTerms(): void
    {
        $this->guard();

        $this->validate([
            'monthly_fee' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'ends_at' => ['nullable', 'date'],
        ]);

        $b = Business::findOrFail($this->businessId);
        $b->monthly_fee = $this->monthly_fee;
        $b->subscription_ends_at = $this->ends_at ?: null; // empty means no billing
        $b->save();
        $this->dispatch('notify', message: 'Payment recorded.');
        $this->close();
    }
}; ?>

<div class="space-y-6">
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <x-stat-card label="Monthly income" :value="'Kshs ' . number_format($monthlyIncome, 2)" caption="Fees of active merchants" />
        <x-stat-card label="Due soon" :value="$dueSoon" caption="Ending within {{ config('billing.warn_days') }} days or already past" />
        <x-stat-card label="Billing-suspended" :value="$billingSuspended" caption="Locked out for non-payment" />
    </div>

    <x-text-input wire:model.live.debounce.300ms="search" type="search"
                  placeholder="Search by business or owner..." class="block w-full" />

    <x-card class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Merchant</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Monthly fee</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Paid until</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse ($businesses as $b)
                    @php
                        $state = $b->subscription_state;
                        $stateColor = ['ok' => 'green', 'expiring' => 'orange', 'grace' => 'red', 'none' => 'gray'][$state];
                        $statusColor = ['active' => 'green', 'pending' => 'orange', 'suspended' => 'red', 'rejected' => 'gray'][$b->status] ?? 'gray';
                    @endphp
                    <tr wire:key="bill-{{ $b->id }}">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $b->name }}</td>
                        <td class="px-4 py-3">
                            <x-badge :color="$statusColor">{{ ucfirst($b->status) }}</x-badge>
                            @if ($b->billing_suspended) <span class="ml-1 text-xs text-red-600">for non-payment</span> @endif
                        </td>
                        <td class="px-4 py-3 text-right">{{ (float) $b->monthly_fee > 0 ? number_format($b->monthly_fee, 2) : '-' }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            @if ($b->subscription_ends_at)
                                {{ $b->subscription_ends_at->format('d M Y') }}
                                <x-badge :color="$stateColor">{{ ['ok' => 'Paid up', 'expiring' => 'Ending soon', 'grace' => 'In grace period'][$state] ?? '' }}</x-badge>
                            @else
                                <span class="text-gray-500">No billing</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right space-x-3 whitespace-nowrap">
                            <button wire:click="openPayment({{ $b->id }})" class="text-brand-600 hover:underline">Record payment</button>
                            <button wire:click="openTerms({{ $b->id }})" class="text-gray-600 hover:underline">Fee and date</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">No merchants found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-card>

    {{ $businesses->links() }}

    <x-card class="overflow-x-auto">
        <div class="px-4 py-3 border-b border-gray-200 font-medium text-gray-900">Recent payments</div>
        <table class="min-w-full text-sm">
            <tbody class="divide-y divide-gray-100">
                @forelse ($recent as $p)
                    <tr wire:key="sp-{{ $p->id }}">
                        <td class="px-4 py-2 whitespace-nowrap">{{ $p->created_at->format('d M Y, H:i') }}</td>
                        <td class="px-4 py-2">{{ $p->business->name }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $p->months }} month(s) to {{ $p->period_end->format('d M Y') }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $p->method }}@if ($p->reference) ({{ $p->reference }})@endif</td>
                        <td class="px-4 py-2 text-right font-semibold">{{ number_format($p->amount, 2) }}</td>
                    </tr>
                @empty
                    <tr><td class="px-4 py-6 text-center text-gray-500">No payments recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-card>

    @if ($mode && $selected)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/40" wire:click="close"></div>

            <x-card class="relative w-full max-w-md p-6 space-y-4 shadow-xl">
                @if ($mode === 'payment')
                    <form wire:submit="savePayment" class="space-y-4">
                        <h3 class="text-lg font-semibold text-gray-900">Record payment: {{ $selected->name }}</h3>
                        <p class="text-sm text-gray-500">
                            Currently paid until {{ $selected->subscription_ends_at?->format('d M Y') ?? 'no date' }}.
                            @if ($selected->billing_suspended) They are suspended for non-payment and will be reactivated. @endif
                        </p>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <x-input-label for="p_months" value="Months paid *" />
                                <x-text-input wire:model.live="months" id="p_months" type="number" min="1" max="24" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('months')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="p_amount" value="Amount (Kshs) *" />
                                <x-text-input wire:model="amount" id="p_amount" type="number" step="0.01" min="0" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('amount')" class="mt-2" />
                            </div>
                        </div>
                        <div>
                            <x-input-label for="p_method" value="Method *" />
                            <select wire:model="method" id="p_method" class="mt-1 block w-full">
                                @foreach ($methods as $m)
                                    <option value="{{ $m }}">{{ $m }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('method')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="p_ref" value="Transaction reference" />
                            <x-text-input wire:model="reference" id="p_ref" type="text" maxlength="50" class="mt-1 block w-full" placeholder="e.g. M-Pesa code" />
                            <x-input-error :messages="$errors->get('reference')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="p_note" value="Note" />
                            <x-text-input wire:model="note" id="p_note" type="text" maxlength="255" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('note')" class="mt-2" />
                        </div>

                        <div class="flex gap-3">
                            <x-primary-button>Record payment</x-primary-button>
                            <x-secondary-button type="button" wire:click="close">Cancel</x-secondary-button>
                        </div>
                    </form>
                @else
                    <form wire:submit="saveTerms" class="space-y-4">
                        <h3 class="text-lg font-semibold text-gray-900">Fee and date: {{ $selected->name }}</h3>
                        <div>
                            <x-input-label for="t_fee" value="Monthly fee (Kshs) *" />
                            <x-text-input wire:model="monthly_fee" id="t_fee" type="number" step="0.01" min="0" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('monthly_fee')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="t_end" value="Paid until (leave empty for no billing)" />
                            <x-text-input wire:model="ends_at" id="t_end" type="date" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('ends_at')" class="mt-2" />
                        </div>
                        <div class="flex gap-3">
                            <x-primary-button>Save</x-primary-button>
                            <x-secondary-button type="button" wire:click="close">Cancel</x-secondary-button>
                        </div>
                    </form>
                @endif
            </x-card>
        </div>
    @endif
</div>
