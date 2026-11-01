<?php

use App\Models\Branch;
use App\Support\ReceiptSettings;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public array $o = [];
    public string $headerText = '';
    public string $footerText = '';
    public string $paper = 'thermal';
    public string $invoiceTerms = '';
    public string $quotationTerms = '';
    public array $tills = [];
    public $logo = null;
    public bool $removeLogo = false;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('manage-settings'), 403);

        $b = auth()->user()->business;
        $s = ReceiptSettings::for($b);

        foreach (array_keys(ReceiptSettings::TOGGLES) as $k) {
            $this->o[$k] = (bool) $s[$k];
        }
        $this->headerText = (string) $s['header_text'];
        $this->footerText = (string) $s['footer_text'];
        $this->paper = (string) $s['paper'];
        $this->invoiceTerms = (string) $b->invoice_terms;
        $this->quotationTerms = (string) $b->quotation_terms;
        $this->tills = Branch::pluck('till_number', 'id')->map(fn ($t) => (string) $t)->all();
    }

    public function with(): array
    {
        $path = auth()->user()->business->logo_path;

        return [
            'branches' => Branch::orderByDesc('is_main')->orderBy('name')->get(),
            'toggles' => ReceiptSettings::TOGGLES,
            'logoUrl' => $path ? Storage::disk('public')->url($path) : null,
        ];
    }

    public function save(): void
    {
        abort_unless(auth()->user()->can('manage-settings'), 403);

        $this->validate([
            'headerText' => ['nullable', 'string', 'max:200'],
            'footerText' => ['nullable', 'string', 'max:300'],
            'paper' => ['required', 'in:thermal,a4'],
            'invoiceTerms' => ['nullable', 'string', 'max:3000'],
            'quotationTerms' => ['nullable', 'string', 'max:3000'],
            'tills.*' => ['nullable', 'string', 'max:20'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1024'],
        ], ['logo.max' => 'The logo is too large (1 MB at most).']);

        $settings = [];
        foreach (array_keys(ReceiptSettings::TOGGLES) as $k) {
            $settings[$k] = (bool) ($this->o[$k] ?? false);
        }
        $settings += ['header_text' => trim($this->headerText), 'footer_text' => trim($this->footerText), 'paper' => $this->paper];

        $b = auth()->user()->business;
        $logoPath = $b->logo_path;

        if (($this->removeLogo || $this->logo) && $logoPath) {
            Storage::disk('public')->delete($logoPath);
            $logoPath = null;
        }
        if ($this->logo) {
            $logoPath = $this->logo->store('logos/' . $b->id, 'public');
        }

        $b->forceFill([
            'receipt_settings' => $settings,
            'invoice_terms' => trim($this->invoiceTerms) ?: null,
            'quotation_terms' => trim($this->quotationTerms) ?: null,
            'logo_path' => $logoPath,
        ])->save();

        foreach (Branch::all() as $branch) {
            $branch->forceFill(['till_number' => trim((string) ($this->tills[$branch->id] ?? '')) ?: null])->save();
        }

        $this->reset('logo', 'removeLogo');
        $this->dispatch('notify', message: 'Receipt and document settings saved.');
    }
}; ?>

<form wire:submit="save" class="space-y-6">
    <x-card class="p-6 space-y-4">
        <h3 class="text-lg font-medium text-gray-900">Logo</h3>
        <div class="flex items-center gap-4">
            <div class="flex h-20 w-32 items-center justify-center overflow-hidden rounded-lg border border-gray-200 bg-white">
                @if ($logo)
                    <img src="{{ $logo->temporaryUrl() }}" class="max-h-full max-w-full object-contain">
                @elseif ($logoUrl && ! $removeLogo)
                    <img src="{{ $logoUrl }}" class="max-h-full max-w-full object-contain">
                @else
                    <span class="text-xs text-gray-400">No logo</span>
                @endif
            </div>
            <div class="space-y-1">
                <input wire:model="logo" type="file" accept="image/png,image/jpeg,image/webp" class="text-sm">
                <div wire:loading wire:target="logo" class="text-xs text-gray-500">Uploading...</div>
                <p class="text-xs text-gray-500">PNG, JPG or WebP, 1 MB at most. A wide, simple logo prints best.</p>
                @if ($logoUrl && ! $logo)
                    <label class="flex items-center gap-2 text-xs text-gray-600"><input type="checkbox" wire:model="removeLogo" class="rounded"> Remove current logo</label>
                @endif
                <x-input-error :messages="$errors->get('logo')" />
            </div>
        </div>
    </x-card>

    <x-card class="p-6 space-y-4">
        <h3 class="text-lg font-medium text-gray-900">Receipt: what to show</h3>
        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
            @foreach ($toggles as $key => $label)
                <label wire:key="rt-{{ $key }}" class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" wire:model="o.{{ $key }}" class="rounded"> {{ $label }}
                </label>
            @endforeach
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <x-input-label for="r_head" value="Extra header line (e.g. KRA PIN, slogan)" />
                <x-text-input wire:model="headerText" id="r_head" type="text" maxlength="200" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('headerText')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="r_foot" value="Footer message" />
                <x-text-input wire:model="footerText" id="r_foot" type="text" maxlength="300" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('footerText')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="r_paper" value="Paper" />
                <select wire:model="paper" id="r_paper" class="mt-1 block w-full">
                    <option value="thermal">Thermal roll (80 mm)</option>
                    <option value="a4">A4 page</option>
                </select>
            </div>
        </div>
    </x-card>

    <x-card class="p-6 space-y-4">
        <h3 class="text-lg font-medium text-gray-900">Till numbers</h3>
        <p class="text-sm text-gray-500">Each branch is a till. The number prints on its receipts when "Till number" is ticked.</p>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @foreach ($branches as $br)
                <div wire:key="tn-{{ $br->id }}">
                    <x-input-label :for="'till'.$br->id" :value="$br->name . ($br->is_main ? ' (main)' : '')" />
                    <x-text-input wire:model="tills.{{ $br->id }}" :id="'till'.$br->id" type="text" maxlength="20" class="mt-1 block w-full" placeholder="e.g. Till 1" />
                </div>
            @endforeach
        </div>
    </x-card>

    <x-card class="p-6 space-y-4">
        <h3 class="text-lg font-medium text-gray-900">Terms and conditions</h3>
        <p class="text-sm text-gray-500">Printed at the bottom of every invoice and quotation. Leave empty to print none.</p>
        <div>
            <x-input-label for="t_inv" value="Invoice terms" />
            <textarea wire:model="invoiceTerms" id="t_inv" rows="5" maxlength="3000" class="mt-1 block w-full rounded-lg border-gray-300 text-sm" placeholder="e.g. Payment due within 30 days."></textarea>
            <x-input-error :messages="$errors->get('invoiceTerms')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="t_quo" value="Quotation terms" />
            <textarea wire:model="quotationTerms" id="t_quo" rows="5" maxlength="3000" class="mt-1 block w-full rounded-lg border-gray-300 text-sm" placeholder="e.g. Prices valid until the date shown."></textarea>
            <x-input-error :messages="$errors->get('quotationTerms')" class="mt-2" />
        </div>
    </x-card>

    <x-primary-button>Save settings</x-primary-button>
</form>
