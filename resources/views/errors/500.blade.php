<x-errors.page
    code="500"
    tone="critical"
    icon="bolt"
    :trail="[__('errors.trail_request_sent'), __('errors.trail_server'), __('errors.trail_processing')]"
    :terminal-label="__('errors.terminal_interrupted')"
    :heading="__('errors.500_heading')"
    :body="__('errors.500_body')"
>
    <x-slot:secondaryAction>
        <x-ui.button tone="secondary" :href="route('dashboard')">{{ __('errors.back_to_dashboard') }}</x-ui.button>
    </x-slot:secondaryAction>
    <x-slot:primaryAction>
        <x-ui.button tone="primary" onclick="window.location.reload()">{{ __('errors.reload_page') }}</x-ui.button>
    </x-slot:primaryAction>
</x-errors.page>
