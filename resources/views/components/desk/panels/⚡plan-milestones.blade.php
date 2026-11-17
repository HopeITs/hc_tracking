<?php

use App\Enums\ActivityPlanStatus;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\ActivityPlanMilestone;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    use IsDeskPanel;

    /** @return Collection<int, ActivityPlanMilestone> */
    #[Computed]
    public function rows(): Collection
    {
        $user = auth()->user();
        $programIds = $user->managedProgramIds();
        $projectIds = $user->ownedProjectIds();

        if (! $user->can('annual-plans.view') || ($programIds === [] && $projectIds === [])) {
            return collect();
        }

        return ActivityPlanMilestone::query()
            ->whereHas('plan', fn ($query) => $query
                ->where('status', ActivityPlanStatus::Approved->value)
                ->where(fn ($query) => $query->whereIn('program_id', $programIds)
                    ->orWhereHas('proposal.projects', fn ($query) => $query
                        ->whereIn('projects.id', $projectIds)
                        ->whereColumn('projects.program_id', 'activity_plans.program_id'))))
            ->with(['plan.proposal', 'target.indicator', 'target.reports'])
            ->orderBy('due_date')->orderBy('id')->get()->toBase();
    }

    /** @return array<string, string> */
    protected function facetsOf(mixed $row): array
    {
        return ['status' => __('annual_plans.milestones.'.$row->status())];
    }
};
?>

@php
    $rows = $this->rows;
    $pg = $this->paginate($rows, self::PerPage);
@endphp

<x-desk.panel :empty="$rows->isEmpty()" :facets="$pg['facets']" id="plan-milestones" :size="$size" :cols="$gridCols" :height="$gridRows" :index="$index" :total="$total" :count="$rows->count()">
    @if ($pg['total'] > 0)
        <ul class="dk-dr" {{ $this->fitAttributes($pg) }}>
            @foreach ($pg['slice'] as $milestone)
                <li wire:key="plan-milestone-{{ $milestone->id }}">
                    <a class="dk-dr-n" href="{{ route('activity-plans.show', $milestone->plan) }}" wire:navigate>{{ $milestone->title }}</a>
                    <span class="dk-dr-t dk-num">{{ $milestone->due_date->translatedFormat(__('formats.date')) }}</span>
                    <span class="dk-dr-m">{{ $milestone->plan->proposal->name }}</span>
                    <span class="dk-pill dk-dr-s">{{ __('annual_plans.milestones.'.$milestone->status()) }}</span>
                </li>
            @endforeach
        </ul>
    @else
        <p class="dk-pempty">{{ __('desk.panel.plan-milestones.empty') }}</p>
    @endif
    <x-slot:foot><x-desk.pager :pg="$pg" /></x-slot:foot>
</x-desk.panel>
