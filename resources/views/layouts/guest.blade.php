<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'KraftPOS') }}</title>
        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
        <meta name="theme-color" content="#f46a32">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="min-h-screen flex flex-col items-center justify-center bg-gray-50 px-4 py-10">
            <a href="/" wire:navigate>
                <x-application-logo class="text-3xl text-ink" />
            </a>
            <p class="mt-2 text-sm text-gray-500">Point of sale for growing businesses</p>

            <div class="mt-6 w-full sm:max-w-md bg-white px-6 py-6 shadow-sm ring-1 ring-gray-200 rounded-2xl">
                {{ $slot }}
            </div>

            <p class="mt-6 text-xs text-gray-400">&copy; {{ date('Y') }} KraftPOS by CodeCraft Solutions</p>
        </div>
    </body>
</html>
