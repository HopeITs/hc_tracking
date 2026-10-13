@php
    use App\Enums\TaskPriority;
    use App\Enums\TaskStatus;
    use App\Support\FluxMarkup;

    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Task> $allTasks */
    $allTasks = $this->boardTasks;
    $canCreateTask = auth()->user()->can('create', App\Models\Task::class);

    $columnAccent = fn (TaskStatus $case): string => match ($case) {
        TaskStatus::Todo => 'bg-secondary-light',
        TaskStatus::InProgress => 'bg-status-active',
        TaskStatus::UnderReview => 'bg-status-warn',
        TaskStatus::Done => 'bg-status-good',
    };

    $columnIcon = fn (TaskStatus $case): string => match ($case) {
        TaskStatus::Todo => 'stop-circle',
        TaskStatus::InProgress => 'play-circle',
        TaskStatus::UnderReview => 'eye',
        TaskStatus::Done => 'check-circle',
    };

    $priorityChipClasses = fn (TaskPriority $case): string => match ($case) {
        TaskPriority::High => 'bg-status-critical-soft text-primary dark:text-status-critical',
        TaskPriority::Medium => 'bg-status-warn-soft text-status-warn',
        TaskPriority::Low => 'bg-secondary-soft text-secondary-medium dark:bg-white/10 dark:text-ink-muted',
    };

    $priorityIconClasses = fn (TaskPriority $case): string => match ($case) {
        TaskPriority::High => 'text-primary dark:text-status-critical',
        TaskPriority::Medium => 'text-status-warn',
        TaskPriority::Low => 'text-secondary-medium dark:text-ink-muted',
    };
@endphp

<div class="grid grid-cols-1 gap-4 @2xl/directory:grid-cols-2 @6xl/directory:grid-cols-4" x-data="{ subtasksOpen: {} }">
    @foreach (TaskStatus::cases() as $case)
        @php $columnTasks = $allTasks->where('status', $case)->values(); @endphp

        <div
            class="flex flex-col gap-3 overflow-hidden rounded-2xl border border-secondary-soft bg-secondary-soft/20 pb-3 transition-shadow dark:border-line dark:bg-white/[0.03]"
            wire:key="task-board-column-{{ $case->value }}"
            data-test="task-board-column-{{ $case->value }}"
            x-data="{ dragOver: false }"
            x-on:dragover.prevent="dragOver = true"
            x-on:dragleave="dragOver = false"
            x-on:drop.prevent="
                dragOver = false;
                const dragged = JSON.parse($event.dataTransfer.getData('text/plain') || '{}');
                if (dragged.id && dragged.status !== '{{ $case->value }}') {
                    $wire.quickSetStatus(dragged.id, '{{ $case->value }}');
                }
            "
            :class="dragOver ? 'ring-2 ring-primary ring-offset-1' : ''"
        >
            <div class="h-1 w-full shrink-0 {{ $columnAccent($case) }}"></div>

            <div class="flex items-center justify-between px-3.5">
                <div class="flex items-center gap-1.5">
                    {{ FluxMarkup::icon($columnIcon($case), 'micro', 'size-4 shrink-0 '.$columnAccent($case).' rounded-full text-white') }}
                    <span class="text-xs font-bold tracking-wide text-secondary-dark uppercase dark:text-ink">{{ $case->label() }}</span>
                </div>
                <span class="inline-flex min-w-6 items-center justify-center rounded-full bg-white px-1.5 py-0.5 text-[11px] font-bold tabular-nums text-secondary-medium shadow-sm dark:bg-surface-1 dark:text-ink-muted">{{ $columnTasks->count() }}</span>
            </div>

            <div class="flex min-h-16 flex-col gap-2 px-3">
@include('pages.tasks._board-card')
            </div>

@include('pages.tasks._board-add-row')
        </div>
    @endforeach
</div>
