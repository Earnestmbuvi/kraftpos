<?php

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $mode = 'shop'; // shop: your own shop. platform: every merchant (super admin).
    public string $search = '';
    public string $action = '';
    public string $subject = '';
    public string $from = '';
    public string $to = '';
    public $businessId = '';

    private function guard(): void
    {
        $u = auth()->user();

        if ($this->mode === 'platform') {
            abort_unless($u?->is_super_admin, 403);
        } else {
            abort_unless($u && $u->business_id && $u->can('manage-users'), 403);
        }
    }

    public function mount(string $mode = 'shop'): void
    {
        $this->mode = $mode === 'platform' ? 'platform' : 'shop';
        $this->guard();
    }

    public function updated($name): void
    {
        $this->resetPage();
    }

    private function base()
    {
        return ActivityLog::query()
            ->when($this->mode === 'shop', fn ($q) => $q->where('business_id', auth()->user()->business_id))
            ->when($this->mode === 'platform' && $this->businessId !== '', fn ($q) => $q->where('business_id', (int) $this->businessId));
    }

    public function with(): array
    {
        $this->guard();
        $term = trim($this->search);

        $logs = $this->base()
            ->when($this->action !== '', fn ($q) => $q->where('action', $this->action))
            ->when($this->subject !== '', fn ($q) => $q->where('subject_type', $this->subject))
            ->when($this->from !== '', fn ($q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to !== '', fn ($q) => $q->whereDate('created_at', '<=', $this->to))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('description', 'like', "%{$term}%")
                ->orWhere('subject_label', 'like', "%{$term}%")
                ->orWhere('user_name', 'like', "%{$term}%")))
            ->latest('id')->paginate(25);

        return [
            'logs' => $logs,
            'subjects' => $this->base()->whereNotNull('subject_type')->distinct()->orderBy('subject_type')->pluck('subject_type'),
            'businesses' => $this->mode === 'platform' ? Business::orderBy('name')->pluck('name', 'id') : collect(),
            'branches' => Branch::pluck('name', 'id'),
            'canCost' => $this->mode === 'platform' || auth()->user()->can('view-cost-prices'),
        ];
    }
}; ?>

<div class="space-y-6">
    <x-card class="p-4">
        <div class="grid grid-cols-1 gap-3 md:grid-cols-6">
            <div class="md:col-span-2"><x-input-label for="a_s" value="Search" /><x-text-input wire:model.live.debounce.300ms="search" id="a_s" type="search" class="mt-1 block w-full" placeholder="Person, item or text" /></div>
            <div><x-input-label for="a_a" value="Action" />
                <select wire:model.live="action" id="a_a" class="mt-1 block w-full text-sm">
                    <option value="">All</option><option value="created">Created</option><option value="updated">Updated</option>
                    <option value="login">Login</option><option value="logout">Logout</option><option value="failed_login">Failed login</option>
                </select></div>
            <div><x-input-label for="a_t" value="Record" />
                <select wire:model.live="subject" id="a_t" class="mt-1 block w-full text-sm">
                    <option value="">All</option>
                    @foreach ($subjects as $s) <option value="{{ $s }}">{{ \Illuminate\Support\Str::headline($s) }}</option> @endforeach
                </select></div>
            <div><x-input-label for="a_f" value="From" /><x-text-input wire:model.live="from" id="a_f" type="date" class="mt-1 block w-full" /></div>
            <div><x-input-label for="a_to" value="To" /><x-text-input wire:model.live="to" id="a_to" type="date" class="mt-1 block w-full" /></div>
            @if ($mode === 'platform')
                <div class="md:col-span-2"><x-input-label for="a_b" value="Merchant" />
                    <select wire:model.live="businessId" id="a_b" class="mt-1 block w-full text-sm">
                        <option value="">All merchants</option>
                        @foreach ($businesses as $id => $name) <option value="{{ $id }}">{{ $name }}</option> @endforeach
                    </select></div>
            @endif
        </div>
    </x-card>

    <x-card class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50"><tr>
                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">When</th>
                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Who</th>
                @if ($mode === 'platform') <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Merchant</th> @endif
                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">What happened</th>
                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Branch</th>
                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">From</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($logs as $a)
                    <tr wire:key="act-{{ $a->id }}">
                        <td class="whitespace-nowrap px-4 py-2 align-top text-gray-500">{{ $a->created_at->format('d M Y H:i:s') }}</td>
                        <td class="px-4 py-2 align-top">{{ $a->user_name ?? 'System' }}</td>
                        @if ($mode === 'platform') <td class="px-4 py-2 align-top">{{ $businesses[$a->business_id] ?? '-' }}</td> @endif
                        <td class="px-4 py-2">
                            <span class="{{ $a->action === 'failed_login' ? 'text-red-600' : '' }}">{{ $a->description }}</span>
                            @foreach (($a->changes ?? []) as $field => [$old, $new])
                                @continue($field === 'cost_price' && ! $canCost)
                                <span class="block text-xs text-gray-500">{{ \Illuminate\Support\Str::headline($field) }}: {{ $old === null || $old === '' ? '(empty)' : $old }} &rarr; {{ $new === null || $new === '' ? '(empty)' : $new }}</span>
                            @endforeach
                        </td>
                        <td class="px-4 py-2 align-top">{{ $branches[$a->branch_id] ?? '-' }}</td>
                        <td class="px-4 py-2 align-top text-xs text-gray-500">{{ $a->ip ?? '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">Nothing recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-4 py-3">{{ $logs->links() }}</div>
    </x-card>
</div>
