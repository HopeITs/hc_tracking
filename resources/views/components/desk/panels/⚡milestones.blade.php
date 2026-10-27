<?php

use App\Enums\MilestoneStatus;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\Milestone;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Milestones: checkpoints not yet reached or cancelled, soonest end
 * first, with their dates and weight in the project.
 */
new class extends Component {
    use IsDeskPanel;

    /**
     * @return Collection<int, Milestone>
     */
    #[Computed]
    public function rows(): Collection
    {
        return $this->visible(Milestone::query(), 'milestones')
            ->select(['id', 'slug', 'name', 'project_id', 'proposal_id', 'is_final_report', 'planned_start_date', 'planned_end_date', 'status', 'weight_percentage'])
            ->with(['project:id,name', 'proposal:id,name'])
            ->whereNotIn('status', [MilestoneStatus::Reached, MilestoneStatus::Cancelled])
            ->orderByRaw('planned_end_date is null')
            ->orderBy('planned_end_date')
            ->orderBy('id')
            ->get();
    }
};
?>

@php
    $rows = $this->rows;
    $pg = $this->paginate($rows, self::PerPage);
    $format = __('desk.date_short');
@endphp

<x-desk.panel :facets="$pg['facets']" id="milestones" register="milestones.index" :size="$size" :index="$index" :total="$total" :count="$rows->count()">
    @if ($pg['total'] > 0)
        <ul class="dk-plist">
            @foreach ($pg['slice'] as $milestone)
                <li wire:key="m-{{ $milestone->id }}">
                    <div>
                        <strong>@if ($milestone->is_final_report)<span class="dk-ms">{{ __('desk.final_report') }}</span> @endif<a href="{{ route('milestones.show', $milestone) }}" wire:navigate>{{ $milestone->name }}</a></strong>
                        <span class="dk-is">{{ $milestone->ownerName() }} · {{ $milestone->planned_start_date->translatedFormat($format) }}@if ($milestone->planned_end_date !== null) – {{ $milestone->planned_end_date->translatedFormat($format) }}@endif · {{ __('desk.weight') }} {{ rtrim(rtrim($milestone->weight_percentage, '0'), '.') }}%</span>
                    </div>
                    <span @class(['dk-pill', 'dk-warn' => $milestone->status === MilestoneStatus::Postponed, 'dk-act' => $milestone->status === MilestoneStatus::Ongoing])>{{ $milestone->status->label() }}</span>
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
