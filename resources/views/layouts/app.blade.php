@php
    $menuItems = \App\Support\Menu::items(auth()->user());
    $hasPanel = \App\Support\Menu::activeGroup($menuItems) !== null;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ isset($header) ? trim(strip_tags($header)) . ' | ' : '' }}{{ config('app.name', 'KraftPOS') }}</title>
        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
        <meta name="theme-color" content="#f46a32">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased bg-gray-50">
        <x-toast />

        <livewire:layout.navigation />

        <div class="min-h-screen pt-16 {{ $hasPanel ? 'md:pl-80' : 'md:pl-24' }} print:pt-0 print:pl-0">
            @if (isset($header))
                <header class="bg-white border-b border-gray-200 print:hidden">
                    <div class="max-w-7xl mx-auto py-4 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endif

            <main>
                <x-subscription-banner />
                {{ $slot }}
            </main>
        </div>
    </body>
</html>
