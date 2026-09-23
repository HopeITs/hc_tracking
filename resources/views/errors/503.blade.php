<x-errors.page
    code="503"
    tone="active"
    icon="wrench-screwdriver"
    :trail="[__('errors.trail_hc_tracking'), __('errors.trail_scheduled_work')]"
    :terminal-label="__('errors.terminal_back_shortly')"
    :heading="__('errors.503_heading')"
    :body="__('errors.503_body')"
    :retry-after="max((int) (isset($exception) ? $exception->getHeaders()['Retry-After'] ?? 30 : 30), 10)"
>
    <x-slot:primaryAction>
        <x-ui.button tone="primary" onclick="window.location.reload()">{{ __('errors.reload_page') }}</x-ui.button>
    </x-slot:primaryAction>
</x-errors.page>
