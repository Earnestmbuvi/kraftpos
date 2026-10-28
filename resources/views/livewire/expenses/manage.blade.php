<?php

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Services\ExpenseService;
use App\Services\ReportService;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $from = '';
    public string $to = '';
    public bool $showForm = false;

    public $categoryId = '';
    public $amount = '';
    public string $date = '';
    public $methodId = '';
    public string $reference = '';
    public string $description = '';
    public $inputTax = '';
    public ?int $voidingId = null;
    public string $reason = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('record-expenses'), 403);

        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->toDateString();
        $this->date = now()->toDateString();
    }

    public function updatedFrom(): void { $this->resetPage(); }
    public function updatedTo(): void { $this->resetPage(); }

    private function base()
    {
        [$f, $t] = ReportService::range($this->from, $this->to);
        $user = auth()->user();

        return Expense::query()
            ->whereDate('expense_date', '>=', $f->toDateString())
            ->whereDate('expense_date', '<=', $t->toDateString())
            // People who cannot see reports only see what they posted themselves
            ->when(! $user->can('view-reports'), fn ($q) => $q->where('user_id', $user->id));
    }

    public function with(): array
    {
        return [
            'expenses' => (clone $this->base())->with('user')->latest('expense_date')->latest('id')->paginate(15),
            'total' => (float) (clone $this->base())->where('status', 'recorded')->sum('amount'),
            'categories' => ExpenseCategory::active()->orderBy('name')->get(),
            'methods' => PaymentMethod::active()->orderBy('name')->get(),
        ];
    }

    private function resetForm(): void
    {
        $this->reset('categoryId', 'amount', 'methodId', 'reference', 'description','inputTax');
        $this->date = now()->toDateString();
        $this->resetErrorBag();
    }

    public function openCreate(): void
    {
        abort_unless(auth()->user()->can('record-expenses'), 403);

        $this->resetForm();
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function save(): void
    {
        ExpenseService::record(
    (int) $this->categoryId, (float) $this->amount, $this->date,
    $this->methodId ? (int) $this->methodId : null, $this->reference, $this->description,
    (float) ($this->inputTax ?: 0)
);

        $this->dispatch('notify', message: 'Expense recorded.');
        $this->closeForm();
    }

    public function startVoid(int $id): void
    {
        abort_unless(auth()->user()->can('void-sales'), 403);

        $e = Expense::findOrFail($id);
        if ($e->status !== 'recorded') {
            return;
        }

        $this->resetErrorBag();
        $this->reason = '';
        $this->voidingId = $e->id;
    }

    public function cancelVoid(): void
    {
        $this->reset('voidingId', 'reason');
        $this->resetErrorBag();
    }

    public function confirmVoid(): void
    {
        ExpenseService::void(Expense::findOrFail($this->voidingId), $this->reason);

        $this->cancelVoid();
    }
}; ?>

<div class="space-y-6">
    <x-card class="p-4">
        <div class="flex flex-wrap items-end gap-4">
            <div>
                <x-input-label for="x_from" value="From" />
                <x-text-input wire:model.live="from" id="x_from" type="date" class="mt-1" />
            </div>
            <div>
                <x-input-label for="x_to" value="To" />
                <x-text-input wire:model.live="to" id="x_to" type="date" class="mt-1" />
            </div>

            <div class="ml-auto flex items-end gap-6">
                <div class="text-right">
                    <p class="text-xs uppercase text-gray-500">Total in this period</p>
                    <p class="text-xl font-bold text-gray-900">Kshs {{ number_format($total, 2) }}</p>
                </div>
                <x-primary-button type="button" wire:click="openCreate" class="whitespace-nowrap">
                    + Record expense
                </x-primary-button>
            </div>
        </div>
    </x-card>

    <x-card class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">By</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse ($expenses as $e)
                    <tr wire:key="exp-{{ $e->id }}" class="{{ $e->status === 'voided' ? 'opacity-60' : '' }}">
                        <td class="px-4 py-3 whitespace-nowrap">{{ $e->expense_date->format('d M Y') }}</td>
                        <td class="px-4 py-3">{{ $e->category_name }}</td>
                        <td class="px-4 py-3">
                            {{ $e->description }}
                            @if ($e->method_name || $e->reference)
                                <p class="text-xs text-gray-500">{{ collect([$e->method_name, $e->reference])->filter()->implode(', ') }}</p>
                            @endif
                            @if ($e->status === 'voided')
                                <p class="text-xs text-red-600">Voided: {{ $e->void_reason }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3">{{ $e->user?->name ?? '-' }}</td>
                        <td class="px-4 py-3 text-right font-semibold">{{ number_format($e->amount, 2) }}</td>
                        <td class="px-4 py-3">
                            <x-badge :color="$e->status === 'recorded' ? 'green' : 'red'">{{ ucfirst($e->status) }}</x-badge>
                        </td>
                        <td class="px-4 py-3 text-right">
                            @can('void-sales')
                                @if ($e->status === 'recorded')
                                    <button wire:click="startVoid({{ $e->id }})" class="text-red-600 hover:underline">Void</button>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-6 text-center text-gray-500">No expenses in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-card>

    {{ $expenses->links() }}

    @if ($showForm)
        <x-slide-over title="Record an expense" width="max-w-xl">
            <form wire:submit="save" class="flex flex-1 flex-col">
                <div class="flex-1 space-y-4 p-6">
                    <x-card class="p-6 space-y-4">
                        <div>
                            <x-input-label for="e_cat" value="Category *" />
                            <select wire:model="categoryId" id="e_cat" class="mt-1 block w-full">
                                <option value="">Choose...</option>
                                @foreach ($categories as $c)
                                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('category')" class="mt-2" />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <x-input-label for="e_amount" value="Amount (Kshs) *" />
                                <x-text-input wire:model="amount" id="e_amount" type="number" step="0.01" min="0" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('amount')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="e_date" value="Date *" />
                                <x-text-input wire:model="date" id="e_date" type="date" max="{{ today()->toDateString() }}" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('date')" class="mt-2" />
                            </div>
                        </div>
                        <div>
    <x-input-label for="e_tax" value="Input tax included in the amount (optional)" />
    <x-text-input wire:model="inputTax" id="e_tax" type="number" step="0.01" min="0" class="mt-1 block w-full" />
    <p class="mt-1 text-xs text-gray-500">Fill this in only if you have a tax invoice and claim the tax back.</p>
    <x-input-error :messages="$errors->get('inputTax')" class="mt-2" />
</div>
                        <div>
                            <x-input-label for="e_desc" value="What was it for? *" />
                            <x-text-input wire:model="description" id="e_desc" type="text" maxlength="255" class="mt-1 block w-full"
                                          placeholder="e.g. October shop rent" />
                            <x-input-error :messages="$errors->get('description')" class="mt-2" />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <x-input-label for="e_method" value="Paid with" />
                                <select wire:model="methodId" id="e_method" class="mt-1 block w-full">
                                    <option value="">Not specified</option>
                                    @foreach ($methods as $m)
                                        <option value="{{ $m->id }}">{{ $m->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('method')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="e_ref" value="Reference / receipt no." />
                                <x-text-input wire:model="reference" id="e_ref" type="text" maxlength="50" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('reference')" class="mt-2" />
                            </div>
                        </div>
                    </x-card>
                </div>

                <div class="sticky bottom-0 flex gap-3 border-t border-gray-200 bg-white px-6 py-4">
                    <x-primary-button>Record expense</x-primary-button>
                    <x-secondary-button type="button" wire:click="closeForm">Cancel</x-secondary-button>
                </div>
            </form>
        </x-slide-over>
    @endif

    @if ($voidingId)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/40" wire:click="cancelVoid"></div>

            <x-card class="relative w-full max-w-md p-6 space-y-4 shadow-xl">
                <h3 class="text-lg font-semibold text-gray-900">Void this expense?</h3>
                <p class="text-sm text-gray-600">It stays on record, marked as voided, and stops counting in profit. This can't be undone.</p>
                <div>
                    <x-input-label for="x_reason" value="Reason *" />
                    <x-text-input wire:model="reason" id="x_reason" type="text" maxlength="255" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('reason')" class="mt-2" />
                </div>
                <div class="flex gap-3">
                    <button type="button" wire:click="confirmVoid" wire:loading.attr="disabled"
                            class="inline-flex items-center px-4 py-2 rounded-lg bg-red-600 text-sm font-semibold text-white hover:bg-red-700">Void expense</button>
                    <x-secondary-button type="button" wire:click="cancelVoid">Keep expense</x-secondary-button>
                </div>
            </x-card>
        </div>
    @endif
</div>
