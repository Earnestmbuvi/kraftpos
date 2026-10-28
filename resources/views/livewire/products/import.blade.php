<?php

use App\Models\Branch;
use App\Services\ProductImportService;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public $file = null;
    public array $problems = [];
    public array $branchIds = [];

    public function mount(): void
    {
        abort_unless(auth()->user()->can('manage-products'), 403);
    }

    public function with(): array
    {
        return ['branches' => Branch::active()->orderByDesc('is_main')->orderBy('name')->get()];
    }

    public function import(): void
    {
        abort_unless(auth()->user()->can('manage-products'), 403);

        $this->validate([
            'file' => ['required', 'file', 'max:1024', 'mimes:csv,txt'],
            'branchIds' => ['array'],
            'branchIds.*' => ['integer', Rule::exists('branches', 'id')->where('business_id', auth()->user()->business_id)],
        ], [
            'file.required' => 'Choose a CSV file first.',
            'file.max' => 'The file is too large (1 MB at most).',
            'file.mimes' => 'The file must be a .csv file.',
        ]);

        $result = ProductImportService::run($this->file->getRealPath(), array_map('intval', $this->branchIds));

        $this->problems = $result['errors'];

        if (! $result['errors']) {
            session()->flash('notify', $result['created'] . ' product(s) imported.');
            $this->redirect(route('products'), navigate: true);
        }
    }
}; ?>

<div class="space-y-6">
    <x-card class="p-6 space-y-4">
        <h3 class="text-lg font-medium text-gray-900">Import products from CSV</h3>
        <ol class="list-decimal space-y-1 pl-5 text-sm text-gray-600">
            <li>Download the template and fill in one product per row. You can edit it in Excel and save as CSV.</li>
            <li>Categories and units must already exist under Setup. Use their exact names.</li>
            <li><strong>type</strong> is "product" or "service". Stock becomes the opening stock of the branch you are working in; services have none.</li>
            <li><strong>branches</strong> is optional. Write branch names separated by <code>|</code>, for example <code>Karen|Westlands</code>. Leave it empty to use the choice below.</li>
            <li>If any row has a problem, nothing is imported and you will see which rows to fix.</li>
        </ol>
        <a href="{{ route('products.import-template') }}" class="inline-block text-sm font-semibold text-brand-600 hover:underline">Download the template</a>

        <form wire:submit="import" class="space-y-4 border-t border-gray-200 pt-4">
            @if ($branches->count() > 1)
                <div>
                    <p class="text-sm font-medium text-gray-700">Sold at</p>
                    <p class="text-xs text-gray-500">Leave all unticked to sell the imported products at every branch. Tick some to limit them. A branch named on a row of the file overrides this for that row.</p>
                    <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-3">
                        @foreach ($branches as $b)
                            <label wire:key="ib-{{ $b->id }}" class="flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" wire:model="branchIds" value="{{ $b->id }}" class="rounded"> {{ $b->name }}
                            </label>
                        @endforeach
                    </div>
                    <x-input-error :messages="collect($errors->get('branchIds.*'))->flatten()->all()" class="mt-2" />
                </div>
            @endif

            <div>
                <input wire:model="file" type="file" accept=".csv,text/csv,text/plain" class="text-sm">
                <div wire:loading wire:target="file" class="text-xs text-gray-500">Uploading...</div>
                <x-input-error :messages="$errors->get('file')" class="mt-2" />
            </div>
            <div class="flex gap-3">
                <x-primary-button wire:loading.attr="disabled">Import products</x-primary-button>
                <a href="{{ route('products') }}" wire:navigate
                   class="inline-flex items-center px-4 py-2 rounded-lg border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50">Cancel</a>
            </div>
        </form>
    </x-card>

    @if ($problems)
        <x-card class="p-6 space-y-2 border-red-200">
            <h4 class="font-medium text-red-700">Nothing was imported. Fix these and upload again:</h4>
            <ul class="list-disc space-y-1 pl-5 text-sm text-red-700">
                @foreach ($problems as $p)
                    <li>{{ $p }}</li>
                @endforeach
            </ul>
        </x-card>
    @endif
</div>
