@props(['title' => null, 'description' => 'KraftPOS is a simple point of sale for shops, pharmacies and growing businesses in Kenya.'])
@php
    $nav = [['Home', '/', 'home'], ['Features', route('marketing.features'), 'marketing.features'], ['Why us', route('marketing.why'), 'marketing.why'],
            ['About', route('marketing.about'), 'marketing.about'], ['Contact', route('marketing.contact'), 'marketing.contact']];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title . ' | ' : '' }}{{ config('app.name', 'KraftPOS') }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <meta name="theme-color" content="#f46a32">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        @keyframes kfloat { 0%,100% { transform: translateY(0) } 50% { transform: translateY(-10px) } }
        @keyframes kfade { from { opacity: 0; transform: translateY(14px) } to { opacity: 1; transform: none } }
        .k-float { animation: kfloat 6s ease-in-out infinite; }
        .k-fade { animation: kfade .8s ease both; }
        .k-fade-2 { animation: kfade .8s .15s ease both; }
        .k-fade-3 { animation: kfade .8s .3s ease both; }
        .k-grid { background-image: radial-gradient(rgba(255,255,255,.12) 1px, transparent 1px); background-size: 22px 22px; }
    </style>
</head>
<body class="flex min-h-screen flex-col bg-white font-sans text-gray-900 antialiased">
    <header x-data="{ open: false }" class="sticky top-0 z-40 border-b border-gray-100 bg-white/90 backdrop-blur">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
            <a href="/"><x-application-logo class="text-2xl text-ink" /></a>

            <nav class="hidden items-center gap-7 md:flex">
                @foreach ($nav as [$label, $url, $route])
                    <a href="{{ $url }}" class="text-sm font-medium {{ request()->routeIs($route) || ($route === 'home' && request()->is('/')) ? 'text-brand-600' : 'text-gray-600 hover:text-gray-900' }}">{{ $label }}</a>
                @endforeach
            </nav>

            <div class="hidden items-center gap-3 md:flex">

                @auth
                    <a href="{{ route('dashboard') }}" class="rounded-lg bg-brand-500 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-600">Dashboard</a>
                @else
                    <a href="{{ route('login') }}" class="text-sm font-semibold text-gray-700 hover:text-brand-600">Log in</a>
                    <a href="{{ route('marketing.demo') }}" class="rounded-lg bg-brand-500 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-600">Book a demo</a>
                @endauth
            </div>

            <button @click="open = ! open" class="rounded-lg p-2 hover:bg-gray-100 md:hidden" aria-label="Menu">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
            </button>
        </div>

        <div x-show="open" x-cloak style="display:none" class="border-t border-gray-100 px-6 py-3 md:hidden">
            @foreach ($nav as [$label, $url, $route])
                <a href="{{ $url }}" class="block py-2 text-sm font-medium text-gray-700">{{ $label }}</a>
            @endforeach
            <div class="mt-2 flex gap-3">
                <a href="{{ route('login') }}" class="flex-1 rounded-lg border border-gray-300 px-4 py-2 text-center text-sm font-semibold">Log in</a>
                <a href="{{ route('marketing.demo') }}" class="flex-1 rounded-lg bg-brand-500 px-4 py-2 text-center text-sm font-semibold text-white">Book a demo</a>
            </div>
        </div>
    </header>

    <main class="flex-1">{{ $slot }}</main>

    <footer class="bg-ink text-gray-300">
        <div class="mx-auto grid max-w-6xl gap-10 px-6 py-12 sm:grid-cols-2 lg:grid-cols-4">
            <div class="lg:col-span-2">
                <x-application-logo class="text-2xl text-white" />
                <p class="mt-3 max-w-sm text-sm text-gray-400">Sales, stock, invoices and reports for every branch of your business, built in Nairobi.</p>
                <p class="mt-4 text-sm">{{ config('kraftpos.leads_email') }}</p>
            </div>
            <div>
                <p class="text-sm font-semibold text-white">Product</p>
                <ul class="mt-3 space-y-2 text-sm">
                    <li><a class="hover:text-white" href="{{ route('marketing.features') }}">Features</a></li>
                    <li><a class="hover:text-white" href="{{ route('marketing.why') }}">Why KraftPOS</a></li>
                    <li><a class="hover:text-white" href="{{ route('marketing.demo') }}">Book a demo</a></li>
                    <li><a class="hover:text-white" href="{{ route('login') }}">Log in</a></li>
                </ul>
            </div>
            <div>
                <p class="text-sm font-semibold text-white">Company</p>
                <ul class="mt-3 space-y-2 text-sm">
                    <li><a class="hover:text-white" href="{{ route('marketing.about') }}">About us</a></li>
                    <li><a class="hover:text-white" href="{{ route('marketing.contact') }}">Contact us</a></li>
                </ul>
            </div>
        </div>
        <div class="border-t border-white/10 py-5 text-center text-xs text-gray-500">&copy; {{ date('Y') }} KraftPOS by CodeCraft Solutions. All rights reserved.</div>
    </footer>
</body>
</html>
