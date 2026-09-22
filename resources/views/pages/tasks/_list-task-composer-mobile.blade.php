@php
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
        <flux:icon.plus variant="micro" class="size-4 shrink-0 text-primary" aria-hidden="true" />
        <span class="sr-only">{{ __('tasks.add_task') }}</span>
        <input
            type="text"
            placeholder="{{ __('tasks.add_task') }}"
            class="w-full min-w-0 border-0 bg-transparent p-1 text-sm text-secondary-dark placeholder:text-secondary-light focus:ring-0 focus:outline-none dark:text-white"
            wire:keydown.enter="quickCreateTask($event.target.value, {{ Illuminate\Support\Js::from($defaultStatus) }}, {{ Illuminate\Support\Js::from($group === 'milestone' && $groupKeyString !== '' ? $groupKeyString : null) }}, {{ Illuminate\Support\Js::from($rowKey) }}); $event.target.value = ''"
            data-test="task-quick-add-mobile-{{ $group }}-{{ $groupKeyString }}"
        />
    </label>
    <div class="flex flex-wrap items-center gap-2 ps-6">
        <x-ui.multi-select-popover
            :label="__('tasks.assignees')"
            class="inline-flex shrink-0 cursor-pointer items-center -space-x-2 rtl:space-x-reverse"
            data-test="task-add-assignee-trigger-mobile-{{ $group }}-{{ $groupKeyString }}"
        >
            <x-slot:trigger>
                @if ($draftAssignees->isEmpty())
                    <span class="inline-flex size-7 items-center justify-center rounded-full border border-dashed border-secondary-light text-secondary-light">
                        <flux:icon.user-plus variant="micro" class="size-3.5" />
                    </span>
                @else
                    @foreach ($draftAssignees->take(3) as $userOption)
                        <span class="inline-flex size-7 items-center justify-center rounded-full border-2 border-white bg-secondary-dark text-[10px] font-semibold text-white dark:border-secondary-dark dark:bg-secondary-medium">
                            {{ Illuminate\Support\Str::of($userOption['name'])->explode(' ')->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('') }}
                        </span>
                    @endforeach
                    @if ($draftAssignees->count() > 3)
                        <span class="inline-flex size-7 items-center justify-center rounded-full border-2 border-white bg-secondary-soft text-[9px] font-semibold text-secondary-dark dark:border-secondary-dark dark:bg-secondary-medium dark:text-white">
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
                    wire:click="toggleTaskDraftAssignee({{ Illuminate\Support\Js::from($rowKey) }}, {{ $userOption['id'] }})"
                    wire:key="task-add-mobile-{{ $group }}-{{ $groupKeyString }}-assignee-{{ $userOption['id'] }}"
                    x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())"
                    class="flex w-full cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm hover:bg-secondary-soft dark:hover:bg-secondary-medium"
                    data-test="task-add-assignee-option-mobile-{{ $group }}-{{ $groupKeyString }}-{{ $userOption['id'] }}"
                >
                    <span class="min-w-0 flex-1 truncate">{{ $userOption['name'] }} · {{ $userOption['secondary_name'] }}</span>
                    @if ($isDraftAssigned)
                        <flux:icon.check class="size-4 shrink-0 text-primary" />
                    @endif
                </button>
            @endforeach
        </x-ui.multi-select-popover>

        <input
            type="date"
            wire:model="taskDrafts.{{ $rowKey }}.due_date"
            value="{{ $taskDraft['due_date'] }}"
            aria-label="{{ __('tasks.due_date_2') }}"
            class="w-28 shrink-0 rounded border-0 bg-transparent p-1 text-xs tabular-nums text-secondary-medium focus:ring-1 focus:ring-primary focus:outline-none dark:text-secondary-soft"
            data-test="task-add-due-date-mobile-{{ $group }}-{{ $groupKeyString }}"
        />

        <select
            wire:model="taskDrafts.{{ $rowKey }}.priority"
            aria-label="{{ __('tasks.priority') }}"
            class="shrink-0 rounded-md border border-secondary-soft bg-white px-1.5 py-1 text-xs text-secondary-dark dark:border-secondary-medium dark:bg-secondary-dark dark:text-white"
            data-test="task-add-priority-mobile-{{ $group }}-{{ $groupKeyString }}"
        >
            @foreach (TaskPriority::cases() as $priorityCase)
                <option value="{{ $priorityCase->value }}" @selected($taskDraft['priority'] === $priorityCase->value)>{{ $priorityCase->label() }}</option>
            @endforeach
        </select>

        <select
            wire:model="taskDrafts.{{ $rowKey }}.status"
            aria-label="{{ __('common.status') }}"
            class="shrink-0 rounded-md border border-secondary-soft bg-white px-1.5 py-1 text-xs text-secondary-dark dark:border-secondary-medium dark:bg-secondary-dark dark:text-white"
            data-test="task-add-status-mobile-{{ $group }}-{{ $groupKeyString }}"
        >
            @foreach (TaskStatus::cases() as $statusCase)
                <option value="{{ $statusCase->value }}" @selected($taskDraft['status'] === $statusCase->value)>{{ $statusCase->label() }}</option>
            @endforeach
        </select>
    </div>
</div>
