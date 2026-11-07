<?php

use App\Models\DocumentText;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component
{
    public string $kind = 'terms';   // terms | payment: the list shown and added to
    public ?int $editingId = null;
    public string $name = '';
    public string $body = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('manage-settings'), 403);
    }

    public function with(): array
    {
        return ['texts' => DocumentText::where('kind', $this->kind)->orderBy('name')->get()];
    }

    public function setKind(string $k): void
    {
        if (in_array($k, ['terms', 'payment'], true)) {
            $this->kind = $k;
            $this->cancel();
        }
    }

    public function edit(int $id): void
    {
        $t = DocumentText::findOrFail($id);
        $this->resetErrorBag();
        $this->editingId = $t->id;
        $this->kind = $t->kind;
        $this->name = $t->name;
        $this->body = $t->body;
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'name', 'body');
        $this->resetErrorBag();
    }

    public function save(): void
    {
        abort_unless(auth()->user()->can('manage-settings'), 403);

        $this->name = trim($this->name);
        $bid = auth()->user()->business_id;

        $this->validate([
            'name' => ['required', 'string', 'max:80',
                Rule::unique('document_texts', 'name')->where('business_id', $bid)->where('kind', $this->kind)->ignore($this->editingId)],
            'body' => ['required', 'string', 'max:3000'],
        ], ['body.required' => 'Write the text that should print on the document.']);

        $this->editingId
            ? DocumentText::findOrFail($this->editingId)->update(['name' => $this->name, 'body' => trim($this->body)])
            : DocumentText::create(['kind' => $this->kind, 'name' => $this->name, 'body' => trim($this->body)]);

        $this->dispatch('notify', message: 'Saved.');
        $this->cancel();
    }

    public function toggle(int $id): void
    {
        abort_unless(auth()->user()->can('manage-settings'), 403);

        $t = DocumentText::findOrFail($id);
        $t->is_active = ! $t->is_active;

        if (! $t->is_active) {
            $t->default_invoice = false;
            $t->default_quotation = false;
        }
        $t->save();
    }

    // Marks it as the one used for new invoices (or quotations). Clicking again removes the default.
    public function toggleDefault(int $id, string $for): void
    {
        abort_unless(auth()->user()->can('manage-settings'), 403);
        abort_unless(in_array($for, ['invoice', 'quotation'], true), 404);

        $t = DocumentText::findOrFail($id);
        $col = 'default_' . $for;

        if (! $t->is_active) {
            return;
        }

        if ($t->{$col}) {
            $t->update([$col => false]);
            return;
        }

        DocumentText::where('kind', $t->kind)->where($col, true)->update([$col => false]);
        $t->update([$col => true]);
    }
}; ?>

<div class="space-y-6">
    <div class="inline-flex rounded-lg border border-gray-300 overflow-hidden">
        <button type="button" wire:click="setKind('terms')" class="px-4 py-2 text-sm font-medium {{ $kind === 'terms' ? 'bg-brand-500 text-white' : 'bg-white text-gray-700' }}">Terms and conditions</button>
        <button type="button" wire:click="setKind('payment')" class="px-4 py-2 text-sm font-medium {{ $kind === 'payment' ? 'bg-brand-500 text-white' : 'bg-white text-gray-700' }}">Payment details</button>
    </div>

    <x-card class="p-6">
        <form wire:submit="save" class="space-y-4">
            <h3 class="text-lg font-medium text-gray-900">{{ $editingId ? 'Edit' : 'Add' }} {{ $kind === 'terms' ? 'terms' : 'payment details' }}</h3>
            <p class="text-sm text-gray-500">
                @if ($kind === 'terms')
                    Save as many sets of terms as you like, for example "Terms 1" and "Terms 2". On each invoice or quotation you pick the one to print.
                @else
                    Save each way of paying, for example "M-Pesa paybill", "Till" or "Bank transfer". On each invoice or quotation you pick the one to print above the terms.
                @endif
            </p>
            <div class="max-w-md">
                <x-input-label for="dt_name" value="Name *" />
                <x-text-input wire:model="name" id="dt_name" type="text" maxlength="80" class="mt-1 block w-full"
                              placeholder="{{ $kind === 'terms' ? 'e.g. Terms 1' : 'e.g. M-Pesa paybill' }}" />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="dt_body" value="Text that prints *" />
                <textarea wire:model="body" id="dt_body" rows="5" maxlength="3000" class="mt-1 block w-full rounded-lg border-gray-300 text-sm"
                          placeholder="{{ $kind === 'terms' ? 'e.g. Payment is due within 30 days. Goods remain the property of the seller until paid.' : "Paybill: 123456\nAccount: your invoice number" }}"></textarea>
                <x-input-error :messages="$errors->get('body')" class="mt-2" />
            </div>
            <div class="flex gap-3">
                <x-primary-button>{{ $editingId ? 'Update' : 'Add' }}</x-primary-button>
                @if ($editingId) <x-secondary-button type="button" wire:click="cancel">Cancel</x-secondary-button> @endif
            </div>
        </form>
    </x-card>

    <x-card class="divide-y divide-gray-100">
        @forelse ($texts as $t)
            <div wire:key="dt-{{ $t->id }}" class="space-y-2 p-4 text-sm {{ $t->is_active ? '' : 'opacity-50' }}">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="font-semibold text-gray-900">
                        {{ $t->name }}
                        @if ($t->default_invoice) <x-badge color="green">Default for invoices</x-badge> @endif
                        @if ($t->default_quotation) <x-badge color="orange">Default for quotations</x-badge> @endif
                    </p>
                    <div class="space-x-3 whitespace-nowrap">
                        <button wire:click="toggleDefault({{ $t->id }}, 'invoice')" class="text-gray-600 hover:underline">{{ $t->default_invoice ? 'Not default for invoices' : 'Default for invoices' }}</button>
                        <button wire:click="toggleDefault({{ $t->id }}, 'quotation')" class="text-gray-600 hover:underline">{{ $t->default_quotation ? 'Not default for quotations' : 'Default for quotations' }}</button>
                        <button wire:click="edit({{ $t->id }})" class="text-brand-600 hover:underline">Edit</button>
                        <button wire:click="toggle({{ $t->id }})" class="text-gray-600 hover:underline">{{ $t->is_active ? 'Disable' : 'Enable' }}</button>
                    </div>
                </div>
                <p class="whitespace-pre-line text-gray-600">{{ $t->body }}</p>
            </div>
        @empty
            <p class="p-6 text-center text-gray-500">None saved yet.</p>
        @endforelse
    </x-card>
</div>
