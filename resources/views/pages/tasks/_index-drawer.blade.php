{{--
    The create/edit task drawer of tasks/index, with the locks it derives from
    the draft (done status, past due date, creator-only due date). Included by
    ⚡index.blade.php.
--}}
@use('App\Enums\TaskPriority')
@use('App\Enums\TaskStatus')
    @php
        $isStatusLocked = $editingTaskId !== null && $taskStatus === TaskStatus::Done->value;
        $isDueDatePast = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate) === 1
            && \Carbon\CarbonImmutable::parse($dueDate)->lt(\Carbon\CarbonImmutable::today());
        $isMissedDraft = $isDueDatePast && $taskStatus === TaskStatus::Todo->value;
        $isOverdueDraft = $isDueDatePast && ! $isMissedDraft && $taskStatus !== TaskStatus::Done->value;
        $isDueDateLocked = $editingTaskId !== null && $editingTaskCreatedBy !== auth()->id();
    @endphp

    {{-- Same form, sections and fields as the calendar page's task modal; the tasks page adds its own locks (done status, creator-only due date). --}}
    <x-ui.modal
        wire:model="showTaskDrawer"
        size="xl"
        :title="$editingTaskId === null ? __('tasks.add_task') : __('tasks.edit_task')"
        :description="__('tasks.every_task_delivers_against_a_proposal_a_milestone_is_option')"
        submit="saveTask"
        close="closeTaskDrawer"
        required-note
    >
        @if ($showTaskDrawer)
            <x-ui.form-section :title="__('common.section_details')" :fields="['title', 'description']">
                <div class="grid gap-y-3">
                    <x-ui.form-input wire:model="title" :label="__('tasks.task_title')" maxlength="255" required autofocus />
                    <x-ui.form-textarea wire:model="description" :label="__('common.description')" rows="2" />
                </div>
            </x-ui.form-section>

            <x-ui.form-section :title="__('common.section_placement')" :fields="['taskSourceType', 'taskSourceId']">
                <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                    <x-ui.form-select wire:model.live="taskSourceType" :label="__('tasks.source_type')" :placeholder="__('common.select')" required>
                        @foreach ($this->sourceTypeOptions as $sourceTypeOption)
                            <option value="{{ $sourceTypeOption['value'] }}">{{ $sourceTypeOption['label'] }}</option>
                        @endforeach
                    </x-ui.form-select>

                    <x-ui.form-select wire:model="taskSourceId" :label="__('tasks.source')" :placeholder="__('common.select')" required>
                        @foreach ($this->sourceOptions as $sourceOption)
                            <option value="{{ $sourceOption['id'] }}">{{ $sourceOption['name'] }}</option>
                        @endforeach
                    </x-ui.form-select>
                </div>
            </x-ui.form-section>

            <x-ui.form-section :title="__('tasks.section_progress')" :fields="['taskStatus', 'taskPriority', 'progressPercentage', 'assignedTo']">
                <div class="grid grid-cols-2 gap-x-4 gap-y-3">
                    <x-ui.form-segmented
                        wire:model="taskStatus"
                        :label="__('common.status')"
                        required
                        class="col-span-2"
                        :disabled="$isStatusLocked"
                        :help="$isStatusLocked ? __('tasks.completed_tasks_cannot_change_status') : null"
                        :options="[
                            ['value' => TaskStatus::Todo->value, 'label' => TaskStatus::Todo->label(), 'dot' => 'border border-secondary-light', 'test' => 'task-drawer-status-'.TaskStatus::Todo->value],
                            ['value' => TaskStatus::InProgress->value, 'label' => TaskStatus::InProgress->label(), 'dot' => 'bg-status-active', 'test' => 'task-drawer-status-'.TaskStatus::InProgress->value],
                            ['value' => TaskStatus::UnderReview->value, 'label' => TaskStatus::UnderReview->label(), 'dot' => 'bg-status-warn', 'test' => 'task-drawer-status-'.TaskStatus::UnderReview->value],
                            ['value' => TaskStatus::Done->value, 'label' => TaskStatus::Done->label(), 'dot' => 'bg-status-good', 'test' => 'task-drawer-status-'.TaskStatus::Done->value],
                        ]"
                    />

                    <x-ui.form-segmented
                        wire:model="taskPriority"
                        :label="__('tasks.priority')"
                        required
                        :options="[
                            ['value' => TaskPriority::Low->value, 'label' => TaskPriority::Low->label(), 'icon' => 'flag', 'iconClass' => 'text-secondary-light', 'test' => 'task-drawer-priority-'.TaskPriority::Low->value],
                            ['value' => TaskPriority::Medium->value, 'label' => TaskPriority::Medium->label(), 'icon' => 'flag', 'iconClass' => 'text-status-warn', 'test' => 'task-drawer-priority-'.TaskPriority::Medium->value],
                            ['value' => TaskPriority::High->value, 'label' => TaskPriority::High->label(), 'icon' => 'flag', 'iconClass' => 'text-status-critical', 'test' => 'task-drawer-priority-'.TaskPriority::High->value],
                        ]"
                    />

                    <x-ui.form-input wire:model="progressPercentage" :label="__('tasks.progress_2')" type="number" min="0" max="100" suffix="%" required data-test="task-drawer-progress-input" />

                    <x-ui.form-multiselect wire:model="assignedTo" :label="__('tasks.assignees')" :placeholder="__('common.select')" class="col-span-2" data-test="task-assignee-picker" employees />
                </div>
            </x-ui.form-section>

            <x-ui.form-section :title="__('common.section_schedule')" :fields="['startDate', 'dueDate', 'completedDate']">
                @if ($isMissedDraft || $isOverdueDraft)
                    <x-slot:hint>
                        <span class="inline-flex items-center rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-bold tracking-wide text-status-critical uppercase">{{ $isMissedDraft ? __('tasks.missed') : __('tasks.overdue') }}</span>
                    </x-slot:hint>
                @endif

                <div class="grid grid-cols-2 gap-x-4 gap-y-3 @4xl/modal:grid-cols-3">
                    <x-ui.form-input wire:model="startDate" :label="__('common.start_date')" type="date" />
                    <x-ui.form-input wire:model="dueDate" :label="__('tasks.due_date_2')" type="date" :disabled="$isDueDateLocked" :help="$isDueDateLocked ? __('tasks.only_the_task_creator_can_change_the_due_date') : null" />
                    <x-ui.form-input wire:model="completedDate" :label="__('tasks.completed_date')" type="date" />
                </div>
            </x-ui.form-section>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-task-button">{{ $editingTaskId === null ? __('tasks.create_task') : __('common.save_changes') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>
