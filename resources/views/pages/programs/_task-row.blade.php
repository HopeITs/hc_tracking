{{--
    One task on the program's Tasks tab, editable in place.

    Expects $task (with taskAssignees.employee and source loaded), $rowKey,
    $subtasks and $parentTitle.
    A nested subtask passes $nested = true and no subtasks of its own.

    Status and priority open the page's shared task menu, the assignees open
    the shared assignee popover, and ⋯ opens the tab's shared "more" panel;
    each control only shows as editable when the policy allows it.
--}}
@php
    use App\Enums\TaskStatus;
    use App\Support\FluxMarkup;

    $nested ??= false;
    $user = auth()->user();
    $assignees = $task->taskAssignees->pluck('employee')->filter()->values();
    $assignedIds = $task->taskAssignees->pluck('employee_id')->map(fn ($id): int => (int) $id)->values()->all();
    $isDone = $task->status === TaskStatus::Done;
    $today = \Carbon\CarbonImmutable::today();
    $isOverdue = ! $isDone && $task->due_date !== null && $task->due_date->lt($today);
    $daysLate = $isOverdue ? (int) $task->due_date->diffInDays($today) : 0;
    $canUpdate = $user?->can('update', $task) === true;
    $canStatus = ! $isDone && $user?->can('updateStatus', $task) === true;
    $canReschedule = $canUpdate && $task->created_by === $user?->id;
    $canDelete = $user?->can('delete', $task) === true;
    $canSubtask = ! $nested && $task->parent_task_id === null && $user?->can('create', \App\Models\Task::class) === true;
    $subtaskDone = $subtasks->where('status', TaskStatus::Done)->count();
    $moreDetail = [
        'id' => $task->id,
        'href' => route('tasks.show', $task),
        'canDelete' => $canDelete && $subtasks->isEmpty(),
    ];
@endphp

<li @class(['dk-ptk-row', 'dk-done' => $isDone, 'dk-ptk-nested' => $nested]) wire:key="task-row-{{ $rowKey }}" data-task="{{ $task->id }}"
    x-data="{
        subOpen: false,
        adding: false,
        title: '',
        renaming: false,
        newTitle: '',
        startRename() {
            this.newTitle = {{ Js::from($task->title) }};
            this.renaming = true;
            this.$nextTick(() => { this.$refs.rename?.focus(); this.$refs.rename?.select() });
        },
        saveRename() {
            if (! this.renaming) { return }
            this.renaming = false;
            const next = this.newTitle.trim();
            if (next !== '' && next !== {{ Js::from($task->title) }}) { this.$wire.renameProgramTask({{ $task->id }}, next) }
        },
        startSubtask() {
            this.subOpen = true;
            this.adding = true;
            this.$nextTick(() => requestAnimationFrame(() => this.$refs.subtask?.focus()));
        },
        stopSubtask() {
            if (! this.adding || this.title.trim() !== '') { return }
            this.title = '';
            this.adding = false;
            if (! {{ $subtasks->isNotEmpty() ? 'true' : 'false' }}) { this.subOpen = false }
        },
    }"
>
    <div class="dk-ptk-line">
        <div class="dk-ptk-title">
            {{-- Caret (when it has subtasks), then as on the Tasks page: an unread dot, a report task's badge,
                 and a report task opens its report. On hover: + adds a subtask, the pencil renames in place. --}}
            <div class="dk-ptk-title-l">
                @if ($subtasks->isNotEmpty())
                    <button type="button" class="dk-ptk-caret" @click="subOpen = ! subOpen" :aria-expanded="subOpen.toString()" aria-label="{{ __('programs.tasks_subtasks', ['done' => $subtaskDone, 'total' => $subtasks->count()]) }}" data-task-subtasks="{{ $task->id }}">
                        <span :class="{ 'dk-ptk-caret-open': subOpen }"><x-desk.icon name="chev" class="dk-flip" /></span>
                    </button>
                @elseif (! $nested)
                    <span class="dk-ptk-caret" aria-hidden="true"></span>
                @endif
                @if (in_array($task->id, $this->unreadTaskIds, true))
                    <span class="dk-ptk-unread" data-unread-dot="task-{{ $task->id }}" title="{{ __('notifications.has_unread') }}"><span class="sr-only">{{ __('notifications.has_unread') }}</span></span>
                @endif
                @if ($task->isReportTask())
                    <x-ui.enum-badge :case="$task->kind" data-test="report-task-chip" />
                @endif
                <a href="{{ $task->reportUrl() ?? route('tasks.show', $task) }}" wire:navigate title="{{ $task->title }}" x-show="! renaming">{{ $task->title }}</a>
                @if ($canUpdate)
                    <input type="text" class="dk-ptk-rename" x-ref="rename" x-show="renaming" x-cloak x-model="newTitle" maxlength="255"
                        @keydown.enter.prevent="saveRename()" @keydown.escape.prevent="renaming = false" @blur="saveRename()"
                        aria-label="{{ __('programs.tasks_rename') }}" data-test="task-rename-input-{{ $task->id }}" />
                @endif
                @if ($subtasks->isNotEmpty())
                    <button type="button" class="dk-ptk-subcount" x-show="! renaming" @click="subOpen = ! subOpen" :aria-expanded="subOpen.toString()" title="{{ __('programs.tasks_subtasks', ['done' => $subtaskDone, 'total' => $subtasks->count()]) }}" data-test="task-subtask-count-{{ $task->id }}">
                        <x-desk.icon name="subtask" /><span class="dk-num">{{ $subtasks->count() }}</span>
                    </button>
                @endif
                @if ($canSubtask || $canUpdate)
                    <span class="dk-ptk-hover" x-show="! renaming">
                        @if ($canSubtask)
                            <button type="button" class="dk-ptk-hbtn" @click="startSubtask()" aria-label="{{ __('programs.tasks_add_subtask') }}: {{ $task->title }}" data-tip="{{ __('programs.tasks_add_subtask') }}" data-test="task-add-subtask-{{ $task->id }}"><x-desk.icon name="plus" /></button>
                        @endif
                        @if ($canUpdate)
                            <button type="button" class="dk-ptk-hbtn" @click="startRename()" aria-label="{{ __('programs.tasks_rename') }}: {{ $task->title }}" data-tip="{{ __('programs.tasks_rename') }}" data-test="task-rename-{{ $task->id }}"><x-desk.icon name="edit" /></button>
                        @endif
                    </span>
                @endif
            </div>
            <p class="dk-ptk-meta">
                @if ($parentTitle !== null)
                    <span class="truncate">{{ __('programs.tasks_subtask_of', ['title' => $parentTitle]) }}</span>
                @endif
            </p>
        </div>

        {{-- Assignees: one person shows photo and name, several show up to three photos and "+N".
             A click opens the shared picker; the × on a photo removes that person straight away. --}}
        @php
            $openPicker = '$store.assigneePicker.openFor('.$task->id.', '.Js::from($assignedIds).', $el.getBoundingClientRect())';
        @endphp
        <div class="dk-ptk-who">
            <div @class(['dk-ptk-who-b', 'dk-ptk-who-off' => ! $canUpdate])
                @if ($canUpdate) role="button" tabindex="0" x-on:click="{{ $openPicker }}" x-on:keydown.enter.prevent="{{ $openPicker }}" x-on:keydown.space.prevent="{{ $openPicker }}" aria-haspopup="listbox" @endif
                aria-label="{{ __('tasks.assignees') }}: {{ $assignees->isEmpty() ? __('common.unassigned') : $assignees->map->name->implode(', ') }}"
                data-test="task-assignee-trigger-{{ $task->id }}">
                @if ($assignees->isEmpty())
                    <span class="dk-ptk-who-empty"><x-desk.icon name="users" /></span>
                @else
                    <span class="dk-ptk-who-photos">
                        @foreach ($assignees->take(3) as $employee)
                            <span class="dk-ptk-who-p" wire:key="task-{{ $rowKey }}-assignee-{{ $employee->id }}">
                                <x-ui.avatar :src="\App\Models\HrEmployee::avatarUrl($employee->id)" class="dk-av dk-av-xs" :title="$employee->name" />
                                @if ($canUpdate)
                                    <button type="button" class="dk-ptk-who-x" x-on:click.stop="$wire.removeProgramTaskAssignee({{ $task->id }}, {{ $employee->id }})" x-on:keydown.enter.stop x-on:keydown.space.stop
                                        aria-label="{{ __('tasks.remove_assignee_name', ['name' => $employee->name]) }}" data-tip="{{ __('tasks.remove_assignee') }}" data-test="task-assignee-remove-{{ $task->id }}-{{ $employee->id }}"><x-desk.icon name="x" /></button>
                                @endif
                            </span>
                        @endforeach
                    </span>
                    @if ($assignees->count() === 1)
                        <span class="dk-ptk-who-n">{{ $assignees->first()->name }}</span>
                    @elseif ($assignees->count() > 3)
                        <span class="dk-ptk-who-more dk-num" title="{{ $assignees->skip(3)->map->name->implode(', ') }}" data-test="task-assignee-more-{{ $task->id }}">+{{ $assignees->count() - 3 }}</span>
                    @endif
                @endif
            </div>
        </div>

        {{-- Priority --}}
        <div class="dk-ptk-prio">
            @if ($canUpdate)
                <button type="button" class="dk-ptk-pick" x-on:click="$store.taskMenu.openFor('priority', 'priority-{{ $task->id }}', '{{ $task->priority->value }}', $el.getBoundingClientRect(), 'setProgramTaskPriority', [{{ $task->id }}])" aria-haspopup="menu" data-test="task-priority-trigger-{{ $task->id }}">
                    {{ FluxMarkup::priorityFlag($task->priority) }}<span>{{ $task->priority->label() }}</span>
                </button>
            @else
                <span class="dk-ptk-pick">{{ FluxMarkup::priorityFlag($task->priority) }}<span>{{ $task->priority->label() }}</span></span>
            @endif
        </div>

        {{-- Due date: the creator changes it in place; late tasks say by how much. --}}
        <div @class(['dk-ptk-due', 'dk-bad' => $isOverdue])>
            @if ($canReschedule)
                <label class="dk-ptk-pick" title="{{ __('programs.tasks_change_due') }}">
                    <x-desk.icon name="cal" />
                    <span>{{ $task->due_date?->translatedFormat('M j, Y') ?? __('tasks.no_due_date') }}</span>
                    <input type="date" class="dk-ptk-date" value="{{ $task->due_date?->toDateString() }}" aria-label="{{ __('programs.tasks_change_due') }}"
                        x-on:click="$el.showPicker?.()" x-on:change="$wire.rescheduleProgramTask({{ $task->id }}, $event.target.value)" data-task-due="{{ $task->id }}" />
                </label>
            @else
                <span class="dk-ptk-pick"><x-desk.icon name="cal" /><span>{{ $task->due_date?->translatedFormat('M j, Y') ?? __('tasks.no_due_date') }}</span></span>
            @endif
            @if ($isOverdue)
                <span class="dk-ptk-late">{{ __('programs.tasks_days_late', ['count' => max(1, $daysLate)]) }}</span>
            @endif
        </div>

        {{-- Status --}}
        <div class="dk-ptk-status">
            @if ($canStatus)
                <button type="button" class="dk-ptk-pick" x-on:click="$store.taskMenu.openFor('status', 'status-{{ $task->id }}', '{{ $task->status->value }}', $el.getBoundingClientRect(), 'setProgramTaskStatus', [{{ $task->id }}])" aria-haspopup="menu" data-test="task-status-trigger-{{ $task->id }}">
                    {{ FluxMarkup::statusPill($task->status) }}
                </button>
            @else
                {{ FluxMarkup::statusPill($task->status) }}
            @endif
        </div>

        @if ($nested)
            {{-- A subtask: one click asks to remove it. --}}
            @if ($moreDetail['canDelete'])
                <button type="button" class="dk-ptk-icon-btn dk-ptk-remove" @click="openMore({{ Js::from($moreDetail) }}, $el.getBoundingClientRect(), 'delete')" aria-haspopup="dialog" aria-label="{{ __('programs.tasks_remove_subtask') }}: {{ $task->title }}" title="{{ __('programs.tasks_remove_subtask') }}" data-test="subtask-remove-{{ $task->id }}"><x-desk.icon name="x" /></button>
            @else
                <span aria-hidden="true"></span>
            @endif
        @else
            <button type="button" class="dk-ptk-icon-btn dk-ptk-more" :class="{ 'dk-ptk-more-on': more.open && more.id === {{ $task->id }} }" @click="openMore({{ Js::from($moreDetail) }}, $el.getBoundingClientRect())" aria-haspopup="menu" aria-label="{{ __('programs.tasks_more_actions') }}" data-test="task-more-{{ $task->id }}"><x-desk.icon name="more" /></button>
        @endif
    </div>

    @if (! $nested && ($subtasks->isNotEmpty() || $canSubtask))
        <div class="dk-ptk-subs" x-show="subOpen" x-collapse x-cloak>
            @if ($subtasks->isNotEmpty())
                <ul>
                    @foreach ($subtasks as $subtask)
                        @include('pages.programs._task-row', ['task' => $subtask, 'rowKey' => $rowKey.'-sub-'.$subtask->id, 'subtasks' => collect(), 'parentTitle' => null, 'nested' => true])
                    @endforeach
                </ul>
            @endif
            @if ($canSubtask)
                {{-- Only open while adding; leaving it empty (blur, a click elsewhere or Esc) removes it, and the panel too when there are no subtasks yet. --}}
                <form class="dk-ptk-add dk-ptk-add-sub" x-show="adding" x-cloak
                    @click.outside="stopSubtask()"
                    @submit.prevent="if (title.trim() !== '') { $wire.addProgramSubtask({{ $task->id }}, title); title = '' }" data-subtask-add="{{ $task->id }}">
                    <x-desk.icon name="plus" />
                    <input type="text" x-ref="subtask" x-model="title" maxlength="255" placeholder="{{ __('programs.tasks_subtask_placeholder') }}" aria-label="{{ __('programs.tasks_add_subtask') }}"
                        @blur="stopSubtask()" @keydown.escape="title = ''; stopSubtask()" data-subtask-input="{{ $task->id }}" />
                </form>
            @endif
        </div>
    @endif
</li>
