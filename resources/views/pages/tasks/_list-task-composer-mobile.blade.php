@php
    use App\Support\FluxMarkup;
    use App\Enums\TaskPriority;
    use App\Enums\TaskStatus;

    /**
     * Mobile counterpart to _list-task-composer — same draft fields, laid
     * out for a narrow card instead of table columns.
     *
     * @var string $group
     * @var string $groupKeyString
     */
    $rowKey = $group.'|'.$groupKeyString;
    $defaultStatus = $group === 'status' && $groupKeyString !== '' ? $groupKeyString : null;
    $taskDraft = $this->taskDraft($rowKey, $defaultStatus);
    $draftAssignees = collect($this->userOptions)->whereIn('id', collect($taskDraft['assignees'])->map(fn ($id) => (int) $id));
@endphp

<div class="flex flex-col gap-1.5 p-3.5" data-test="task-add-row-mobile-{{ $group }}-{{ $groupKeyString }}">
    <label class="flex items-center gap-2">
        {{ FluxMarkup::icon('plus', 'micro', 'size-4 shrink-0 text-primary dark:text-brand-text') }}
        <span class="sr-only">{{ __('tasks.add_task') }}</span>
        <input
            type="text"
            placeholder="{{ __('tasks.add_task') }}"
            class="w-full min-w-0 border-0 bg-transparent p-1 text-sm text-secondary-dark placeholder:text-secondary-light focus:ring-0 focus:outline-none dark:text-ink"
            wire:keydown.enter="quickCreateTask($event.target.value, {{ Illuminate\Support\Js::from($defaultStatus) }}, {{ Illuminate\Support\Js::from($group === 'milestone' && $groupKeyString !== '' ? $groupKeyString : null) }}, {{ Illuminate\Support\Js::from($rowKey) }}); $event.target.value = ''"
            data-test="task-quick-add-mobile-{{ $group }}-{{ $groupKeyString }}"
        />
    </label>
    <div class="flex flex-wrap items-center gap-2 ps-6">
        {{-- Trigger only: the option list lives once in _assignee-popover.blade.php. --}}
        <div
            x-data
            role="button"
            tabindex="0"
            x-on:click="$store.assigneePicker.openFor($el.dataset.test, {{ \Illuminate\Support\Js::from(collect($taskDraft['assignees'])->map(fn ($id) => (int) $id)->values()->all()) }}, $el.getBoundingClientRect(), 'toggleTaskDraftAssignee', [{{ Illuminate\Support\Js::from($rowKey) }}])"
            x-on:keydown.enter="$store.assigneePicker.openFor($el.dataset.test, {{ \Illuminate\Support\Js::from(collect($taskDraft['assignees'])->map(fn ($id) => (int) $id)->values()->all()) }}, $el.getBoundingClientRect(), 'toggleTaskDraftAssignee', [{{ Illuminate\Support\Js::from($rowKey) }}])"
            x-on:keydown.space.prevent="$store.assigneePicker.openFor($el.dataset.test, {{ \Illuminate\Support\Js::from(collect($taskDraft['assignees'])->map(fn ($id) => (int) $id)->values()->all()) }}, $el.getBoundingClientRect(), 'toggleTaskDraftAssignee', [{{ Illuminate\Support\Js::from($rowKey) }}])"
            aria-haspopup="listbox"
            aria-label="{{ __('tasks.assignees') }}"
            class="inline-flex shrink-0 cursor-pointer items-center -space-x-2 rtl:space-x-reverse"
            data-test="task-add-assignee-trigger-mobile-{{ $group }}-{{ $groupKeyString }}"
        >
                @if ($draftAssignees->isEmpty())
                    <span class="inline-flex size-7 items-center justify-center rounded-full border border-dashed border-secondary-light text-secondary-light">
                        {{ FluxMarkup::icon('user-plus', 'micro', 'size-3.5') }}
                    </span>
                @else
                    @foreach ($draftAssignees->take(3) as $userOption)
                        <span class="inline-flex size-7 items-center justify-center rounded-full border-2 border-white bg-secondary-dark text-[10px] font-semibold text-white dark:border-surface-1 dark:bg-surface-3">
                            {{ Illuminate\Support\Str::of($userOption['name'])->explode(' ')->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('') }}
                        </span>
                    @endforeach
                    @if ($draftAssignees->count() > 3)
                        <span class="inline-flex size-7 items-center justify-center rounded-full border-2 border-white bg-secondary-soft text-[9px] font-semibold text-secondary-dark dark:border-surface-1 dark:bg-surface-3 dark:text-ink">
                            +{{ $draftAssignees->count() - 3 }}
                        </span>
                    @endif
                @endif
        </div>

        <input
            type="date"
            wire:model="taskDrafts.{{ $rowKey }}.due_date"
            value="{{ $taskDraft['due_date'] }}"
            aria-label="{{ __('tasks.due_date_2') }}"
            class="w-28 shrink-0 rounded border-0 bg-transparent p-1 text-xs tabular-nums text-secondary-medium focus:ring-1 focus:ring-secondary-light focus:outline-none dark:text-ink-muted"
            data-test="task-add-due-date-mobile-{{ $group }}-{{ $groupKeyString }}"
        />

        <select
            wire:model="taskDrafts.{{ $rowKey }}.priority"
            aria-label="{{ __('tasks.priority') }}"
            class="shrink-0 rounded-md border border-secondary-soft bg-white px-1.5 py-1 text-xs text-secondary-dark dark:border-field-line dark:bg-surface-2 dark:text-ink"
            data-test="task-add-priority-mobile-{{ $group }}-{{ $groupKeyString }}"
        >
            @foreach (TaskPriority::cases() as $priorityCase)
                <option value="{{ $priorityCase->value }}" @selected($taskDraft['priority'] === $priorityCase->value)>{{ $priorityCase->label() }}</option>
            @endforeach
        </select>

        <select
            wire:model="taskDrafts.{{ $rowKey }}.status"
            aria-label="{{ __('common.status') }}"
            class="shrink-0 rounded-md border border-secondary-soft bg-white px-1.5 py-1 text-xs text-secondary-dark dark:border-field-line dark:bg-surface-2 dark:text-ink"
            data-test="task-add-status-mobile-{{ $group }}-{{ $groupKeyString }}"
        >
            @foreach (TaskStatus::cases() as $statusCase)
                <option value="{{ $statusCase->value }}" @selected($taskDraft['status'] === $statusCase->value)>{{ $statusCase->label() }}</option>
            @endforeach
        </select>
    </div>
</div>
