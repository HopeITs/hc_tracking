{{--
    The task dialog of calendar/index and its delete confirmation. Included
    by ⚡index.blade.php.
--}}
@use('App\Enums\TaskPriority')
@use('App\Enums\TaskStatus')
    <x-ui.modal
        wire:model="showTaskModal"
        size="xl"
        :title="$editingTaskId === null ? __('tasks.add_task') : __('tasks.edit_task')"
        :description="__('tasks.every_task_delivers_against_a_proposal_a_milestone_is_option')"
        submit="saveTask"
        close="closeTaskModal"
        required-note
    >
        @if ($showTaskModal)
        <x-ui.form-section :title="__('common.section_details')" :fields="['title', 'description']">
            <div class="grid gap-y-3">
                <x-ui.form-input wire:model="title" :label="__('tasks.task_title')" maxlength="255" required autofocus />
                <x-ui.form-textarea wire:model="description" :label="__('common.description')" rows="2" />
            </div>
        </x-ui.form-section>

        <x-ui.form-section :title="__('common.section_placement')" :fields="['taskSourceType', 'taskSourceId']">
            <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                <x-ui.form-select wire:model.live="taskSourceType" :label="__('tasks.source_type')" :placeholder="__('common.select')" required>
                    @foreach ($this->sourceTypeOptions as $sourceTypeKey => $sourceTypeLabel)
                        <option value="{{ $sourceTypeKey }}">{{ $sourceTypeLabel }}</option>
                    @endforeach
                </x-ui.form-select>

                <x-ui.form-select wire:model="taskSourceId" :label="__('tasks.source')" :placeholder="__('common.select')" required>
                    @foreach ($this->sourceOptions as $sourceOption)
                        <option value="{{ $sourceOption->id }}">{{ $sourceOption->name }}{{ $taskSourceType === 'milestone' ? ' · '.$sourceOption->ownerName() : '' }}</option>
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
                    :options="[
                        ['value' => TaskStatus::Todo->value, 'label' => TaskStatus::Todo->label(), 'dot' => 'border border-secondary-light'],
                        ['value' => TaskStatus::InProgress->value, 'label' => TaskStatus::InProgress->label(), 'dot' => 'bg-status-active'],
                        ['value' => TaskStatus::UnderReview->value, 'label' => TaskStatus::UnderReview->label(), 'dot' => 'bg-status-warn'],
                        ['value' => TaskStatus::Done->value, 'label' => TaskStatus::Done->label(), 'dot' => 'bg-status-good'],
                    ]"
                />

                <x-ui.form-segmented
                    wire:model="taskPriority"
                    :label="__('tasks.priority')"
                    required
                    :options="[
                        ['value' => TaskPriority::Low->value, 'label' => TaskPriority::Low->label(), 'icon' => 'flag', 'iconClass' => 'text-secondary-light'],
                        ['value' => TaskPriority::Medium->value, 'label' => TaskPriority::Medium->label(), 'icon' => 'flag', 'iconClass' => 'text-status-warn'],
                        ['value' => TaskPriority::High->value, 'label' => TaskPriority::High->label(), 'icon' => 'flag', 'iconClass' => 'text-status-critical'],
                    ]"
                />

                <x-ui.form-input wire:model="progressPercentage" :label="__('tasks.progress_2')" type="number" min="0" max="100" suffix="%" required />

                <x-ui.form-multiselect wire:model="assignedTo" :label="__('tasks.assignees')" :placeholder="__('common.select')" class="col-span-2" data-test="task-assignee-picker" employees />
            </div>
        </x-ui.form-section>

        <x-ui.form-section :title="__('common.section_schedule')" :fields="['startDate', 'dueDate', 'completedDate']">
            <div class="grid grid-cols-2 gap-x-4 gap-y-3 @4xl/modal:grid-cols-3">
                <x-ui.form-input wire:model="startDate" :label="__('common.start_date')" type="date" />
                <x-ui.form-input wire:model="dueDate" :label="__('tasks.due_date_2')" type="date" data-test="calendar-task-form-due-date" />
                <x-ui.form-input wire:model="completedDate" :label="__('tasks.completed_date')" type="date" />
            </div>
        </x-ui.form-section>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="calendar-save-task-button">{{ $editingTaskId === null ? __('tasks.create_task') : __('common.save_changes') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    <x-ui.confirm-modal
        wire:model="showTaskDeleteModal"
        :title="__('tasks.delete_this_task')"
        confirm="deleteTask"
        :confirm-label="__('tasks.delete_task')"
        confirm-test="calendar-confirm-delete-task"
    />
