<x-errors.page
    code="429"
    tone="warn"
    icon="pause-circle"
    :trail="[__('errors.trail_request_sent'), __('errors.trail_request_sent'), __('errors.trail_request_sent')]"
    :terminal-label="__('errors.terminal_please_wait')"
    :heading="__('errors.429_heading')"
    :body="__('errors.429_body')"
    :retry-after="max((int) (isset($exception) ? $exception->getHeaders()['Retry-After'] ?? 10 : 10), 5)"
>
    <x-slot:primaryAction>
        <x-ui.button tone="primary" :href="route('dashboard')">{{ __('errors.back_to_dashboard') }}</x-ui.button>
    </x-slot:primaryAction>
</x-errors.page>
