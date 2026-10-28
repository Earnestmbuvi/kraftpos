<x-public-layout title="About us" description="KraftPOS is built by CodeCraft Solutions in Nairobi.">
    <section class="bg-gradient-to-br from-ink to-gray-800 py-16 text-center text-white">
        <h1 class="text-4xl font-extrabold tracking-tight">About us</h1>
        <p class="mx-auto mt-3 max-w-xl text-gray-300">Software craftsmanship, made in Nairobi.</p>
    </section>
    <section class="mx-auto max-w-3xl space-y-6 px-6 py-16 text-gray-700">
        <p class="text-lg">KraftPOS is a product of <strong>CodeCraft Solutions</strong>. We build business software that is simple enough for a busy cashier and strict enough for an accountant.</p>
        <p>We started KraftPOS because too many shops still run on notebooks and spreadsheets, or on tools that were designed somewhere else and never quite fit. Our goal is a system that understands how Kenyan businesses really trade: cash, M-Pesa, credit, many branches and tight margins.</p>
        <div class="grid gap-4 sm:grid-cols-3">
            @foreach ([['Simple', 'If it needs a manual, we redesign it.'], ['Honest', 'Reports you can trust, with a trail behind every figure.'], ['Close', 'Real people, real support, real fast.']] as [$t, $d])
                <div class="rounded-2xl bg-gray-50 p-5 text-center"><p class="font-bold text-brand-600">{{ $t }}</p><p class="mt-1 text-sm">{{ $d }}</p></div>
            @endforeach
        </div>
        <p>Want to know more, or see KraftPOS on your own products? <a class="font-semibold text-brand-600 hover:underline" href="{{ route('marketing.contact') }}">Get in touch</a>.</p>
    </section>
</x-public-layout>
