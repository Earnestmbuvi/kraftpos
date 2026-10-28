<?php

use App\Models\Lead;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $show = 'new';

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_super_admin, 403);
    }

    public function updatedShow(): void { $this->resetPage(); }

    public function with(): array
    {
        abort_unless(auth()->user()?->is_super_admin, 403);

        return [
            'leads' => Lead::when($this->show !== 'all', fn ($q) => $q->where('status', $this->show))->latest('id')->paginate(15),
            'newCount' => Lead::where('status', 'new')->count(),
        ];
    }

    public function toggle(int $id): void
    {
        abort_unless(auth()->user()?->is_super_admin, 403);

        $l = Lead::findOrFail($id);
        $l->status = $l->status === 'new' ? 'handled' : 'new';
        $l->save();
    }
}; ?>

<div class="space-y-6">
    <div class="flex items-center gap-4">
        <select wire:model.live="show" class="text-sm">
            <option value="new">New ({{ $newCount }})</option><option value="handled">Handled</option><option value="all">All</option>
        </select>
    </div>

    <x-card class="divide-y divide-gray-100">
        @forelse ($leads as $l)
            <div wire:key="ld-{{ $l->id }}" class="flex flex-wrap items-start justify-between gap-4 p-4 text-sm">
                <div class="min-w-0 space-y-1">
                    <p><x-badge :color="$l->type === 'demo' ? 'orange' : 'gray'">{{ ucfirst($l->type) }}</x-badge>
                        <span class="ml-2 font-semibold">{{ $l->name }}</span>
                        <span class="text-gray-500">{{ $l->created_at->format('d M Y, H:i') }}</span></p>
                    <p class="text-gray-600">{{ $l->email }} {{ $l->phone }}</p>
                    @if ($l->business_name) <p class="text-gray-600">{{ $l->business_name }} {{ $l->sector ? '(' . $l->sector . ')' : '' }} {{ $l->branches_count ? ', ' . $l->branches_count . ' branch(es)' : '' }} {{ $l->preferred_date ? ', wants ' . $l->preferred_date->format('d M Y') : '' }}</p> @endif
                    @if ($l->message) <p class="whitespace-pre-line text-gray-800">{{ $l->message }}</p> @endif
                </div>
                <button wire:click="toggle({{ $l->id }})" class="whitespace-nowrap text-brand-600 hover:underline">{{ $l->status === 'new' ? 'Mark handled' : 'Reopen' }}</button>
            </div>
        @empty
            <p class="p-6 text-center text-gray-500">Nothing here.</p>
        @endforelse
    </x-card>

    {{ $leads->links() }}
</div>
