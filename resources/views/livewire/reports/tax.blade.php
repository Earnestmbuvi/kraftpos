<?php

use App\Services\ReportService;
use Livewire\Volt\Component;

new class extends Component
{
    public string $from = '';
    public string $to = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('view-reports'), 403);
        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->toDateString();
    }

    public function with(): array
    {
        [$f, $t] = ReportService::range($this->from, $this->to);

        return ['d' => ReportService::tax($f, $t)];
    }
}; ?>

<div class="space-y-6">
    <x-card class="p-4">
        <div class="flex flex-wrap items-end gap-4">
            <div><x-input-label for="t_from" value="From" /><x-text-input wire:model.live="from" id="t_from" type="date" class="mt-1" /></div>
            <div><x-input-label for="t_to" value="To" /><x-text-input wire:model.live="to" id="t_to" type="date" class="mt-1" /></div>
            <button type="button" onclick="window.print()" class="ml-auto rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Print</button>
            <a href="{{ route('reports.export', ['report' => 'tax', 'from' => $from, 'to' => $to]) }}"
               class="rounded-lg border border-brand-500 px-4 py-2 text-sm font-semibold text-brand-600 hover:bg-brand-50">Export CSV</a>
        </div>
    </x-card>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-stat-card label="Output tax" :value="'Kshs ' . number_format($d['output'], 2)" caption="Charged on sales, less returns" />
        <x-stat-card label="Input tax" :value="'Kshs ' . number_format($d['input'], 2)" caption="Claimed on expenses" />
        <x-stat-card label="Net tax payable" :value="'Kshs ' . number_format($d['net'], 2)" caption="Output minus input" />
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-card class="p-6">
            <h4 class="font-medium text-gray-900">Sales by tax class</h4>
            <table class="mt-3 min-w-full text-sm"><tbody class="divide-y divide-gray-100">
                @foreach ($d['classes'] as $c)
                    <tr wire:key="tc-{{ $c['label'] }}"><td class="py-2">{{ $c['label'] }}</td><td class="py-2 text-right font-semibold">{{ number_format($c['value'], 2) }}</td></tr>
                @endforeach
            </tbody></table>
            <p class="mt-3 text-xs text-gray-500">Line totals after line discounts, before any whole-cart discount.</p>
        </x-card>

        <x-card class="p-6">
            <h4 class="font-medium text-gray-900">Tax payable</h4>
            <table class="mt-3 min-w-full text-sm"><tbody class="divide-y divide-gray-100">
                <tr><td class="py-2">Output tax on till sales</td><td class="py-2 text-right">{{ number_format($d['outSales'], 2) }}</td></tr>
                <tr><td class="py-2">Output tax on invoices</td><td class="py-2 text-right">{{ number_format($d['outInvoices'], 2) }}</td></tr>
                <tr><td class="py-2 text-gray-600">Less: tax on returns</td><td class="py-2 text-right text-gray-600">({{ number_format($d['returnsTax'], 2) }})</td></tr>
                <tr class="font-semibold"><td class="py-2">Total output tax</td><td class="py-2 text-right">{{ number_format($d['output'], 2) }}</td></tr>
                <tr><td class="py-2 text-gray-600">Less: input tax</td><td class="py-2 text-right text-gray-600">({{ number_format($d['input'], 2) }})</td></tr>
                <tr class="border-t-2 border-gray-300 text-base font-bold"><td class="py-3">Net tax payable</td><td class="py-3 text-right {{ $d['net'] < 0 ? 'text-green-700' : '' }}">{{ number_format($d['net'], 2) }}</td></tr>
            </tbody></table>
            <p class="mt-3 text-xs text-gray-500">A negative figure means you are owed a refund or can carry it forward.</p>
        </x-card>
    </div>

    <x-card class="overflow-x-auto">
        <div class="border-b border-gray-200 px-4 py-3 font-medium text-gray-900">Input tax entries</div>
        <table class="min-w-full text-sm"><tbody class="divide-y divide-gray-100">
            @forelse ($d['inputList'] as $e)
                <tr wire:key="it-{{ $e->id }}">
                    <td class="px-4 py-2 whitespace-nowrap">{{ $e->expense_date->format('d M Y') }}</td>
                    <td class="px-4 py-2">{{ $e->description }} <span class="text-xs text-gray-500">({{ $e->category_name }})</span></td>
                    <td class="px-4 py-2 text-right text-gray-500">{{ number_format($e->amount, 2) }}</td>
                    <td class="px-4 py-2 text-right font-semibold">{{ number_format($e->input_tax, 2) }}</td>
                </tr>
            @empty
                <tr><td class="px-4 py-6 text-center text-gray-500">No input tax recorded. Add it in the Expenses form.</td></tr>
            @endforelse
        </tbody></table>
    </x-card>
</div>
