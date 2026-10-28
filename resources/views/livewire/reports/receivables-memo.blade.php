<?php

use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Services\ReportService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Reactive;
use Livewire\Volt\Component;

new class extends Component
{
    #[Reactive]
    public string $from = '';

    #[Reactive]
    public string $to = '';

    public function with(): array
    {
        abort_unless(auth()->user()->can('view-reports') && auth()->user()->can('view-cost-prices'), 403);

        [$f, $t] = ReportService::range($this->from, $this->to);

        $issued = fn () => Invoice::where('status', 'issued')->whereBetween('created_at', [$f, $t]);
        $payments = fn () => InvoicePayment::whereIn('invoice_id', $issued()->select('id'));

        $invoiced = (float) $issued()->sum('total');
        $collected = (float) $payments()->where('type', '!=', 'writeoff')->sum('amount');
        $writtenOff = (float) $payments()->where('type', 'writeoff')->sum('amount');

        $open = fn () => Invoice::where('status', 'issued')->whereColumn('amount_paid', '<', 'total');

        return [
            'count' => $issued()->count(),
            'invoiced' => $invoiced,
            'collected' => $collected,
            'writtenOff' => $writtenOff,
            'unpaid' => round($invoiced - $collected - $writtenOff, 2),
            'allOwed' => (float) $open()->sum(DB::raw('total - amount_paid')),
            'overdue' => (float) $open()->whereDate('due_date', '<', today())->sum(DB::raw('total - amount_paid')),
        ];
    }
}; ?>

<x-card class="max-w-2xl p-6">
    <h4 class="font-medium text-gray-900">Credit sales in this period</h4>
    <p class="mt-1 text-xs text-gray-500">
        {{ $count }} invoice(s) issued in this period are already counted in the revenue above, whether or not they have been paid.
    </p>

    <table class="mt-3 min-w-full text-sm">
        <tbody>
            <tr><td class="py-1">Invoiced (including tax)</td><td class="py-1 text-right">{{ number_format($invoiced, 2) }}</td></tr>
            <tr><td class="py-1 pl-4 text-gray-600">Collected so far</td><td class="py-1 text-right text-gray-600">{{ number_format($collected, 2) }}</td></tr>
            <tr><td class="py-1 pl-4 text-gray-600">Written off as bad debts</td><td class="py-1 text-right text-gray-600">{{ number_format($writtenOff, 2) }}</td></tr>
            <tr class="border-t border-gray-200 font-semibold"><td class="py-2">Still unpaid</td><td class="py-2 text-right {{ $unpaid > 0 ? 'text-red-600' : '' }}">{{ number_format($unpaid, 2) }}</td></tr>
        </tbody>
    </table>

    <div class="mt-4 border-t border-gray-200 pt-3 text-sm text-gray-700">
        <div class="flex justify-between"><span>Owed to you in total, all invoices</span><span class="font-semibold">Kshs {{ number_format($allOwed, 2) }}</span></div>
        <div class="flex justify-between"><span>Of which overdue</span><span class="font-semibold {{ $overdue > 0 ? 'text-red-600' : '' }}">Kshs {{ number_format($overdue, 2) }}</span></div>
    </div>

    <p class="mt-3 text-xs text-gray-500">
        Unpaid invoices are money owed to you, not a loss. Profit is not the same as cash: see "Money received" on the sales report for what actually came in.
    </p>
</x-card>
