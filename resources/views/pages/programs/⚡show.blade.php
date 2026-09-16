<?php

use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Models\Milestone;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Report;
use App\Models\Result;
use App\Models\Risk;
use App\Models\AnnualPlan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.program')] class extends Component {
    use WithPagination;

    public int $programId;

    #[Url(except: 'projects')]
    public string $tab = 'projects';

    #[Url(except: '')]
    public string $projectSearch = '';

    #[Url(except: '')]
    public string $projectStatus = '';

    public function mount(Program $program): void
    {
        $this->programId = $program->id;

        if (ProjectStatus::tryFrom($this->projectStatus) === null) {
            $this->projectStatus = '';
        }
    }

    public function updatedProjectSearch(): void
    {
        $this->resetPage('projectsPage');
    }

    public function updatedProjectStatus(): void
    {
        if (ProjectStatus::tryFrom($this->projectStatus) === null) {
            $this->projectStatus = '';
        }

        $this->resetPage('projectsPage');
    }

    /**
     * Narrow the delivery timeline to one project status, or clear the filter
     * with an empty string. Unknown values clear the filter.
     */
    public function filterProjectsByStatus(string $status): void
    {
        $this->projectStatus = ProjectStatus::tryFrom($status)?->value ?? '';

        $this->resetPage('projectsPage');
    }

    #[Computed]
    public function program(): Program
    {
        return Program::query()
            ->select(['id', 'name', 'strategy_id', 'manager_id', 'updated_at'])
            ->selectSub(
                Risk::query()
                    ->selectRaw('count(*)')
                    ->where('happened', false)
                    ->whereIn('project_id', Project::query()
                        ->select('id')
                        ->whereColumn('program_id', 'programs.id')),
                'open_risks_count',
            )
            ->with([
                'strategy:id,name,start_year,end_year',
                'manager:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
            ])
            ->withCount(['projects', 'annualPlans', 'results'])
            ->findOrFail($this->programId);
    }

    /**
     * The page of projects on the delivery timeline, each with its weighted
     * milestone progress and the dated milestones plotted along its bar.
     *
     * @return LengthAwarePaginator<int, Project>
     */
    #[Computed]
    public function projects(): LengthAwarePaginator
    {
        $status = ProjectStatus::tryFrom($this->projectStatus);

        return Project::query()
            ->select(['id', 'name', 'status', 'start_date', 'end_date', 'city_id', 'program_id', 'user_id'])
            ->where('program_id', $this->programId)
            ->when($this->projectSearch !== '', fn ($query) => $query->whereRaw(
                "name like ? escape '!'",
                ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $this->projectSearch).'%'],
            ))
            ->when($status !== null, fn ($query) => $query->where('status', $status->value))
            ->with([
                'city:id,name_en,name_ar',
                'user:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
                'milestones' => fn ($query) => $query
                    ->select(['id', 'project_id', 'name', 'planned_date', 'status'])
                    ->where('status', '!=', MilestoneStatus::Cancelled->value)
                    ->reorder()
                    ->orderBy('planned_date'),
            ])
            ->withCount([
                'risks',
                'risks as materialised_risks_count' => fn ($query) => $query->where('happened', true),
            ])
            ->withSum(['milestones as weight_total' => fn ($query) => $query->where('status', '!=', MilestoneStatus::Cancelled->value)], 'weight_percentage')
            ->withSum(['milestones as weight_reached' => fn ($query) => $query->where('status', MilestoneStatus::Reached->value)], 'weight_percentage')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15, pageName: 'projectsPage');
    }

    /**
     * The date range every project bar is plotted against: the program's
     * project dates, today, and each project's milestone dates.
     *
     * A milestone only stretches the range when it falls within a year of its
     * own project's dates, so one mistyped date cannot squash every bar. Such
     * milestones are still drawn, pinned to the edge and flagged as outside
     * the delivery window.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}|null
     */
    #[Computed]
    public function deliveryWindow(): ?array
    {
        $projects = Project::query()
            ->select(['id', 'start_date', 'end_date'])
            ->where('program_id', $this->programId)
            ->get()
            ->keyBy('id');

        $milestoneBounds = Milestone::query()
            ->whereIn('project_id', $projects->keys())
            ->where('status', '!=', MilestoneStatus::Cancelled->value)
            ->groupBy('project_id')
            ->selectRaw('project_id, min(planned_date) as first_planned, max(planned_date) as last_planned')
            ->toBase()
            ->get();

        $dates = $projects
            ->flatMap(fn (Project $project): array => [$project->start_date, $project->end_date])
            ->filter()
            ->map(fn ($date): CarbonImmutable => CarbonImmutable::parse($date));

        foreach ($milestoneBounds as $bounds) {
            $project = $projects->get($bounds->project_id);
            $earliest = $project?->start_date ?? $project?->end_date;
            $latest = $project?->end_date ?? $project?->start_date;

            foreach ([$bounds->first_planned, $bounds->last_planned] as $planned) {
                $plannedDate = CarbonImmutable::parse($planned);

                if ($earliest === null || $plannedDate->betweenIncluded(CarbonImmutable::parse($earliest)->subYear(), CarbonImmutable::parse($latest)->addYear())) {
                    $dates->push($plannedDate);
                }
            }
        }

        if ($dates->isEmpty()) {
            return null;
        }

        $dates->push(CarbonImmutable::today());

        return [
            'start' => $dates->min()->startOfMonth(),
            'end' => $dates->max()->endOfMonth(),
        ];
    }

    /** @return Collection<int, AnnualPlan> */
    #[Computed]
    public function annualPlans(): Collection
    {
        return AnnualPlan::query()
            ->select(['id', 'program_id', 'manager_id', 'year'])
            ->where('program_id', $this->programId)
            ->with(['manager:id,first_name_en,last_name_en,first_name_ar,last_name_ar'])
            ->withCount('planIndicators')
            ->orderByDesc('year')
            ->get();
    }

    /**
     * One slot per fiscal year across the strategy window and every planned
     * year, each carrying its plan (or null for a gap) and the plan's target
     * and reporting totals.
     *
     * Totals come from two grouped reads over all of the program's plans, so
     * the slot count never adds queries.
     *
     * @return array<int, array{year: int, plan: AnnualPlan|null, targets: int, attributed: int, levels: array<string, int>, reports: int, approved: int}>
     */
    #[Computed]
    public function planTimeline(): array
    {
        $plans = $this->annualPlans->keyBy('year');
        $planIds = $this->annualPlans->pluck('id');

        $targetTotals = PlanIndicator::query()
            ->where('plan_indicators.source_type', 'annual_plan')
            ->whereIn('plan_indicators.source_id', $planIds)
            ->leftJoin('indicators', 'indicators.id', '=', 'plan_indicators.indicator_id')
            ->leftJoin('results', 'results.id', '=', 'indicators.result_id')
            ->groupBy('plan_indicators.source_id')
            ->selectRaw('plan_indicators.source_id as plan_id, count(*) as targets')
            ->selectRaw('sum(case when plan_indicators.project_id is not null then 1 else 0 end) as attributed')
            ->selectRaw('sum(case when results.type = ? then 1 else 0 end) as impact', [ResultType::Impact->value])
            ->selectRaw('sum(case when results.type = ? then 1 else 0 end) as outcome', [ResultType::Outcome->value])
            ->selectRaw('sum(case when results.type = ? then 1 else 0 end) as output', [ResultType::Output->value])
            ->toBase()
            ->get()
            ->keyBy('plan_id');

        $reportTotals = Report::query()
            ->join('plan_indicators', 'plan_indicators.id', '=', 'reports.plan_indicator_id')
            ->where('plan_indicators.source_type', 'annual_plan')
            ->whereIn('plan_indicators.source_id', $planIds)
            ->groupBy('plan_indicators.source_id')
            ->selectRaw('plan_indicators.source_id as plan_id, count(*) as reports')
            ->selectRaw('sum(case when reports.status = ? then 1 else 0 end) as approved', [ReportStatus::Approved->value])
            ->toBase()
            ->get()
            ->keyBy('plan_id');

        $years = $plans->keys()->all();
        $strategy = $this->program->strategy;

        if ($strategy !== null && $strategy->start_year !== null && $strategy->end_year !== null && $strategy->end_year - $strategy->start_year < 15) {
            $years = [...$years, ...range((int) $strategy->start_year, (int) $strategy->end_year)];
        }

        if ($years === []) {
            return [];
        }

        return collect(range(min($years), max($years)))
            ->map(function (int $year) use ($plans, $targetTotals, $reportTotals): array {
                $plan = $plans->get($year);
                $targets = $plan === null ? null : $targetTotals->get($plan->id);
                $reports = $plan === null ? null : $reportTotals->get($plan->id);

                return [
                    'year' => $year,
                    'plan' => $plan,
                    'targets' => (int) ($targets->targets ?? 0),
                    'attributed' => (int) ($targets->attributed ?? 0),
                    'levels' => [
                        ResultType::Impact->value => (int) ($targets->impact ?? 0),
                        ResultType::Outcome->value => (int) ($targets->outcome ?? 0),
                        ResultType::Output->value => (int) ($targets->output ?? 0),
                    ],
                    'reports' => (int) ($reports->reports ?? 0),
                    'approved' => (int) ($reports->approved ?? 0),
                ];
            })
            ->all();
    }

    /**
     * The program's logframe as root results with their subtrees attached.
     *
     * Loads every statement in one query with its indicator count, then wires
     * childrenRecursive in memory so every level carries the count.
     *
     * @return Collection<int, Result>
     */
    #[Computed]
    public function resultRoots(): Collection
    {
        $results = Result::query()
            ->select(['id', 'statement', 'parent_id', 'program_id', 'type', 'checkout_period'])
            ->where('program_id', $this->programId)
            ->withCount('indicators')
            ->orderBy('id')
            ->get();

        $childrenByParent = $results->groupBy('parent_id');

        foreach ($results as $result) {
            $result->setRelation('childrenRecursive', new Collection($childrenByParent->get($result->id, [])));
        }

        return $results
            ->filter(fn (Result $result): bool => $result->parent_id === null || ! $results->contains('id', $result->parent_id))
            ->values();
    }

    /**
     * Statement and indicator totals across the program's logframe.
     *
     * @return array{levels: array<string, int>, indicators: int, unmeasured: int}
     */
    #[Computed]
    public function resultSummary(): array
    {
        $all = $this->resultRoots->flatMap(fn (Result $root) => collect([$root])->merge($root->descendants()));

        return [
            'levels' => collect(ResultType::cases())
                ->mapWithKeys(fn (ResultType $type): array => [$type->value => $all->where('type', $type)->count()])
                ->all(),
            'indicators' => (int) $all->sum('indicators_count'),
            'unmeasured' => $all->where('indicators_count', 0)->count(),
        ];
    }

    /** @return array{percentage: float|null, reached: float, total: float} */
    #[Computed]
    public function milestoneProgress(): array
    {
        $weights = Milestone::query()
            ->whereIn('project_id', Project::query()->select('id')->where('program_id', $this->programId))
            ->where('status', '!=', MilestoneStatus::Cancelled->value)
            ->selectRaw(
                'coalesce(sum(case when status = ? then weight_percentage else 0 end), 0) as reached_weight, coalesce(sum(weight_percentage), 0) as total_weight',
                [MilestoneStatus::Reached->value],
            )
            ->toBase()
            ->first();

        $reached = (float) ($weights->reached_weight ?? 0);
        $total = (float) ($weights->total_weight ?? 0);

        return [
            'percentage' => $total > 0 ? round(($reached / $total) * 100, 1) : null,
            'reached' => $reached,
            'total' => $total,
        ];
    }

    /** @return array<int, array{label: string, count: int, tone: string}> */
    #[Computed]
    public function projectStatusSegments(): array
    {
        $counts = Project::query()
            ->where('program_id', $this->programId)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return collect(ProjectStatus::cases())
            ->map(fn (ProjectStatus $status): array => [
                'label' => $status->label(),
                'count' => (int) ($counts[$status->value] ?? 0),
                'tone' => match ($status) {
                    ProjectStatus::Active => 'bg-status-good',
                    ProjectStatus::Submitted, ProjectStatus::Approved => 'bg-status-warn',
                    default => 'bg-secondary-light',
                },
            ])
            ->all();
    }

    /** @return array<int, array{key: string, label: string, count: int, icon: string}> */
    #[Computed]
    public function tabs(): array
    {
        return [
            ['key' => 'projects', 'label' => __('common.projects'), 'count' => $this->program->projects_count, 'icon' => 'rectangle-stack'],
            ['key' => 'plans', 'label' => __('programs.annual_plans'), 'count' => $this->program->annual_plans_count, 'icon' => 'calendar-days'],
            ['key' => 'results', 'label' => __('programs.results'), 'count' => $this->program->results_count, 'icon' => 'chart-bar'],
        ];
    }
};
?>

<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-6 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white">
    <header class="border-b border-secondary-soft @4xl/detail:border-b-0 dark:border-secondary-medium">
        <nav aria-label="{{ __('ui.breadcrumb') }}" class="flex flex-wrap items-center gap-1.5 text-xs text-secondary-medium dark:text-secondary-soft">
            <a href="{{ route('programs.index') }}" wire:navigate class="rounded font-medium hover:text-secondary-dark hover:underline dark:hover:text-white">{{ __('common.programs') }}</a>
            <span aria-hidden="true" class="text-secondary-light">/</span>
            <span aria-current="page" class="font-semibold text-secondary-dark dark:text-white">{{ $this->program->name }}</span>
        </nav>

        <div class="mt-5 flex flex-col gap-7 @4xl/detail:flex-row @4xl/detail:items-stretch @4xl/detail:gap-10">
            <div class="min-w-0 @4xl/detail:flex-1 @4xl/detail:border-b @4xl/detail:border-secondary-soft dark:border-secondary-medium">
                <div class="flex items-start gap-3 @4xl/detail:min-h-[8.3125rem]">
                    <div class="flex size-11 shrink-0 items-center justify-center rounded-md bg-primary text-white"><flux:icon name="briefcase" class="size-5" /></div>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold tracking-[0.12em] text-secondary-medium uppercase dark:text-secondary-soft">{{ __('common.program') }}</p>
                        <h1 class="mt-1 text-3xl font-bold tracking-tight text-secondary-dark dark:text-white @3xl/detail:text-4xl">{{ $this->program->name }}</h1>
                    </div>
                </div>

                <dl class="grid gap-5 border-t border-secondary-soft pt-4 @3xl/detail:grid-cols-3 dark:border-secondary-medium">
                    <div class="min-w-0">
                        <dt class="text-xs font-semibold tracking-[0.1em] text-secondary-medium uppercase dark:text-secondary-soft">{{ __('programs.linked_strategy') }}</dt>
                        <dd class="mt-1.5 truncate text-sm font-semibold">
                            @if ($this->program->strategy)
                                <a href="{{ route('strategies.show', $this->program->strategy) }}" wire:navigate class="hover:text-primary hover:underline">{{ $this->program->strategy->name }}</a>
                            @else
                                <span class="font-normal text-secondary-medium dark:text-secondary-soft">{{ __('common.not_assigned') }}</span>
                            @endif
                        </dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="text-xs font-semibold tracking-[0.1em] text-secondary-medium uppercase dark:text-secondary-soft">{{ __('programs.direct_manager') }}</dt>
                        <dd class="mt-1.5 truncate text-sm font-semibold">
                            {{ $this->program->manager?->name ?? __('common.not_assigned') }}
                            @if ($this->program->manager)
                                <span class="sr-only" dir="auto" lang="{{ \App\Enums\Locale::current()->other()->value }}">{{ $this->program->manager->secondary_name }}</span>
                            @endif
                        </dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="text-xs font-semibold tracking-[0.1em] text-secondary-medium uppercase dark:text-secondary-soft">{{ __('programs.updated') }}</dt>
                        <dd class="mt-1.5 truncate text-sm font-semibold">{{ $this->program->updated_at?->translatedFormat(__('formats.date')) ?? '—' }}</dd>
                    </div>
                </dl>
            </div>

            <aside class="overflow-hidden rounded-lg border border-secondary-soft bg-white text-secondary-dark @4xl/detail:w-[22rem] @4xl/detail:shrink-0 dark:border-secondary-medium dark:bg-secondary-dark dark:text-white" aria-label="{{ __('programs.delivery_health') }}">
                <div class="min-h-32 p-5">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <p class="text-xs font-semibold tracking-[0.1em] text-secondary-medium uppercase dark:text-secondary-soft">{{ __('programs.delivery_health') }}</p>
                            <p class="mt-1 text-sm font-semibold">{{ __('programs.milestone_progress') }}</p>
                        </div>
                        <span class="shrink-0 text-3xl font-bold tracking-tight text-primary tabular-nums">{{ $this->milestoneProgress['percentage'] === null ? '—' : number_format($this->milestoneProgress['percentage'], 0).'%' }}</span>
                    </div>
                    <p class="mt-3 text-xs text-secondary-medium tabular-nums dark:text-secondary-soft">{{ $this->milestoneProgress['percentage'] === null ? __('programs.no_weighted_milestones') : __('programs.weighted_progress_of_total', ['reached' => number_format($this->milestoneProgress['reached'], 0), 'total' => number_format($this->milestoneProgress['total'], 0)]) }}</p>
                    <div class="mt-4 h-2 overflow-hidden rounded-full bg-secondary-soft dark:bg-secondary-medium" role="progressbar" aria-valuemin="0" aria-valuemax="100" @if ($this->milestoneProgress['percentage'] !== null) aria-valuenow="{{ $this->milestoneProgress['percentage'] }}" @endif aria-label="{{ __('programs.milestone_progress') }}">
                        <div class="h-full rounded-full bg-primary motion-reduce:transition-none" style="width: {{ $this->milestoneProgress['percentage'] ?? 0 }}%"></div>
                    </div>
                </div>
                <div class="border-t border-secondary-soft px-5 py-4 dark:border-secondary-medium">
                    <p class="text-xs font-semibold tracking-[0.1em] text-secondary-medium uppercase dark:text-secondary-soft">{{ __('programs.projects_by_status') }}</p>
                    <div class="mt-3 flex h-2 overflow-hidden rounded-full bg-secondary-soft dark:bg-secondary-medium" role="img" aria-label="{{ __('programs.projects_by_status') }}">
                        @foreach ($this->projectStatusSegments as $segment)
                            @if ($segment['count'] > 0)<span class="{{ $segment['tone'] }}" style="flex: {{ $segment['count'] }}" title="{{ $segment['label'] }}: {{ $segment['count'] }}"></span>@endif
                        @endforeach
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($this->projectStatusSegments as $segment)
                            @if ($segment['count'] > 0)
                                <span class="inline-flex items-center gap-1.5 rounded-md border border-secondary-soft px-2 py-1 text-xs font-medium text-secondary-medium dark:border-secondary-medium dark:text-secondary-soft"><span class="size-1.5 rounded-full {{ $segment['tone'] }}"></span>{{ $segment['label'] }} <span class="font-semibold tabular-nums">{{ $segment['count'] }}</span></span>
                            @endif
                        @endforeach
                    </div>
                </div>
            </aside>
        </div>
    </header>

    <div class="flex flex-wrap items-center gap-1 border-b border-secondary-soft dark:border-secondary-medium" role="tablist" aria-label="{{ __('programs.connection_views') }}">
        @foreach ($this->tabs as $tabOption)
            @php $isActive = $tab === $tabOption['key']; @endphp
            <button type="button" role="tab" wire:key="program-tab-{{ $tabOption['key'] }}" wire:click="$set('tab', '{{ $tabOption['key'] }}')" aria-selected="{{ $isActive ? 'true' : 'false' }}" @class([
                'inline-flex min-h-11 items-center gap-1.5 border-b-2 px-3 text-sm font-semibold transition-colors',
                'border-primary text-secondary-dark dark:text-white' => $isActive,
                'border-transparent text-secondary-medium hover:text-secondary-dark dark:text-secondary-soft dark:hover:text-white' => ! $isActive,
            ])>
                <span>{{ $tabOption['label'] }}</span>
                <span class="inline-flex min-w-5 items-center justify-center rounded-md border border-secondary-light px-1 py-0.5 text-[11px] font-semibold tabular-nums text-secondary-medium dark:border-secondary-medium dark:text-secondary-soft">{{ $tabOption['count'] }}</span>
            </button>
        @endforeach
    </div>

    @if ($tab === 'projects')
        <x-ui.project-timeline
            :projects="$this->projects"
            :window="$this->deliveryWindow"
            :status-segments="$this->projectStatusSegments"
            :search="$projectSearch"
            :status="$projectStatus"
        />
    @elseif ($tab === 'plans')
        <x-ui.plan-years :slots="$this->planTimeline" :strategy="$this->program->strategy" />
    @else
        <x-ui.logframe :roots="$this->resultRoots" :summary="$this->resultSummary" />
    @endif
</section>
