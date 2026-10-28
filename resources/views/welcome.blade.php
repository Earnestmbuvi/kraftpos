<x-public-layout title="Point of sale for Kenyan businesses">
    {{-- Hero --}}
    <section class="relative overflow-hidden bg-gradient-to-br from-orange-50 via-orange-100/60 to-white">
        <div class="pointer-events-none absolute -right-40 -top-40 h-[34rem] w-[34rem] rounded-full bg-brand-500"></div>
        <div class="pointer-events-none absolute -right-10 top-40 h-52 w-52 rounded-full bg-orange-300/70"></div>
        <div class="pointer-events-none absolute -left-24 bottom-0 h-72 w-72 rounded-full bg-orange-200/60 blur-2xl"></div>

        <div class="relative mx-auto grid max-w-6xl items-center gap-12 px-6 py-20 lg:grid-cols-2 lg:py-28">
            <div>
                <span class="k-fade inline-block rounded-full bg-ink px-4 py-1 text-xs font-semibold tracking-wide text-orange-200">Built for shops, pharmacies and growing chains</span>
                <h1 class="k-fade-2 mt-5 text-4xl font-extrabold leading-tight tracking-tight text-gray-900 sm:text-5xl">
                    Run every till, branch and shelf <span class="text-brand-600">from one place</span>
                </h1>
                <p class="k-fade-3 mt-5 max-w-xl text-lg font-medium text-gray-700">
                    KraftPOS handles sales, credit, stock, expiry dates, expenses and tax, so you spend your time growing the business, not counting it.
                </p>
                <div class="k-fade-3 mt-8 flex flex-wrap gap-3">
                    <a href="{{ route('marketing.demo') }}" class="rounded-xl bg-brand-600 px-7 py-3 font-semibold text-white shadow-lg shadow-brand-500/40 hover:bg-brand-700">Book a free demo</a>
                    <a href="{{ route('marketing.features') }}" class="rounded-xl border-2 border-ink px-7 py-3 font-semibold text-ink hover:bg-ink hover:text-white">See features</a>
                </div>
            </div>

            {{-- Dashboard mock-up --}}
            <div class="k-float relative mx-auto w-full max-w-md">
                <div class="rounded-2xl bg-white p-5 text-gray-900 shadow-2xl ring-1 ring-black/5">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-bold">Today</p>
                        <span class="rounded-full bg-green-100 px-2 py-0.5 text-[11px] font-semibold text-green-800">+18%</span>
                    </div>
                    <p class="mt-1 text-3xl font-extrabold">Kshs 84,250</p>
                    <svg viewBox="0 0 300 80" class="mt-3 w-full"><polygon points="0,80 0,60 40,52 80,58 120,34 160,40 200,18 240,28 300,8 300,80" fill="rgba(244,106,50,.18)"/><polyline points="0,60 40,52 80,58 120,34 160,40 200,18 240,28 300,8" fill="none" stroke="#f46a32" stroke-width="3" stroke-linecap="round"/></svg>
                    <div class="mt-3 grid grid-cols-3 gap-2 text-center text-xs font-medium text-gray-700">
                        <div class="rounded-xl bg-orange-100 p-2"><p class="text-base font-bold text-brand-700">126</p>Sales</div>
                        <div class="rounded-xl bg-green-100 p-2"><p class="text-base font-bold text-green-800">4</p>Branches</div>
                        <div class="rounded-xl bg-sky-100 p-2"><p class="text-base font-bold text-sky-800">12</p>Low stock</div>
                    </div>
                </div>
                <div class="absolute -bottom-5 -left-6 hidden rounded-xl bg-white px-4 py-3 text-xs text-gray-900 shadow-xl ring-1 ring-black/5 sm:block">
                    <p class="font-semibold">M-Pesa received</p><p class="font-bold text-green-700">Kshs 2,400 &check;</p>
                </div>
            </div>
        </div>
    </section>

    {{-- Stats band --}}
    <section class="bg-ink">
        <div class="mx-auto grid max-w-6xl grid-cols-2 gap-6 px-6 py-9 text-center sm:grid-cols-4">
            @foreach ([['1 system', 'Till, stock, credit and reports'], ['Many branches', 'Each with its own books'], ['Expiry aware', 'Soonest-expiring sold first'], ['Excel ready', 'Export every report']] as [$a, $b])
                <div><p class="text-xl font-extrabold text-brand-500">{{ $a }}</p><p class="mt-1 text-sm text-gray-200">{{ $b }}</p></div>
            @endforeach
        </div>
    </section>

    {{-- Features --}}
    <section class="bg-white">
        <div class="mx-auto max-w-6xl px-6 py-20">
            <div class="mx-auto max-w-2xl text-center">
                <h2 class="text-3xl font-extrabold tracking-tight text-gray-900">Everything your business needs</h2>
                <p class="mt-3 font-medium text-gray-700">From the first sale of the morning to the income statement at month end.</p>
            </div>
            <div class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach (\App\Support\Marketing::features() as [$title, $icon, $text])
                    <div class="group rounded-2xl border border-orange-200 bg-orange-50/50 p-6 transition hover:-translate-y-1 hover:border-brand-500 hover:bg-white hover:shadow-lg">
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-500 text-white">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="{{ \App\Support\Menu::icon($icon) }}"/></svg>
                        </span>
                        <h3 class="mt-4 font-bold text-gray-900">{{ $title }}</h3>
                        <p class="mt-1 text-sm text-gray-700">{{ $text }}</p>
                    </div>
                @endforeach
            </div>
            <p class="mt-8 text-center"><a href="{{ route('marketing.features') }}" class="font-semibold text-brand-700 hover:underline">Explore all features &rarr;</a></p>
        </div>
    </section>

    {{-- How it works --}}
    <section class="bg-orange-100/70">
        <div class="mx-auto max-w-6xl px-6 py-20">
            <h2 class="text-center text-3xl font-extrabold tracking-tight text-gray-900">Up and running in three steps</h2>
            <div class="mt-12 grid gap-6 md:grid-cols-3">
                @foreach ([['1', 'We set you up', 'Tell us about your business. We create your account, branches and staff.'], ['2', 'Add your products', 'Import a CSV or add items one by one, with stock, prices and tax.'], ['3', 'Start selling', 'Open the till, ring up sales and watch your reports fill in live.']] as [$n, $t, $d])
                    <div class="rounded-2xl bg-white p-6 text-center shadow-md ring-1 ring-orange-200">
                        <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-brand-600 text-lg font-extrabold text-white">{{ $n }}</span>
                        <h3 class="mt-4 font-bold text-gray-900">{{ $t }}</h3>
                        <p class="mt-1 text-sm text-gray-700">{{ $d }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Who it is for --}}
    <section class="bg-white">
        <div class="mx-auto max-w-6xl px-6 py-20 text-center">
            <h2 class="text-3xl font-extrabold tracking-tight text-gray-900">Made for businesses like yours</h2>
            <div class="mt-8 flex flex-wrap justify-center gap-3">
                @foreach (\App\Support\Marketing::sectors() as $s)
                    <span class="rounded-full bg-ink px-5 py-2 text-sm font-semibold text-white">{{ $s }}</span>
                @endforeach
            </div>
        </div>
    </section>

    {{-- CTA --}}
    <section class="bg-white px-6 pb-20">
        <div class="relative mx-auto max-w-6xl overflow-hidden rounded-3xl bg-ink px-8 py-14 text-center text-white">
            <div class="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full bg-brand-500"></div>
            <div class="relative">
                <h2 class="text-3xl font-extrabold tracking-tight">See KraftPOS working on your own products</h2>
                <p class="mx-auto mt-3 max-w-xl text-gray-200">A 30-minute walk-through, in person or online. No pressure and no jargon.</p>
                <a href="{{ route('marketing.demo') }}" class="mt-7 inline-block rounded-xl bg-brand-500 px-8 py-3 font-bold text-white shadow-lg hover:bg-brand-600">Book a free demo</a>
            </div>
        </div>
    </section>
</x-public-layout>
