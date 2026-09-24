<?php

use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\HrEmployee;
use App\Models\Milestone;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Report;
use App\Models\Result;
use App\Models\Risk;
use App\Models\AnnualPlan;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
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

    public bool $showAddTaskModal = false;

    public string $newTaskTitle = '';

    public string $newTaskStatus = 'todo';

    public string $newTaskPriority = 'medium';

    public string $newTaskDueDate = '';

    /** @var array<int, string> */
    public array $newTaskAssignedTo = [];

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
            ->select(['id', 'strategy_id', 'manager_id', 'updated_at'])
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
                    ->select(['id', 'project_id', 'name', 'planned_start_date', 'planned_end_date', 'actual_end_date', 'status'])
                    ->where('status', '!=', MilestoneStatus::Cancelled->value)
                    ->reorder()
                    ->orderBy('planned_start_date'),
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
            ->selectRaw('project_id, min(planned_start_date) as first_planned, max(planned_start_date) as last_planned')
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

    /**
     * Every project under this program, unpaginated and unfiltered — used to
     * name and group tasks on the Tasks tab, distinct from {@see projects()}
     * which is the paginated, search/status-filtered delivery timeline.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function allProjects(): Collection
    {
        return Project::query()
            ->select(['id', 'name'])
            ->where('program_id', $this->programId)
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * Tasks reachable from this program: raised straight against the
     * program, against one of its projects, or against a milestone belonging
     * to one of those projects.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function tasks(): Collection
    {
        $projectIds = $this->allProjects->modelKeys();
        $milestoneIds = Milestone::query()->whereIn('project_id', $projectIds)->pluck('id');

        return Task::query()
            ->select(['id', 'source_type', 'source_id', 'title', 'status', 'priority', 'due_date', 'progress_percentage'])
            ->with([
                'taskAssignees.employee:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
                'source' => fn (MorphTo $morphTo) => $morphTo->morphWith([Milestone::class => ['project:id']]),
            ])
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('source_type', 'program')->where('source_id', $this->programId))
                ->orWhere(fn (Builder $query) => $query->where('source_type', 'project')->whereIn('source_id', $projectIds))
                ->orWhere(fn (Builder $query) => $query->where('source_type', 'milestone')->whereIn('source_id', $milestoneIds)))
            ->orderByRaw('due_date is null, due_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * The project a task lands on, for grouping on the Tasks tab: a
     * project-sourced task names itself, a milestone-sourced task names its
     * milestone's project. A program-direct task has no project and groups
     * separately.
     */
    public function taskProjectId(Task $task): ?int
    {
        return match ($task->source_type) {
            'project' => $task->source_id,
            'milestone' => $task->source?->project_id,
            default => null,
        };
    }

    /** @return Collection<int, HrEmployee> */
    #[Computed]
    public function userOptions(): Collection
    {
        return HrEmployee::pickerOptions();
    }

    public function openAddTaskModal(): void
    {
        $this->resetValidation();
        $this->newTaskTitle = '';
        $this->newTaskStatus = TaskStatus::Todo->value;
        $this->newTaskPriority = TaskPriority::Medium->value;
        $this->newTaskDueDate = '';
        $this->newTaskAssignedTo = [];
        $this->showAddTaskModal = true;
    }

    public function closeAddTaskModal(): void
    {
        $this->showAddTaskModal = false;
    }

    /**
     * Raise a task straight against this program — the source is always this
     * program, never one of its projects or milestones: a task tied to a
     * project's or milestone's own delivery is added from that record's page
     * instead.
     */
    public function addTask(): void
    {
        $this->newTaskTitle = trim($this->newTaskTitle);

        $validated = $this->validate([
            'newTaskTitle' => ['required', 'string', 'max:255'],
            'newTaskStatus' => ['required', new Enum(TaskStatus::class)],
            'newTaskPriority' => ['required', new Enum(TaskPriority::class)],
            'newTaskDueDate' => ['nullable', 'date'],
            'newTaskAssignedTo' => ['required', 'array', 'min:1'],
            'newTaskAssignedTo.*' => ['integer', Rule::exists(HrEmployee::class, 'id')],
        ], [
            'newTaskTitle.required' => __('tasks.enter_a_task_title'),
            'newTaskAssignedTo.required' => __('tasks.assign_this_task_to_someone'),
            'newTaskAssignedTo.min' => __('tasks.assign_this_task_to_someone'),
            'newTaskAssignedTo.*.exists' => __('tasks.the_selected_assignee_is_no_longer_available'),
        ]);

        $programId = $this->programId;
        $statusCase = TaskStatus::from($validated['newTaskStatus']);
        $employeeIds = collect($validated['newTaskAssignedTo'])->map(fn ($id): int => (int) $id)->unique()->values();

        DB::transaction(function () use ($programId, $validated, $statusCase, $employeeIds): void {
            $task = Task::query()->create([
                'source_type' => 'program',
                'source_id' => $programId,
                'title' => $validated['newTaskTitle'],
                'status' => $statusCase,
                'priority' => $validated['newTaskPriority'],
                'due_date' => $validated['newTaskDueDate'] === '' ? null : $validated['newTaskDueDate'],
                'progress_percentage' => $statusCase === TaskStatus::Done ? 100 : 0,
                'completed_date' => $statusCase === TaskStatus::Done ? CarbonImmutable::today() : null,
                'created_by' => (int) auth()->id(),
            ]);

            foreach ($employeeIds as $employeeId) {
                $task->taskAssignees()->create(['employee_id' => $employeeId]);
            }
        });

        $this->showAddTaskModal = false;
        unset($this->tasks);

        Flux::toast(variant: 'success', text: __('tasks.task_created'));
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
            ['key' => 'tasks', 'label' => __('common.tasks'), 'count' => $this->tasks->count(), 'icon' => 'clipboard-document-list'],
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
                        <div class="mt-1 flex items-center gap-2">
                            <h1 class="text-3xl font-bold tracking-tight text-secondary-dark dark:text-white @3xl/detail:text-4xl">{{ $this->program->name }}</h1>
                            @if (app(\App\Notifications\UnreadSubjects::class)->has(auth()->user(), 'program', $this->program->id))
                                <span class="size-2.5 shrink-0 rounded-full bg-primary" data-unread-dot title="{{ __('notifications.has_unread') }}"><span class="sr-only">{{ __('notifications.has_unread') }}</span></span>
                            @endif
                        </div>
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
    @elseif ($tab === 'tasks')
        <x-ui.panel :title="__('common.tasks')" :count="$this->tasks->count()" :subtitle="__('programs.tasks_across_the_program_its_projects_and_their_milestones')">
            <x-slot:toolbar>
                <x-ui.button tone="primary" size="sm" icon="plus" wire:click="openAddTaskModal" data-test="add-program-task-button">{{ __('tasks.add_task') }}</x-ui.button>
            </x-slot:toolbar>

            @if ($this->tasks->isEmpty())
                <x-ui.empty-state icon="clipboard-document-list" :heading="__('common.no_tasks_yet')" :body="__('programs.tasks_for_this_program_will_appear_here')">
                    <x-slot:action>
                        <x-ui.button size="sm" tone="primary" icon="plus" wire:click="openAddTaskModal">{{ __('tasks.add_task') }}</x-ui.button>
                    </x-slot:action>
                </x-ui.empty-state>
            @else
                @php
                    $taskDone = $this->tasks->where('status', TaskStatus::Done)->count();
                    $taskTotal = $this->tasks->count();
                    $taskOverdue = $this->tasks->filter(fn (Task $task): bool => $task->status !== TaskStatus::Done && $task->due_date !== null && $task->due_date->lt(today()))->count();
                    $taskUnassigned = $this->tasks->filter(fn (Task $task): bool => $task->taskAssignees->isEmpty())->count();
                    $taskInkText = 'text-secondary-dark dark:text-white';
                    $taskMutedText = 'text-secondary-medium dark:text-secondary-soft';

                    $ordered = $this->tasks->sortBy([
                        fn (Task $a, Task $b): int => ($a->status === TaskStatus::Done) <=> ($b->status === TaskStatus::Done),
                        fn (Task $a, Task $b): int => ($a->due_date === null) <=> ($b->due_date === null),
                        fn (Task $a, Task $b): int => ($a->due_date?->getTimestamp() ?? 0) <=> ($b->due_date?->getTimestamp() ?? 0),
                        fn (Task $a, Task $b): int => $a->id <=> $b->id,
                    ]);
                    $taskGroups = $this->allProjects
                        ->map(fn (Project $project): array => [
                            'project' => $project,
                            'tasks' => $ordered->filter(fn (Task $task): bool => $this->taskProjectId($task) === $project->id)->values(),
                        ])
                        ->filter(fn (array $group): bool => $group['tasks']->isNotEmpty())
                        ->values();
                    $looseTasks = $ordered->filter(fn (Task $task): bool => $this->taskProjectId($task) === null)->values();
                @endphp

                <div class="grid gap-3 border-b border-secondary-soft p-3.5 @lg/detail:grid-cols-3 dark:border-secondary-medium">
                    <x-ui.stat-tile :label="__('programs.stat_tasks_done')" :value="$taskDone" :denominator="$taskTotal" />
                    <x-ui.stat-tile :label="__('tasks.overdue')" :value="$taskOverdue" :tone="$taskOverdue > 0 ? 'warn' : null" />
                    <x-ui.stat-tile :label="__('common.unassigned')" :value="$taskUnassigned" />
                </div>

                <div class="hidden grid-cols-[1.25rem_minmax(0,1fr)_11rem_6rem_7.5rem_8rem_7rem] gap-x-4 px-3.5 pt-4 @3xl/detail:grid" aria-hidden="true">
                    <span></span>
                    @foreach ([__('tasks.task'), __('tasks.assignee'), __('tasks.priority'), __('tasks.due'), __('tasks.progress'), __('common.status')] as $column)
                        <span @class(['type-label', $taskMutedText, 'text-end' => $loop->last])>{{ $column }}</span>
                    @endforeach
                </div>

                <div class="flex flex-col gap-8 px-3.5 pb-3.5 @6xl/detail:px-5 @6xl/detail:pb-5">
                    @foreach ($taskGroups as $group)
                        <section class="min-w-0" wire:key="program-task-group-{{ $group['project']->id }}">
                            <header class="border-b border-secondary-soft pt-4 pb-3 dark:border-secondary-medium">
                                <a href="{{ route('projects.show', $group['project']) }}" wire:navigate class="min-w-0 truncate text-base font-bold underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary {{ $taskInkText }}">{{ $group['project']->name }}</a>
                            </header>
                            <ul class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                                @foreach ($group['tasks'] as $task)
                                    @include('pages.programs._task-row', ['task' => $task, 'inkText' => $taskInkText, 'mutedText' => $taskMutedText])
                                @endforeach
                            </ul>
                        </section>
                    @endforeach

                    @if ($looseTasks->isNotEmpty())
                        <section class="min-w-0" data-loose-tasks>
                            <header class="border-b border-secondary-soft pt-4 pb-3 dark:border-secondary-medium">
                                <p class="text-base font-bold {{ $taskInkText }}">{{ __('programs.work_not_tied_to_a_project') }}</p>
                            </header>
                            <ul class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                                @foreach ($looseTasks as $task)
                                    @include('pages.programs._task-row', ['task' => $task, 'inkText' => $taskInkText, 'mutedText' => $taskMutedText])
                                @endforeach
                            </ul>
                        </section>
                    @endif
                </div>
            @endif
        </x-ui.panel>
    @elseif ($tab === 'plans')
        <x-ui.plan-years :slots="$this->planTimeline" :strategy="$this->program->strategy" />
    @else
        <x-ui.logframe :roots="$this->resultRoots" :summary="$this->resultSummary" />
    @endif

    <section aria-labelledby="record-activity-heading" class="grid gap-3" data-record-activity>
        <h2 id="record-activity-heading" class="text-base font-bold">{{ __('activity.title') }}</h2>
        <livewire:activity.timeline :subjects="['program' => [$this->program->id]]" :key="'program-activity-'.$this->program->id" />
    </section>

    <x-tasks.add-task-modal test-prefix="new-program-task" :user-options="$this->userOptions" />
</section>
