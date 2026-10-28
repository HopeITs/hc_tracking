<?php

use App\Enums\MilestoneStatus;
use App\Enums\TaskStatus;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\Milestone;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Milestones: open checkpoints (planned or ongoing) with their progress and
 * health. Progress is the share of the milestone's tasks that are done
 * (tasks the viewer may see). Health: off track (bad) = past its end date; needs a look (warn) = due
 * within a week and under 70% done; else on track (good). Late first, then the
 * soonest end date.
 */
new class extends Component {
    use IsDeskPanel;

    /**
     * Below this share of done tasks, a milestone due within a week needs a look.
     */
    private const int WatchBelowPercent = 70;

    /**
     * @return Collection<int, array{milestone: Milestone, done: int, tasks: int, percent: int, health: string, daysLeft: int|null}>
     */
    #[Computed]
    public function rows(): Collection
    {
        $today = CarbonImmutable::today();

        return $this->visible(Milestone::query(), 'milestones')
            ->select(['id', 'slug', 'name', 'project_id', 'proposal_id', 'is_final_report', 'planned_start_date', 'planned_end_date', 'status'])
            ->with(['project:id,name', 'proposal:id,name'])
            ->withCount(['tasks', 'tasks as done_tasks_count' => fn (Builder $query) => $query->where('status', TaskStatus::Done)])
            ->whereIn('status', [MilestoneStatus::Planned, MilestoneStatus::Ongoing])
            ->get()
            ->map(function (Milestone $milestone) use ($today): array {
                $tasks = (int) $milestone->tasks_count;
                $done = (int) $milestone->done_tasks_count;
                $percent = $tasks === 0 ? 0 : (int) floor($done * 100 / $tasks);
                $daysLeft = $milestone->planned_end_date === null ? null : (int) $today->diffInDays($milestone->planned_end_date->startOfDay(), false);

                return [
                    'milestone' => $milestone,
                    'done' => $done,
                    'tasks' => $tasks,
                    'percent' => $percent,
                    'health' => match (true) {
                        $daysLeft !== null && $daysLeft < 0 => 'bad',
                        $daysLeft !== null && $daysLeft <= 7 && $percent < self::WatchBelowPercent => 'warn',
                        default => 'good',
                    },
                    'daysLeft' => $daysLeft,
                ];
            })
            ->sortBy([
                fn (array $a, array $b): int => ($b['health'] === 'bad') <=> ($a['health'] === 'bad'),
                fn (array $a, array $b): int => ($a['daysLeft'] ?? PHP_INT_MAX) <=> ($b['daysLeft'] ?? PHP_INT_MAX),
            ])
            ->values();
    }

    /**
     * @return array<string, string>
     */
    protected function facetsOf(mixed $row): array
    {
        return [
            'project' => $row['milestone']->ownerName(),
            'health' => __('desk.health.'.$row['health']),
        ];
    }
};
?>

@php
    $rows = $this->rows;
    $pg = $this->paginate($rows, self::PerPage);
@endphp

<x-desk.panel :facets="$pg['facets']" id="milestones" register="milestones.index" :size="$size" :index="$index" :total="$total" :count="$rows->count()">
    @if ($pg['total'] > 0)
        <ul class="dk-ms-rows">
            @foreach ($pg['slice'] as $row)
                @php
                    $milestone = $row['milestone'];
                    $tone = 'dk-'.$row['health'];
                @endphp
                <li wire:key="m-{{ $milestone->id }}" data-health="{{ $row['health'] }}">
                    <a href="{{ route('milestones.show', $milestone) }}" wire:navigate class="dk-ms-n" title="{{ $milestone->name }}">@if ($milestone->is_final_report)<span class="dk-ms">{{ __('desk.final_report') }}</span>@endif{{ $milestone->name }}</a>
                    <span class="dk-pill {{ $tone }} dk-ms-h">{{ __('desk.health.'.$row['health']) }}</span>
                    <span class="dk-ms-meta dk-is"><span class="dk-ms-p" title="{{ $milestone->ownerName() }}">{{ $milestone->ownerName() }}</span><span @class(['dk-bad' => $row['health'] === 'bad'])> · {{ $row['daysLeft'] === null ? __('desk.no_end_date') : \App\Support\Desk\RelativeDay::for($milestone->planned_end_date) }}</span></span>
                    <span class="dk-ms-bar" title="{{ __('desk.milestone_progress', ['done' => $row['done'], 'tasks' => $row['tasks']]) }}">
                        <span class="dk-meter"><b style="width:{{ $row['percent'] }}%"></b></span>
                        <span class="dk-num">{{ $row['tasks'] === 0 ? '—' : $row['percent'].'%' }}</span>
                    </span>
                </li>
            @endforeach
        </ul>
    @else
        <p class="dk-pempty">{{ __('desk.panel.milestones.empty') }}</p>
    @endif

    <x-slot:foot>
        <x-desk.pager :pg="$pg" />
    </x-slot:foot>
</x-desk.panel>
