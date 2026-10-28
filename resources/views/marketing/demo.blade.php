<x-public-layout title="Book a demo" description="Book a free KraftPOS demo for your business.">
    <section class="bg-gradient-to-br from-ink to-gray-800 py-16 text-center text-white">
        <h1 class="text-4xl font-extrabold tracking-tight">Book a free demo</h1>
        <p class="mx-auto mt-3 max-w-xl text-gray-300">A 30-minute walk-through with your own kind of products. In person or online.</p>
    </section>
    <section class="mx-auto grid max-w-5xl gap-10 px-6 py-16 md:grid-cols-5">
        <div class="space-y-4 md:col-span-2">
            <h2 class="text-xl font-extrabold">What you will see</h2>
            @foreach (['A real sale, from scanning to receipt', 'Stock, expiry dates and branches', 'Invoices, credit and reports', 'Pricing and how to get started'] as $i)
                <p class="flex gap-3 text-sm text-gray-700"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-500 text-xs text-white">&check;</span>{{ $i }}</p>
            @endforeach
        </div>
        <div class="rounded-2xl border border-gray-200 p-6 md:col-span-3"><livewire:public.lead-form type="demo" /></div>
    </section>
</x-public-layout>
