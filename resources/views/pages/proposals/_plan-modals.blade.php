{{--
    The Plan tab's dialogs (T31): add/edit a milestone, the Final Report
    template, add/edit a department task, and name the person who carries a
    task. Each body renders only while its dialog is open. Included by
    _tab-plan.blade.php.
--}}
@use('App\Enums\DeliverableKind')
@use('App\Enums\TaskKind')

    <x-ui.modal
        wire:model="showPlanMilestoneModal"
        size="lg"
        :title="$editingPlanMilestoneId === null ? __('milestones.add_milestone') : __('milestones.edit_milestone')"
        :description="__('milestones.plan_milestone_help')"
        submit="savePlanMilestone"
        close="closePlanMilestoneModal"
        required-note
    >
        @if ($showPlanMilestoneModal)
            <x-ui.form-section :title="__('common.section_details')" :fields="['planMilestone.name', 'planMilestone.description']">
                <div class="grid gap-y-3">
                    <x-ui.form-input wire:model="planMilestone.name" :label="__('milestones.milestone_name')" maxlength="255" required autofocus />
                    <x-ui.form-textarea wire:model="planMilestone.description" :label="__('common.description')" rows="2" />
                </div>
            </x-ui.form-section>

            <x-ui.form-section :title="__('common.section_placement')" :fields="['planMilestone.responsible_employee_id', 'planMilestone.project_id', 'planMilestone.parent_milestone_id']">
                <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-3">
                    <x-ui.form-select wire:model="planMilestone.responsible_employee_id" :label="__('milestones.responsible')" :placeholder="__('common.select')" :search-label="__('milestones.search_responsible')" required employees />

                    <x-ui.form-select wire:model="planMilestone.project_id" :label="__('milestones.plan_center')" :help="__('milestones.plan_center_help')">
                        <option value="">{{ __('milestones.shared_across_centers') }}</option>
                        @foreach ($this->planProjectOptions as $projectId => $projectName)
                            <option value="{{ $projectId }}">{{ $projectName }}</option>
                        @endforeach
                    </x-ui.form-select>

                    <x-ui.form-select wire:model="planMilestone.parent_milestone_id" :label="__('milestones.nest_under')">
                        <option value="">{{ __('common.top_level') }}</option>
                        @foreach ($this->planMilestones as $row)
                            @if ($row['milestone']->id !== $editingPlanMilestoneId)
                                <option value="{{ $row['milestone']->id }}">{{ $row['milestone']->name }}</option>
                            @endif
                        @endforeach
                    </x-ui.form-select>
                </div>
            </x-ui.form-section>

            <x-ui.form-section :title="__('common.section_schedule')" :fields="['planMilestone.planned_start_date', 'planMilestone.planned_end_date']">
                <div class="grid grid-cols-2 gap-x-4 gap-y-3">
                    <x-ui.form-input wire:model="planMilestone.planned_start_date" :label="__('milestones.planned_start_date')" type="date" required />
                    <x-ui.form-input wire:model="planMilestone.planned_end_date" :label="__('milestones.planned_end_date')" type="date" :help="__('milestones.plan_period_help')" />
                </div>
            </x-ui.form-section>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-plan-milestone-button">{{ $editingPlanMilestoneId === null ? __('milestones.create_milestone') : __('common.save_changes') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    <x-ui.modal
        wire:model="showFinalReportModal"
        size="lg"
        :title="__('milestones.add_final_report')"
        :description="__('milestones.final_report_template_help')"
        submit="saveFinalReport"
        close="closeFinalReportModal"
        required-note
    >
        @if ($showFinalReportModal)
            @error('finalReport')
                <p class="mb-4 text-sm font-semibold text-status-critical" role="alert" data-final-report-error>{{ $message }}</p>
            @enderror

            <x-ui.form-section :title="__('milestones.final_report_milestone_name')" :fields="['finalReport.planned_start_date', 'finalReport.planned_end_date', 'finalReport.responsible_employee_id']">
                <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-3">
                    <x-ui.form-input wire:model="finalReport.planned_start_date" :label="__('milestones.planned_start_date')" type="date" required />
                    <x-ui.form-input wire:model="finalReport.planned_end_date" :label="__('milestones.planned_end_date')" type="date" />
                    <x-ui.form-select wire:model="finalReport.responsible_employee_id" :label="__('milestones.responsible')" :placeholder="__('common.select')" :search-label="__('milestones.search_responsible')" required employees />
                </div>
            </x-ui.form-section>

            <x-ui.form-section :title="__('department_tasks.final_report_task_title')" :fields="['finalReport.department_id', 'finalReport.due_date']">
                <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                    <x-ui.form-select wire:model="finalReport.department_id" :label="__('department_tasks.department')" :placeholder="__('common.select')" required>
                        @foreach ($this->departmentOptions as $department)
                            <option value="{{ $department->id }}">{{ $department->name }}</option>
                        @endforeach
                    </x-ui.form-select>
                    <x-ui.form-input wire:model="finalReport.due_date" :label="__('tasks.due')" type="date" :help="__('milestones.final_report_due_help')" />
                </div>
            </x-ui.form-section>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-final-report-button">{{ __('milestones.add_final_report') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    <x-ui.modal
        wire:model="showPlanTaskModal"
        size="lg"
        :title="$editingPlanTaskId === null ? __('department_tasks.add_department_task') : __('department_tasks.edit_task')"
        submit="savePlanTask"
        close="closePlanTaskModal"
        required-note
    >
        @if ($showPlanTaskModal)
            @php
                $isDeliverable = $planTask['kind'] === TaskKind::MediaDeliverable->value;
            @endphp
            <x-ui.form-section :title="__('common.section_details')" :fields="['planTask.title', 'planTask.description', 'planTask.kind', 'planTask.media_kinds']">
                <div class="grid gap-y-3">
                    <x-ui.form-segmented
                        wire:model.live="planTask.kind"
                        :label="__('department_tasks.kind')"
                        :options="collect(TaskKind::cases())->map(fn (TaskKind $kind): array => ['value' => $kind->value, 'label' => $kind->label()])->all()"
                        :disabled="$editingPlanTaskId !== null && $isDeliverable"
                        required
                    />
                    <x-ui.form-input wire:model="planTask.title" :label="__('department_tasks.title')" maxlength="255" required autofocus :help="$planTask['kind'] === TaskKind::Financial->value ? __('department_tasks.financial_title_help') : null" />
                    <x-ui.form-textarea wire:model="planTask.description" :label="__('common.description')" rows="2" />
                    @if ($isDeliverable)
                        <x-ui.form-multiselect wire:model="planTask.media_kinds" :label="__('department_tasks.media_kinds')" :placeholder="__('common.select')" :help="__('department_tasks.media_kinds_help')" required>
                            @foreach (DeliverableKind::cases() as $deliverableKind)
                                <option value="{{ $deliverableKind->value }}">{{ $deliverableKind->label() }}</option>
                            @endforeach
                        </x-ui.form-multiselect>
                    @endif
                </div>
            </x-ui.form-section>

            <x-ui.form-section :title="__('department_tasks.who')" :fields="['planTask.department_id', 'planTask.assignee_employee_id', 'planTask.milestone_id']">
                <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-3">
                    <x-ui.form-select wire:model.live="planTask.department_id" :label="__('department_tasks.department')" :placeholder="__('common.select')" required>
                        @foreach ($this->departmentOptions as $department)
                            <option value="{{ $department->id }}">{{ $department->name }}</option>
                        @endforeach
                    </x-ui.form-select>

                    <x-ui.form-select wire:model="planTask.assignee_employee_id" :label="__('department_tasks.assignee')" :help="__('department_tasks.assignee_help')" :disabled="$planTask['department_id'] === ''" wire:key="plan-task-assignee-{{ $planTask['department_id'] }}">
                        <option value="">{{ __('department_tasks.department_decides') }}</option>
                        @foreach ($this->planTaskAssigneeOptions as $person)
                            <option value="{{ $person['value'] }}">{{ $person['label'] }}</option>
                        @endforeach
                    </x-ui.form-select>

                    <x-ui.form-select wire:model="planTask.milestone_id" :label="__('department_tasks.milestone')">
                        <option value="">{{ __('department_tasks.no_milestone') }}</option>
                        @foreach ($this->planMilestones as $row)
                            <option value="{{ $row['milestone']->id }}">{{ $row['milestone']->name }}</option>
                        @endforeach
                    </x-ui.form-select>
                </div>
            </x-ui.form-section>

            <x-ui.form-section :title="__('common.section_schedule')" :fields="['planTask.requested_on', 'planTask.start_date', 'planTask.due_date']">
                <div class="grid grid-cols-2 gap-x-4 gap-y-3 @2xl/modal:grid-cols-3">
                    <x-ui.form-input wire:model="planTask.requested_on" :label="__('department_tasks.requested_on')" type="date" :help="__('department_tasks.requested_on_help')" />
                    <x-ui.form-input wire:model="planTask.start_date" :label="__('department_tasks.start_date')" type="date" />
                    <x-ui.form-input wire:model="planTask.due_date" :label="__('tasks.due')" type="date" required />
                </div>
            </x-ui.form-section>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-plan-task-button">{{ $editingPlanTaskId === null ? __('department_tasks.add_department_task') : __('common.save_changes') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    <x-ui.modal
        wire:model="showAssignModal"
        size="sm"
        :title="__('department_tasks.assign_person')"
        :description="__('department_tasks.assign_help')"
        submit="saveAssignee"
        close="closeAssignModal"
    >
        @if ($showAssignModal)
            <x-ui.form-select wire:model="assigneeEmployeeId" :label="__('department_tasks.assignee')">
                <option value="">{{ __('department_tasks.department_decides') }}</option>
                @foreach ($this->assignOptions as $person)
                    <option value="{{ $person['value'] }}">{{ $person['label'] }}</option>
                @endforeach
            </x-ui.form-select>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-assignee-button">{{ __('common.save_changes') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>
