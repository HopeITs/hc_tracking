<?php

use Livewire\Component;
use Livewire\Attributes\Title;

new #[Title('titles.appearance_settings')] class extends Component {
    //
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('settings.appearance_settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('common.appearance')" :subheading="__('settings.update_the_appearance_settings_for_your_account')">
        <flux:radio.group x-data variant="segmented" x-model="$flux.appearance">
            <flux:radio value="light" icon="sun">{{ __('settings.light') }}</flux:radio>
            <flux:radio value="dark" icon="moon">{{ __('settings.dark') }}</flux:radio>
            <flux:radio value="system" icon="computer-desktop">{{ __('settings.system') }}</flux:radio>
        </flux:radio.group>
    </x-pages::settings.layout>
</section>
