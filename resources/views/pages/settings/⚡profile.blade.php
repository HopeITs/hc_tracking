<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('titles.profile_settings')] class extends Component {
    public string $name = '';
    public string $email = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('settings.profile_settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('common.profile')" :subheading="__('settings.your_name_and_email_come_from_the_hr_system')">
        <div class="my-6 w-full space-y-6">
            <flux:input wire:model="name" :label="__('common.name')" type="text" readonly />

            <flux:input wire:model="email" :label="__('common.email')" type="email" readonly />

            <flux:text data-test="hr-profile-notice">
                {{ __('settings.to_change_your_name_or_email_update_your_record_in_the_hr_sy') }}
            </flux:text>
        </div>
    </x-pages::settings.layout>
</section>
