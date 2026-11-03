@props(['task' => null, 'paused' => null, 'compact' => false])

{{-- "Paused" on a task whose project sits on an inactive HR center (D-66,
     W-02). Text with an icon, never colour alone; the due date and status
     stay untouched, nothing is blocked. Pass the task (its `source` and the
     milestone's `project:id,...,center_id` must already be loaded, so a list
     costs no query per row) or an already resolved `paused` flag. Renders
     nothing for a task whose project is active or that has no project.
     `compact` shows the one-word label (desk rows are one clipped line) and
     keeps the full sentence as the title and for screen readers. --}}
@php
    $showChip = $paused ?? ($task !== null && $task->isOfPausedProject());
@endphp

@if ($showChip === true)
    <span {{ $attributes->class('inline-flex shrink-0 items-center gap-1 rounded-full bg-status-warn-soft px-2 py-0.5 text-[11px] font-bold text-secondary-dark dark:bg-surface-2 dark:text-ink') }} data-test="paused-chip" @if ($compact) title="{{ __('tasks.paused_center_inactive') }}" @endif>
        <flux:icon name="pause-circle" variant="micro" class="size-3 shrink-0" aria-hidden="true" />
        @if ($compact)
            <span aria-hidden="true">{{ __('tasks.paused') }}</span>
            <span class="sr-only">{{ __('tasks.paused_center_inactive') }}</span>
        @else
            <span>{{ __('tasks.paused_center_inactive') }}</span>
        @endif
    </span>
@endif
