@php
    $canManageMilestones = app(\App\Actions\AnnualPlans\ManagePlanMilestones::class)->mayManage(auth()->user(), $plan);
@endphp
<section class="ap-card" data-plan-milestones>
    <div class="ap-row">
        <h2>{{ __('annual_plans.milestones.title') }}</h2>
        @if ($canManageMilestones)
            <x-ui.button wire:click="openMilestoneModal" icon="plus">{{ __('annual_plans.milestones.add') }}</x-ui.button>
        @endif
    </div>
    @forelse ($this->milestones as $milestone)
        <div class="ap-bt" wire:key="plan-milestone-{{ $milestone->id }}">
            <div class="ap-sp">
                <b dir="auto">{{ $milestone->title }}</b>
                @if ($milestone->description)<p class="ap-sm">{{ $milestone->description }}</p>@endif
            </div>
            <span class="ap-sm">{{ $milestone->due_date->translatedFormat(__('formats.date')) }}</span>
            <span class="dk-pill">{{ __('annual_plans.milestones.'.$milestone->status()) }}</span>
            @if ($canManageMilestones)
                @if ($milestone->measure === 'checkpoint')
                    <x-ui.button size="sm" wire:click="completeMilestone({{ $milestone->id }}, {{ $milestone->completed_at ? 'false' : 'true' }})">{{ $milestone->completed_at ? __('annual_plans.milestones.reopen') : __('annual_plans.milestones.complete') }}</x-ui.button>
                @endif
                <x-ui.button size="sm" icon="plus" wire:click="openMilestoneTaskModal({{ $milestone->id }})" data-test="milestone-add-task-{{ $milestone->id }}">{{ __('annual_plans.milestones.add_task') }}</x-ui.button>
                <x-ui.button size="sm" wire:click="openMilestoneModal({{ $milestone->id }})">{{ __('common.edit') }}</x-ui.button>
                <x-ui.button size="sm" wire:click="confirmDeleteMilestone({{ $milestone->id }})">{{ __('common.delete') }}</x-ui.button>
            @endif
        </div>
        @if ($milestone->tasks->isNotEmpty())
            <ul class="ap-sm ps-4" data-milestone-tasks="{{ $milestone->id }}">
                @foreach ($milestone->tasks as $task)
                    <li wire:key="milestone-task-{{ $task->id }}">
                        <a href="{{ route('tasks.show', $task) }}" wire:navigate dir="auto">{{ $task->title }}</a>
                        · {{ $task->status->label() }}@if ($task->due_date) · {{ $task->due_date->translatedFormat(__('formats.date')) }}@endif
                    </li>
                @endforeach
            </ul>
        @endif
    @empty
        <p class="ap-sm">{{ __('annual_plans.milestones.empty') }}</p>
    @endforelse
</section>

<x-ui.modal wire:model="showMilestoneModal" :title="__('annual_plans.milestones.form_title')" submit="saveMilestone">
    @if ($showMilestoneModal)
        <div class="grid gap-4">
            <x-ui.form-input wire:model="milestoneForm.title" :label="__('common.name')" required />
            <x-ui.form-input wire:model="milestoneForm.description" :label="__('common.description')" />
            <x-ui.form-input wire:model="milestoneForm.due_date" type="date" :label="__('annual_plans.milestones.due_date')" required />
            <x-ui.form-select wire:model.live="milestoneForm.measure" :label="__('annual_plans.milestones.measure')" required>
                <option value="checkpoint">{{ __('annual_plans.milestones.checkpoint') }}</option>
                <option value="number">{{ __('annual_plans.milestones.number') }}</option>
                <option value="percentage">{{ __('annual_plans.milestones.percentage') }}</option>
            </x-ui.form-select>
            @if (($milestoneForm['measure'] ?? '') !== 'checkpoint')
                <x-ui.form-select wire:model="milestoneForm.plan_indicator_id" :label="__('annual_plans.milestones.target')" required>
                    <option value="">{{ __('annual_plans.milestones.select_target') }}</option>
                    @foreach ($this->milestoneTargets as $target)
                        <option value="{{ $target->id }}">{{ $target->indicator?->name }} · {{ $target->target }}</option>
                    @endforeach
                </x-ui.form-select>
                <x-ui.form-input wire:model="milestoneForm.threshold" type="number" min="0.01" step="0.01" :label="__('annual_plans.milestones.threshold')" required />
            @endif
        </div>
    @endif
    <x-slot:actions><x-ui.button type="submit" tone="primary">{{ __('common.save') }}</x-ui.button></x-slot:actions>
</x-ui.modal>

<x-ui.modal wire:model="showMilestoneTaskModal" :title="__('annual_plans.milestones.add_task')" submit="saveMilestoneTask">
    @if ($showMilestoneTaskModal)
        <div class="grid gap-4">
            <x-ui.form-input wire:model="milestoneTaskForm.title" :label="__('common.name')" maxlength="255" required autofocus />
            <x-ui.form-input wire:model="milestoneTaskForm.due_date" type="date" :label="__('annual_plans.milestones.due_date')" />
            <x-ui.form-multiselect wire:model="milestoneTaskForm.assignees" :label="__('tasks.assignees')" :placeholder="__('common.select')">
                @foreach (\App\Models\HrEmployee::pickerLabels()->reject(fn (array $person): bool => $person['inactive']) as $person)
                    <option value="{{ $person['value'] }}">{{ $person['label'] }}</option>
                @endforeach
            </x-ui.form-multiselect>
        </div>
    @endif
    <x-slot:actions><x-ui.button type="submit" tone="primary" data-test="save-milestone-task">{{ __('common.save') }}</x-ui.button></x-slot:actions>
</x-ui.modal>

<x-ui.confirm-modal
    wire:model="showDeleteMilestoneModal"
    close="cancelDeleteMilestone"
    :title="__('annual_plans.milestones.delete_title')"
    :record="$showDeleteMilestoneModal ? $this->milestones->firstWhere('id', $deletingMilestoneId)?->title : null"
    :confirm-label="__('common.delete')"
    confirm="deleteMilestone"
    permanent
/>
