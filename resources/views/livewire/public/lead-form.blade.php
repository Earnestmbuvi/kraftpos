<?php

use App\Models\Lead;
use App\Support\Marketing;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Volt\Component;

new class extends Component
{
    public string $type = 'contact';
    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $business = '';
    public string $sector = '';
    public $branches = '';
    public string $preferred = '';
    public string $message = '';
    public string $website = ''; // honeypot: real people leave it empty
    public bool $sent = false;

    public function mount(string $type = 'contact'): void
    {
        $this->type = $type === 'demo' ? 'demo' : 'contact';
    }

    public function with(): array
    {
        return ['sectors' => Marketing::sectors()];
    }

    public function submit(): void
    {
        if ($this->website !== '') {
            $this->sent = true; // quietly drop bots
            return;
        }

        $key = 'lead:' . request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('name', 'Too many requests. Please try again later.');
            return;
        }
        RateLimiter::hit($key, 3600);

        $demo = $this->type === 'demo';

        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150'],
            'phone' => [$demo ? 'required' : 'nullable', 'string', 'max:30'],
            'business' => [$demo ? 'required' : 'nullable', 'string', 'max:150'],
            'sector' => ['nullable', 'string', 'max:60'],
            'branches' => ['nullable', 'integer', 'min:1', 'max:500'],
            'preferred' => ['nullable', 'date', 'after_or_equal:today'],
            'message' => [$demo ? 'nullable' : 'required', 'string', 'max:2000'],
        ]);

        $lead = Lead::create([
            'type' => $this->type, 'name' => trim($this->name), 'email' => strtolower(trim($this->email)),
            'phone' => trim($this->phone) ?: null, 'business_name' => trim($this->business) ?: null,
            'sector' => $this->sector ?: null, 'branches_count' => $this->branches ?: null,
            'preferred_date' => $this->preferred ?: null, 'message' => trim($this->message) ?: null,
        ]);

        try {
            Mail::raw(
                ($demo ? 'New DEMO request' : 'New contact message') . "\n\n"
                . "Name: {$lead->name}\nEmail: {$lead->email}\nPhone: {$lead->phone}\nBusiness: {$lead->business_name}\n"
                . "Sector: {$lead->sector}\nBranches: {$lead->branches_count}\nPreferred date: {$lead->preferred_date?->toDateString()}\n\n{$lead->message}",
                fn ($m) => $m->to(config('kraftpos.leads_email'))->replyTo($lead->email)
                    ->subject(($demo ? 'Demo request: ' : 'Contact: ') . $lead->name)
            );
        } catch (\Throwable $e) {
            report($e); // the lead is saved even if email is not set up yet
        }

        $this->sent = true;
    }
}; ?>

<div>
    @if ($sent)
        <div class="py-10 text-center">
            <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-green-100 text-2xl text-green-600">&check;</span>
            <h3 class="mt-4 text-xl font-bold">Thank you!</h3>
            <p class="mt-1 text-sm text-gray-600">{{ $type === 'demo' ? 'We have your demo request and will contact you shortly to confirm a time.' : 'We have your message and will reply within one working day.' }}</p>
        </div>
    @else
        <form wire:submit="submit" class="space-y-4">
            <div class="hidden" aria-hidden="true"><input type="text" wire:model="website" tabindex="-1" autocomplete="off"></div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="lf_name" value="Your name *" />
                    <x-text-input wire:model="name" id="lf_name" type="text" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="lf_email" value="Email *" />
                    <x-text-input wire:model="email" id="lf_email" type="email" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('email')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="lf_phone" :value="$type === 'demo' ? 'Phone *' : 'Phone'" />
                    <x-text-input wire:model="phone" id="lf_phone" type="text" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('phone')" class="mt-1" />
                </div>

                @if ($type === 'demo')
                    <div>
                        <x-input-label for="lf_biz" value="Business name *" />
                        <x-text-input wire:model="business" id="lf_biz" type="text" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('business')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="lf_sector" value="What do you sell?" />
                        <select wire:model="sector" id="lf_sector" class="mt-1 block w-full">
                            <option value="">Choose...</option>
                            @foreach ($sectors as $s) <option value="{{ $s }}">{{ $s }}</option> @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="lf_br" value="Number of branches" />
                        <x-text-input wire:model="branches" id="lf_br" type="number" min="1" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('branches')" class="mt-1" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label for="lf_date" value="Preferred date" />
                        <x-text-input wire:model="preferred" id="lf_date" type="date" min="{{ today()->toDateString() }}" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('preferred')" class="mt-1" />
                    </div>
                @endif
            </div>

            <div>
                <x-input-label for="lf_msg" :value="$type === 'demo' ? 'Anything we should know?' : 'Message *'" />
                <textarea wire:model="message" id="lf_msg" rows="4" maxlength="2000" class="mt-1 block w-full rounded-lg border-gray-300 text-sm"></textarea>
                <x-input-error :messages="$errors->get('message')" class="mt-1" />
            </div>

            <x-primary-button class="w-full justify-center py-3" wire:loading.attr="disabled">{{ $type === 'demo' ? 'Request my demo' : 'Send message' }}</x-primary-button>
        </form>
    @endif
</div>
