@php
    use App\Enums\TaskPriority;
    use App\Enums\TaskStatus;
    use App\Support\FluxMarkup;
    use Illuminate\Support\Js;

    /**
     * The "+ Add task" row at the bottom of one group heading — same column
     * layout as _list-row so a brand-new task reads as "one more row being
     * typed", not a separate form, exactly like _list-subtask-composer does
     * for a parent's own subtasks. Every field but the title is a draft
     * (taskDrafts.{rowKey}.*): deferred wire:model / $set() calls that cost
     * no round trip until Enter on the title submits the whole row in one
     * request.
     *
     * @var string $group
     * @var string $groupKeyString
     * @var \Closure $priorityIconClasses
     */
    $rowKey = $group.'|'.$groupKeyString;
    $defaultStatus = $group === 'status' && $groupKeyString !== '' ? $groupKeyString : null;
    $milestoneId = $group === 'milestone' && $groupKeyString !== '' ? $groupKeyString : null;
    $taskDraft = $this->taskDraft($rowKey, $defaultStatus);
    $draftPriority = TaskPriority::from($taskDraft['priority']);
    $draftStatus = TaskStatus::from($taskDraft['status']);
    $draftAssignees = collect($this->userOptions)->whereIn('id', collect($taskDraft['assignees'])->map(fn ($id) => (int) $id));
@endphp

<tr wire:key="task-add-row-{{ $rowKey }}" data-test="task-add-row-{{ $group }}-{{ $groupKeyString }}">
    <td class="ps-3">
        {{ FluxMarkup::icon('plus', 'micro', 'size-4 shrink-0 text-primary dark:text-brand-text') }}
    </td>
    <td class="min-w-0 px-2 py-2.5">
        <input
            type="text"
            placeholder="{{ __('tasks.add_task') }}"
            class="w-full min-w-0 border-0 bg-transparent p-1 text-sm text-secondary-dark placeholder:text-secondary-light focus:ring-0 focus:outline-none dark:text-ink"
            wire:keydown.enter="quickCreateTask($event.target.value, {{ Js::from($defaultStatus) }}, {{ Js::from($milestoneId) }}, {{ Js::from($rowKey) }}); $event.target.value = ''"
            data-test="task-quick-add-{{ $group }}-{{ $groupKeyString }}"
        />
    </td>
    <td class="px-2">
        {{-- Trigger only: the option list lives once in _assignee-popover.blade.php. --}}
        <div
            x-data
            role="button"
            tabindex="0"
            x-on:click="$store.assigneePicker.openFor($el.dataset.test, {{ \Illuminate\Support\Js::from(collect($taskDraft['assignees'])->map(fn ($id) => (int) $id)->values()->all()) }}, $el.getBoundingClientRect(), 'toggleTaskDraftAssignee', [{{ Js::from($rowKey) }}])"
            x-on:keydown.enter="$store.assigneePicker.openFor($el.dataset.test, {{ \Illuminate\Support\Js::from(collect($taskDraft['assignees'])->map(fn ($id) => (int) $id)->values()->all()) }}, $el.getBoundingClientRect(), 'toggleTaskDraftAssignee', [{{ Js::from($rowKey) }}])"
            x-on:keydown.space.prevent="$store.assigneePicker.openFor($el.dataset.test, {{ \Illuminate\Support\Js::from(collect($taskDraft['assignees'])->map(fn ($id) => (int) $id)->values()->all()) }}, $el.getBoundingClientRect(), 'toggleTaskDraftAssignee', [{{ Js::from($rowKey) }}])"
            aria-haspopup="listbox"
            aria-label="{{ __('tasks.assignees') }}"
            class="inline-flex cursor-pointer items-center -space-x-2 rtl:space-x-reverse"
            data-test="task-add-assignee-trigger-{{ $group }}-{{ $groupKeyString }}"
        >
                @if ($draftAssignees->isEmpty())
                    <span class="inline-flex size-7 items-center justify-center rounded-full border border-dashed border-secondary-light text-secondary-light">
                        {{ FluxMarkup::icon('user-plus', 'micro', 'size-3.5') }}
                    </span>
                @else
                    @foreach ($draftAssignees->take(3) as $userOption)
                        <span class="inline-flex size-7 items-center justify-center rounded-full border-2 border-white bg-secondary-dark text-[11px] font-semibold text-white dark:border-surface-1 dark:bg-surface-3">
                            {{ Illuminate\Support\Str::of($userOption['name'])->explode(' ')->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('') }}
                        </span>
                    @endforeach
                    @if ($draftAssignees->count() > 3)
                        <span class="inline-flex size-7 items-center justify-center rounded-full border-2 border-white bg-secondary-soft text-[10px] font-semibold text-secondary-dark dark:border-surface-1 dark:bg-surface-3 dark:text-ink">
                            +{{ $draftAssignees->count() - 3 }}
                        </span>
                    @endif
                @endif
        </div>
    </td>
    <td class="px-2">
        <input
            type="date"
            wire:model="taskDrafts.{{ $rowKey }}.due_date"
            value="{{ $taskDraft['due_date'] }}"
            aria-label="{{ __('tasks.due_date_2') }}"
            class="w-full min-w-0 rounded border-0 bg-transparent p-1 text-xs tabular-nums text-secondary-medium focus:ring-1 focus:ring-primary focus:outline-none dark:text-ink-muted"
            data-test="task-add-due-date-{{ $group }}-{{ $groupKeyString }}"
        />
    </td>
    <td class="px-2">
        <button type="button" x-data aria-haspopup="menu" x-on:click="$store.taskMenu.openFor('priority', {{ Js::from('taskDrafts.'.$rowKey.'.priority') }}, '{{ $draftPriority->value }}', $el.getBoundingClientRect(), '$set', [{{ Js::from('taskDrafts.'.$rowKey.'.priority') }}])" aria-label="{{ __('tasks.priority') }}: {{ $draftPriority->label() }}" class="inline-flex size-7 cursor-pointer items-center justify-center rounded hover:bg-secondary-soft dark:hover:bg-surface-2" data-test="task-add-priority-trigger-{{ $group }}-{{ $groupKeyString }}">
                {{ FluxMarkup::priorityFlag($draftPriority) }}
            </button>
    </td>
    <td class="px-2">
        <button type="button" x-data aria-haspopup="menu" x-on:click="$store.taskMenu.openFor('status', {{ Js::from('taskDrafts.'.$rowKey.'.status') }}, '{{ $draftStatus->value }}', $el.getBoundingClientRect(), '$set', [{{ Js::from('taskDrafts.'.$rowKey.'.status') }}])" class="cursor-pointer" data-test="task-add-status-trigger-{{ $group }}-{{ $groupKeyString }}">
                {{ FluxMarkup::statusPill($draftStatus) }}
            </button>
    </td>
    <td></td>
</tr>
