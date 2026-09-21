@php
    /** @var \App\Models\Milestone $milestone */
    /** @var int $depth */
@endphp

<article data-tree-depth="{{ $depth }}" wire:key="milestone-node-{{ $milestone->id }}" class="flex flex-col gap-3 px-3.5 py-3" style="padding-inline-start: {{ 0.875 + ($depth * 1.25) }}rem">
    <div class="flex flex-col gap-2 @lg/directory:flex-row @lg/directory:items-start">
        <div class="flex min-w-0 flex-1 items-start gap-2.5">
            @if ($depth > 0)
                <span aria-hidden="true" class="mt-0.5 shrink-0 text-secondary-light">└</span>
            @endif

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="min-w-0 font-semibold text-secondary-dark dark:text-white">{{ $milestone->name }}</p>
                    <x-ui.enum-badge :case="$milestone->status" />
                    <x-ui.milestone-flags :milestone="$milestone" />
                </div>
                <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-secondary-medium dark:text-secondary-soft">
                    <span>{{ __('milestones.planned_date', ['date' => $milestone->planned_start_date->translatedFormat(__('formats.date'))]) }}</span>
                    <span>{{ __('milestones.weight_weight', ['weight' => $milestone->weight_percentage]) }}</span>
                    <x-ui.employee-chip :employee="$milestone->responsibleUser" compact />
                </div>
                @if ($milestone->description !== null)
                    <p class="mt-2 text-sm leading-5 text-secondary-medium dark:text-secondary-soft">{{ $milestone->description }}</p>
                @endif
            </div>
        </div>

        <div class="flex shrink-0 items-center gap-1 self-end @lg/directory:self-start">
            <x-ui.button tone="ghost" size="sm" icon="plus" wire:click="openCreateModal(null, null, null, {{ $milestone->id }})" class="size-11! touch-manipulation @sm/directory:size-9!" data-test="milestone-add-nested-{{ $milestone->id }}" :aria-label="__('milestones.add_a_milestone_nested_under_this_one')" />
            <x-ui.button tone="ghost" size="sm" icon="pencil-square" href="{{ route('milestones.show', $milestone) }}" wire:navigate class="size-11! touch-manipulation @sm/directory:size-9!" :aria-label="__('common.edit_name', ['name' => $milestone->name])" />
            @if ($milestone->children_count === 0 && $milestone->tasks_count === 0)
                <x-ui.button tone="ghost" size="sm" icon="trash" class="size-11! touch-manipulation @sm/directory:size-9!" wire:click="confirmDelete({{ $milestone->id }})" :aria-label="__('common.delete_name', ['name' => $milestone->name])" />
            @else
                <flux:tooltip :content="__('milestones.only_a_milestone_with_no_nested_milestones_and_no_tasks_can')">
                    <span class="inline-flex size-11 cursor-not-allowed items-center justify-center text-secondary-light @sm/directory:size-9" aria-disabled="true">
                        <flux:icon.lock-closed class="size-4" />
                    </span>
                </flux:tooltip>
            @endif
        </div>
    </div>
</article>

@foreach ($milestone->childrenRecursive as $childMilestone)
    @include('pages.milestones._tree-node', ['milestone' => $childMilestone, 'depth' => $depth + 1])
@endforeach
