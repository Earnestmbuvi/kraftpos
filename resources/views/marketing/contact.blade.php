<x-public-layout title="Contact us" description="Talk to the KraftPOS team.">
    <section class="bg-gradient-to-br from-ink to-gray-800 py-16 text-center text-white">
        <h1 class="text-4xl font-extrabold tracking-tight">Contact us</h1>
        <p class="mx-auto mt-3 max-w-xl text-gray-300">Questions, pricing or support. We usually reply within one working day.</p>
    </section>
    <section class="mx-auto grid max-w-5xl gap-10 px-6 py-16 md:grid-cols-5">
        <div class="space-y-5 md:col-span-2">
            <div class="rounded-2xl bg-gray-50 p-5"><p class="text-xs font-semibold uppercase text-gray-500">Email</p><p class="mt-1 font-semibold">{{ config('kraftpos.leads_email') }}</p></div>
            <div class="rounded-2xl bg-gray-50 p-5"><p class="text-xs font-semibold uppercase text-gray-500">Based in</p><p class="mt-1 font-semibold">Nairobi, Kenya</p></div>
            <div class="rounded-2xl bg-brand-50 p-5"><p class="font-semibold text-brand-700">Prefer to see it first?</p><a href="{{ route('marketing.demo') }}" class="mt-1 inline-block text-sm font-semibold text-brand-600 hover:underline">Book a free demo &rarr;</a></div>
        </div>
        <div class="rounded-2xl border border-gray-200 p-6 md:col-span-3"><livewire:public.lead-form type="contact" /></div>
    </section>
</x-public-layout>
