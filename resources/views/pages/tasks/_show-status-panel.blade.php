{{-- The status / edit form on ⚡show.blade.php. Shown to editors and to assignees (who may move status and progress only). --}}
@use('App\Enums\TaskPriority')
@use('App\Enums\TaskStatus')
        <x-ui.panel
            :title="$canEditTask ? __('tasks.edit_task') : __('tasks.update_task_status')"
            :subtitle="$canEditTask ? __('tasks.edit_task_hint') : __('tasks.update_task_status_hint')"
        >
            <form wire:submit="updateTask" class="flex flex-col gap-5 p-4" data-test="task-edit-form">
                @if ($canEditTask)
                    <x-ui.input wire:model="title" :label="__('tasks.task_title')" type="text" maxlength="255" required data-test="task-edit-title-input" />

                    <flux:textarea wire:model="description" :label="__('common.description')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:border-field-line! dark:bg-surface-2! dark:text-ink!" />
                @endif

                <div @class(['grid gap-5', 'sm:grid-cols-2' => $canEditTask])>
                    <div class="min-w-0">
                        <label class="mb-1.5 block text-[11px] font-semibold tracking-[0.1em] text-secondary-medium uppercase dark:text-ink-muted" id="task-edit-status-label">{{ __('common.status') }}</label>
                        <div class="grid grid-cols-2 gap-1.5" role="radiogroup" aria-labelledby="task-edit-status-label">
                            @foreach (TaskStatus::cases() as $statusCase)
                                @php $isStatusSelected = $taskStatus === $statusCase->value; @endphp
                                <button
                                    type="button"
                                    role="radio"
                                    aria-checked="{{ $isStatusSelected ? 'true' : 'false' }}"
                                    wire:click="$set('taskStatus', '{{ $statusCase->value }}')"
                                    @class([
                                        'inline-flex min-h-10 cursor-pointer touch-manipulation items-center justify-center gap-1.5 rounded-lg border px-2.5 py-2 text-xs font-semibold whitespace-nowrap transition-colors',
                                        'border-secondary-dark bg-secondary-dark text-white dark:border-ink dark:bg-ink dark:text-surface-0' => $isStatusSelected,
                                        'border-secondary-light text-secondary-medium hover:border-secondary-medium hover:text-secondary-dark dark:text-ink-muted dark:hover:text-ink' => ! $isStatusSelected,
                                    ])
                                    data-test="task-edit-status-{{ $statusCase->value }}"
                                >
                                    {{ $statusCase->label() }}
                                </button>
                            @endforeach
                        </div>
                        <flux:error name="taskStatus" class="mt-1.5" />
                    </div>

                    @if ($canEditTask)
                        <div class="min-w-0">
                            <label class="mb-1.5 block text-[11px] font-semibold tracking-[0.1em] text-secondary-medium uppercase dark:text-ink-muted" id="task-edit-priority-label">{{ __('tasks.priority') }}</label>
                            <div class="grid grid-cols-3 gap-1.5" role="radiogroup" aria-labelledby="task-edit-priority-label">
                                @foreach (TaskPriority::cases() as $priorityCase)
                                    @php $isPrioritySelected = $taskPriority === $priorityCase->value; @endphp
                                    <button
                                        type="button"
                                        role="radio"
                                        aria-checked="{{ $isPrioritySelected ? 'true' : 'false' }}"
                                        wire:click="$set('taskPriority', '{{ $priorityCase->value }}')"
                                        @class([
                                            'inline-flex min-h-10 cursor-pointer touch-manipulation items-center justify-center gap-1.5 rounded-lg border px-2.5 py-2 text-xs font-semibold whitespace-nowrap transition-colors',
                                            'border-secondary-dark bg-secondary-dark text-white dark:border-ink dark:bg-ink dark:text-surface-0' => $isPrioritySelected,
                                            'border-secondary-light text-secondary-medium hover:border-secondary-medium hover:text-secondary-dark dark:text-ink-muted dark:hover:text-ink' => ! $isPrioritySelected,
                                        ])
                                        data-test="task-edit-priority-{{ $priorityCase->value }}"
                                    >
                                        {{ $priorityCase->label() }}
                                    </button>
                                @endforeach
                            </div>
                            <flux:error name="taskPriority" class="mt-1.5" />
                        </div>
                    @endif
                </div>

                <div class="min-w-0">
                    <label class="mb-1.5 block text-[11px] font-semibold tracking-[0.1em] text-secondary-medium uppercase dark:text-ink-muted" for="task-edit-progress-slider">{{ __('tasks.progress_2') }}</label>
                    <div class="flex items-center gap-3">
                        <input id="task-edit-progress-slider" type="range" wire:model="progressPercentage" min="0" max="100" step="1" class="h-1.5 w-full flex-1 cursor-pointer accent-primary" data-test="task-edit-progress-slider" />
                        <div class="relative w-16 shrink-0">
                            <input type="number" wire:model="progressPercentage" min="0" max="100" required class="w-full rounded-lg border border-secondary-light bg-white py-1.5 ps-2 pe-6 text-end text-sm font-semibold tabular-nums text-secondary-dark focus:ring-1 focus:ring-secondary-light focus:outline-none dark:border-field-line dark:bg-surface-2 dark:text-ink" aria-label="{{ __('tasks.progress_2') }}" data-test="task-edit-progress-input" />
                            <span class="pointer-events-none absolute inset-y-0 end-2 flex items-center text-sm text-secondary-medium dark:text-ink-muted">%</span>
                        </div>
                    </div>
                    <flux:error name="progressPercentage" class="mt-1.5" />
                </div>

                @if ($canEditTask)
                    <div class="min-w-0">
                        <x-ui.form-multiselect wire:model="assignedTo" :label="__('tasks.assignees')" :placeholder="__('common.select')" data-test="task-edit-assignee-picker" employees />
                    </div>
                @endif

                <div @class(['grid gap-4', 'sm:grid-cols-3' => $canEditTask])>
                    @if ($canEditTask)
                        <x-ui.input wire:model="startDate" :label="__('common.start_date')" type="date" />
                        <x-ui.input wire:model="dueDate" :label="__('tasks.due_date_2')" type="date" />
                    @endif
                    <x-ui.input wire:model="completedDate" :label="__('tasks.completed_date')" type="date" data-test="task-edit-completed-date-input" />
                </div>
                @if ($canEditTask)
                    <flux:error name="dueDate" class="-mt-3" />
                @endif

                <div class="flex justify-end gap-2 border-t border-secondary-soft pt-4 dark:border-line">
                    <x-ui.button type="submit" tone="primary" class="min-h-11 touch-manipulation sm:min-h-10" data-test="update-task-button">{{ __('common.save_changes') }}</x-ui.button>
                </div>
            </form>
        </x-ui.panel>
