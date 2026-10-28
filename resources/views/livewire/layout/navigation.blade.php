<?php

use App\Livewire\Actions\Logout;
use App\Services\BranchService;
use App\Support\Menu;
use Livewire\Volt\Component;

new class extends Component
{
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }

   public function switchBranch(string $id): void
{
    $u = auth()->user();
    abort_unless($u && $u->business_id, 403);

    if ($id === 'all') {
        abort_unless(BranchService::unrestricted($u), 403);
        session(['branch_id' => 'all']);
    } else {
        abort_unless(in_array((int) $id, BranchService::allowed($u), true), 403);
        session(['branch_id' => (int) $id]);
    }

    BranchService::forget();
    $this->redirect(url()->previous(), navigate: true);
}
}; ?>

@php
    $user = auth()->user();
    $items = Menu::items($user);
    $group = Menu::activeGroup($items);
@endphp

<div x-data="{ open: false }">
    <!-- Top bar -->
    <header class="fixed inset-x-0 top-0 z-40 h-16 bg-ink text-white flex items-center justify-between px-4 print:hidden">
        <div class="flex items-center gap-3">
            <button @click="open = ! open" class="md:hidden p-2 rounded-lg hover:bg-white/10" aria-label="Menu">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                </svg>
            </button>
            <a href="{{ route('dashboard') }}" wire:navigate>
                <x-application-logo class="text-xl text-white" />
            </a>
        </div>

        <div class="flex items-center gap-4">
            <div class="hidden sm:block text-sm text-gray-300">
                {{ $user->business?->name ?? 'Platform admin' }}
            </div>

            @if ($user->business_id)
    @php $allowedIds = BranchService::allowed(); @endphp

    @if (count($allowedIds) > 1)
        @php
            $currentBranch = BranchService::currentId();
            $branchList = \App\Models\Branch::active()->whereIn('id', $allowedIds)->orderByDesc('is_main')->orderBy('name')->get();
            $viewingAll = BranchService::viewing() === null;
            $canAll = BranchService::unrestricted();
        @endphp
        <select wire:change="switchBranch($event.target.value)"
                title="{{ $viewingAll ? 'Showing all branches together. Selling and stock use ' . $branchList->firstWhere('id', $currentBranch)?->name . '.' : 'The branch you are working in' }}"
                class="rounded-md border-0 bg-white/10 py-1 text-sm text-white">
            @foreach ($branchList as $b)
                <option class="text-gray-900" value="{{ $b->id }}" @selected(! $viewingAll && $b->id === $currentBranch)>{{ $b->name }}</option>
            @endforeach
            @if ($canAll)
                <option class="text-gray-900" value="all" @selected($viewingAll)>All branches</option>
            @endif
        </select>
    @elseif (BranchService::multi())
        <span class="text-sm text-gray-300">{{ \App\Models\Branch::find(BranchService::currentId())?->name }}</span>
    @endif
@endif

        </div>

        <x-dropdown align="right" width="48">
            <x-slot name="trigger">
                <button class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium hover:bg-white/10 focus:outline-none">
                    <div x-data="{{ json_encode(['name' => $user->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></div>
                    <span class="hidden text-xs font-normal text-gray-300 sm:inline">{{ \App\Support\RoleLabel::for($user) }}</span>
                    <svg class="h-4 w-4 fill-current" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                    </svg>
                </button>
            </x-slot>

            <x-slot name="content">
                <x-dropdown-link :href="route('profile')" wire:navigate>
                    {{ __('Profile') }}
                </x-dropdown-link>

                <button wire:click="logout" class="w-full text-start">
                    <x-dropdown-link>
                        {{ __('Log Out') }}
                    </x-dropdown-link>
                </button>
            </x-slot>
        </x-dropdown>
    </header>

    <!-- Icon rail (desktop): every section, always visible -->
    <aside class="hidden md:flex fixed left-0 top-16 bottom-0 z-30 w-24 flex-col gap-1 overflow-y-auto border-r border-gray-200 bg-white p-2 print:hidden">
        @foreach ($items as $item)
            @php $active = Menu::active($item); @endphp
            <a href="{{ route($item['route'], $item['params'] ?? []) }}" wire:navigate
               class="flex flex-col items-center gap-1 rounded-lg px-1 py-3 text-center text-xs font-medium transition {{ $active ? 'bg-brand-100 text-brand-600' : 'text-gray-600 hover:bg-gray-100' }}">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['path'] }}" />
                </svg>
                <span>{{ $item['label'] }}</span>
            </a>
        @endforeach
    </aside>

    <!-- Section panel (desktop): the pages of the open section, from the top -->
    @if ($group)
        <nav class="hidden md:block fixed left-24 top-16 bottom-0 z-20 w-56 overflow-y-auto border-r border-gray-200 bg-white px-3 py-5 print:hidden">
            <p class="px-3 pb-3 text-xs font-semibold uppercase tracking-wider text-gray-500">{{ $group['label'] }}</p>

            <div class="space-y-1">
                @foreach ($group['children'] as $child)
                    @php $childActive = Menu::childActive($child); @endphp
                    <a href="{{ route($child['route'], $child['params'] ?? []) }}" @unless (! empty($child['download'])) wire:navigate @endunless
                       class="block rounded-lg px-3 py-2 text-sm font-medium {{ $childActive ? 'bg-brand-100 text-brand-600' : 'text-gray-700 hover:bg-gray-100' }}">
                        {{ $child['label'] }}
                    </a>
                @endforeach
            </div>
        </nav>
    @endif

    <!-- Phone menu -->
    <div :class="open ? 'block' : 'hidden'" class="md:hidden fixed inset-x-0 top-16 z-30 max-h-[calc(100vh-4rem)] overflow-y-auto border-b border-gray-200 bg-white p-2 shadow print:hidden">
        @foreach ($items as $item)
            @if (isset($item['children']))
                <p class="px-3 pt-3 pb-1 text-xs font-semibold uppercase text-gray-400">{{ $item['label'] }}</p>
                @foreach ($item['children'] as $child)
                    @php $childActive = Menu::childActive($child); @endphp
                    <a href="{{ route($child['route'], $child['params'] ?? []) }}" @unless (! empty($child['download'])) wire:navigate @endunless @click="open = false"
                       class="block rounded-lg px-3 py-3 pl-8 text-sm font-medium {{ $childActive ? 'bg-brand-100 text-brand-600' : 'text-gray-700 hover:bg-gray-100' }}">
                        {{ $child['label'] }}
                    </a>
                @endforeach
            @else
                @php $active = Menu::active($item); @endphp
                <a href="{{ route($item['route'], $item['params'] ?? []) }}" wire:navigate @click="open = false"
                   class="flex items-center gap-3 rounded-lg px-3 py-3 text-sm font-medium {{ $active ? 'bg-brand-100 text-brand-600' : 'text-gray-700 hover:bg-gray-100' }}">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['path'] }}" />
                    </svg>
                    <span>{{ $item['label'] }}</span>
                </a>
            @endif
        @endforeach
    </div>
</div>
