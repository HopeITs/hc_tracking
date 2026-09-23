<x-errors.page
    code="419"
    tone="warn"
    icon="clock"
    :trail="[__('errors.trail_form_opened'), __('errors.trail_filled_in')]"
    :terminal-label="__('errors.terminal_expired')"
    :heading="__('errors.419_heading')"
    :body="__('errors.419_body')"
>
    <x-slot:secondaryAction>
        <x-ui.button tone="secondary" :href="route('dashboard')">{{ __('errors.back_to_dashboard') }}</x-ui.button>
    </x-slot:secondaryAction>
    <x-slot:primaryAction>
        <x-ui.button tone="primary" onclick="window.location.reload()">{{ __('errors.reload_page') }}</x-ui.button>
    </x-slot:primaryAction>
</x-errors.page>
