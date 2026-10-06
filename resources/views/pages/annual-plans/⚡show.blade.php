<?php

use App\Enums\Period;
use App\Enums\ReportStatus;
use App\Models\AnnualPlan;
use App\Models\HrEmployee;
use App\Models\PlanIndicator;
use App\Models\Report;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('titles.annual_plan')] class extends Component {
    #[Locked]
    public int $annualPlanId;

    public bool $showPlanModal = false;

    public bool $confirmingDelete = false;

    public string $planManagerId = '';

    public string $planYear = '';

    public function mount(AnnualPlan $annualPlan): void
    {
        $this->annualPlanId = $annualPlan->id;
    }

    #[Computed]
    public function plan(): AnnualPlan
    {
        return AnnualPlan::query()
            ->select(['id', 'program_id', 'manager_id', 'year', 'updated_at'])
            ->with([
                'program:id,strategy_id',
                'program.strategy:id,name',
                'manager:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
            ])
            ->withCount('planIndicators')
            ->findOrFail($this->annualPlanId);
    }

    /**
     * The targets committed in this plan, with everything the page reads to
     * draw the logframe connection (result → indicator → project) and the
     * reporting signal on each one.
     *
     * Filtered on the morph map key 'annual_plan', never a class name: the
     * column is VARCHAR(50) and the map is enforced application-wide.
     *
     * @return Collection<int, PlanIndicator>
     */
    #[Computed]
    public function targets(): Collection
    {
        return PlanIndicator::query()
            ->select(['id', 'source_type', 'source_id', 'indicator_id', 'target', 'project_id', 'period'])
            ->where('source_type', 'annual_plan')
            ->where('source_id', $this->annualPlanId)
            ->with([
                'indicator:id,name,result_id,measure',
                'indicator.result:id,statement,type',
                'project:id,name,city_id',
                'project.city:id,name_en,name_ar',
            ])
            ->withCount('reports')
            ->get();
    }

    /**
     * Reports submitted against this plan's targets, grouped by review status.
     *
     * One extra query beyond the four-reads guarantee that binds the coverage
     * matrix — that guarantee is scoped to the index page's grid, not this
     * single-record detail view.
     *
     * @return array<value-of<ReportStatus>, int>
     */
    #[Computed]
    public function reportStatusCounts(): array
    {
        $targetIds = $this->targets->modelKeys();

        if ($targetIds === []) {
            return [];
        }

        return Report::query()
            ->whereIn('plan_indicator_id', $targetIds)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();
    }

    /**
     * The headline figures for the plan pulse board.
     *
     * @return array{
     *     targets: int,
     *     projects: int,
     *     orgWide: int,
     *     results: int,
     *     reported: int,
     *     unreported: int,
     *     reportingShare: int,
     *     reportsTotal: int,
     *     reportsApproved: int,
     *     reportsReviewed: int,
     *     reportsDraft: int,
     *     approvedShare: int,
     * }
     */
    #[Computed]
    public function summary(): array
    {
        $targets = $this->targets;
        $statusCounts = $this->reportStatusCounts;

        $reported = $targets->filter(fn (PlanIndicator $target): bool => $target->reports_count > 0)->count();
        $reportsTotal = array_sum($statusCounts);
        $approved = $statusCounts[ReportStatus::Approved->value] ?? 0;

        return [
            'targets' => $targets->count(),
            'projects' => $targets->pluck('project_id')->filter()->unique()->count(),
            'orgWide' => $targets->whereNull('project_id')->count(),
            'results' => $targets->pluck('indicator.result_id')->filter()->unique()->count(),
            'reported' => $reported,
            'unreported' => $targets->count() - $reported,
            'reportingShare' => $targets->isEmpty() ? 0 : (int) round($reported / $targets->count() * 100),
            'reportsTotal' => $reportsTotal,
            'reportsApproved' => $approved,
            'reportsReviewed' => $statusCounts[ReportStatus::Reviewed->value] ?? 0,
            'reportsDraft' => $statusCounts[ReportStatus::Draft->value] ?? 0,
            'approvedShare' => $reportsTotal > 0 ? (int) round($approved / $reportsTotal * 100) : 0,
        ];
    }

    /**
     * Targets grouped under the logframe result they measure, ordered by
     * result type (impact, then outcome, then output) so the plan reads top
     * down the same way the logframe does.
     *
     * Grouped from the already-loaded targets collection, so this costs no
     * additional query.
     *
     * @return SupportCollection<int, array{result: \App\Models\Result, targets: Collection<int, PlanIndicator>}>
     */
    #[Computed]
    public function resultGroups(): SupportCollection
    {
        $typeOrder = ['impact' => 0, 'outcome' => 1, 'output' => 2];

        return $this->targets
            ->groupBy('indicator.result_id')
            ->map(fn (Collection $targets): array => [
                'result' => $targets->first()->indicator->result,
                'targets' => $targets,
            ])
            ->sortBy(fn (array $group): int => $typeOrder[$group['result']->type->value] ?? 99)
            ->values();
    }

    /**
     * How many committed targets fall in each reporting period, for the
     * period-mix bar.
     *
     * @return array<int, array{period: Period, count: int, share: int}>
     */
    #[Computed]
    public function periodMix(): array
    {
        $targets = $this->targets;
        $total = max(1, $targets->count());

        return collect(Period::cases())
            ->map(fn (Period $period): array => [
                'period' => $period,
                'count' => $count = $targets->where('period', $period)->count(),
                'share' => (int) round($count / $total * 100),
            ])
            ->filter(fn (array $row): bool => $row['count'] > 0)
            ->values()
            ->all();
    }

    /**
     * The distinct projects this plan's targets are attributed to, for the
     * connections panel. Org-wide targets (no project) are excluded — they
     * are already surfaced as their own figure in the plan pulse.
     *
     * @return SupportCollection<int, \App\Models\Project>
     */
    #[Computed]
    public function linkedProjects(): SupportCollection
    {
        return $this->targets
            ->pluck('project')
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values();
    }

    /**
     * The most recent activity reported against this plan, newest first.
     *
     * @return Collection<int, Report>
     */
    #[Computed]
    public function recentReports(): Collection
    {
        $targetIds = $this->targets->modelKeys();

        if ($targetIds === []) {
            return new Collection();
        }

        return Report::query()
            ->select(['id', 'plan_indicator_id', 'status', 'value', 'period_start', 'period_end', 'submit_date', 'created_at'])
            ->whereIn('plan_indicator_id', $targetIds)
            ->with(['planIndicator:id,indicator_id,project_id', 'planIndicator.indicator:id,name,measure', 'planIndicator.project:id,name'])
            ->latest('created_at')
            ->limit(8)
            ->get();
    }

    /**
     * The fiscal years the form offers.
     *
     * This plan's own year is always among them, so an older plan stays
     * editable without its year silently moving.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function yearFormOptions(): array
    {
        $thisYear = (int) now()->year;

        $years = AnnualPlan::query()
            ->select('year')
            ->distinct()
            ->pluck('year')
            ->map(fn ($year): int => (int) $year)
            ->all();

        $years = [...$years, $thisYear, $thisYear + 1, $this->plan->year];

        if ($this->planYear !== '') {
            $years[] = (int) $this->planYear;
        }

        $years = array_values(array_unique($years));
        sort($years);

        return $years;
    }

    /**
     * Load the plan's own values into the edit form.
     *
     * The program is not offered here: moving a plan to another program on its
     * detail page would change what the page is about under the reader. That
     * edit belongs to the matrix, where both programs are on screen.
     */
    public function openEditModal(): void
    {
        $plan = $this->plan;

        Gate::authorize('update', $plan);

        $this->resetValidation();
        $this->planManagerId = (string) $plan->manager_id;
        $this->planYear = (string) $plan->year;
        $this->showPlanModal = true;
    }

    /**
     * Save the manager and fiscal year.
     *
     * The (program_id, year) pair is unique in the database, so moving the plan
     * onto a year the same program already covers is caught here rather than by
     * the driver.
     */
    public function savePlan(): void
    {
        $plan = $this->plan;

        Gate::authorize('update', $plan);

        $validated = $this->validate([
            'planManagerId' => ['required', 'integer', Rule::exists(HrEmployee::class, 'id')],
            'planYear' => [
                'required',
                'integer',
                'min:2000',
                'max:2100',
                Rule::unique(AnnualPlan::class, 'year')
                    ->where(fn ($query) => $query->where('program_id', $plan->program_id))
                    ->ignore($plan->id),
            ],
        ], [
            'planManagerId.required' => __('annual_plans.select_the_manager_accountable_for_this_plan'),
            'planManagerId.exists' => __('annual_plans.the_selected_manager_is_no_longer_available'),
            'planYear.required' => __('annual_plans.select_the_fiscal_year_this_plan_covers'),
            'planYear.unique' => __('annual_plans.this_program_already_has_a_plan_for_that_fiscal_year'),
        ]);

        $plan->fill([
            'manager_id' => Gate::allows('reassign', $plan) ? (int) $validated['planManagerId'] : $plan->manager_id,
            'year' => (int) $validated['planYear'],
        ])->save();

        $this->showPlanModal = false;
        $this->resetValidation();

        // The whole page reads through plan(), targets() and the figures built
        // on them, all memoised earlier in this request.
        unset($this->plan, $this->targets, $this->reportStatusCounts, $this->summary, $this->yearFormOptions);

        Flux::toast(variant: 'success', text: __('annual_plans.annual_plan_updated'));
    }

    /**
     * Delete the plan and return to the matrix.
     *
     * Its observer removes the targets committed in the plan, and the reports
     * filed against those targets go with them; the confirmation names that
     * count before this runs.
     */
    public function deletePlan(): void
    {
        Gate::authorize('delete', $this->plan);

        $this->plan->delete();

        Flux::toast(variant: 'success', text: __('annual_plans.annual_plan_deleted'));

        $this->redirectRoute('annual-plans.index', navigate: true);
    }

    public function closePlanModal(): void
    {
        $this->showPlanModal = false;
        $this->resetValidation();
    }
};
?>

@php
    $plan = $this->plan;
    $summary = $this->summary;
    $resultGroups = $this->resultGroups;
    $periodMix = $this->periodMix;
    $linkedProjects = $this->linkedProjects;
    $recentReports = $this->recentReports;

    $reportSegments = [
        ['count' => $summary['reportsApproved'], 'class' => 'bg-status-good', 'label' => \App\Enums\ReportStatus::Approved->label()],
        ['count' => $summary['reportsReviewed'], 'class' => 'bg-status-warn', 'label' => \App\Enums\ReportStatus::Reviewed->label()],
        ['count' => $summary['reportsDraft'], 'class' => 'bg-secondary-medium dark:bg-ink-faint', 'label' => \App\Enums\ReportStatus::Draft->label()],
    ];

    $gaugeRadius = 46;
    $gaugeCircumference = 2 * M_PI * $gaugeRadius;
    $gaugeOffset = $gaugeCircumference * (1 - $summary['reportingShare'] / 100);
@endphp

<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-8 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink">
    <x-ui.detail-header
        :breadcrumbs="[
            ['label' => __('common.annual_plans'), 'url' => route('annual-plans.index')],
            ['label' => __('common.fy_year', ['year' => $plan->year])],
        ]"
        :title="__('common.fy_year_program', ['year' => $plan->year, 'program' => $plan->program->name])"
        :subtitle="$plan->program->strategy?->name ?? __('common.strategy_not_assigned')"
    >
        <x-slot:meta>
            @if (Route::has('programs.show'))
                <a href="{{ route('programs.show', $plan->program) }}" wire:navigate class="font-medium hover:text-secondary-dark hover:underline dark:hover:text-ink">
                    {{ $plan->program->name }}
                </a>
            @else
                <span class="font-medium">{{ $plan->program->name }}</span>
            @endif
            @if ($plan->program->strategy !== null && Route::has('strategies.show'))
                <a href="{{ route('strategies.show', $plan->program->strategy) }}" wire:navigate class="hover:text-secondary-dark hover:underline dark:hover:text-ink">
                    {{ $plan->program->strategy->name }}
                </a>
            @endif
            <span>{{ trans_choice('common.count_target', $summary['targets'], ['count' => $summary['targets']]) }}</span>
            <span>{{ trans_choice('common.count_project', $summary['projects'], ['count' => $summary['projects']]) }}</span>
        </x-slot:meta>

        <x-slot:actions>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <x-ui.employee-chip :employee="$plan->manager" class="rounded-lg border border-secondary-soft p-2.5 dark:border-line" />
                <div class="flex items-center gap-1.5">
                    @can('update', $plan)<x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-11! touch-manipulation @sm/detail:size-10!" wire:click="openEditModal" :aria-label="__('annual_plans.edit_this_annual_plan')" data-test="edit-annual-plan-button" />@endcan
                    @can('delete', $plan)<x-ui.button tone="ghost" size="sm" icon="trash" class="size-11! touch-manipulation @sm/detail:size-10!" wire:click="$set('confirmingDelete', true)" :aria-label="__('annual_plans.delete_this_annual_plan')" data-test="delete-annual-plan-button" />@endcan
                </div>
            </div>
        </x-slot:actions>
    </x-ui.detail-header>

    {{-- Plan pulse: how much of the plan is being reported on, and how that reporting is landing. --}}
    <section class="overflow-hidden rounded-xl border border-board-line bg-board" aria-labelledby="plan-pulse-heading">
        <div class="border-b border-board-line bg-board-head px-5 py-4">
            <h2 id="plan-pulse-heading" class="text-base font-bold">{{ __('annual_plans.plan_pulse') }}</h2>
            <p class="mt-0.5 type-meta text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.plan_pulse_hint') }}</p>
        </div>

        <div class="grid gap-0 @4xl/detail:grid-cols-[20rem_minmax(0,1fr)]">
            <div class="flex items-center gap-5 border-b border-board-line px-5 py-5 @4xl/detail:border-e @4xl/detail:border-b-0">
                <div class="relative size-24 shrink-0" role="img" aria-label="{{ __('annual_plans.targets_reported_on') }}: {{ $summary['reportingShare'] }}%">
                    <svg viewBox="0 0 120 120" class="size-full -rotate-90" aria-hidden="true">
                        <circle cx="60" cy="60" r="{{ $gaugeRadius }}" class="fill-none stroke-board-line" stroke-width="10" />
                        <circle
                            cx="60" cy="60" r="{{ $gaugeRadius }}"
                            class="fill-none stroke-primary transition-[stroke-dashoffset] duration-500 ease-out motion-reduce:transition-none"
                            stroke-width="10"
                            stroke-linecap="round"
                            stroke-dasharray="{{ $gaugeCircumference }}"
                            stroke-dashoffset="{{ $gaugeOffset }}"
                        />
                    </svg>
                    <div class="absolute inset-0 flex items-center justify-center">
                        <span class="text-xl font-bold tabular-nums text-secondary-dark dark:text-ink" aria-hidden="true">{{ $summary['reportingShare'] }}%</span>
                    </div>
                </div>

                <div class="min-w-0">
                    <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.targets_reported_on') }}</p>
                    <p class="mt-1.5 type-figure font-bold tabular-nums">{{ $summary['reported'] }}<span class="type-body font-normal text-secondary-medium dark:text-ink-muted"> / {{ $summary['targets'] }}</span></p>
                    <p class="mt-1.5 type-meta text-secondary-medium dark:text-ink-muted">
                        {{ trans_choice('annual_plans.count_target_awaiting_first_report', $summary['unreported'], ['count' => $summary['unreported']]) }}
                    </p>
                </div>
            </div>

            <div class="flex flex-col justify-center gap-3 px-5 py-5">
                <div class="flex items-baseline justify-between gap-2">
                    <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.report_review_mix') }}</p>
                    <p class="type-meta tabular-nums text-secondary-medium dark:text-ink-muted">{{ trans_choice('common.count_report', $summary['reportsTotal'], ['count' => $summary['reportsTotal']]) }}</p>
                </div>

                @if ($summary['reportsTotal'] === 0)
                    <p class="type-meta text-secondary-light">{{ __('annual_plans.no_reports_submitted_yet') }}</p>
                @else
                    <div class="flex h-2.5 w-full overflow-hidden rounded-full bg-secondary-soft dark:bg-surface-2" role="img" aria-label="{{ __('annual_plans.approved') }} {{ $summary['reportsApproved'] }}, {{ \App\Enums\ReportStatus::Reviewed->label() }} {{ $summary['reportsReviewed'] }}, {{ \App\Enums\ReportStatus::Draft->label() }} {{ $summary['reportsDraft'] }}">
                        @foreach ($reportSegments as $segment)
                            @if ($segment['count'] > 0)
                                <span class="{{ $segment['class'] }} h-full" style="width: {{ $summary['reportsTotal'] > 0 ? round($segment['count'] / $summary['reportsTotal'] * 100, 2) : 0 }}%"></span>
                            @endif
                        @endforeach
                    </div>

                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 type-meta text-secondary-medium dark:text-ink-muted">
                        @foreach ($reportSegments as $segment)
                            <span class="inline-flex items-center gap-1.5">
                                <span aria-hidden="true" class="{{ $segment['class'] }} size-2 shrink-0 rounded-full"></span>
                                {{ $segment['label'] }} <span class="font-semibold tabular-nums text-secondary-dark dark:text-ink">{{ $segment['count'] }}</span>
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3 border-t border-board-line bg-board-head p-4 @2xl/detail:grid-cols-4">
            <x-ui.stat-tile :label="__('common.targets')" :value="$summary['targets']" icon="flag" />
            <x-ui.stat-tile :label="__('common.projects')" :value="$summary['projects']" icon="map-pin" />
            <x-ui.stat-tile :label="__('common.organisation_wide')" :value="$summary['orgWide']" icon="building-office" />
            <x-ui.stat-tile :label="__('annual_plans.results_touched')" :value="$summary['results']" icon="squares-2x2" />
        </div>

        @if ($periodMix !== [])
            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-board-line px-5 py-3 type-meta text-secondary-medium dark:text-ink-muted">
                <span class="font-semibold text-secondary-dark dark:text-ink">{{ __('annual_plans.reporting_rhythm') }}</span>
                @foreach ($periodMix as $row)
                    <span class="inline-flex items-center gap-1.5">
                        <x-ui.enum-badge :case="$row['period']" />
                        <span class="tabular-nums">{{ $row['count'] }} · {{ $row['share'] }}%</span>
                    </span>
                @endforeach
            </div>
        @endif
    </section>

    {{-- The logframe connection: every result this plan measures, and the targets committed under it, one aligned row per target. --}}
    <x-ui.panel :title="__('annual_plans.results_and_targets')" :count="$summary['targets']" :subtitle="__('annual_plans.how_this_plans_targets_connect_to_the_logframe')">
        <x-slot:toolbar>
            <a href="{{ route('targets.index', ['sourceType' => 'annual_plan']) }}" wire:navigate class="text-xs font-semibold text-secondary-medium hover:underline dark:text-ink-muted">
                {{ __('annual_plans.all_annual_plan_targets') }}
            </a>
        </x-slot:toolbar>

        @if ($resultGroups->isEmpty())
            <x-ui.empty-state icon="chart-bar" :heading="__('common.no_targets_yet')" :body="__('annual_plans.indicator_targets_committed_in_this_plan_will_appear_here')" />
        @else
            {{-- Column headings, shown once for the whole table rather than per result group. --}}
            <div class="hidden border-b border-board-line bg-board-head px-4 py-2 @2xl/detail:grid @2xl/detail:grid-cols-[minmax(0,1fr)_7rem_8rem_6rem]">
                <span class="type-label text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.indicator_and_location') }}</span>
                <span class="type-label text-secondary-medium dark:text-ink-muted">{{ __('common.period') }}</span>
                <span class="type-label text-end text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.committed_value') }}</span>
                <span class="type-label text-end text-secondary-medium dark:text-ink-muted">{{ __('common.reports') }}</span>
            </div>

            <div class="divide-y divide-board-line">
                @foreach ($resultGroups as $group)
                    @php
                        $result = $group['result'];
                    @endphp
                    <div wire:key="result-group-{{ $result->id }}">
                        <div class="flex flex-wrap items-start gap-x-2.5 gap-y-1.5 bg-board-head px-4 py-2.5">
                            <x-ui.enum-badge :case="$result->type" />
                            <p class="min-w-0 flex-1 text-sm font-semibold">{{ $result->statement }}</p>
                            @if (Route::has('results.index'))
                                <a href="{{ route('results.index', ['program' => $plan->program_id]) }}" wire:navigate class="shrink-0 type-meta font-semibold text-secondary-medium hover:underline dark:text-ink-muted">
                                    {{ __('annual_plans.open_in_logframe') }}
                                </a>
                            @endif
                        </div>

                        <div class="divide-y divide-board-line">
                            @foreach ($group['targets'] as $target)
                                @php
                                    $targetHref = $target->project !== null ? route('projects.show', ['project' => $target->project, 'tab' => 'targets']) : (Route::has('indicators.index') ? route('indicators.index', ['result' => $result->id]) : null);
                                @endphp
                                <a
                                    @if ($targetHref) href="{{ $targetHref }}" wire:navigate @endif
                                    class="grid grid-cols-1 gap-x-4 gap-y-1.5 px-4 py-3 transition-colors @2xl/detail:grid-cols-[minmax(0,1fr)_7rem_8rem_6rem] @2xl/detail:items-center hover:bg-board-head focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary"
                                    wire:key="plan-target-{{ $target->id }}"
                                >
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm font-semibold">{{ $target->indicator->name }}</span>
                                        <span class="block truncate type-meta text-secondary-medium dark:text-ink-muted">
                                            @if ($target->project === null)
                                                {{ __('common.organisation_wide') }}
                                            @else
                                                {{ $target->project->name }} · {{ $target->project->city?->name ?? __('common.unknown_city') }}
                                            @endif
                                        </span>
                                    </span>

                                    <span class="mt-1 @2xl/detail:mt-0"><x-ui.enum-badge :case="$target->period" /></span>

                                    <span class="text-sm font-semibold tabular-nums @2xl/detail:text-end">{{ number_format((float) $target->target, 2) }}</span>

                                    <span class="@2xl/detail:text-end">
                                        <span
                                            @class([
                                                'inline-flex min-w-6 items-center justify-center gap-1 rounded-md border px-1.5 py-0.5 text-[11px] font-semibold tabular-nums',
                                                'border-status-good text-status-good' => $target->reports_count > 0,
                                                'border-secondary-light text-secondary-medium dark:text-ink-muted' => $target->reports_count === 0,
                                            ])
                                            title="{{ trans_choice('common.count_report', $target->reports_count, ['count' => $target->reports_count]) }}"
                                        >
                                            <flux:icon.document-text variant="micro" class="size-3" aria-hidden="true" />
                                            {{ $target->reports_count }}
                                        </span>
                                    </span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-ui.panel>

    {{-- Recent activity is capped to the Connections card's own natural height and scrolls the rest, so neither card stretches to fill space the other doesn't need. --}}
    <div
        class="grid gap-8 @4xl/detail:grid-cols-2 @4xl/detail:items-start"
        x-data="{ matchHeight: null }"
        x-init="
            const target = $refs.connectionsCard;
            const sync = () => { matchHeight = target.offsetHeight; };
            sync();
            new ResizeObserver(sync).observe(target);
        "
    >
        {{-- Connections: the program, strategy, manager and projects this plan ties together. --}}
        <x-ui.panel x-ref="connectionsCard" :title="__('annual_plans.connections')" :subtitle="__('annual_plans.connections_hint')">
            <ul class="divide-y divide-board-line">
                <li class="flex items-center gap-3 p-3.5">
                    <span aria-hidden="true" class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-secondary-soft text-secondary-dark dark:bg-surface-2 dark:text-ink">
                        <flux:icon.briefcase variant="micro" class="size-4" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block type-meta text-secondary-medium dark:text-ink-muted">{{ __('common.program') }}</span>
                        @if (Route::has('programs.show'))
                            <a href="{{ route('programs.show', $plan->program) }}" wire:navigate class="block truncate text-sm font-semibold hover:underline">{{ $plan->program->name }}</a>
                        @else
                            <span class="block truncate text-sm font-semibold">{{ $plan->program->name }}</span>
                        @endif
                    </span>
                </li>

                <li class="flex items-center gap-3 p-3.5">
                    <span aria-hidden="true" class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-secondary-soft text-secondary-dark dark:bg-surface-2 dark:text-ink">
                        <flux:icon.map variant="micro" class="size-4" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block type-meta text-secondary-medium dark:text-ink-muted">{{ __('common.strategy') }}</span>
                        @if ($plan->program->strategy === null)
                            <span class="block truncate text-sm font-semibold text-secondary-light">{{ __('common.strategy_not_assigned') }}</span>
                        @elseif (Route::has('strategies.show'))
                            <a href="{{ route('strategies.show', $plan->program->strategy) }}" wire:navigate class="block truncate text-sm font-semibold hover:underline">{{ $plan->program->strategy->name }}</a>
                        @else
                            <span class="block truncate text-sm font-semibold">{{ $plan->program->strategy->name }}</span>
                        @endif
                    </span>
                </li>

                <li class="flex items-center gap-3 p-3.5">
                    <span aria-hidden="true" class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-secondary-soft text-secondary-dark dark:bg-surface-2 dark:text-ink">
                        <flux:icon.user variant="micro" class="size-4" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block type-meta text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.assigned_manager') }}</span>
                        <x-ui.employee-chip :employee="$plan->manager" compact />
                    </span>
                </li>

                @if ($linkedProjects->isEmpty())
                    <li class="p-3.5">
                        <span class="block type-meta text-secondary-medium dark:text-ink-muted">{{ __('common.projects') }}</span>
                        <p class="mt-1 type-meta text-secondary-light">{{ __('annual_plans.no_projects_attributed_yet') }}</p>
                    </li>
                @else
                    @foreach ($linkedProjects as $project)
                        <li class="flex items-center gap-3 p-3.5" wire:key="connection-project-{{ $project->id }}">
                            <span aria-hidden="true" class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-secondary-soft text-secondary-dark dark:bg-surface-2 dark:text-ink">
                                <flux:icon.map-pin variant="micro" class="size-4" />
                            </span>
                            <span class="min-w-0 flex-1">
                                @if ($loop->first)
                                    <span class="block type-meta text-secondary-medium dark:text-ink-muted">{{ __('common.projects') }}</span>
                                @endif
                                <a href="{{ route('projects.show', $project) }}" wire:navigate class="block truncate text-sm font-semibold hover:underline">{{ $project->name }}</a>
                                <span class="block truncate type-meta text-secondary-medium dark:text-ink-muted">{{ $project->city?->name ?? __('common.unknown_city') }}</span>
                            </span>
                        </li>
                    @endforeach
                @endif
            </ul>
        </x-ui.panel>

        {{-- Recent activity: the latest reports landing against this plan. Height-locked to the Connections card via matchHeight; the list scrolls internally past that point. --}}
        <x-ui.panel
            :title="__('annual_plans.recent_activity')"
            :subtitle="__('annual_plans.recent_activity_hint')"
            class="flex flex-col overflow-hidden"
            x-bind:style="matchHeight ? { height: matchHeight + 'px' } : {}"
        >
            @if ($recentReports->isEmpty())
                <x-ui.empty-state icon="clock" :heading="__('annual_plans.no_activity_yet')" :body="__('annual_plans.reports_submitted_against_this_plan_will_appear_here')" />
            @else
                <ol class="min-h-0 flex-1 divide-y divide-board-line overflow-y-auto">
                    @foreach ($recentReports as $report)
                        @php
                            $indicator = $report->planIndicator->indicator;
                        @endphp
                        <li wire:key="activity-{{ $report->id }}">
                            <a
                                @if (Route::has('reports.show')) href="{{ route('reports.show', $report) }}" wire:navigate @endif
                                class="flex items-start gap-3 p-3.5 transition-colors hover:bg-board-head focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary"
                            >
                                <span
                                    aria-hidden="true"
                                    @class([
                                        'mt-1.5 size-2 shrink-0 rounded-full',
                                        'bg-status-good' => $report->status === \App\Enums\ReportStatus::Approved,
                                        'bg-status-warn' => $report->status === \App\Enums\ReportStatus::Reviewed,
                                        'bg-secondary-medium dark:bg-ink-faint' => $report->status === \App\Enums\ReportStatus::Draft,
                                    ])
                                ></span>

                                <span class="min-w-0 flex-1">
                                    <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <span class="truncate text-sm font-semibold">{{ $indicator->name }}</span>
                                        <x-ui.enum-badge :case="$report->status" />
                                    </span>
                                    <span class="mt-0.5 block type-meta text-secondary-medium dark:text-ink-muted">
                                        {{ $report->planIndicator->project?->name ?? __('common.organisation_wide') }}
                                        · <span class="font-semibold tabular-nums text-secondary-dark dark:text-ink">{{ number_format((float) $report->value, 2) }}</span>
                                    </span>
                                    <span class="mt-0.5 block type-meta text-secondary-light">{{ $report->created_at?->translatedFormat(__('formats.date')) }}</span>
                                </span>

                                <flux:icon.chevron-right variant="micro" class="mt-1 size-3.5 shrink-0 text-secondary-light rtl:-scale-x-100" aria-hidden="true" />
                            </a>
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-ui.panel>
    </div>

    <x-ui.modal
        wire:model="showPlanModal"
        size="md"
        :title="__('annual_plans.edit_annual_plan')"
        :description="$plan->program->name"
        submit="savePlan"
        close="closePlanModal"
        required-note
    >
        @if ($showPlanModal)
            <div class="grid grid-cols-2 gap-x-4 gap-y-4">
                <x-ui.form-select wire:model="planYear" :label="__('annual_plans.fiscal_year')" :placeholder="__('annual_plans.select_a_fiscal_year')" required autofocus>
                    @foreach ($this->yearFormOptions as $yearFormOption)
                        <option value="{{ $yearFormOption }}">{{ $yearFormOption }}</option>
                    @endforeach
                </x-ui.form-select>

                <x-ui.form-select wire:model="planManagerId" :label="__('annual_plans.assigned_manager')" :placeholder="__('annual_plans.select_a_manager')" required employees />
            </div>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-annual-plan-button">{{ __('common.save_changes') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    <x-ui.confirm-modal
        wire:model="confirmingDelete"
        :title="__('annual_plans.delete_this_annual_plan')"
        :message="$summary['targets'] > 0
            ? __('annual_plans.deleting_the_plan_also_removes_its_targets_and_their_reports', [
                'program' => $plan->program->name,
                'year' => $plan->year,
                'count' => $summary['targets'],
            ])
            : __('annual_plans.this_plan_has_no_targets_committed_in_it', [
                'program' => $plan->program->name,
                'year' => $plan->year,
            ])"
        confirm="deletePlan"
        :confirm-label="__('annual_plans.delete_annual_plan')"
        confirm-test="confirm-delete-annual-plan"
    />
</section>
