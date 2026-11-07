<?php

use App\Models\DocumentText;
use App\Models\Invoice;
use App\Models\Quotation;
use Livewire\Volt\Component;

new class extends Component
{
    public string $kind = 'invoice';
    public int $documentId;
    public $termsId = '';
    public $paymentId = '';

    public function mount(string $kind, int $documentId): void
    {
        $this->kind = $kind === 'quotation' ? 'quotation' : 'invoice';
        $this->documentId = $documentId;

        $d = $this->doc();
        $this->termsId = (string) ($d->terms_text_id ?? '');
        $this->paymentId = (string) ($d->payment_text_id ?? '');
    }

    // Tenant and branch scopes apply, so another shop's document gives a 404
    private function doc()
    {
        $u = auth()->user();

        if ($this->kind === 'invoice') {
            abort_unless($u->can('issue-invoices'), 403);

            return Invoice::findOrFail($this->documentId);
        }

        abort_unless($u->can('make-sales'), 403);
        $q = Quotation::findOrFail($this->documentId);
        abort_unless($u->can('view-reports') || $q->user_id === $u->id, 403);

        return $q;
    }

    private function editable($d): bool
    {
        return $this->kind === 'invoice'
            ? $d->status === 'issued'
            : in_array($d->status, ['open', 'accepted'], true);
    }

    private function options(string $kind, $currentId)
    {
        return DocumentText::where('kind', $kind)
            ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', (int) $currentId))
            ->orderBy('name')->get(['id', 'name']);
    }

    public function with(): array
    {
        $d = $this->doc();

        return [
            'd' => $d,
            'editable' => $this->editable($d),
            'termsList' => $this->options('terms', $this->termsId),
            'paymentList' => $this->options('payment', $this->paymentId),
        ];
    }

    public function updatedTermsId($value): void
    {
        $this->apply('terms', $value);
    }

    public function updatedPaymentId($value): void
    {
        $this->apply('payment', $value);
    }

    private function apply(string $which, $id): void
    {
        $d = $this->doc();
        abort_unless($this->editable($d), 403);

        $text = null;
        $textId = null;

        if ($id !== '' && $id !== null) {
            $t = DocumentText::active()->where('kind', $which)->find((int) $id);
            abort_unless($t, 404);
            $text = $t->body;
            $textId = $t->id;
        }

        $d->{$which . '_text'} = $text;
        $d->{$which . '_text_id'} = $textId;
        $d->save();
    }
}; ?>

<div class="space-y-4">
    @if ($editable && ($termsList->count() || $paymentList->count()))
        <div class="grid gap-3 rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm sm:grid-cols-2 print:hidden">
            <div>
                <label for="tb_pay" class="text-xs font-medium text-gray-600">Choose payment details</label>
                <select wire:model.live="paymentId" id="tb_pay" class="mt-1 block w-full text-sm">
                    <option value="">None</option>
                    @foreach ($paymentList as $t) <option value="{{ $t->id }}">{{ $t->name }}</option> @endforeach
                </select>
            </div>
            <div>
                <label for="tb_terms" class="text-xs font-medium text-gray-600">Choose terms</label>
                <select wire:model.live="termsId" id="tb_terms" class="mt-1 block w-full text-sm">
                    <option value="">None</option>
                    @foreach ($termsList as $t) <option value="{{ $t->id }}">{{ $t->name }}</option> @endforeach
                </select>
            </div>
            <p class="text-xs text-gray-500 sm:col-span-2">This changes only this document. Save and edit your texts under Setup, Terms and payment details.</p>
        </div>
    @endif

    @if (filled($d->payment_text))
        <div class="border-t border-gray-200 pt-4 text-xs text-gray-700">
            <p class="mb-1 font-semibold uppercase tracking-wide text-gray-500">Payment details</p>
            <p class="whitespace-pre-line">{{ $d->payment_text }}</p>
        </div>
    @endif

    @if (filled($d->terms_text))
        <div class="border-t border-gray-200 pt-4 text-xs text-gray-600">
            <p class="mb-1 font-semibold uppercase tracking-wide text-gray-500">Terms and conditions</p>
            <p class="whitespace-pre-line">{{ $d->terms_text }}</p>
        </div>
    @endif
</div>
