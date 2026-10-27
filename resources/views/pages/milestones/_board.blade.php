@php
    use App\Enums\MilestoneStatus;

    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Project|\App\Models\Proposal> $projects */

    $allMilestones = $projects
        ->flatMap(fn ($projectRow) => $this->flattenMilestones($projectRow->milestones)->map(function ($milestone) use ($projectRow) {
            $milestone->setRelation($projectRow instanceof \App\Models\Project ? 'project' : 'proposal', $projectRow);

            return $milestone;
        }))
        ->sortBy('planned_start_date')
        ->values();

    $columnTone = fn (MilestoneStatus $case): string => match ($case) {
        MilestoneStatus::Reached => 'border-status-good/30 bg-status-good-soft',
        MilestoneStatus::Ongoing => 'border-status-warn/30 bg-status-warn-soft',
        MilestoneStatus::Postponed => 'border-primary bg-status-critical-soft',
        MilestoneStatus::Cancelled => 'border-secondary-light bg-secondary-soft/60 dark:bg-white/10',
        MilestoneStatus::Planned => 'border-board-line bg-board',
    };
@endphp

<div class="grid grid-cols-1 gap-4 @2xl/directory:grid-cols-2 @5xl/directory:grid-cols-4">
    @foreach (MilestoneStatus::cases() as $case)
        @php $columnMilestones = $allMilestones->where('status', $case)->values(); @endphp

        <div
            class="flex cursor-pointer flex-col gap-3 rounded-xl border p-3 transition-colors {{ $columnTone($case) }}"
            wire:key="milestone-board-column-{{ $case->value }}"
            data-test="milestone-board-column-{{ $case->value }}"
            x-data="{ dragOver: false }"
            @can('create', App\Models\Milestone::class) x-on:click="$event.target.closest('[data-milestone-card]') || $wire.openCreateModal(null, '{{ $case->value }}')" @endcan
            x-on:dragover.prevent="dragOver = true"
            x-on:dragleave="dragOver = false"
            x-on:drop.prevent="
                dragOver = false;
                const dragged = JSON.parse($event.dataTransfer.getData('text/plain') || '{}');
                if (dragged.id && dragged.status !== '{{ $case->value }}') {
                    $wire.quickSetStatus(dragged.id, '{{ $case->value }}');
                }
            "
            :class="dragOver ? 'ring-2 ring-primary ring-offset-1' : ''"
        >
            <div class="flex items-center justify-between px-1">
                <x-ui.enum-badge :case="$case" />
                <span class="text-xs font-semibold tabular-nums text-secondary-medium dark:text-ink-muted">{{ $columnMilestones->count() }}</span>
            </div>

            <div class="flex flex-col gap-2">
                @forelse ($columnMilestones as $milestone)
                    @php
                        $milestoneDueDate = $milestone->planned_end_date ?? $milestone->planned_start_date;
                        $canMoveMilestone = auth()->user()->can('updateProgress', $milestone);
                    @endphp
                    <div
                        class="flex flex-col gap-2 rounded-lg border border-secondary-soft bg-white p-3 shadow-hairline transition-opacity dark:border-line dark:bg-surface-1"
                        wire:key="milestone-board-card-{{ $milestone->id }}"
                        data-milestone-card
                        draggable="{{ $canMoveMilestone ? 'true' : 'false' }}"
                        @if ($canMoveMilestone) style="cursor: grab" @endif
                        x-on:dragstart="$event.dataTransfer.setData('text/plain', JSON.stringify({ id: {{ $milestone->id }}, status: '{{ $milestone->status->value }}' })); $event.currentTarget.classList.add('opacity-40')"
                        x-on:dragend="$event.currentTarget.classList.remove('opacity-40')"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex min-w-0 flex-wrap items-center gap-1.5">
                                <a href="{{ route('milestones.show', $milestone) }}" wire:navigate class="min-w-0 truncate text-sm font-semibold text-secondary-dark hover:underline dark:text-ink" data-test="milestone-board-card-{{ $milestone->id }}">
                                    {{ $milestone->name }}
                                </a>
                                <x-ui.milestone-flags :milestone="$milestone" />
                            </div>

                            @if ($milestone->children_count === 0 && $milestone->tasks_count === 0)
                                @can('milestones.delete')
                                <x-ui.button tone="ghost" size="sm" icon="trash" class="size-8! shrink-0" wire:click="confirmDelete({{ $milestone->id }})" :aria-label="__('common.delete_name', ['name' => $milestone->name])" />
                                @endcan
                            @else
                                <flux:tooltip :content="__('milestones.only_a_milestone_with_no_nested_milestones_and_no_tasks_can')">
                                    <span class="inline-flex size-8 shrink-0 cursor-not-allowed items-center justify-center text-secondary-light" aria-disabled="true">
                                        <flux:icon.lock-closed class="size-4" />
                                    </span>
                                </flux:tooltip>
                            @endif
                        </div>

                        @if ($milestone->project_id !== null)
                            <a href="{{ route('projects.show', $milestone->project) }}" wire:navigate class="truncate text-xs text-secondary-medium hover:underline dark:text-ink-muted">{{ $milestone->ownerName() }}</a>
                        @else
                            <span class="truncate text-xs text-secondary-medium dark:text-ink-muted">{{ $milestone->ownerName() }}</span>
                        @endif

                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                            @if ($milestoneDueDate !== null)
                                <span @class(['tabular-nums', 'font-semibold text-status-warn' => $milestone->isMissed(), 'text-secondary-medium dark:text-ink-muted' => ! $milestone->isMissed()])>
                                    {{ __('milestones.planned_date', ['date' => $milestoneDueDate->translatedFormat(__('formats.date'))]) }}
                                </span>
                            @endif
                            <span class="text-secondary-medium tabular-nums dark:text-ink-muted">{{ __('milestones.weight_weight', ['weight' => $milestone->weight_percentage]) }}</span>
                        </div>

                        <x-ui.employee-chip :employee="$milestone->responsibleUser" compact />

                        <label class="block">
                            <span class="sr-only">{{ __('milestones.change_status') }}</span>
                            <select
                                wire:change="quickSetStatus({{ $milestone->id }}, $event.target.value)"
                                @disabled(! $canMoveMilestone)
                                class="w-full rounded-md border border-secondary-soft bg-white px-2 py-1.5 text-xs text-secondary-dark dark:border-line dark:bg-surface-1 dark:text-ink"
                                data-test="milestone-quick-status-{{ $milestone->id }}"
                            >
                                @foreach (MilestoneStatus::cases() as $statusOption)
                                    <option value="{{ $statusOption->value }}" @selected($statusOption === $milestone->status)>{{ $statusOption->label() }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                @empty
                    <p class="px-1 py-4 text-center text-xs text-secondary-light">{{ __('milestones.no_milestones_in_this_status') }}</p>
                @endforelse
            </div>
        </div>
    @endforeach
</div>
