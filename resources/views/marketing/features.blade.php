<x-public-layout title="Features" description="Everything KraftPOS does: till, invoices, stock, expiry, branches, reports and tax.">
    <section class="bg-gradient-to-br from-ink to-gray-800 py-16 text-center text-white">
        <h1 class="text-4xl font-extrabold tracking-tight">Features</h1>
        <p class="mx-auto mt-3 max-w-xl text-gray-300">Powerful where it counts, simple where it should be.</p>
    </section>
    <section class="mx-auto max-w-6xl px-6 py-16">
        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach (\App\Support\Marketing::features() as [$title, $icon, $text])
                <div class="rounded-2xl border border-gray-200 p-6 transition hover:border-brand-500 hover:shadow-md">
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-50 text-brand-600">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="{{ \App\Support\Menu::icon($icon) }}"/></svg>
                    </span>
                    <h3 class="mt-4 font-bold">{{ $title }}</h3><p class="mt-1 text-sm text-gray-600">{{ $text }}</p>
                </div>
            @endforeach
            <div class="rounded-2xl border-2 border-dashed border-brand-500 bg-brand-50 p-6">
                <h3 class="font-bold text-brand-700">Coming next: M-Pesa prompts</h3>
                <p class="mt-1 text-sm text-gray-700">Send a payment prompt to your customer's phone from the till and confirm it automatically.</p>
            </div>
        </div>
        <p class="mt-12 text-center"><a href="{{ route('marketing.demo') }}" class="rounded-xl bg-brand-500 px-7 py-3 font-semibold text-white hover:bg-brand-600">Book a demo</a></p>
    </section>
</x-public-layout>
