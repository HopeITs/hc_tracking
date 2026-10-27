@use('App\Enums\TaskPriority')
@use('App\Support\FluxMarkup')
@use('Illuminate\Support\Js')
            @can('create', App\Models\Task::class)
            @php
                $boardRowKey = 'board|'.$case->value;
                $boardTaskDraft = $this->taskDraft($boardRowKey, $case->value);
                $boardTaskDraftAssignees = collect($this->userOptions)->whereIn('id', collect($boardTaskDraft['assignees'])->map(fn ($id) => (int) $id));
                $boardDraftPriority = TaskPriority::from($boardTaskDraft['priority']);
            @endphp
            <div class="mx-3 flex flex-col gap-1.5 rounded-xl border border-dashed border-secondary-light bg-white/60 px-2.5 py-2 transition-colors focus-within:border-secondary-medium hover:border-secondary-medium dark:border-line dark:bg-surface-1" data-test="task-board-add-row-{{ $case->value }}">
                <label class="flex items-center gap-2">
                    {{ FluxMarkup::icon('plus', 'micro', 'size-4 shrink-0 text-primary dark:text-brand-text') }}
                    <span class="sr-only">{{ __('tasks.add_task') }}</span>
                    <input
                        type="text"
                        placeholder="{{ __('tasks.add_task') }}"
                        class="w-full min-w-0 border-0 bg-transparent p-0 text-sm text-secondary-dark placeholder:text-secondary-light focus:ring-0 focus:outline-none dark:text-ink"
                        wire:keydown.enter="quickCreateTask($event.target.value, '{{ $case->value }}', null, {{ Js::from($boardRowKey) }}); $event.target.value = ''"
                        data-test="task-board-quick-add-{{ $case->value }}"
                    />
                </label>
                <div class="flex flex-wrap items-center gap-1.5 ps-6">
                    @php
                        $boardTaskDraftPicker = "\$store.assigneePicker.openFor(".Js::from('board-add-'.$case->value).', '.Js::from(collect($boardTaskDraft['assignees'])->map(fn ($id) => (int) $id)->values()->all()).", \$el.getBoundingClientRect(), 'toggleTaskDraftAssignee', [".Js::from($boardRowKey).'])';
                    @endphp
                    <div
                        x-data
                        role="button"
                        tabindex="0"
                        x-on:click="{{ $boardTaskDraftPicker }}"
                        x-on:keydown.enter="{{ $boardTaskDraftPicker }}"
                        x-on:keydown.space.prevent="{{ $boardTaskDraftPicker }}"
                        aria-haspopup="listbox"
                        aria-label="{{ __('tasks.assignees') }}"
                        class="inline-flex shrink-0 cursor-pointer items-center -space-x-2 rtl:space-x-reverse"
                        data-test="task-board-add-assignee-trigger-{{ $case->value }}"
                    >
                            @if ($boardTaskDraftAssignees->isEmpty())
                                <span class="inline-flex size-6 items-center justify-center rounded-full border border-dashed border-secondary-light text-secondary-light">
                                    {{ FluxMarkup::icon('user-plus', 'micro', 'size-3') }}
                                </span>
                            @else
                                @foreach ($boardTaskDraftAssignees->take(3) as $userOption)
                                    <span class="inline-flex size-6 items-center justify-center rounded-full bg-secondary-dark text-[10px] font-semibold text-white ring-2 ring-white dark:bg-surface-3 dark:ring-surface-1">
                                        {{ Illuminate\Support\Str::of($userOption['name'])->explode(' ')->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('') }}
                                    </span>
                                @endforeach
                                @if ($boardTaskDraftAssignees->count() > 3)
                                    <span class="inline-flex size-6 items-center justify-center rounded-full bg-secondary-soft text-[9px] font-semibold text-secondary-dark ring-2 ring-white dark:bg-surface-3 dark:text-ink dark:ring-surface-1">
                                        +{{ $boardTaskDraftAssignees->count() - 3 }}
                                    </span>
                                @endif
                            @endif
                    </div>

                    <input
                        type="date"
                        wire:model="taskDrafts.{{ $boardRowKey }}.due_date"
                        value="{{ $boardTaskDraft['due_date'] }}"
                        aria-label="{{ __('tasks.due_date_2') }}"
                        class="w-24 shrink-0 rounded border-0 bg-transparent p-0.5 text-[11px] tabular-nums text-secondary-medium focus:ring-1 focus:ring-secondary-light focus:outline-none dark:text-ink-muted"
                        data-test="task-board-add-due-date-{{ $case->value }}"
                    />

                    <button type="button" x-data aria-haspopup="menu" x-on:click="$store.taskMenu.openFor('priority', {{ Js::from('taskDrafts.'.$boardRowKey.'.priority') }}, '{{ $boardDraftPriority->value }}', $el.getBoundingClientRect(), '$set', [{{ Js::from('taskDrafts.'.$boardRowKey.'.priority') }}])" aria-label="{{ __('tasks.priority') }}: {{ $boardDraftPriority->label() }}" class="inline-flex size-6 cursor-pointer items-center justify-center rounded hover:bg-secondary-soft dark:hover:bg-surface-2" data-test="task-board-add-priority-trigger-{{ $case->value }}">
                                {{ FluxMarkup::icon('flag', $boardDraftPriority === TaskPriority::Low ? 'outline' : 'solid', 'size-3.5 '.$priorityIconClasses($boardDraftPriority)) }}
                            </button>
                </div>
            </div>
            @endcan
