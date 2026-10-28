@php
    $user = auth()->user();
    $b = $user && ! $user->is_super_admin ? $user->business : null;
    $state = $b?->subscription_state;
    $ends = $b?->subscription_ends_at;
@endphp

@if ($b && in_array($state, ['expiring', 'grace']))
    <div class="print:hidden border-b {{ $state === 'grace' ? 'border-red-200 bg-red-50 text-red-800' : 'border-brand-200 bg-brand-50 text-brand-700' }}">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-2 text-sm">
            @if ($state === 'expiring')
                Your KraftPOS subscription ends on <strong>{{ $ends->format('d M Y') }}</strong>. Please renew to avoid interruption.
            @else
                Your subscription ended on <strong>{{ $ends->format('d M Y') }}</strong>. Your account will be suspended on
                <strong>{{ $ends->copy()->addDays((int) config('billing.grace_days') + 1)->format('d M Y') }}</strong> unless you renew.
            @endif
        </div>
    </div>
@endif
