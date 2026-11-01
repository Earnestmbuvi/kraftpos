@props(['business', 'class' => 'h-14'])
@php $s = \App\Support\ReceiptSettings::for($business); @endphp
@if ($business->logo_path && $s['show_logo'])
    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($business->logo_path) }}" alt="" class="mx-auto mb-1 object-contain {{ $class }}">
@endif
