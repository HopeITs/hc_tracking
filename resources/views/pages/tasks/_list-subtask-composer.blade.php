@php
    use App\Enums\TaskPriority;
    use App\Enums\TaskStatus;
    use App\Support\FluxMarkup;
    use Illuminate\Support\Js;

    /**
     * The "add subtask" row directly under a parent's own row (and its
     * existing subtasks) — same column layout as _list-row so it reads as
     * "one more row being typed", not a separate form. Every field but the
     * title is a draft (subtaskDrafts.{parent}.*): deferred wire:model /
     * $set() calls that cost no round trip until Enter on the title submits
     * the whole row in one request.
     *
     * @var \App\Models\Task $task Parent task.
     * @var \Closure $priorityIconClasses
     */
    $subtaskDraft = $this->subtaskDraft($task);
    $draftPriority = TaskPriority::from($subtaskDraft['priority']);
    $draftStatus = TaskStatus::from($subtaskDraft['status']);
    $draftAssigneeIds = collect($subtaskDraft['assignees'])->map(fn ($id) => (int) $id)->values()->all();
    $draftAssignees = collect($this->userOptions)->whereIn('id', $draftAssigneeIds);
    // Opens the one shared _assignee-popover instead of rendering every employee here.
    $draftPicker = "\$store.assigneePicker.openFor('list-subtask-{$task->id}', ".Js::from($draftAssigneeIds).", \$el.getBoundingClientRect(), 'toggleSubtaskDraftAssignee', [{$task->id}])";
@endphp

<tr x-show="subtasksOpen[{{ $task->id }}]" x-cloak wire:key="task-subtask-composer-{{ $task->id }}" class="bg-secondary-soft/20 dark:bg-white/[0.03]" data-test="task-subtask-add-row-{{ $task->id }}">
    <td class="ps-3">
        {{ FluxMarkup::icon('plus', 'micro', 'size-4 shrink-0 text-primary dark:text-brand-text') }}
    </td>
    <td class="min-w-0 px-2 py-2.5">
        <input
            type="text"
            x-ref="subtaskAddInput{{ $task->id }}"
            placeholder="{{ __('tasks.add_subtask') }}"
            class="w-full min-w-0 border-0 bg-transparent p-1 text-sm text-secondary-dark placeholder:text-secondary-light focus:ring-0 focus:outline-none {{ 'ps-5' }} dark:text-ink"
            wire:keydown.enter="addSubtaskQuick({{ $task->id }}, $event.target.value); $event.target.value = ''"
            data-test="task-subtask-quick-add-{{ $task->id }}"
        />
    </td>
    <td class="px-2">
        <div
            x-data
            role="button"
            tabindex="0"
            x-on:click="{{ $draftPicker }}"
            x-on:keydown.enter="{{ $draftPicker }}"
            x-on:keydown.space.prevent="{{ $draftPicker }}"
            aria-haspopup="listbox"
            aria-label="{{ __('tasks.assignees') }}"
            class="inline-flex cursor-pointer items-center -space-x-2 rtl:space-x-reverse"
            data-test="task-subtask-assignee-trigger-{{ $task->id }}"
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
            wire:model="subtaskDrafts.{{ $task->id }}.due_date"
            value="{{ $subtaskDraft['due_date'] }}"
            aria-label="{{ __('tasks.due_date_2') }}"
            class="w-full min-w-0 rounded border-0 bg-transparent p-1 text-xs tabular-nums text-secondary-medium focus:ring-1 focus:ring-secondary-light focus:outline-none dark:text-ink-muted"
            data-test="task-subtask-due-date-{{ $task->id }}"
        />
    </td>
    <td class="px-2">
        <button type="button" x-data aria-haspopup="menu" x-on:click="$store.taskMenu.openFor('priority', {{ Js::from('subtaskDrafts.'.$task->id.'.priority') }}, '{{ $draftPriority->value }}', $el.getBoundingClientRect(), '$set', [{{ Js::from('subtaskDrafts.'.$task->id.'.priority') }}])" aria-label="{{ __('tasks.priority') }}: {{ $draftPriority->label() }}" class="inline-flex size-7 cursor-pointer items-center justify-center rounded hover:bg-secondary-soft dark:hover:bg-surface-2" data-test="task-subtask-priority-trigger-{{ $task->id }}">
                {{ FluxMarkup::priorityFlag($draftPriority) }}
            </button>
    </td>
    <td class="px-2">
        <button type="button" x-data aria-haspopup="menu" x-on:click="$store.taskMenu.openFor('status', {{ Js::from('subtaskDrafts.'.$task->id.'.status') }}, '{{ $draftStatus->value }}', $el.getBoundingClientRect(), '$set', [{{ Js::from('subtaskDrafts.'.$task->id.'.status') }}])" class="cursor-pointer" data-test="task-subtask-status-trigger-{{ $task->id }}">
                {{ FluxMarkup::statusPill($draftStatus) }}
            </button>
    </td>
    <td></td>
</tr>
