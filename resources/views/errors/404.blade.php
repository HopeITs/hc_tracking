<x-errors.page
    code="404"
    tone="neutral"
    icon="question-mark-circle"
    :trail="[__('common.programs'), __('common.projects'), __('common.milestones')]"
    :terminal-label="__('errors.terminal_this_page')"
    :heading="__('errors.404_heading')"
    :body="__('errors.404_body')"
>
    <x-slot:secondaryAction>
        <x-ui.button tone="secondary" onclick="history.back()">{{ __('errors.go_back') }}</x-ui.button>
    </x-slot:secondaryAction>
    <x-slot:primaryAction>
        <x-ui.button tone="primary" :href="route('dashboard')">{{ __('errors.back_to_dashboard') }}</x-ui.button>
    </x-slot:primaryAction>
</x-errors.page>
