@php
    use App\Enums\MilestoneStatus;
    use Carbon\CarbonImmutable;

    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Project> $projects */

    $today = CarbonImmutable::today();

    $allMilestones = $projects
        ->flatMap(fn ($projectRow) => $this->flattenMilestones($projectRow->milestones)->map(function ($milestone) use ($projectRow) {
            $milestone->setRelation('project', $projectRow);

            return $milestone;
        }))
        ->sortBy('planned_date')
        ->values();

    $columnTone = fn (MilestoneStatus $case): string => match ($case) {
        MilestoneStatus::Reached => 'border-status-good/30 bg-status-good-soft',
        MilestoneStatus::Missed => 'border-primary/30 bg-status-critical-soft',
        MilestoneStatus::Cancelled => 'border-secondary-light bg-secondary-soft/60 dark:bg-secondary-medium/40',
        MilestoneStatus::Pending => 'border-board-line bg-board',
    };
@endphp

<div class="grid grid-cols-1 gap-4 @2xl/directory:grid-cols-2 @5xl/directory:grid-cols-4">
    @foreach (MilestoneStatus::cases() as $case)
        @php $columnMilestones = $allMilestones->where('status', $case)->values(); @endphp

        <div class="flex flex-col gap-3 rounded-xl border p-3 {{ $columnTone($case) }}" wire:key="milestone-board-column-{{ $case->value }}">
            <div class="flex items-center justify-between px-1">
                <x-ui.enum-badge :case="$case" />
                <span class="text-xs font-semibold tabular-nums text-secondary-medium dark:text-secondary-soft">{{ $columnMilestones->count() }}</span>
            </div>

            <div class="flex flex-col gap-2">
                @forelse ($columnMilestones as $milestone)
                    @php
                        $isOverdue = $milestone->status === MilestoneStatus::Pending && $milestone->planned_date->lt($today);
                    @endphp
                    <div class="flex flex-col gap-2 rounded-lg border border-secondary-soft bg-white p-3 shadow-[0_1px_2px_rgba(16,24,40,0.04)] dark:border-secondary-medium dark:bg-secondary-dark" wire:key="milestone-board-card-{{ $milestone->id }}">
                        <div class="flex items-start justify-between gap-2">
                            <button type="button" wire:click="openEditModal({{ $milestone->id }})" class="min-w-0 text-start text-sm font-semibold text-secondary-dark hover:underline dark:text-white" data-test="milestone-board-card-{{ $milestone->id }}">
                                {{ $milestone->name }}
                            </button>

                            @if ($milestone->children_count === 0 && $milestone->tasks_count === 0)
                                <x-ui.button tone="ghost" size="sm" icon="trash" class="size-8! shrink-0" wire:click="confirmDelete({{ $milestone->id }})" :aria-label="__('common.delete_name', ['name' => $milestone->name])" />
                            @else
                                <flux:tooltip :content="__('milestones.only_a_milestone_with_no_nested_milestones_and_no_tasks_can')">
                                    <span class="inline-flex size-8 shrink-0 cursor-not-allowed items-center justify-center text-secondary-light" aria-disabled="true">
                                        <flux:icon.lock-closed class="size-4" />
                                    </span>
                                </flux:tooltip>
                            @endif
                        </div>

                        <a href="{{ route('projects.show', $milestone->project) }}" wire:navigate class="truncate text-xs text-secondary-medium hover:underline dark:text-secondary-soft">{{ $milestone->project->name }}</a>

                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                            <span @class(['tabular-nums', 'font-semibold text-status-warn' => $isOverdue, 'text-secondary-medium dark:text-secondary-soft' => ! $isOverdue])>
                                {{ __('milestones.planned_date', ['date' => $milestone->planned_date->translatedFormat(__('formats.date'))]) }}
                                @if ($isOverdue)
                                    · {{ __('milestones.timeline_overdue') }}
                                @endif
                            </span>
                            <span class="text-secondary-medium tabular-nums dark:text-secondary-soft">{{ __('milestones.weight_weight', ['weight' => $milestone->weight_percentage]) }}</span>
                        </div>

                        <x-ui.employee-chip :employee="$milestone->responsibleUser" compact />

                        <label class="block">
                            <span class="sr-only">{{ __('milestones.change_status') }}</span>
                            <select
                                wire:change="quickSetStatus({{ $milestone->id }}, $event.target.value)"
                                class="w-full rounded-md border border-secondary-soft bg-white px-2 py-1.5 text-xs text-secondary-dark dark:border-secondary-medium dark:bg-secondary-dark dark:text-white"
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
