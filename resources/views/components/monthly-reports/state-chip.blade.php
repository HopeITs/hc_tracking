@props(['state'])

@php
    /*
     * The state of one generated month as the list and the report header show
     * it: the stored status plus what the workflow derives from it (upcoming,
     * returned, reopen pending). Always text and an icon, never colour alone.
     */
    [$icon, $classes] = match ($state) {
        'upcoming' => ['clock', 'border-secondary-soft text-secondary-medium dark:border-line dark:text-ink-faint'],
        'draft' => ['pencil-square', 'border-secondary-light text-secondary-dark dark:border-line-strong dark:text-ink'],
        'returned' => ['arrow-uturn-left', 'border-status-warn/30 bg-status-warn-soft text-status-warn'],
        'submitted' => ['paper-airplane', 'border-secondary-dark bg-secondary-dark text-white dark:border-ink dark:bg-ink dark:text-surface-0'],
        'validated' => ['check-badge', 'border-status-good/30 bg-status-good-soft text-status-good'],
        'reopen_pending' => ['arrow-path', 'border-status-warn/30 bg-status-warn-soft text-status-warn'],
    };
@endphp

<span {{ $attributes->class(['inline-flex shrink-0 items-center gap-1 rounded-md border px-1.5 py-0.5 text-xs font-semibold', $classes]) }} data-state-chip="{{ $state }}">
    <flux:icon :name="$icon" variant="micro" @class(['size-3.5', 'rtl:-scale-x-100' => $state === 'returned']) aria-hidden="true" />
    {{ __('monthly_reports.states.'.$state) }}
</span>
