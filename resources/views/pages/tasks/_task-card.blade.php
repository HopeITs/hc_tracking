{{--
    One task as a card: status, who it is with, title, due date and priority.
    Used by the Cards view.

    Expects: $task (Task with taskAssignees.employee loaded),
    $viewerEmployeeId (?int), $testPrefix (data-test prefix),
    $cardClass (width/shrink classes for where the card sits).
--}}
@php $isAssignedToMe = $viewerEmployeeId !== null && $task->taskAssignees->pluck('employee_id')->contains($viewerEmployeeId); @endphp
<div
    role="button"
    tabindex="0"
    @can('update', $task)
        wire:click="openEditDrawer({{ $task->id }})"
        x-on:keydown="if (! $event.target.closest('a') && ($event.key === 'Enter' || $event.key === ' ')) { $event.preventDefault(); $wire.openEditDrawer({{ $task->id }}) }"
    @else
        {{-- Only the status is theirs to change: open the task's own page. --}}
        x-on:click="if (! $event.target.closest('a')) { Livewire.navigate({{ Illuminate\Support\Js::from(route('tasks.show', $task)) }}) }"
        x-on:keydown="if (! $event.target.closest('a') && ($event.key === 'Enter' || $event.key === ' ')) { $event.preventDefault(); Livewire.navigate({{ Illuminate\Support\Js::from(route('tasks.show', $task)) }}) }"
    @endcan
    wire:key="{{ $testPrefix }}-{{ $task->id }}"
    data-test="{{ $testPrefix }}-card-{{ $task->id }}"
    @class([
        $cardClass,
        'flex flex-col gap-2 rounded-xl border p-3 text-start shadow-sm transition-shadow hover:shadow-md dark:bg-surface-1',
        'border-primary bg-white dark:border-status-critical' => $isAssignedToMe,
        'border-dashed border-secondary-light bg-secondary-soft/30 dark:border-line dark:bg-white/[0.03]' => ! $isAssignedToMe,
    ])
>
    <div class="flex items-center justify-between gap-2">
        <x-ui.status-pill :case="$task->status" />
        @if ($isAssignedToMe)
            <span class="inline-flex shrink-0 items-center gap-1 text-[10px] font-bold text-primary dark:text-status-critical" data-test="{{ $testPrefix }}-role-assigned-{{ $task->id }}">
                <flux:icon.user variant="micro" class="size-3" aria-hidden="true" />
                {{ __('tasks.assigned_to_you') }}
            </span>
        @endif
    </div>
    @unless ($isAssignedToMe)
        @php $assigneeName = $task->taskAssignees->first()?->employee?->name; @endphp
        @if ($assigneeName !== null)
            <span class="inline-flex items-start gap-1 text-[10px] font-semibold text-secondary-medium dark:text-ink-muted" data-test="{{ $testPrefix }}-role-delegated-{{ $task->id }}">
                <flux:icon.paper-airplane variant="micro" class="mt-0.5 size-3 shrink-0" aria-hidden="true" />
                <span class="break-words">{{ __('tasks.assigned_to_name', ['name' => $assigneeName]) }}</span>
            </span>
        @endif
    @endunless
    <a
        href="{{ route('tasks.show', $task) }}"
        wire:navigate
        title="{{ $task->title }}"
        dir="auto"
        x-on:click.stop
        class="truncate text-sm font-semibold text-secondary-dark hover:text-primary hover:underline dark:text-ink dark:hover:text-status-critical"
        data-test="{{ $testPrefix }}-title-{{ $task->id }}"
    >{{ $task->title }}</a>
    <div class="flex items-center justify-between text-xs text-secondary-medium dark:text-ink-muted">
        <span @class(['font-semibold text-status-critical' => $task->isOverdue() || $task->isMissed()])>
            {{ $task->due_date?->translatedFormat(__($task->due_date->isCurrentYear() ? 'formats.date_short' : 'formats.date')) ?? __('tasks.no_due_date') }}
        </span>
        <x-ui.enum-badge :case="$task->priority" />
    </div>
</div>
