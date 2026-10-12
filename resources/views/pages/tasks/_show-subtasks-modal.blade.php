{{-- The add-subtask dialog on ⚡show.blade.php. Shown on top-level tasks only. --}}
@use('App\Enums\TaskPriority')
@use('App\Enums\TaskStatus')
        <x-ui.modal
            wire:model="showAddSubtaskModal"
            size="md"
            :title="__('tasks.add_subtask')"
            submit="addSubtask"
            close="closeAddSubtaskModal"
            required-note
        >
            @if ($showAddSubtaskModal)
                <div class="grid grid-cols-2 gap-x-4 gap-y-4">
                    <x-ui.form-input wire:model="newSubtaskTitle" :label="__('tasks.subtask_title')" maxlength="255" required autofocus class="col-span-2" data-test="new-subtask-title-input" />

                    <x-ui.form-segmented
                        wire:model="newSubtaskStatus"
                        :label="__('common.status')"
                        class="col-span-2"
                        :options="[
                            ['value' => TaskStatus::Todo->value, 'label' => TaskStatus::Todo->label(), 'dot' => 'border border-secondary-light', 'test' => 'new-subtask-status-'.TaskStatus::Todo->value],
                            ['value' => TaskStatus::InProgress->value, 'label' => TaskStatus::InProgress->label(), 'dot' => 'bg-status-active', 'test' => 'new-subtask-status-'.TaskStatus::InProgress->value],
                            ['value' => TaskStatus::UnderReview->value, 'label' => TaskStatus::UnderReview->label(), 'dot' => 'bg-status-warn', 'test' => 'new-subtask-status-'.TaskStatus::UnderReview->value],
                            ['value' => TaskStatus::Done->value, 'label' => TaskStatus::Done->label(), 'dot' => 'bg-status-good', 'test' => 'new-subtask-status-'.TaskStatus::Done->value],
                        ]"
                    />

                    <x-ui.form-segmented
                        wire:model="newSubtaskPriority"
                        :label="__('tasks.priority')"
                        :options="[
                            ['value' => TaskPriority::Low->value, 'label' => TaskPriority::Low->label(), 'icon' => 'flag', 'iconClass' => 'text-secondary-light', 'test' => 'new-subtask-priority-'.TaskPriority::Low->value],
                            ['value' => TaskPriority::Medium->value, 'label' => TaskPriority::Medium->label(), 'icon' => 'flag', 'iconClass' => 'text-status-warn', 'test' => 'new-subtask-priority-'.TaskPriority::Medium->value],
                            ['value' => TaskPriority::High->value, 'label' => TaskPriority::High->label(), 'icon' => 'flag', 'iconClass' => 'text-status-critical', 'test' => 'new-subtask-priority-'.TaskPriority::High->value],
                        ]"
                    />

                    <x-ui.form-input wire:model="newSubtaskDueDate" :label="__('tasks.due_date_2')" type="date" />

                    <x-ui.form-multiselect wire:model="newSubtaskAssignedTo" :label="__('tasks.assignees')" :placeholder="__('common.select')" class="col-span-2" data-test="new-subtask-assignee-picker">
                        @foreach ($this->userOptions as $userOption)
                            <option value="{{ $userOption->id }}">{{ $userOption->name }} · {{ $userOption->secondary_name }}</option>
                        @endforeach
                    </x-ui.form-multiselect>
                </div>
            @endif

            <x-slot:actions>
                <x-ui.button type="submit" tone="primary" data-test="save-new-subtask-button">{{ __('tasks.add_subtask') }}</x-ui.button>
            </x-slot:actions>
        </x-ui.modal>
