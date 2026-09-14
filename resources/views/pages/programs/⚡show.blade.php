<?php

use App\Models\Program;
use App\Models\Project;
use App\Models\Result;
use App\Models\YearlyPlan;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Program')] class extends Component {
    public int $programId;

    #[Url(except: 'projects')]
    public string $tab = 'projects';

    public function mount(Program $program): void
    {
        $this->programId = $program->id;
    }

    #[Computed]
    public function program(): Program
    {
        return Program::query()
            ->select(['id', 'name', 'strategy_id', 'manager_id', 'updated_at'])
            ->with([
                'strategy:id,name,start_year,end_year',
                'manager:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
            ])
            ->withCount(['projects', 'yearlyPlans', 'results'])
            ->findOrFail($this->programId);
    }

    /** @return Collection<int, Project> */
    #[Computed]
    public function projects(): Collection
    {
        return Project::query()
            ->select(['id', 'name', 'city_id', 'program_id', 'user_id'])
            ->where('program_id', $this->programId)
            ->with(['city:id,name_en', 'user:id,first_name_en,last_name_en,first_name_ar,last_name_ar'])
            ->withCount(['risks', 'milestones'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();
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

    /**
     * The program's logframe roots, with their whole subtree loaded.
     *
     * childrenRecursive costs one query per level and the logframe is three
     * levels deep at most, so the tree renders without touching the database
     * again per node.
     *
     * @return Collection<int, Result>
     */
    #[Computed]
    public function resultRoots(): Collection
    {
        return Result::query()
            ->select(['id', 'statement', 'parent_id', 'program_id', 'type', 'checkout_period'])
            ->where('program_id', $this->programId)
            ->roots()
            ->with('childrenRecursive')
            ->get();
    }

    /** @return array<int, array{key: string, label: string, count: int}> */
    #[Computed]
    public function tabs(): array
    {
        return [
            ['key' => 'projects', 'label' => __('Projects'), 'count' => $this->program->projects_count],
            ['key' => 'plans', 'label' => __('Annual plans'), 'count' => $this->program->yearly_plans_count],
            ['key' => 'results', 'label' => __('Results'), 'count' => $this->program->results_count],
        ];
    }
};
?>

<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white">
    <x-ui.detail-header
        :breadcrumbs="[
            ['label' => __('Programs'), 'url' => route('programs.index')],
            ['label' => $this->program->name],
        ]"
        :title="$this->program->name"
    >
        <x-slot:meta>
            @if ($this->program->strategy)
                <a href="{{ route('strategies.show', $this->program->strategy) }}" wire:navigate class="font-medium hover:text-secondary-dark hover:underline dark:hover:text-white">
                    {{ $this->program->strategy->name }}
                    ({{ $this->program->strategy->start_year }}–{{ $this->program->strategy->end_year }})
                </a>
            @else
                <span>{{ __('Strategy not assigned') }}</span>
            @endif
            <span>{{ __('Updated :date', ['date' => $this->program->updated_at?->format('M j, Y')]) }}</span>
        </x-slot:meta>

        <x-slot:actions>
            <x-ui.employee-chip :employee="$this->program->manager" class="rounded-lg border border-secondary-soft p-2.5 dark:border-secondary-medium" />
        </x-slot:actions>
    </x-ui.detail-header>

    <x-ui.relation-tabs :tabs="$this->tabs" :active="$tab" wire-target="tab" />

    @if ($tab === 'projects')
        <x-ui.panel :title="__('Projects')" :count="$this->projects->count()">
            @if ($this->projects->isEmpty())
                <x-ui.empty-state icon="rectangle-stack" :heading="__('No projects yet')" :body="__('Projects delivered under this program will appear here.')" />
            @else
                <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($this->projects as $project)
                        <article class="flex flex-col gap-3 p-3.5 @3xl/detail:flex-row @3xl/detail:items-center" wire:key="program-project-{{ $project->id }}">
                            <div class="min-w-0 @3xl/detail:me-auto">
                                <a href="{{ route('projects.show', $project) }}" wire:navigate class="truncate text-sm font-semibold hover:underline">{{ $project->name }}</a>
                                <p class="text-xs text-secondary-medium dark:text-secondary-soft">
                                    {{ $project->city?->name_en ?? __('Unknown city') }}
                                    ·
                                    {{ trans_choice(':count risk|:count risks', $project->risks_count, ['count' => $project->risks_count]) }}
                                    ·
                                    {{ trans_choice(':count milestone|:count milestones', $project->milestones_count, ['count' => $project->milestones_count]) }}
                                </p>
                            </div>
                            <x-ui.employee-chip :employee="$project->user" class="@3xl/detail:w-64" />
                        </article>
                    @endforeach
                </div>
            @endif
        </x-ui.panel>
    @elseif ($tab === 'plans')
        <x-ui.panel :title="__('Annual plans')" :count="$this->yearlyPlans->count()">
            @if ($this->yearlyPlans->isEmpty())
                <x-ui.empty-state icon="calendar-days" :heading="__('No annual plans yet')" :body="__('Operational plans written for this program will appear here.')" />
            @else
                <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($this->yearlyPlans as $plan)
                        <article class="flex flex-col gap-3 p-3.5 @3xl/detail:flex-row @3xl/detail:items-center" wire:key="program-plan-{{ $plan->id }}">
                            <div class="min-w-0 @3xl/detail:me-auto">
                                @if (Route::has('yearly-plans.show'))
                                    <a href="{{ route('yearly-plans.show', $plan) }}" wire:navigate class="text-sm font-semibold tabular-nums hover:underline">{{ __('FY :year', ['year' => $plan->year]) }}</a>
                                @else
                                    <p class="text-sm font-semibold tabular-nums">{{ __('FY :year', ['year' => $plan->year]) }}</p>
                                @endif
                                <p class="text-xs text-secondary-medium dark:text-secondary-soft">
                                    {{ trans_choice(':count target|:count targets', $plan->plan_indicators_count, ['count' => $plan->plan_indicators_count]) }}
                                </p>
                            </div>
                            <x-ui.employee-chip :employee="$plan->manager" class="@3xl/detail:w-64" />
                        </article>
                    @endforeach
                </div>
            @endif
        </x-ui.panel>
    @else
        <x-ui.panel :title="__('Results logframe')" :count="$this->program->results_count" :subtitle="__('Impact, outcome and output statements, nested as recorded.')">
            @if ($this->resultRoots->isEmpty())
                <x-ui.empty-state icon="chart-bar" :heading="__('No results yet')" :body="__('Logframe statements assigned to this program will appear here.')" />
            @else
                <x-ui.tree-rows
                    :nodes="$this->resultRoots"
                    :label="fn (\App\Models\Result $result): string => $result->statement"
                    :badge="fn (\App\Models\Result $result) => $result->type"
                />
            @endif
        </x-ui.panel>
    @endif
</section>
