{{--
    The milestone dialog of calendar/index and its delete confirmation.
    Included by ⚡index.blade.php.
--}}
@use('App\Enums\MilestoneStatus')
    <x-ui.modal
        wire:model="showMilestoneModal"
        size="xl"
        :title="$editingMilestoneId === null ? __('milestones.add_milestone') : __('milestones.edit_milestone')"
        submit="saveMilestone"
        close="closeMilestoneModal"
        required-note
    >
        @if ($showMilestoneModal)
        <x-ui.form-section :title="__('common.section_details')" :fields="['name']">
            <x-ui.form-input wire:model="name" :label="__('milestones.milestone_name')" maxlength="255" required autofocus />
        </x-ui.form-section>

        <x-ui.form-section :title="__('common.section_placement')" :fields="['milestoneProjectId', 'parentMilestoneId', 'responsibleUserId']">
            <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-3">
                <x-ui.form-select
                    wire:model.live="milestoneProjectId"
                    id="milestone-form-project"
                    :label="__('common.project')"
                    :placeholder="__('common.select_a_project')"
                    :search-label="__('milestones.search_projects')"
                    :disabled="$editingMilestoneId !== null"
                    :locked-reason="__('milestones.a_milestone_cannot_be_moved_to_another_project')"
                    required
                >
                    @foreach ($this->projectOptions as $projectOption)
                        <option value="{{ $projectOption->id }}">{{ $projectOption->name }}</option>
                    @endforeach
                </x-ui.form-select>

                <x-ui.form-select wire:model="parentMilestoneId" :label="__('milestones.nest_under')" :search-label="__('milestones.search_milestones')">
                    <option value="">{{ __('common.top_level') }}</option>
                    @foreach ($this->parentOptions as $parentOption)
                        <option value="{{ $parentOption->id }}">{{ $parentOption->name }}</option>
                    @endforeach
                </x-ui.form-select>

                <x-ui.form-select wire:model="responsibleUserId" :label="__('milestones.responsible')" :placeholder="__('common.select')" :search-label="__('milestones.search_responsible')" employees />
            </div>
        </x-ui.form-section>

        <x-ui.form-section :title="__('common.section_schedule')" :fields="['plannedStartDate', 'plannedEndDate', 'milestoneStatus', 'sequence', 'weightPercentage']">
            <div class="grid grid-cols-2 gap-x-4 gap-y-3 @4xl/modal:grid-cols-3">
                <x-ui.form-input wire:model="plannedStartDate" :label="__('milestones.planned_start_date')" type="date" required data-test="calendar-form-planned-date" />
                <x-ui.form-input wire:model="plannedEndDate" :label="__('milestones.planned_end_date')" type="date" />

                <x-ui.form-select wire:model="milestoneStatus" :label="__('common.status')" required class="col-span-2 @4xl/modal:col-span-1">
                    @foreach (MilestoneStatus::cases() as $statusCase)
                        <option value="{{ $statusCase->value }}">{{ $statusCase->label() }}</option>
                    @endforeach
                </x-ui.form-select>

                <x-ui.form-input wire:model="sequence" :label="__('milestones.sequence')" type="number" min="0" required />
                <x-ui.form-input wire:model="weightPercentage" :label="__('milestones.weight')" type="number" min="0" max="100" step="0.01" suffix="%" required />
            </div>
        </x-ui.form-section>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="calendar-save-milestone-button">{{ $editingMilestoneId === null ? __('milestones.create_milestone') : __('common.save_changes') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    <x-ui.confirm-modal
        wire:model="showDeleteModal"
        :title="__('milestones.delete_this_milestone')"
        :message="__('milestones.only_a_milestone_with_no_nested_milestones_and_no_tasks_can')"
        confirm="deleteMilestone"
        :confirm-label="__('milestones.delete_milestone')"
        confirm-test="calendar-confirm-delete-milestone"
    />
