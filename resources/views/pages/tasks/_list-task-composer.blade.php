@php
    use App\Enums\TaskPriority;
    use App\Enums\TaskStatus;
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
        <flux:icon.plus variant="micro" class="size-4 shrink-0 text-primary" aria-hidden="true" />
    </td>
    <td class="min-w-0 px-2 py-2.5">
        <input
            type="text"
            placeholder="{{ __('tasks.add_task') }}"
            class="w-full min-w-0 border-0 bg-transparent p-1 text-sm text-secondary-dark placeholder:text-secondary-light focus:ring-0 focus:outline-none dark:text-white"
            wire:keydown.enter="quickCreateTask($event.target.value, {{ Js::from($defaultStatus) }}, {{ Js::from($milestoneId) }}, {{ Js::from($rowKey) }}); $event.target.value = ''"
            data-test="task-quick-add-{{ $group }}-{{ $groupKeyString }}"
        />
    </td>
    <td class="px-2">
        <x-ui.multi-select-popover
            :label="__('tasks.assignees')"
            class="inline-flex cursor-pointer items-center -space-x-2 rtl:space-x-reverse"
            data-test="task-add-assignee-trigger-{{ $group }}-{{ $groupKeyString }}"
        >
            <x-slot:trigger>
                @if ($draftAssignees->isEmpty())
                    <span class="inline-flex size-7 items-center justify-center rounded-full border border-dashed border-secondary-light text-secondary-light">
                        <flux:icon.user-plus variant="micro" class="size-3.5" />
                    </span>
                @else
                    @foreach ($draftAssignees->take(3) as $userOption)
                        <span class="inline-flex size-7 items-center justify-center rounded-full border-2 border-white bg-secondary-dark text-[11px] font-semibold text-white dark:border-secondary-dark dark:bg-secondary-medium">
                            {{ Illuminate\Support\Str::of($userOption['name'])->explode(' ')->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('') }}
                        </span>
                    @endforeach
                    @if ($draftAssignees->count() > 3)
                        <span class="inline-flex size-7 items-center justify-center rounded-full border-2 border-white bg-secondary-soft text-[10px] font-semibold text-secondary-dark dark:border-secondary-dark dark:bg-secondary-medium dark:text-white">
                            +{{ $draftAssignees->count() - 3 }}
                        </span>
                    @endif
                @endif
            </x-slot:trigger>

            @foreach ($this->userOptions as $userOption)
                @php $isDraftAssigned = in_array((string) $userOption['id'], $taskDraft['assignees'], true); @endphp
                <button
                    type="button"
                    role="option"
                    aria-selected="{{ $isDraftAssigned ? 'true' : 'false' }}"
                    wire:click="toggleTaskDraftAssignee({{ Js::from($rowKey) }}, {{ $userOption['id'] }})"
                    wire:key="task-add-{{ $group }}-{{ $groupKeyString }}-assignee-{{ $userOption['id'] }}"
                    x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())"
                    class="flex w-full cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm hover:bg-secondary-soft dark:hover:bg-secondary-medium"
                    data-test="task-add-assignee-option-{{ $group }}-{{ $groupKeyString }}-{{ $userOption['id'] }}"
                >
                    <span class="min-w-0 flex-1 truncate">{{ $userOption['name'] }} · {{ $userOption['secondary_name'] }}</span>
                    @if ($isDraftAssigned)
                        <flux:icon.check class="size-4 shrink-0 text-primary" />
                    @endif
                </button>
            @endforeach
        </x-ui.multi-select-popover>
    </td>
    <td class="px-2">
        <input
            type="date"
            wire:model="taskDrafts.{{ $rowKey }}.due_date"
            value="{{ $taskDraft['due_date'] }}"
            aria-label="{{ __('tasks.due_date_2') }}"
            class="w-full min-w-0 rounded border-0 bg-transparent p-1 text-xs tabular-nums text-secondary-medium focus:ring-1 focus:ring-primary focus:outline-none dark:text-secondary-soft"
            data-test="task-add-due-date-{{ $group }}-{{ $groupKeyString }}"
        />
    </td>
    <td class="px-2">
        <flux:dropdown position="bottom" align="start">
            <button type="button" aria-label="{{ __('tasks.priority') }}: {{ $draftPriority->label() }}" class="inline-flex size-7 cursor-pointer items-center justify-center rounded hover:bg-secondary-soft dark:hover:bg-secondary-medium" data-test="task-add-priority-trigger-{{ $group }}-{{ $groupKeyString }}">
                <flux:icon.flag :variant="$draftPriority === TaskPriority::Low ? 'outline' : 'solid'" class="size-4 {{ $priorityIconClasses($draftPriority) }}" />
            </button>
            <flux:menu>
                @foreach (TaskPriority::cases() as $priorityCase)
                    <flux:menu.item wire:click="$set('taskDrafts.{{ $rowKey }}.priority', '{{ $priorityCase->value }}')" :wire:key="'task-add-'.$group.'-'.$groupKeyString.'-priority-'.$priorityCase->value">
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
            <button type="button" class="cursor-pointer" data-test="task-add-status-trigger-{{ $group }}-{{ $groupKeyString }}">
                <x-ui.status-pill :case="$draftStatus" />
            </button>
            <flux:menu>
                @foreach (TaskStatus::cases() as $statusCase)
                    <flux:menu.item wire:click="$set('taskDrafts.{{ $rowKey }}.status', '{{ $statusCase->value }}')" :wire:key="'task-add-'.$group.'-'.$groupKeyString.'-status-'.$statusCase->value" :data-test="'task-add-status-option-'.$group.'-'.$groupKeyString.'-'.$statusCase->value">
                        <x-ui.status-pill :case="$statusCase" />
                        @if ($draftStatus === $statusCase)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                    </flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>
    </td>
    <td></td>
</tr>
