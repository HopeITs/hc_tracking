<?php

use App\Livewire\Concerns\AnnualPlans\EditsPlanUpdate;
use App\Models\ActivityPlan;
use App\Models\Project;
use App\Policies\PlanUpdatePolicy;
use App\Support\PlanExecution;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/*
 * The monthly update of an annual plan for one centre and month
 * (2026-11-11 workflow): the project manager fills and sends it in two parts;
 * the MEAL officer, the MEAL manager and the program manager review it in
 * turn. Everyone involved reads all of it at every stage.
 */
new #[Layout('layouts::desk'), Title('titles.annual_plan')] class extends Component {
    use EditsPlanUpdate;
    use WithFileUploads;

    public function mount(ActivityPlan $activityPlan, Project $project, string $month): void
    {
        abort_unless(PlanUpdatePolicy::canRead(auth()->user()), 403);
        abort_unless(preg_match('/^\d{4}-\d{2}$/', $month) === 1, 404);

        $execution = PlanExecution::for($activityPlan);
        $monthStart = CarbonImmutable::createFromFormat('!Y-m', $month);
        abort_unless($execution->project($project->id) !== null, 404);
        abort_unless(in_array($month, array_map(fn (CarbonImmutable $value): string => $value->format('Y-m'), $execution->calendar->months()), true), 404);

        $this->planId = $activityPlan->id;
        $this->projectId = $project->id;
        $this->month = $monthStart->format('Y-m');
    }
};
?>

@use('App\Enums\PlanUpdateStatus')

@php
    $plan = $this->plan;
    $project = $this->project;
    $record = $this->record;
    $mode = $this->mode;
    $monthLabel = $this->monthStart->translatedFormat('F Y');
    $formatMoment = fn (\Carbon\CarbonInterface $moment): string => $moment->translatedFormat(__('formats.date'));
    $status = $record?->status ?? PlanUpdateStatus::Draft;
@endphp

<section class="@container/detail flex w-full flex-1 flex-col gap-6" data-plan-update data-update-mode="{{ $mode }}">
    <x-ui.detail-header
        :back="\App\Support\BackUrl::for(route('activity-plans.show', $plan))"
        :breadcrumbs="[
            ['label' => __('common.annual_plans'), 'url' => route('annual-plans.index')],
            ['label' => $plan->proposal->name, 'url' => route('activity-plans.show', $plan)],
            ['label' => $project->name],
            ['label' => $monthLabel],
        ]"
        :title="$monthLabel.' · '.$project->name"
        :subtitle="$plan->proposal->name.' · '.$plan->program?->name"
    >
        <x-slot:meta>
            @include('pages.annual-plans._update-status-pill', ['update' => $record])
            @if ($this->isQuarter())<span class="dk-pill dk-act" data-quarterly>{{ __('annual_plans.up.quarterly') }}</span>@endif
            <span class="tabular-nums">{{ __('annual_plans.up.due_on', ['date' => $formatMoment($this->dueDate())]) }}</span>
        </x-slot:meta>
    </x-ui.detail-header>

    <div class="ap-page">
        @include('pages.annual-plans._guide', ['guideKey' => match ($mode) {
            'fill', 'answer' => 'centre',
            'officer' => 'officer',
            'meal' => 'meal',
            'program' => 'pm',
            default => 'viewer',
        }])
        @if ($mode === 'fill')
            @include('pages.annual-plans._update-form')
        @else
            @include('pages.annual-plans._update-view')
        @endif
    </div>
</section>
