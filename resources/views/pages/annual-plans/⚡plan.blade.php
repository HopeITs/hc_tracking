<?php

use App\Livewire\Concerns\AnnualPlans\EditsActivityPlan;
use App\Models\ActivityPlan;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * One annual activity plan (2026-11-11 workflow): the program manager's
 * four steps, the MEAL manager's review, and the approved plan. A record page
 * of its own (records are pages, 2026-11-04).
 */
new #[Layout('layouts::desk'), Title('titles.annual_plan')] class extends Component {
    use EditsActivityPlan;

    public function mount(ActivityPlan $activityPlan): void
    {
        Gate::authorize('view', $activityPlan);

        $this->planId = $activityPlan->id;
    }
};
?>

@use('App\Enums\ActivityPlanStatus')

@php
    $plan = $this->plan;
    $mode = $this->mode;
    $railIndex = match ($plan->status) {
        ActivityPlanStatus::Approved => 5,
        ActivityPlanStatus::InReview => 4,
        ActivityPlanStatus::Draft => $plan->step,
    };
    $steps = [
        __('annual_plans.wf.step_proposal'),
        __('annual_plans.wf.step_activities'),
        __('annual_plans.wf.step_dates'),
        __('annual_plans.wf.step_send'),
        __('annual_plans.wf.step_review'),
        __('annual_plans.wf.step_approved'),
    ];
    $formatMoment = fn (\Carbon\CarbonInterface $moment): string => $moment->translatedFormat(__('formats.date'));
@endphp

<section class="@container/detail flex w-full flex-1 flex-col gap-6" data-activity-plan="{{ $plan->id }}" data-plan-mode="{{ $mode }}">
    <x-ui.detail-header
        :back="\App\Support\BackUrl::for(route('annual-plans.index'))"
        :breadcrumbs="[
            ['label' => __('common.annual_plans'), 'url' => route('annual-plans.index')],
            ['label' => $plan->proposal->name],
            ['label' => $plan->program?->name ?? '—'],
        ]"
        :title="$plan->proposal->name"
        :subtitle="$plan->program?->name"
    >
        <x-slot:meta>
            <span class="dk-pill {{ match ($plan->status) { ActivityPlanStatus::Approved => 'dk-good', ActivityPlanStatus::InReview => 'dk-act', ActivityPlanStatus::Draft => $plan->sent_back_reason ? 'dk-bad' : '' } }}" data-plan-status="{{ $plan->status->value }}">{{ $plan->sent_back_reason && $plan->status === ActivityPlanStatus::Draft ? __('annual_plans.wf.status_sent_back') : $plan->status->label() }}</span>
            <span class="tabular-nums">{{ $formatMoment($this->calendar->begin) }} → {{ $formatMoment($this->calendar->end) }} · {{ trans_choice('annual_plans.wf.weeks_count', $this->calendar->weekCount(), ['count' => $this->calendar->weekCount()]) }}</span>
            @if ($plan->submitted_at !== null && $plan->submitter !== null)
                <span class="tabular-nums">{{ __('annual_plans.wf.sent_by_on', ['name' => $plan->submitter->name, 'date' => $formatMoment($plan->submitted_at)]) }}</span>
            @endif
            @if ($plan->approved_at !== null && $plan->approver !== null)
                <span class="tabular-nums">{{ __('annual_plans.wf.approved_by_on', ['name' => $plan->approver->name, 'date' => $formatMoment($plan->approved_at)]) }}</span>
            @endif
        </x-slot:meta>
    </x-ui.detail-header>

    <div class="ap-page">
        @php
            $viewer = auth()->user();
            $guideKey = match (true) {
                $mode === 'plan' || $mode === 'wait' => 'pm',
                $mode === 'review' || \App\Policies\ActivityPlanPolicy::isMealManager($viewer) => 'meal',
                $viewer->hasRole('General Manager') && ! $viewer->hasRole('Super Admin') => 'ceo',
                $viewer->hasRole('MEAL') => 'officer',
                $viewer->employee_id !== null && collect($this->execution->projects())->contains(fn ($centre) => (int) $centre->user_id === (int) $viewer->employee_id) => 'centre',
                default => 'viewer',
            };
        @endphp
        <div class="ap-row">
            <div class="ap-sp">@include('pages.annual-plans._guide', ['guideKey' => $guideKey])</div>
            <a class="dk-btn" href="{{ route('activity-plans.export', [$plan, 'format' => 'pdf']) }}" target="_blank" rel="noopener" data-test="plan-export-pdf">{{ __('annual_plans.up.export_pdf') }}</a>
            <a class="dk-btn" href="{{ route('activity-plans.export', [$plan, 'format' => 'xlsx']) }}" data-test="plan-export-excel">{{ __('annual_plans.up.export_excel') }}</a>
        </div>
        <div class="ap-rail" style="--ap-steps: 6" aria-label="{{ __('annual_plans.wf.progress') }}">
            @foreach ($steps as $index => $label)
                <div @class(['ap-st', 'is-done' => $index < $railIndex, 'is-now' => $index === $railIndex]) @if ($index === $railIndex) aria-current="step" @endif><i></i>{{ $index + 1 }}. {{ $label }}</div>
            @endforeach
        </div>

        @if ($mode === 'plan')
            @include('pages.annual-plans._plan-step-'.['proposal', 'activities', 'dates', 'send'][$plan->step])
        @elseif ($mode === 'review')
            @include('pages.annual-plans._plan-review')
        @elseif ($plan->status === ActivityPlanStatus::Approved)
            @include('pages.annual-plans._plan-approved')
        @elseif ($plan->status === ActivityPlanStatus::InReview)
            <section class="ap-card" data-plan-waiting>
                <div>
                    <h2>{{ $mode === 'wait' ? __('annual_plans.wf.with_meal_title') : __('annual_plans.wf.in_review_title') }}</h2>
                    <p class="ap-q">{{ __('annual_plans.wf.with_meal_body', ['date' => $formatMoment($plan->submitted_at)]) }}</p>
                </div>
                @include('pages.annual-plans._plan-gantt')
            </section>
        @else
            <section class="ap-card" data-plan-preparing>
                <div>
                    <h2>{{ __('annual_plans.wf.preparing_title') }}</h2>
                    <p class="ap-q">{{ __('annual_plans.wf.preparing_body', ['step' => $plan->step + 1]) }}</p>
                </div>
                @if ($plan->sent_back_reason)
                    <div class="ap-info is-bad">↩︎ <b>{{ __('annual_plans.wf.sent_back_banner') }}</b> “{{ $plan->sent_back_reason }}”</div>
                @endif
                @if ($plan->items->isNotEmpty())
                    @include('pages.annual-plans._plan-gantt')
                @endif
            </section>
        @endif
    </div>
</section>
