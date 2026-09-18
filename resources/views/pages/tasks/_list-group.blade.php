@php
    use App\Enums\TaskPriority;
    use App\Enums\TaskStatus;
    use Illuminate\Support\Js;

    /** @var \Illuminate\Support\Collection<int, \App\Models\Task> $groupTasks */
@endphp

<div class="@container">
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
                            <a
                                href="{{ route('tasks.show', $task) }}"
                                wire:navigate
                                title="{{ $task->title }}"
                                @class(['min-w-0 truncate text-sm font-semibold hover:underline', 'text-secondary-light line-through' => $isDone, 'text-secondary-dark dark:text-white' => ! $isDone])
                            >{{ $task->title }}</a>
                            @if ($task->subtasks_count > 0)
                                <span class="inline-flex shrink-0 items-center gap-0.5 text-xs text-secondary-medium dark:text-secondary-soft" title="{{ __('tasks.subtask_count', ['count' => $task->subtasks_count]) }}">
                                    <flux:icon.rectangle-stack variant="micro" class="size-3.5" aria-hidden="true" />
                                    {{ $task->subtasks_count }}
                                </span>
                            @endif
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
                        <button
                            type="button"
                            wire:click="openEditDrawer({{ $task->id }})"
                            class="inline-flex cursor-pointer items-center rounded-full hover:ring-2 hover:ring-secondary-soft dark:hover:ring-secondary-medium"
                            aria-label="{{ __('tasks.assignee') }}: {{ $task->assignee?->name ?? __('common.not_assigned') }} — {{ __('tasks.edit_task') }}"
                            data-test="task-assignee-trigger-{{ $task->id }}"
                        >
                            @if ($task->assignee === null)
                                <span class="inline-flex size-7 items-center justify-center rounded-full border border-dashed border-secondary-light text-secondary-light">
                                    <flux:icon.user variant="micro" class="size-3.5" />
                                </span>
                            @else
                                <span class="inline-flex size-7 items-center justify-center rounded-full bg-secondary-dark text-[11px] font-semibold text-white dark:bg-secondary-medium">
                                    {{ Illuminate\Support\Str::of($task->assignee->name)->explode(' ')->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('') }}
                                </span>
                            @endif
                        </button>
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
                            <x-ui.button tone="ghost" size="sm" icon="document-duplicate" class="size-8!" wire:click="duplicateTask({{ $task->id }})" :aria-label="__('tasks.duplicate_title', ['title' => $task->title])" data-test="task-duplicate-{{ $task->id }}" />
                            <x-ui.button tone="ghost" size="sm" icon="trash" class="size-8!" wire:click="confirmDelete({{ $task->id }})" :aria-label="__('tasks.delete_title', ['title' => $task->title])" />
                        </div>
                    </td>
                </tr>
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
                    @if ($task->subtasks_count > 0)
                        <span class="inline-flex items-center gap-0.5 text-xs text-secondary-medium dark:text-secondary-soft">
                            <flux:icon.rectangle-stack variant="micro" class="size-3.5" aria-hidden="true" />
                            {{ $task->subtasks_count }}
                        </span>
                    @endif
                    @if ($task->due_date !== null)
                        <span @class(['text-xs tabular-nums', 'font-semibold text-primary' => $isOverdue, 'text-secondary-medium dark:text-secondary-soft' => ! $isOverdue])>
                            {{ $task->due_date->translatedFormat(__('formats.date_short')) }}
                        </span>
                    @endif
                    <x-ui.employee-chip :employee="$task->assignee" compact class="ms-auto" />
                </div>
                <div class="flex items-center justify-end gap-1 ps-12">
                    <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-11! touch-manipulation" wire:click="openEditDrawer({{ $task->id }})" :aria-label="__('tasks.edit_title', ['title' => $task->title])" />
                    <x-ui.button tone="ghost" size="sm" icon="document-duplicate" class="size-11! touch-manipulation" wire:click="duplicateTask({{ $task->id }})" :aria-label="__('tasks.duplicate_title', ['title' => $task->title])" />
                    <x-ui.button tone="ghost" size="sm" icon="trash" class="size-11! touch-manipulation" wire:click="confirmDelete({{ $task->id }})" :aria-label="__('tasks.delete_title', ['title' => $task->title])" />
                </div>
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
                wire:keydown.enter="quickCreateTask($event.target.value, {{ $group === 'status' ? Js::from($groupKeyString) : 'null' }}, {{ $group === 'milestone' && $groupKeyString !== '' ? Js::from($groupKeyString) : 'null' }})"
                data-test="task-quick-add-{{ $group }}-{{ $groupKeyString }}"
            />
        </label>
    </div>
</div>
