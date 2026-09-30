@php
    use App\Enums\TaskPriority;
    use App\Enums\TaskStatus;

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
    $draftAssignees = collect($this->userOptions)->whereIn('id', collect($subtaskDraft['assignees'])->map(fn ($id) => (int) $id));
@endphp

<tr x-show="subtasksOpen[{{ $task->id }}]" x-cloak wire:key="task-subtask-composer-{{ $task->id }}" class="bg-secondary-soft/20 dark:bg-white/[0.03]" data-test="task-subtask-add-row-{{ $task->id }}">
    <td class="ps-3">
        <flux:icon.plus variant="micro" class="size-4 shrink-0 text-primary dark:text-brand-text" aria-hidden="true" />
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
        <x-ui.multi-select-popover
            :label="__('tasks.assignees')"
            class="inline-flex cursor-pointer items-center -space-x-2 rtl:space-x-reverse"
            data-test="task-subtask-assignee-trigger-{{ $task->id }}"
        >
            <x-slot:trigger>
                @if ($draftAssignees->isEmpty())
                    <span class="inline-flex size-7 items-center justify-center rounded-full border border-dashed border-secondary-light text-secondary-light">
                        <flux:icon.user-plus variant="micro" class="size-3.5" />
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
            </x-slot:trigger>

            @foreach ($this->userOptions as $userOption)
                @php $isDraftAssigned = in_array((string) $userOption['id'], $subtaskDraft['assignees'], true); @endphp
                <button
                    type="button"
                    role="option"
                    aria-selected="{{ $isDraftAssigned ? 'true' : 'false' }}"
                    wire:click="toggleSubtaskDraftAssignee({{ $task->id }}, {{ $userOption['id'] }})"
                    wire:key="task-{{ $task->id }}-subtask-draft-assignee-{{ $userOption['id'] }}"
                    x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())"
                    class="flex w-full cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm hover:bg-secondary-soft dark:hover:bg-white/[0.06]"
                    data-test="task-subtask-assignee-option-{{ $task->id }}-{{ $userOption['id'] }}"
                >
                    <span class="min-w-0 flex-1 truncate">{{ $userOption['name'] }} · {{ $userOption['secondary_name'] }}</span>
                    @if ($isDraftAssigned)
                        <flux:icon.check class="size-4 shrink-0 text-primary dark:text-brand-text" />
                    @endif
                </button>
            @endforeach
        </x-ui.multi-select-popover>
    </td>
    <td class="px-2">
        <input
            type="date"
            wire:model="subtaskDrafts.{{ $task->id }}.due_date"
            value="{{ $subtaskDraft['due_date'] }}"
            aria-label="{{ __('tasks.due_date_2') }}"
            class="w-full min-w-0 rounded border-0 bg-transparent p-1 text-xs tabular-nums text-secondary-medium focus:ring-1 focus:ring-primary focus:outline-none dark:text-ink-muted"
            data-test="task-subtask-due-date-{{ $task->id }}"
        />
    </td>
    <td class="px-2">
        <flux:dropdown position="bottom" align="start">
            <button type="button" aria-label="{{ __('tasks.priority') }}: {{ $draftPriority->label() }}" class="inline-flex size-7 cursor-pointer items-center justify-center rounded hover:bg-secondary-soft dark:hover:bg-surface-2" data-test="task-subtask-priority-trigger-{{ $task->id }}">
                <flux:icon.flag :variant="$draftPriority === TaskPriority::Low ? 'outline' : 'solid'" class="size-4 {{ $priorityIconClasses($draftPriority) }}" />
            </button>
            <flux:menu>
                @foreach (TaskPriority::cases() as $priorityCase)
                    <flux:menu.item wire:click="$set('subtaskDrafts.{{ $task->id }}.priority', '{{ $priorityCase->value }}')" :wire:key="'task-'.$task->id.'-subtask-draft-priority-'.$priorityCase->value">
                        <flux:icon.flag :variant="$priorityCase === TaskPriority::Low ? 'outline' : 'solid'" class="size-4 shrink-0 {{ $priorityIconClasses($priorityCase) }}" />
                        <span class="min-w-0 truncate">{{ $priorityCase->label() }}</span>
                        @if ($draftPriority === $priorityCase)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                    </flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>
    </td>
    <td class="px-2">
        <flux:dropdown position="bottom" align="start">
            <button type="button" class="cursor-pointer" data-test="task-subtask-status-trigger-{{ $task->id }}">
                <x-ui.status-pill :case="$draftStatus" />
            </button>
            <flux:menu>
                @foreach (TaskStatus::cases() as $statusCase)
                    <flux:menu.item wire:click="$set('subtaskDrafts.{{ $task->id }}.status', '{{ $statusCase->value }}')" :wire:key="'task-'.$task->id.'-subtask-draft-status-'.$statusCase->value" :data-test="'task-subtask-status-option-'.$task->id.'-'.$statusCase->value">
                        <x-ui.status-pill :case="$statusCase" />
                        @if ($draftStatus === $statusCase)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                    </flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>
    </td>
    <td></td>
</tr>
