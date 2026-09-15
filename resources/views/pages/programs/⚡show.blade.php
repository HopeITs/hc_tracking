<?php

use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Models\Milestone;
use App\Models\Program;
use App\Models\Project;
use App\Models\Result;
use App\Models\Risk;
use App\Models\YearlyPlan;
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

    public function mount(Program $program): void
    {
        $this->programId = $program->id;
    }

    public function updatedProjectSearch(): void
    {
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
            ->withCount(['projects', 'yearlyPlans', 'results'])
            ->findOrFail($this->programId);
    }

    /** @return LengthAwarePaginator<int, Project> */
    #[Computed]
    public function projects(): LengthAwarePaginator
    {
        return Project::query()
            ->select(['id', 'name', 'status', 'start_date', 'end_date', 'city_id', 'program_id', 'user_id'])
            ->where('program_id', $this->programId)
            ->when($this->projectSearch !== '', fn ($query) => $query->where('name', 'like', '%'.$this->projectSearch.'%'))
            ->with([
                'city:id,name_en,name_ar',
                'user:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
            ])
            ->withCount([
                'risks',
                'milestones',
                'risks as materialised_risks_count' => fn ($query) => $query->where('happened', true),
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15, pageName: 'projectsPage');
    }

    /** @return Collection<int, YearlyPlan> */
    #[Computed]
    public function yearlyPlans(): Collection
    {
        return YearlyPlan::query()
            ->select(['id', 'program_id', 'manager_id', 'year'])
            ->where('program_id', $this->programId)
            ->with(['manager:id,first_name_en,last_name_en,first_name_ar,last_name_ar'])
            ->withCount('planIndicators')
            ->orderByDesc('year')
            ->get();
    }

    /** @return Collection<int, Result> */
    #[Computed]
    public function resultRoots(): Collection
    {
        return Result::query()
            ->select(['id', 'statement', 'parent_id', 'program_id', 'type', 'checkout_period'])
            ->where('program_id', $this->programId)
            ->roots()
            ->withCount('indicators')
            ->with('childrenRecursive')
            ->get();
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
            ['key' => 'plans', 'label' => __('programs.annual_plans'), 'count' => $this->program->yearly_plans_count, 'icon' => 'calendar-days'],
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
        <section class="overflow-hidden rounded-md border border-secondary-soft bg-white dark:border-secondary-medium dark:bg-secondary-dark" aria-labelledby="program-projects-heading">
            <div class="flex flex-col gap-3 border-b border-secondary-soft bg-secondary-soft/35 px-4 py-3 @3xl/detail:flex-row @3xl/detail:items-end dark:border-secondary-medium dark:bg-secondary-medium/30">
                <div class="@3xl/detail:me-auto"><p class="text-xs font-semibold tracking-[0.1em] text-secondary-medium uppercase dark:text-secondary-soft">{{ __('programs.delivery_projects') }}</p><h2 id="program-projects-heading" class="mt-1 text-lg font-bold">{{ __('common.projects') }}</h2></div>
                <label class="block @3xl/detail:w-80"><span class="mb-1 block text-xs font-semibold tracking-[0.1em] text-secondary-medium uppercase dark:text-secondary-soft">{{ __('programs.find_a_project') }}</span><div class="flex min-h-10 items-center gap-2 rounded-md border border-secondary-light bg-white px-3 dark:border-secondary-medium dark:bg-secondary-dark"><flux:icon name="magnifying-glass" class="size-4 shrink-0 text-secondary-medium dark:text-secondary-soft" /><input type="search" wire:model.live.debounce.300ms="projectSearch" placeholder="{{ __('programs.search_projects') }}" class="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm text-secondary-dark placeholder:text-secondary-light outline-none focus:outline-none focus:ring-0 dark:text-white" /></div></label>
            </div>
            @if ($this->projects->isEmpty())
                <x-ui.empty-state icon="rectangle-stack" :heading="$projectSearch === '' ? __('common.no_projects_yet') : __('programs.no_projects_match_search')" :body="$projectSearch === '' ? __('programs.projects_delivered_under_this_program_will_appear_here') : __('programs.adjust_your_project_search')" />
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-[52rem] w-full text-sm"><thead class="bg-secondary-soft/35 text-xs font-semibold tracking-[0.1em] text-secondary-medium uppercase dark:bg-secondary-medium/30 dark:text-secondary-soft"><tr><th scope="col" class="px-4 py-3 text-start">{{ __('common.projects') }}</th><th scope="col" class="px-4 py-3 text-start">{{ __('programs.delivery_status') }}</th><th scope="col" class="px-4 py-3 text-start">{{ __('common.city') }}</th><th scope="col" class="px-4 py-3 text-start">{{ __('programs.delivery_window') }}</th><th scope="col" class="px-4 py-3 text-center">{{ __('common.milestones') }}</th><th scope="col" class="px-4 py-3 text-center">{{ __('common.risks') }}</th><th scope="col" class="px-4 py-3 text-center">{{ __('programs.project_owner') }}</th></tr></thead>
                    <tbody class="divide-y divide-secondary-soft dark:divide-secondary-medium" wire:loading.class="opacity-60" wire:target="projectSearch, gotoPage, previousPage, nextPage">
                        @foreach ($this->projects as $project)
                            <tr wire:key="program-project-{{ $project->id }}" class="transition-colors hover:bg-secondary-soft/25 dark:hover:bg-secondary-medium/25"><td class="px-4 py-3"><a href="{{ route('projects.show', $project) }}" wire:navigate class="font-semibold hover:text-primary hover:underline">{{ $project->name }}</a></td><td class="px-4 py-3"><x-ui.enum-badge :case="$project->status" /></td><td class="px-4 py-3 text-secondary-medium dark:text-secondary-soft">{{ $project->city?->name ?? __('common.unknown_city') }}</td><td class="px-4 py-3 text-xs text-secondary-medium tabular-nums dark:text-secondary-soft">{{ $project->start_date?->translatedFormat(__('formats.date')) ?? '—' }} <span aria-hidden="true">–</span> {{ $project->end_date?->translatedFormat(__('formats.date')) ?? '—' }}</td><td class="px-4 py-3 text-center font-semibold tabular-nums">{{ $project->milestones_count }}</td><td class="px-4 py-3 text-center font-semibold tabular-nums {{ $project->materialised_risks_count > 0 ? 'text-primary' : '' }}">{{ $project->risks_count }}</td><td class="px-4 py-3 text-center text-secondary-medium dark:text-secondary-soft">{{ $project->user?->name ?? __('common.not_assigned') }}</td></tr>
                        @endforeach
                    </tbody></table>
                </div>
                <div class="border-t border-secondary-soft px-4 py-3 dark:border-secondary-medium"><flux:pagination :paginator="$this->projects" class="text-secondary-medium! dark:text-secondary-soft!" scroll-to="#program-projects-heading" /></div>
            @endif
        </section>
    @elseif ($tab === 'plans')
        <section class="overflow-hidden rounded-md border border-secondary-soft bg-white dark:border-secondary-medium dark:bg-secondary-dark" aria-labelledby="program-plans-heading">
            <div class="border-b border-secondary-soft bg-secondary-soft/35 px-4 py-3 dark:border-secondary-medium dark:bg-secondary-medium/30"><p class="text-xs font-semibold tracking-[0.1em] text-secondary-medium uppercase dark:text-secondary-soft">{{ __('programs.plan_years') }}</p><h2 id="program-plans-heading" class="mt-1 text-lg font-bold">{{ __('programs.annual_plans') }}</h2></div>
            @if ($this->yearlyPlans->isEmpty())
                <x-ui.empty-state icon="calendar-days" :heading="__('common.no_annual_plans_yet')" :body="__('programs.operational_plans_written_for_this_program_will_appear_here')" />
            @else
                @php $maxTargets = max(1, $this->yearlyPlans->max('plan_indicators_count')); @endphp
                <div class="grid gap-px bg-secondary-soft p-4 @2xl/detail:grid-cols-2 @5xl/detail:grid-cols-4 dark:bg-secondary-medium">
                    @foreach ($this->yearlyPlans as $plan)
                        @php $ratio = $plan->plan_indicators_count / $maxTargets; $toneClass = match (true) { $plan->plan_indicators_count === 0 => 'border-dashed border-secondary-light bg-white text-secondary-medium dark:border-secondary-medium dark:bg-secondary-dark dark:text-secondary-soft', $ratio <= 0.25 => 'border-secondary-soft bg-secondary-soft/50 text-secondary-dark dark:border-secondary-medium dark:bg-secondary-medium/40 dark:text-white', $ratio <= 0.5 => 'border-secondary-light bg-secondary-light/60 text-secondary-dark dark:border-secondary-medium dark:bg-secondary-medium/70 dark:text-white', default => 'border-secondary-dark bg-secondary-dark text-white dark:border-white', }; @endphp
                        <a href="{{ route('yearly-plans.show', $plan) }}" wire:navigate class="group min-h-36 border p-4 transition-colors hover:border-primary focus-visible:outline-2 focus-visible:outline-primary focus-visible:outline-offset-2 {{ $toneClass }}" wire:key="program-plan-{{ $plan->id }}"><p class="text-xs font-semibold tracking-[0.1em] uppercase opacity-75">{{ __('common.fy_year', ['year' => $plan->year]) }}</p><p class="mt-4 text-3xl font-bold tabular-nums">{{ $plan->plan_indicators_count }}</p><p class="mt-1 text-xs font-semibold opacity-75">{{ trans_choice('common.count_target', $plan->plan_indicators_count, ['count' => $plan->plan_indicators_count]) }}</p><p class="mt-4 truncate text-xs opacity-80">{{ $plan->manager?->name ?? __('common.not_assigned') }}</p></a>
                    @endforeach
                </div>
            @endif
        </section>
    @else
        <section class="overflow-hidden rounded-md border border-secondary-soft bg-white dark:border-secondary-medium dark:bg-secondary-dark" aria-labelledby="program-results-heading">
            <div class="border-b border-secondary-soft bg-secondary-soft/35 px-4 py-3 dark:border-secondary-medium dark:bg-secondary-medium/30"><p class="text-xs font-semibold tracking-[0.1em] text-secondary-medium uppercase dark:text-secondary-soft">{{ __('programs.results_framework') }}</p><h2 id="program-results-heading" class="mt-1 text-lg font-bold">{{ __('programs.results_logframe') }}</h2><p class="mt-1 text-sm text-secondary-medium dark:text-secondary-soft">{{ __('common.impact_outcome_and_output_statements_nested_as_recorded') }}</p></div>
            @if ($this->resultRoots->isEmpty())
                <x-ui.empty-state icon="chart-bar" :heading="__('common.no_results_yet')" :body="__('programs.logframe_statements_assigned_to_this_program_will_appear_her')" />
            @else
                <x-ui.tree-rows :nodes="$this->resultRoots" :label="fn (\App\Models\Result $result): string => $result->statement" :badge="fn (\App\Models\Result $result) => $result->type" />
            @endif
        </section>
    @endif
</section>
