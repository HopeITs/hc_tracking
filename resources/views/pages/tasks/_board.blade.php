@php
    use App\Enums\TaskPriority;
    use App\Enums\TaskStatus;
    use App\Support\FluxMarkup;

    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Task> $allTasks */
    $allTasks = $this->boardTasks;
    $canCreateTask = auth()->user()->can('create', App\Models\Task::class);

    $columnAccent = fn (TaskStatus $case): string => $case->tone()->dotClass();

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

{{--
    Columns work like ClickUp's: a count beside each status, a "+ Add task"
    control that creates straight into that status, and an empty column
    collapses to a slim strip (a vertical strip on the wide four-column
    layout, a header-only bar when stacked) that stays a drop target. Opening
    the add control on a collapsed column widens it back for the composer.
--}}
<div class="flex flex-col gap-4 @2xl/directory:flex-row @2xl/directory:flex-wrap @6xl/directory:flex-nowrap" x-data="taskSubtaskScope">
    @foreach (TaskStatus::cases() as $case)
        @php
            $columnAll = $allTasks->where('status', $case)->values();
            $columnTotal = $columnAll->count();
            $columnTasks = $columnAll->take($this->boardCardLimit($case));
            $columnHidden = $columnTotal - $columnTasks->count();
            $isColumnEmpty = $columnAll->isEmpty();
        @endphp

        <div
            @class([
                'flex min-w-0 flex-col gap-3 overflow-hidden rounded-2xl border border-secondary-soft bg-secondary-soft/20 pb-3 transition-shadow @2xl/directory:basis-[calc(50%-0.5rem)] dark:border-line dark:bg-white/[0.03]',
                'self-start @6xl/directory:min-h-48 @6xl/directory:self-stretch' => $isColumnEmpty,
            ])
            wire:key="task-board-column-{{ $case->value }}"
            data-test="task-board-column-{{ $case->value }}"
            data-collapsed="{{ $isColumnEmpty ? 'true' : 'false' }}"
            x-data="{ dragOver: false, adding: false, collapsed: {{ $isColumnEmpty ? 'true' : 'false' }} }"
            x-on:dragover.prevent="dragOver = true"
            x-on:dragleave="dragOver = false"
            x-on:drop.prevent="
                dragOver = false;
                const dragged = JSON.parse($event.dataTransfer.getData('text/plain') || '{}');
                if (dragged.id && dragged.status !== '{{ $case->value }}') {
                    {{-- Move the card at once; the server's answer then confirms it, or puts it back if refused. --}}
                    const card = document.querySelector(`[data-task-id='${dragged.id}']`);
                    $el.querySelector('[data-test=task-board-cards-{{ $case->value }}]')?.prepend(card);
                    $wire.quickSetStatus(dragged.id, '{{ $case->value }}');
                }
            "
            :class="[
                dragOver ? 'ring-2 ring-primary ring-offset-1' : '',
                collapsed && ! adding ? '@6xl/directory:w-12 @6xl/directory:flex-none @6xl/directory:pb-0' : '@6xl/directory:basis-0 @6xl/directory:flex-1',
            ]"
        >
            <div class="h-1 w-full shrink-0 {{ $columnAccent($case) }}"></div>

            @if ($isColumnEmpty)
                {{-- Wide layout only: the empty column as a narrow vertical strip. --}}
                <div class="hidden flex-1 flex-col items-center gap-2 px-1" :class="adding ? '' : '@6xl/directory:flex'" data-test="task-board-strip-{{ $case->value }}">
                    {{ FluxMarkup::icon($columnIcon($case), 'micro', 'size-4 shrink-0 '.$columnAccent($case).' rounded-full text-white') }}
                    <span class="inline-flex min-w-6 items-center justify-center rounded-full bg-white px-1.5 py-0.5 text-[11px] font-bold tabular-nums text-secondary-medium shadow-sm dark:bg-surface-1 dark:text-ink-muted">{{ $columnTotal }}</span>
                    @if ($canCreateTask)
                        {{ FluxMarkup::ghostIconButton('plus', __('tasks.add_task_to_status', ['status' => $case->label()]), 'x-on:click="adding = true; $nextTick(() => $refs.boardQuickAdd?.focus())" data-test="task-board-strip-add-'.$case->value.'"', 'size-7!') }}
                    @endif
                    <span class="text-xs font-bold tracking-wide whitespace-nowrap text-secondary-dark uppercase [writing-mode:vertical-rl] dark:text-ink">{{ $case->label() }}</span>
                </div>
            @endif

            <div class="flex items-center justify-between gap-2 px-3.5" :class="collapsed && ! adding ? '@6xl/directory:hidden' : ''">
                <div class="flex min-w-0 items-center gap-1.5">
                    {{ FluxMarkup::icon($columnIcon($case), 'micro', 'size-4 shrink-0 '.$columnAccent($case).' rounded-full text-white') }}
                    <span class="truncate text-xs font-bold tracking-wide text-secondary-dark uppercase dark:text-ink">{{ $case->label() }}</span>
                    <span class="inline-flex min-w-6 shrink-0 items-center justify-center rounded-full bg-white px-1.5 py-0.5 text-[11px] font-bold tabular-nums text-secondary-medium shadow-sm dark:bg-surface-1 dark:text-ink-muted" data-test="task-board-count-{{ $case->value }}">{{ $columnTotal }}</span>
                </div>
                @if ($canCreateTask)
                    {{ FluxMarkup::ghostIconButton('plus', __('tasks.add_task_to_status', ['status' => $case->label()]), 'x-on:click="adding = true; $nextTick(() => $refs.boardQuickAdd?.focus())" data-test="task-board-header-add-'.$case->value.'"', 'size-7!') }}
                @endif
            </div>

            @unless ($isColumnEmpty)
                {{-- Side by side, a long column scrolls on its own (thin scrollbar) so the header and "+ Add task" stay in view; stacked on a phone it grows with the page. --}}
                <div class="flex flex-col gap-2 px-3 @2xl/directory:max-h-[calc(100dvh-18rem)] @2xl/directory:min-h-0 @2xl/directory:overflow-y-auto @2xl/directory:overscroll-contain [scrollbar-width:thin] [scrollbar-color:var(--color-secondary-light)_transparent] dark:[scrollbar-color:var(--color-line-strong)_transparent]" data-test="task-board-cards-{{ $case->value }}">
@include('pages.tasks._board-card')
                    @if ($columnHidden > 0)
                        {{-- The next cards load as this comes into view; the button is there for keyboards and a slow scroll. --}}
                        <button type="button" x-intersect.margin.300px="$wire.showMoreBoard('{{ $case->value }}')" wire:click="showMoreBoard('{{ $case->value }}')" wire:loading.attr="disabled" wire:target="showMoreBoard('{{ $case->value }}')" class="min-h-9 cursor-pointer rounded-xl border border-dashed border-secondary-soft text-xs font-bold text-secondary-medium hover:border-secondary-light hover:text-secondary-dark disabled:opacity-60 dark:border-line dark:text-ink-muted dark:hover:text-ink" data-test="task-board-more-{{ $case->value }}">{{ trans_choice('tasks.show_more_cards', $columnHidden, ['count' => $columnHidden]) }}</button>
                    @endif
                </div>
            @endunless

@include('pages.tasks._board-add-row')
        </div>
    @endforeach
</div>
