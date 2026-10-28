<?php

use Livewire\Volt\Component;

new class extends Component
{
    public $tax_rate = 0;
    public bool $tax_inclusive = false;
    public bool $discounts_enabled = true;
    public bool $allow_zero_stock_sales = true;

    public function mount(): void
    {
        $b = auth()->user()->business;
        $this->tax_rate = $b->tax_rate;
        $this->tax_inclusive = (bool) $b->tax_inclusive;
        $this->discounts_enabled = (bool) $b->discounts_enabled;
        $this->allow_zero_stock_sales = (bool) $b->allow_zero_stock_sales;
    }

    public function save(): void
    {
        abort_unless(auth()->user()->can('manage-settings'), 403);

        $this->validate([
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'tax_inclusive' => ['boolean'],
            'discounts_enabled' => ['boolean'],
            'allow_zero_stock_sales' => ['boolean'],
        ]);

        $b = auth()->user()->business;
        $b->tax_rate = $this->tax_rate;
        $b->tax_inclusive = $this->tax_inclusive;
        $b->discounts_enabled = $this->discounts_enabled;
        $b->allow_zero_stock_sales = $this->allow_zero_stock_sales;
        $b->save();

        $this->dispatch('notify', message: 'Settings saved.');
    }
}; ?>

<x-card class="p-6">
    <form wire:submit="save" class="space-y-6">
        <div class="space-y-4">
            <h3 class="text-lg font-medium text-gray-900">Tax</h3>
            <p class="text-sm text-gray-500">
                This is your standard rate. Each product is marked standard-rated, zero-rated or exempt on the product form,
                and only standard-rated products are charged. Changes apply to new sales only.
            </p>
            <div class="max-w-xs">
                <x-input-label for="tax_rate" value="Standard tax rate (%)" />
                <x-text-input wire:model="tax_rate" id="tax_rate" type="number" step="0.01" min="0" max="100" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('tax_rate')" class="mt-2" />
            </div>
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="tax_inclusive" class="rounded"> My selling prices already include this tax
            </label>
        </div>

        <div class="space-y-3 border-t border-gray-200 pt-6">
            <h3 class="text-lg font-medium text-gray-900">Discounts</h3>
            <label class="flex items-start gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="discounts_enabled" class="mt-1 rounded">
                <span><span class="font-medium">Allow discounts</span>
                <span class="block text-xs text-gray-500">Untick to switch discounts off for everyone, including managers.</span></span>
            </label>
        </div>

        <div class="space-y-3 border-t border-gray-200 pt-6">
            <h3 class="text-lg font-medium text-gray-900">Selling without stock</h3>
            <label class="flex items-start gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="allow_zero_stock_sales" class="mt-1 rounded">
                <span><span class="font-medium">Allow selling items that are out of stock</span>
                <span class="block text-xs text-gray-500">Untick to refuse any sale or invoice for more than the branch has. Services are not affected.</span></span>
            </label>
        </div>

        <x-primary-button>Save</x-primary-button>
    </form>
</x-card>
