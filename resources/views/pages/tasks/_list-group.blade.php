@php
    use App\Enums\TaskPriority;
    use App\Enums\TaskStatus;
    use Illuminate\Support\Js;

    /** @var \Illuminate\Support\Collection<int, \App\Models\Task> $groupTasks */
@endphp

<div class="@container" x-data="{ subtasksOpen: {} }">
    <table class="hidden w-full table-fixed border-collapse text-start text-sm @3xl:table">
        <thead class="bg-secondary-soft/30 dark:bg-secondary-medium/20">
            <tr class="[&>th]:px-3.5 [&>th]:py-2 [&>th]:text-start [&>th]:text-[11px] [&>th]:font-semibold [&>th]:tracking-wide [&>th]:text-secondary-medium [&>th]:uppercase [&>th]:dark:text-secondary-soft">
                <th class="w-9"></th>
                @include('pages.tasks._sortable-th', ['field' => 'title', 'label' => __('common.name')])
                @include('pages.tasks._sortable-th', ['field' => 'assignee', 'label' => __('tasks.assignee'), 'class' => 'w-28'])
                @include('pages.tasks._sortable-th', ['field' => 'due', 'label' => __('tasks.due_date_2'), 'class' => 'w-32'])
                @include('pages.tasks._sortable-th', ['field' => 'priority', 'label' => __('tasks.priority'), 'class' => 'w-24'])
                @include('pages.tasks._sortable-th', ['field' => 'status', 'label' => __('common.status'), 'class' => 'w-40'])
                <th class="w-20"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-secondary-soft dark:divide-secondary-medium">
            @foreach ($groupTasks as $task)
                @php
                    $isDone = $task->status === TaskStatus::Done;
                    $isOverdue = ! $isDone && $task->due_date !== null && $task->due_date->lt($today);
                    $isDueSoon = ! $isDone && ! $isOverdue && $task->due_date !== null && $task->due_date->between($today, $today->addDay());
                    $dueDateClasses = match (true) {
                        $isDone => 'text-secondary-light line-through',
                        $isOverdue => 'font-semibold text-primary',
                        $isDueSoon => 'font-medium text-status-warn',
                        default => 'text-secondary-medium dark:text-secondary-soft',
                    };
                @endphp
                <tr
                    tabindex="0"
                    wire:key="task-table-row-{{ $task->id }}"
                    data-test="task-row-{{ $task->id }}"
                    class="group/row outline-none hover:bg-secondary-soft/40 focus-visible:bg-secondary-soft/40 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary dark:hover:bg-secondary-medium/30"
                    x-on:keydown.down.prevent="$event.currentTarget.nextElementSibling?.focus()"
                    x-on:keydown.up.prevent="$event.currentTarget.previousElementSibling?.focus()"
                    x-on:keydown.enter="if (!$event.target.closest('button, a, input')) { window.location.href = {{ Js::from(route('tasks.show', $task)) }} }"
                >
                    <td class="ps-3">
                        <button
                            type="button"
                            wire:click="toggleComplete({{ $task->id }})"
                            aria-label="{{ $isDone ? __('tasks.mark_incomplete') : __('tasks.mark_complete') }}"
                            class="inline-flex size-6 cursor-pointer touch-manipulation items-center justify-center rounded-full text-secondary-medium hover:bg-secondary-soft dark:text-secondary-soft dark:hover:bg-secondary-medium"
                            data-test="task-toggle-complete-{{ $task->id }}"
                        >
                            <flux:icon.check-circle :variant="$isDone ? 'solid' : 'outline'" class="size-4.5 {{ $isDone ? 'text-status-good' : '' }}" />
                        </button>
                    </td>
                    <td class="min-w-0 px-2 py-2.5">
                        <div class="flex min-w-0 items-center gap-1.5">
                            @if ($task->parent_task_id === null)
                                <button
                                    type="button"
                                    x-on:click="subtasksOpen[{{ $task->id }}] = !subtasksOpen[{{ $task->id }}]"
                                    :aria-expanded="!!subtasksOpen[{{ $task->id }}]"
                                    class="inline-flex shrink-0 items-center gap-0.5 rounded px-1 py-0.5 text-xs text-secondary-medium hover:bg-secondary-soft hover:text-secondary-dark dark:text-secondary-soft dark:hover:bg-secondary-medium dark:hover:text-white"
                                    :aria-label="subtasksOpen[{{ $task->id }}] ? {{ Js::from(__('tasks.hide_subtasks')) }} : {{ Js::from(__('tasks.show_subtasks')) }}"
                                    data-test="task-subtasks-toggle-{{ $task->id }}"
                                >
                                    <flux:icon.chevron-right variant="micro" class="size-3.5 transition-transform rtl:rotate-180" x-bind:class="subtasksOpen[{{ $task->id }}] ? 'rotate-90 rtl:rotate-90' : ''" aria-hidden="true" />
                                    @if ($task->subtasks_count > 0)
                                        <flux:icon.rectangle-stack variant="micro" class="size-3.5" aria-hidden="true" />
                                        {{ $task->subtasks_count }}
                                    @endif
                                </button>
                            @endif
                            <a
                                href="{{ route('tasks.show', $task) }}"
                                wire:navigate
                                title="{{ $task->title }}"
                                @class(['min-w-0 truncate text-sm font-semibold hover:underline', 'text-secondary-light line-through' => $isDone, 'text-secondary-dark dark:text-white' => ! $isDone])
                            >{{ $task->title }}</a>
                        </div>
                        <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft">
                            @if ($task->milestone === null)
                                <span class="italic">{{ __('tasks.no_milestone') }}</span>
                            @else
                                {{ $task->milestone->name }}
                            @endif
                            · {{ $task->proposal->name }}
                        </p>
                    </td>
                    <td class="px-2">
                        @php
                            $assignedIds = $task->taskAssignees->pluck('employee_id')->all();
                            $assignees = $task->taskAssignees->pluck('employee')->filter();
                        @endphp
                        <x-ui.multi-select-popover
                            :label="__('tasks.assignees')"
                            :trigger-label="__('tasks.assignees').': '.($assignees->isEmpty() ? __('common.not_assigned') : $assignees->map->name->implode(', '))"
                            class="inline-flex cursor-pointer items-center -space-x-2 rtl:space-x-reverse"
                            data-test="task-assignee-trigger-{{ $task->id }}"
                        >
                            <x-slot:trigger>
                                @if ($assignees->isEmpty())
                                    <span class="inline-flex size-7 items-center justify-center rounded-full border border-dashed border-secondary-light text-secondary-light">
                                        <flux:icon.user-plus variant="micro" class="size-3.5" />
                                    </span>
                                @else
                                    @foreach ($assignees->take(3) as $employee)
                                        <div class="group/avatar relative">
                                            <span class="inline-flex size-7 items-center justify-center rounded-full border-2 border-white bg-secondary-dark text-[11px] font-semibold text-white dark:border-secondary-dark dark:bg-secondary-medium">
                                                {{ Illuminate\Support\Str::of($employee->name)->explode(' ')->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('') }}
                                            </span>
                                            <button
                                                type="button"
                                                wire:click.stop="toggleAssignee({{ $task->id }}, {{ $employee->id }})"
                                                class="absolute -top-1 -end-1 hidden size-4 cursor-pointer items-center justify-center rounded-full bg-secondary-dark text-white hover:bg-primary group-hover/avatar:flex dark:bg-secondary-medium"
                                                aria-label="{{ __('tasks.remove_assignee_name', ['name' => $employee->name]) }}"
                                                data-test="task-assignee-remove-{{ $task->id }}-{{ $employee->id }}"
                                            >
                                                <flux:icon.x-mark variant="micro" class="size-2.5" />
                                            </button>
                                        </div>
                                    @endforeach
                                    @if ($assignees->count() > 3)
                                        <span class="inline-flex size-7 items-center justify-center rounded-full border-2 border-white bg-secondary-soft text-[10px] font-semibold text-secondary-dark dark:border-secondary-dark dark:bg-secondary-medium dark:text-white">
                                            +{{ $assignees->count() - 3 }}
                                        </span>
                                    @endif
                                @endif
                            </x-slot:trigger>

                            @foreach ($this->userOptions as $userOption)
                                @php $isAssigned = in_array($userOption['id'], $assignedIds, true); @endphp
                                <button
                                    type="button"
                                    role="option"
                                    aria-selected="{{ $isAssigned ? 'true' : 'false' }}"
                                    wire:click="toggleAssignee({{ $task->id }}, {{ $userOption['id'] }})"
                                    wire:key="task-{{ $task->id }}-assignee-option-{{ $userOption['id'] }}"
                                    x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())"
                                    class="flex w-full cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm hover:bg-secondary-soft dark:hover:bg-secondary-medium"
                                    data-test="task-assignee-option-{{ $task->id }}-{{ $userOption['id'] }}"
                                >
                                    <span class="min-w-0 flex-1 truncate">{{ $userOption['name'] }} · {{ $userOption['secondary_name'] }}</span>
                                    @if ($isAssigned)
                                        <flux:icon.check class="size-4 shrink-0 text-primary" />
                                    @endif
                                </button>
                            @endforeach
                        </x-ui.multi-select-popover>
                    </td>
                    <td class="px-2">
                        <input
                            type="date"
                            value="{{ $task->due_date?->toDateString() }}"
                            wire:change="rescheduleTask({{ $task->id }}, $event.target.value)"
                            aria-label="{{ __('tasks.due_date_2') }}: {{ $task->title }}"
                            class="w-full min-w-0 rounded border-0 bg-transparent p-1 text-xs tabular-nums focus:ring-1 focus:ring-primary focus:outline-none {{ $dueDateClasses }}"
                            data-test="task-due-date-{{ $task->id }}"
                        />
                    </td>
                    <td class="px-2">
                        <flux:dropdown position="bottom" align="start">
                            <button type="button" aria-label="{{ __('tasks.priority') }}: {{ $task->priority->label() }}" class="inline-flex size-7 cursor-pointer items-center justify-center rounded hover:bg-secondary-soft dark:hover:bg-secondary-medium" data-test="task-priority-trigger-{{ $task->id }}">
                                <flux:icon.flag :variant="$task->priority === TaskPriority::Low ? 'outline' : 'solid'" class="size-4 {{ $priorityIconClasses($task->priority) }}" />
                            </button>
                            <flux:menu>
                                @foreach (TaskPriority::cases() as $priorityCase)
                                    <flux:menu.item wire:click="quickSetPriority({{ $task->id }}, '{{ $priorityCase->value }}')" :wire:key="'task-'.$task->id.'-priority-'.$priorityCase->value">
                                        <flux:icon.flag :variant="$priorityCase === TaskPriority::Low ? 'outline' : 'solid'" class="size-4 shrink-0 {{ $priorityIconClasses($priorityCase) }}" />
                                        <span class="min-w-0 truncate">{{ $priorityCase->label() }}</span>
                                        @if ($task->priority === $priorityCase)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                                    </flux:menu.item>
                                @endforeach
                            </flux:menu>
                        </flux:dropdown>
                    </td>
                    <td class="px-2">
                        <flux:dropdown position="bottom" align="start">
                            <button type="button" class="cursor-pointer" data-test="task-status-trigger-{{ $task->id }}">
                                <x-ui.status-pill :case="$task->status" />
                            </button>
                            <flux:menu>
                                @foreach (TaskStatus::cases() as $statusCase)
                                    <flux:menu.item wire:click="quickSetStatus({{ $task->id }}, '{{ $statusCase->value }}')" :wire:key="'task-'.$task->id.'-status-'.$statusCase->value" :data-test="'task-status-option-'.$task->id.'-'.$statusCase->value">
                                        <x-ui.status-pill :case="$statusCase" />
                                        @if ($task->status === $statusCase)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                                    </flux:menu.item>
                                @endforeach
                            </flux:menu>
                        </flux:dropdown>
                    </td>
                    <td class="pe-3 text-end opacity-0 group-hover/row:opacity-100 group-focus-within/row:opacity-100 focus-within:opacity-100">
                        <div class="inline-flex items-center gap-0.5">
                            <x-ui.button tone="ghost" size="sm" icon="trash" class="size-8!" wire:click="confirmDelete({{ $task->id }})" :aria-label="__('tasks.delete_title', ['title' => $task->title])" />
                        </div>
                    </td>
                </tr>
                @if ($task->parent_task_id === null)
                    <tr x-show="subtasksOpen[{{ $task->id }}]" x-cloak wire:key="task-subtasks-panel-{{ $task->id }}">
                        <td></td>
                        <td colspan="5" class="bg-secondary-soft/30 px-3.5 py-2.5 dark:bg-secondary-medium/20">
                            <div class="flex flex-col gap-1.5">
                                @foreach ($task->subtasks as $subtask)
                                    <div class="flex items-center gap-2" wire:key="task-subtask-row-{{ $subtask->id }}" data-test="task-subtask-row-{{ $subtask->id }}">
                                        <button
                                            type="button"
                                            wire:click="toggleComplete({{ $subtask->id }})"
                                            aria-label="{{ $subtask->status === TaskStatus::Done ? __('tasks.mark_incomplete') : __('tasks.mark_complete') }}"
                                            class="inline-flex size-5 shrink-0 cursor-pointer items-center justify-center rounded-full text-secondary-medium hover:bg-secondary-soft dark:text-secondary-soft dark:hover:bg-secondary-medium"
                                        >
                                            <flux:icon.check-circle :variant="$subtask->status === TaskStatus::Done ? 'solid' : 'outline'" class="size-4 {{ $subtask->status === TaskStatus::Done ? 'text-status-good' : '' }}" />
                                        </button>
                                        <a
                                            href="{{ route('tasks.show', $subtask) }}"
                                            wire:navigate
                                            title="{{ $subtask->title }}"
                                            @class(['min-w-0 flex-1 truncate text-sm hover:underline', 'text-secondary-light line-through' => $subtask->status === TaskStatus::Done, 'text-secondary-dark dark:text-white' => $subtask->status !== TaskStatus::Done])
                                        >{{ $subtask->title }}</a>
                                        <x-ui.status-pill :case="$subtask->status" class="shrink-0" />
                                    </div>
                                @endforeach
                                <label class="flex items-center gap-2 pt-0.5">
                                    <flux:icon.plus variant="micro" class="size-3.5 shrink-0 text-primary" aria-hidden="true" />
                                    <span class="sr-only">{{ __('tasks.add_subtask') }}</span>
                                    <input
                                        type="text"
                                        placeholder="{{ __('tasks.add_subtask') }}"
                                        class="w-full min-w-0 border-0 bg-transparent p-1 text-sm text-secondary-dark placeholder:text-secondary-light focus:ring-0 focus:outline-none dark:text-white"
                                        wire:keydown.enter="addSubtaskQuick({{ $task->id }}, $event.target.value); $event.target.value = ''"
                                        data-test="task-subtask-quick-add-{{ $task->id }}"
                                    />
                                </label>
                            </div>
                        </td>
                        <td></td>
                    </tr>
                @endif
            @endforeach
        </tbody>
    </table>

    {{-- Mobile / tablet fallback: stacked cards instead of a squeezed table. --}}
    <div class="divide-y divide-secondary-soft @3xl:hidden dark:divide-secondary-medium">
        @foreach ($groupTasks as $task)
            @php
                $isDone = $task->status === TaskStatus::Done;
                $isOverdue = ! $isDone && $task->due_date !== null && $task->due_date->lt($today);
            @endphp
            <article class="flex flex-col gap-3 p-3.5" wire:key="task-card-{{ $task->id }}">
                <div class="flex items-start gap-3">
                    <button
                        type="button"
                        wire:click="toggleComplete({{ $task->id }})"
                        class="inline-flex size-9 shrink-0 cursor-pointer touch-manipulation items-center justify-center rounded-full text-secondary-medium hover:bg-secondary-soft dark:text-secondary-soft dark:hover:bg-secondary-medium"
                        aria-label="{{ $isDone ? __('tasks.mark_incomplete') : __('tasks.mark_complete') }}"
                    >
                        <flux:icon.check-circle :variant="$isDone ? 'solid' : 'outline'" class="size-5 {{ $isDone ? 'text-status-good' : '' }}" />
                    </button>
                    <div class="min-w-0 flex-1">
                        <a href="{{ route('tasks.show', $task) }}" wire:navigate title="{{ $task->title }}" @class(['line-clamp-2 text-sm font-semibold break-words hover:underline', 'text-secondary-light line-through' => $isDone])>{{ $task->title }}</a>
                        <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft">
                            @if ($task->milestone === null)
                                <span class="italic">{{ __('tasks.no_milestone') }}</span>
                            @else
                                {{ $task->milestone->name }}
                            @endif
                            · {{ $task->proposal->name }}
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2 ps-12">
                    <x-ui.status-pill :case="$task->status" />
                    <flux:icon.flag :variant="$task->priority === TaskPriority::Low ? 'outline' : 'solid'" class="size-4 {{ $priorityIconClasses($task->priority) }}" />
                    @if ($task->parent_task_id === null)
                        <button
                            type="button"
                            x-on:click="subtasksOpen[{{ $task->id }}] = !subtasksOpen[{{ $task->id }}]"
                            :aria-expanded="!!subtasksOpen[{{ $task->id }}]"
                            class="inline-flex items-center gap-0.5 rounded px-1 py-0.5 text-xs text-secondary-medium hover:bg-secondary-soft dark:text-secondary-soft dark:hover:bg-secondary-medium"
                            :aria-label="subtasksOpen[{{ $task->id }}] ? {{ Js::from(__('tasks.hide_subtasks')) }} : {{ Js::from(__('tasks.show_subtasks')) }}"
                            data-test="task-subtasks-toggle-mobile-{{ $task->id }}"
                        >
                            <flux:icon.rectangle-stack variant="micro" class="size-3.5" aria-hidden="true" />
                            {{ $task->subtasks_count }}
                        </button>
                    @endif
                    @if ($task->due_date !== null)
                        <span @class(['text-xs tabular-nums', 'font-semibold text-primary' => $isOverdue, 'text-secondary-medium dark:text-secondary-soft' => ! $isOverdue])>
                            {{ $task->due_date->translatedFormat(__('formats.date_short')) }}
                        </span>
                    @endif
                    @php $mobileAssignees = $task->taskAssignees->pluck('employee')->filter(); @endphp
                    @if ($mobileAssignees->isEmpty())
                        <x-ui.employee-chip :employee="null" compact class="ms-auto" />
                    @else
                        <span class="ms-auto truncate text-xs text-secondary-medium dark:text-secondary-soft">{{ $mobileAssignees->map->name->implode(', ') }}</span>
                    @endif
                </div>
                <div class="flex items-center justify-end gap-1 ps-12">
                    <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-11! touch-manipulation" wire:click="openEditDrawer({{ $task->id }})" :aria-label="__('tasks.edit_title', ['title' => $task->title])" />
                    <x-ui.button tone="ghost" size="sm" icon="trash" class="size-11! touch-manipulation" wire:click="confirmDelete({{ $task->id }})" :aria-label="__('tasks.delete_title', ['title' => $task->title])" />
                </div>
                @if ($task->parent_task_id === null)
                    <div x-show="subtasksOpen[{{ $task->id }}]" x-cloak class="ms-12 flex flex-col gap-1.5 rounded-lg bg-secondary-soft/30 p-2.5 dark:bg-secondary-medium/20">
                        @foreach ($task->subtasks as $subtask)
                            <div class="flex items-center gap-2" wire:key="task-subtask-row-mobile-{{ $subtask->id }}" data-test="task-subtask-row-{{ $subtask->id }}">
                                <button
                                    type="button"
                                    wire:click="toggleComplete({{ $subtask->id }})"
                                    aria-label="{{ $subtask->status === TaskStatus::Done ? __('tasks.mark_incomplete') : __('tasks.mark_complete') }}"
                                    class="inline-flex size-6 shrink-0 cursor-pointer touch-manipulation items-center justify-center rounded-full text-secondary-medium hover:bg-secondary-soft dark:text-secondary-soft dark:hover:bg-secondary-medium"
                                >
                                    <flux:icon.check-circle :variant="$subtask->status === TaskStatus::Done ? 'solid' : 'outline'" class="size-4 {{ $subtask->status === TaskStatus::Done ? 'text-status-good' : '' }}" />
                                </button>
                                <a
                                    href="{{ route('tasks.show', $subtask) }}"
                                    wire:navigate
                                    title="{{ $subtask->title }}"
                                    @class(['min-w-0 flex-1 truncate text-sm hover:underline', 'text-secondary-light line-through' => $subtask->status === TaskStatus::Done, 'text-secondary-dark dark:text-white' => $subtask->status !== TaskStatus::Done])
                                >{{ $subtask->title }}</a>
                                <x-ui.status-pill :case="$subtask->status" class="shrink-0" />
                            </div>
                        @endforeach
                        <label class="flex items-center gap-2 pt-0.5">
                            <flux:icon.plus variant="micro" class="size-3.5 shrink-0 text-primary" aria-hidden="true" />
                            <span class="sr-only">{{ __('tasks.add_subtask') }}</span>
                            <input
                                type="text"
                                placeholder="{{ __('tasks.add_subtask') }}"
                                class="w-full min-w-0 border-0 bg-transparent p-1 text-sm text-secondary-dark placeholder:text-secondary-light focus:ring-0 focus:outline-none dark:text-white"
                                wire:keydown.enter="addSubtaskQuick({{ $task->id }}, $event.target.value); $event.target.value = ''"
                                data-test="task-subtask-quick-add-mobile-{{ $task->id }}"
                            />
                        </label>
                    </div>
                @endif
            </article>
        @endforeach
    </div>

    <div class="border-t border-secondary-soft px-3.5 py-2 dark:border-secondary-medium" wire:key="task-add-row-{{ $group }}-{{ $groupKeyString }}">
        <label class="flex items-center gap-2">
            <flux:icon.plus variant="micro" class="size-4 shrink-0 text-primary" aria-hidden="true" />
            <span class="sr-only">{{ __('tasks.add_task') }}</span>
            <input
                type="text"
                placeholder="{{ __('tasks.add_task') }}"
                class="w-full min-w-0 border-0 bg-transparent p-1 text-sm text-secondary-dark placeholder:text-secondary-light focus:ring-0 focus:outline-none dark:text-white"
                wire:keydown.enter="quickCreateTask($event.target.value, {{ $group === 'status' ? Js::from($groupKeyString) : 'null' }}, {{ $group === 'milestone' && $groupKeyString !== '' ? Js::from($groupKeyString) : 'null' }}); $event.target.value = ''"
                data-test="task-quick-add-{{ $group }}-{{ $groupKeyString }}"
            />
        </label>
    </div>
</div>
