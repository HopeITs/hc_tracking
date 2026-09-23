<x-errors.page
    code="403"
    tone="critical"
    icon="lock-closed"
    :trail="[__('errors.trail_your_account'), __('errors.trail_this_workspace')]"
    :terminal-label="__('errors.terminal_restricted')"
    :heading="__('errors.403_heading')"
    :body="__('errors.403_body')"
>
    <x-slot:secondaryAction>
        <x-ui.button tone="secondary" onclick="history.back()">{{ __('errors.go_back') }}</x-ui.button>
    </x-slot:secondaryAction>
    <x-slot:primaryAction>
        <x-ui.button tone="primary" :href="route('dashboard')">{{ __('errors.back_to_dashboard') }}</x-ui.button>
    </x-slot:primaryAction>
</x-errors.page>
