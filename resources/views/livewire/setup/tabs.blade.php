<?php

use Livewire\Volt\Component;

new class extends Component
{
    public string $tab = 'categories';

    public function mount(): void
    {
        $tabs = $this->availableTabs();
        abort_if(empty($tabs), 403);

        $this->tab = array_key_first($tabs);
    }

    private function availableTabs(): array
    {
        $user = auth()->user();
        $tabs = [];

        if ($user->can('manage-categories')) {
            $tabs['categories'] = 'Categories';
        }
        if ($user->can('manage-products')) {
            $tabs['units'] = 'Units';
            $tabs['options'] = 'Variants';
        }
        if ($user->can('manage-categories')) {
            $tabs['expense-categories'] = 'Expense categories';
        }
        if ($user->can('manage-settings')) {
            $tabs['payments'] = 'Payment methods';
            $tabs['tax'] = 'Sales settings';
            $tabs['branches'] = 'Branches';
        }

        return $tabs;
    }

    public function setTab(string $tab): void
    {
        if (array_key_exists($tab, $this->availableTabs())) {
            $this->tab = $tab;
        }
    }

    public function with(): array
    {
        $tabs = $this->availableTabs();

        // A tampered request must not reach a tab this person may not see
        abort_unless(array_key_exists($this->tab, $tabs), 403);

        return ['tabs' => $tabs];
    }
}; ?>

<div class="space-y-6">
    <div class="border-b border-gray-200 overflow-x-auto">
        <nav class="-mb-px flex space-x-8">
            @foreach ($tabs as $key => $label)
                <button wire:key="stab-{{ $key }}" wire:click="setTab('{{ $key }}')"
                        class="py-3 px-1 border-b-2 text-sm font-medium whitespace-nowrap {{ $tab === $key ? 'border-brand-500 text-brand-600' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
                    {{ $label }}
                </button>
            @endforeach
        </nav>
    </div>

    @if ($tab === 'categories')
        <livewire:categories.manage wire:key="panel-categories" />
    @elseif ($tab === 'units')
        <livewire:units.manage wire:key="panel-units" />
    @elseif ($tab === 'options')
        <livewire:options.manage wire:key="panel-options" />
    @elseif ($tab === 'expense-categories')
        <livewire:expense-categories.manage wire:key="panel-expense-categories" />
    @elseif ($tab === 'payments')
        <livewire:payments.manage wire:key="panel-payments" />
    @elseif ($tab === 'tax')
        <livewire:settings.tax wire:key="panel-tax" />
    @elseif ($tab === 'branches')
        <livewire:branches.manage wire:key="panel-branches" />
    @endif
</div>
