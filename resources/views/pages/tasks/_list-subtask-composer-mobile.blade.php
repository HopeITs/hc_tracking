@php
    use App\Enums\TaskPriority;
    use App\Enums\TaskStatus;

    /**
     * Mobile counterpart to _list-subtask-composer — same draft fields, laid
     * out for a narrow card instead of table columns.
     *
     * @var \App\Models\Task $task Parent task.
     */
    $subtaskDraft = $this->subtaskDraft($task);
    $draftAssignees = collect($this->userOptions)->whereIn('id', collect($subtaskDraft['assignees'])->map(fn ($id) => (int) $id));
@endphp

<div x-show="subtasksOpen[{{ $task->id }}]" x-cloak class="flex flex-col gap-1.5 p-3.5 pt-0 ps-[4.5rem]" data-test="task-subtask-add-row-mobile-{{ $task->id }}">
    <label class="flex items-center gap-2">
        <flux:icon.plus variant="micro" class="size-3.5 shrink-0 text-primary dark:text-brand-text" aria-hidden="true" />
        <span class="sr-only">{{ __('tasks.add_subtask') }}</span>
        <input
            type="text"
            x-ref="subtaskAddInputMobile{{ $task->id }}"
            placeholder="{{ __('tasks.add_subtask') }}"
            class="w-full min-w-0 border-0 bg-transparent p-1 text-sm text-secondary-dark placeholder:text-secondary-light focus:ring-0 focus:outline-none dark:text-ink"
            wire:keydown.enter="addSubtaskQuick({{ $task->id }}, $event.target.value); $event.target.value = ''"
            data-test="task-subtask-quick-add-mobile-{{ $task->id }}"
        />
    </label>
    <div class="flex flex-wrap items-center gap-2 ps-6">
        <x-ui.multi-select-popover
            :label="__('tasks.assignees')"
            class="inline-flex shrink-0 cursor-pointer items-center -space-x-2 rtl:space-x-reverse"
            data-test="task-subtask-assignee-trigger-mobile-{{ $task->id }}"
        >
            <x-slot:trigger>
                @if ($draftAssignees->isEmpty())
                    <span class="inline-flex size-7 items-center justify-center rounded-full border border-dashed border-secondary-light text-secondary-light">
                        <flux:icon.user-plus variant="micro" class="size-3.5" />
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
            </x-slot:trigger>

            @foreach ($this->userOptions as $userOption)
                @php $isDraftAssigned = in_array((string) $userOption['id'], $subtaskDraft['assignees'], true); @endphp
                <button
                    type="button"
                    role="option"
                    aria-selected="{{ $isDraftAssigned ? 'true' : 'false' }}"
                    wire:click="toggleSubtaskDraftAssignee({{ $task->id }}, {{ $userOption['id'] }})"
                    wire:key="task-{{ $task->id }}-subtask-draft-assignee-mobile-{{ $userOption['id'] }}"
                    x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())"
                    class="flex w-full cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm hover:bg-secondary-soft dark:hover:bg-white/[0.06]"
                    data-test="task-subtask-assignee-option-mobile-{{ $task->id }}-{{ $userOption['id'] }}"
                >
                    <span class="min-w-0 flex-1 truncate">{{ $userOption['name'] }} · {{ $userOption['secondary_name'] }}</span>
                    @if ($isDraftAssigned)
                        <flux:icon.check class="size-4 shrink-0 text-primary dark:text-brand-text" />
                    @endif
                </button>
            @endforeach
        </x-ui.multi-select-popover>

        <input
            type="date"
            wire:model="subtaskDrafts.{{ $task->id }}.due_date"
            value="{{ $subtaskDraft['due_date'] }}"
            aria-label="{{ __('tasks.due_date_2') }}"
            class="w-28 shrink-0 rounded border-0 bg-transparent p-1 text-xs tabular-nums text-secondary-medium focus:ring-1 focus:ring-primary focus:outline-none dark:text-ink-muted"
            data-test="task-subtask-due-date-mobile-{{ $task->id }}"
        />

        <select
            wire:model="subtaskDrafts.{{ $task->id }}.priority"
            aria-label="{{ __('tasks.priority') }}"
            class="shrink-0 rounded-md border border-secondary-soft bg-white px-1.5 py-1 text-xs text-secondary-dark dark:border-field-line dark:bg-surface-2 dark:text-ink"
            data-test="task-subtask-priority-mobile-{{ $task->id }}"
        >
            @foreach (TaskPriority::cases() as $priorityCase)
                <option value="{{ $priorityCase->value }}" @selected($subtaskDraft['priority'] === $priorityCase->value)>{{ $priorityCase->label() }}</option>
            @endforeach
        </select>

        <select
            wire:model="subtaskDrafts.{{ $task->id }}.status"
            aria-label="{{ __('common.status') }}"
            class="shrink-0 rounded-md border border-secondary-soft bg-white px-1.5 py-1 text-xs text-secondary-dark dark:border-field-line dark:bg-surface-2 dark:text-ink"
            data-test="task-subtask-status-mobile-{{ $task->id }}"
        >
            @foreach (TaskStatus::cases() as $statusCase)
                <option value="{{ $statusCase->value }}" @selected($subtaskDraft['status'] === $statusCase->value)>{{ $statusCase->label() }}</option>
            @endforeach
        </select>
    </div>
</div>
