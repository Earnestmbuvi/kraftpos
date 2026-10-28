<x-public-layout title="Why KraftPOS" description="Why businesses choose KraftPOS for sales, stock and reporting.">
    <section class="bg-gradient-to-br from-ink to-gray-800 py-16 text-center text-white">
        <h1 class="text-4xl font-extrabold tracking-tight">Why KraftPOS</h1>
        <p class="mx-auto mt-3 max-w-xl text-gray-300">Because your records should be right, and easy.</p>
    </section>
    <section class="mx-auto max-w-5xl px-6 py-16">
        <div class="grid gap-6 md:grid-cols-2">
            @foreach ([
                ['Built for the way you trade', 'Credit sales, M-Pesa codes, zero-rated items and quotations are first-class, not afterthoughts.'],
                ['Your numbers add up', 'Sales, returns, discounts, expenses and bad debts all flow into one honest profit figure.'],
                ['Branches that stay separate', 'Each branch has its own stock and books, and owners can still see everything together.'],
                ['Control without friction', 'Cashiers sell, managers manage and owners decide, with permissions you can fine-tune per person.'],
                ['Nothing is ever deleted', 'Voids, returns and write-offs leave a trail, so mistakes are visible and staff are accountable.'],
                ['Local support', 'Built by CodeCraft Solutions in Nairobi, so you talk to the people who made it.'],
            ] as [$t, $d])
                <div class="flex gap-4 rounded-2xl border border-gray-200 p-6">
                    <span class="mt-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-500 text-white">&check;</span>
                    <div><h3 class="font-bold">{{ $t }}</h3><p class="mt-1 text-sm text-gray-600">{{ $d }}</p></div>
                </div>
            @endforeach
        </div>
        <p class="mt-12 text-center"><a href="{{ route('marketing.demo') }}" class="rounded-xl bg-brand-500 px-7 py-3 font-semibold text-white hover:bg-brand-600">See it in action</a></p>
    </section>
</x-public-layout>
