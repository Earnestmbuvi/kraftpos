<?php

use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use App\Services\ReportService;
use App\Services\SalesDetail;
use App\Services\SaleService;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use App\Services\BranchService;

new class extends Component
{
    use WithPagination;

    public string $from = '';
    public string $to = '';
    public string $kind = 'all';
    public $userId = '';
    public ?int $voidingId = null;
    public string $reason = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('make-sales'), 403);
        $this->from = $this->to = now()->toDateString();
    }

    public function updatedFrom(): void { $this->resetPage(); }
    public function updatedTo(): void { $this->resetPage(); }
    public function updatedKind(): void { $this->resetPage(); }
    public function updatedUserId(): void { $this->resetPage(); }

    public function with(): array
    {
        $user = auth()->user();
        $bid = $user->business_id;
        $all = $user->can('view-reports');
        [$f, $t] = ReportService::range($this->from, $this->to);

        $base = fn (string $table) => DB::table($table)->where('business_id', $bid)->whereBetween('created_at', [$f, $t])
            ->when(BranchService::viewing() !== null, fn ($q) => BranchService::limit($q, BranchService::viewing(), 'branch_id'))
            ->when(! $all, fn ($q) => $q->where('user_id', $user->id))
            ->when($all && $this->userId, fn ($q) => $q->where('user_id', (int) $this->userId));

        $cols = fn (string $kind, string $num, string $ok) =>
            "id, '{$kind}' as kind, {$num} as number, created_at, customer_id, user_id, total, amount_paid as paid, "
            . "CASE WHEN status = '{$ok}' THEN 1 ELSE 0 END as ok";

        $sales = $base('sales')->selectRaw($cols('sale', 'receipt_number', 'completed'));
        $invoices = $base('invoices')->selectRaw($cols('invoice', 'invoice_number', 'issued'));
        $union = $this->kind === 'sale' ? $sales : ($this->kind === 'invoice' ? $invoices : $sales->unionAll($invoices));

        $rows = DB::query()->fromSub($union, 'h')->orderByDesc('created_at')->orderByDesc('id')->paginate(20);

        return [
            'rows' => $rows,
            'customers' => Customer::whereIn('id', collect($rows->items())->pluck('customer_id')->filter())->pluck('name', 'id'),
            'people' => User::whereIn('id', collect($rows->items())->pluck('user_id'))->pluck('name', 'id'),
            'staff' => $all ? User::where('business_id', $bid)->orderBy('name')->get(['id', 'name']) : collect(),
            'tillTotal' => (float) $base('sales')->where('status', 'completed')->sum('total'),
            'invoiceTotal' => (float) $base('invoices')->where('status', 'issued')->sum('total'),
            'unpaid' => (float) $base('invoices')->where('status', 'issued')->sum(DB::raw('total - amount_paid')),
            'canVoid' => $user->can('void-sales'),
        ];
    }

    public function startVoid(int $id): void
    {
        abort_unless(auth()->user()->can('void-sales'), 403);
        Sale::findOrFail($id);
        $this->resetErrorBag();
        $this->reason = '';
        $this->voidingId = $id;
    }

    public function cancelVoid(): void
    {
        $this->reset('voidingId', 'reason');
        $this->resetErrorBag();
    }

    public function confirmVoid(): void
    {
        SaleService::void(Sale::findOrFail($this->voidingId), $this->reason);
        $this->cancelVoid();
        $this->dispatch('notify', message: 'Sale voided.');
    }
}; ?>

<div class="space-y-6">
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <x-stat-card label="Total" :value="'Kshs ' . number_format($tillTotal + $invoiceTotal, 2)" caption="Till sales and invoices, voided excluded" />
        <x-stat-card label="Till sales" :value="'Kshs ' . number_format($tillTotal, 2)" caption="Paid at the till" />
        <x-stat-card label="Invoices" :value="'Kshs ' . number_format($invoiceTotal, 2)" caption="Credit sales issued" />
        <x-stat-card label="Still unpaid" :value="'Kshs ' . number_format($unpaid, 2)" caption="On those invoices" />
    </div>

    <x-card class="p-4">
        <div class="flex flex-wrap items-end gap-4">
            <div><x-input-label for="h_from" value="From" /><x-text-input wire:model.live="from" id="h_from" type="date" class="mt-1" /></div>
            <div><x-input-label for="h_to" value="To" /><x-text-input wire:model.live="to" id="h_to" type="date" class="mt-1" /></div>
            <div>
                <x-input-label for="h_kind" value="Show" />
                <select wire:model.live="kind" id="h_kind" class="mt-1 text-sm">
                    <option value="all">Sales and invoices</option><option value="sale">Till sales</option><option value="invoice">Invoices</option>
                </select>
            </div>
            @if ($staff->isNotEmpty())
                <div>
                    <x-input-label for="h_user" value="Served by" />
                    <select wire:model.live="userId" id="h_user" class="mt-1 text-sm">
                        <option value="">Everyone</option>
                        @foreach ($staff as $s) <option value="{{ $s->id }}">{{ $s->name }}</option> @endforeach
                    </select>
                </div>
            @endif
        </div>
    </x-card>

    <x-card class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50"><tr>
                @foreach (['Number', 'Type', 'Date', 'Customer', 'Served by', 'Total', 'Paid', 'Status', ''] as $h)
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ $h }}</th>
                @endforeach
            </tr></thead>
            <tbody class="divide-y divide-gray-200">
                @forelse ($rows as $r)
                    <tr wire:key="h-{{ $r->kind }}-{{ $r->id }}" class="{{ $r->ok ? '' : 'opacity-60' }}">
                        <td class="px-4 py-3 font-medium">{{ \App\Services\SalesDetail::label($r->kind === 'invoice' ? 'Invoice' : 'Till', $r->number) }}</td>
                        <td class="px-4 py-3"><x-badge :color="$r->kind === 'invoice' ? 'orange' : 'gray'">{{ $r->kind === 'invoice' ? 'Invoice' : 'Till' }}</x-badge></td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ \Carbon\Carbon::parse($r->created_at)->format('d M Y, H:i') }}</td>
                        <td class="px-4 py-3">{{ $customers[$r->customer_id] ?? 'Walk-in' }}</td>
                        <td class="px-4 py-3">{{ $people[$r->user_id] ?? '-' }}</td>
                        <td class="px-4 py-3">{{ number_format($r->total, 2) }}</td>
                        <td class="px-4 py-3">{{ number_format($r->paid, 2) }}</td>
                        <td class="px-4 py-3"><x-badge :color="$r->ok ? 'green' : 'red'">{{ $r->ok ? 'OK' : 'Voided' }}</x-badge></td>
                        <td class="px-4 py-3 text-right space-x-3 whitespace-nowrap">
                            @if ($r->kind === 'invoice')
                                <a href="{{ route('invoices.show', $r->id) }}" wire:navigate class="text-brand-600 hover:underline">View</a>
                            @else
                                <a href="{{ route('sales.receipt', $r->id) }}" target="_blank" class="text-brand-600 hover:underline">Receipt</a>
                                @if ($canVoid && $r->ok)
                                    <button wire:click="startVoid({{ $r->id }})" class="text-red-600 hover:underline">Void</button>
                                @endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-6 text-center text-gray-500">Nothing in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-card>

    {{ $rows->links() }}

    @if ($voidingId)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/40" wire:click="cancelVoid"></div>
            <x-card class="relative w-full max-w-md p-6 space-y-4 shadow-xl">
                <h3 class="text-lg font-semibold text-gray-900">Void this sale?</h3>
                <p class="text-sm text-gray-600">It stays on record, marked as voided, and the items go back into stock.</p>
                <div>
                    <x-input-label for="vr" value="Reason *" />
                    <x-text-input wire:model="reason" id="vr" type="text" maxlength="255" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('reason')" class="mt-2" />
                </div>
                <div class="flex gap-3">
                    <button type="button" wire:click="confirmVoid" class="inline-flex items-center px-4 py-2 rounded-lg bg-red-600 text-sm font-semibold text-white hover:bg-red-700">Void sale</button>
                    <x-secondary-button type="button" wire:click="cancelVoid">Keep sale</x-secondary-button>
                </div>
            </x-card>
        </div>
    @endif
</div>
