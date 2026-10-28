<?php

use App\Actions\Targets\SetProgramDonorAttribution;
use App\Enums\DonorAttribution;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Livewire\Concerns\AddsQuickTask;
use App\Livewire\Concerns\Programs\ReadsProgramDelivery;
use App\Livewire\Concerns\Results\FiltersResultsChain;
use App\Models\Milestone;
use App\Models\Program;
use App\Models\Project;
use App\Models\Risk;
use App\Models\Task;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::desk'), Title('titles.program')] class extends Component {
    use AddsQuickTask;
    use FiltersResultsChain;
    use ReadsProgramDelivery;
    use WithPagination;

    #[Locked]
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

    /**
     * Set how this program combines one indicator's targets across donors
     * (D-40/D-46). Unknown values are ignored.
     */
    public function setDonorAttribution(string $attribution): void
    {
        $value = DonorAttribution::tryFrom($attribution);

        if ($value === null) {
            return;
        }

        app(SetProgramDonorAttribution::class)->handle($this->program, $value);

        unset($this->program);

        Flux::toast(variant: 'success', text: __('programs.donor_attribution_saved'));
    }

    #[Computed]
    public function program(): Program
    {
        return Program::query()
            ->select(['id', 'strategy_id', 'manager_id', 'donor_attribution', 'updated_at'])
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
                ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_substr($this->projectSearch, 0, Like::MaxTermLength)).'%'],
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
    protected function taskProjectId(Task $task): ?int
    {
        return match ($task->source_type) {
            'project' => $task->source_id,
            'milestone' => $task->source?->project_id,
            default => null,
        };
    }

    /**
     * Raise a task straight against this program — the source is always this
     * program, never one of its projects or milestones: a task tied to a
     * project's or milestone's own delivery is added from that record's page
     * instead.
     *
     * @return array{type: string, id: int}
     */
    protected function quickTaskSource(): array
    {
        return ['type' => 'program', 'id' => $this->programId];
    }

    protected function quickTaskCreated(): void
    {
        unset($this->tasks);
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

<section class="dk-story @container/detail flex flex-col gap-6" data-story>
    <div>
        <a class="dk-back" href="{{ route('programs.index') }}" wire:navigate data-story-back><x-desk.icon name="back" class="dk-flip" />{{ __('common.programs') }}</a>

        <nav class="dk-crumb" aria-label="{{ __('ui.breadcrumb') }}">
            @if ($this->program->strategy)
                <a href="{{ route('strategies.show', $this->program->strategy) }}" wire:navigate>{{ $this->program->strategy->name }}</a>
            @else
                <span>{{ __('common.strategy_not_assigned') }}</span>
            @endif
            ›
            <span aria-current="page">{{ $this->program->name }}</span>
        </nav>

        <div class="dk-story-h">
            <div>
                <p class="dk-eyebrow">{{ __('common.program') }}</p>
                <h1>
                    {{ $this->program->name }}
                    @if (app(\App\Notifications\UnreadSubjects::class)->has(auth()->user(), 'program', $this->program->id))
                        <span class="inline-block size-2.5 rounded-full bg-primary align-middle dark:bg-ink" data-unread-dot title="{{ __('notifications.has_unread') }}"><span class="sr-only">{{ __('notifications.has_unread') }}</span></span>
                    @endif
                </h1>
            </div>

            <aside class="dk-next" aria-label="{{ __('programs.delivery_health') }}">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="dk-eyebrow">{{ __('programs.delivery_health') }}</p>
                        <p class="dk-who mt-1">{{ __('programs.milestone_progress') }}</p>
                    </div>
                    <span class="dk-num shrink-0 text-3xl font-extrabold tracking-tight">{{ $this->milestoneProgress['percentage'] === null ? '—' : number_format($this->milestoneProgress['percentage'], 0).'%' }}</span>
                </div>
                <div>
                    <span class="dk-meter dk-res" role="progressbar" aria-valuemin="0" aria-valuemax="100" @if ($this->milestoneProgress['percentage'] !== null) aria-valuenow="{{ $this->milestoneProgress['percentage'] }}" @endif aria-label="{{ __('programs.milestone_progress') }}"><b style="width: {{ $this->milestoneProgress['percentage'] ?? 0 }}%"></b></span>
                    <p class="dk-who dk-num mt-2">{{ $this->milestoneProgress['percentage'] === null ? __('programs.no_weighted_milestones') : __('programs.weighted_progress_of_total', ['reached' => number_format($this->milestoneProgress['reached'], 0), 'total' => number_format($this->milestoneProgress['total'], 0)]) }}</p>
                </div>
                <div>
                    <p class="dk-fcat mt-0!">{{ __('programs.projects_by_status') }}</p>
                    <div class="mt-2 flex h-1.5 overflow-hidden rounded-full bg-secondary-soft dark:bg-surface-2" role="img" aria-label="{{ __('programs.projects_by_status') }}">
                        @foreach ($this->projectStatusSegments as $segment)
                            @if ($segment['count'] > 0)<span class="{{ $segment['tone'] }}" style="flex: {{ $segment['count'] }}" title="{{ $segment['label'] }}: {{ $segment['count'] }}"></span>@endif
                        @endforeach
                    </div>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @foreach ($this->projectStatusSegments as $segment)
                            @if ($segment['count'] > 0)
                                <span class="dk-pill gap-1.5"><span class="size-1.5 rounded-full {{ $segment['tone'] }}"></span>{{ $segment['label'] }} <span class="dk-num">{{ $segment['count'] }}</span></span>
                            @endif
                        @endforeach
                    </div>
                </div>
            </aside>
        </div>

        <dl class="dk-facts">
            <div>
                <dt>{{ __('programs.linked_strategy') }}</dt>
                <dd>
                    @if ($this->program->strategy)
                        <a href="{{ route('strategies.show', $this->program->strategy) }}" wire:navigate class="hover:underline">{{ $this->program->strategy->name }}</a>
                    @else
                        <span class="dk-muted">{{ __('common.not_assigned') }}</span>
                    @endif
                </dd>
            </div>
            <div>
                <dt>{{ __('programs.direct_manager') }}</dt>
                <dd>
                    {{ $this->program->manager?->name ?? __('common.not_assigned') }}
                    @if ($this->program->manager)
                        <span class="sr-only" dir="auto" lang="{{ \App\Enums\Locale::current()->other()->value }}">{{ $this->program->manager->secondary_name }}</span>
                    @endif
                </dd>
            </div>
            <div>
                <dt>{{ __('programs.updated') }}</dt>
                <dd>{{ $this->program->updated_at?->translatedFormat(__('formats.date')) ?? '—' }}</dd>
            </div>
            <div class="max-w-80" data-program-donor-attribution>
                <dt><label for="program-donor-attribution">{{ __('programs.donor_attribution') }}</label></dt>
                <dd class="block!">
                    @can('report-templates.manage')
                        <flux:select id="program-donor-attribution" size="sm" wire:change="setDonorAttribution($event.target.value)" wire:loading.attr="disabled" wire:target="setDonorAttribution">
                            @foreach (DonorAttribution::cases() as $attribution)
                                <flux:select.option value="{{ $attribution->value }}" :selected="$this->program->donor_attribution === $attribution">{{ $attribution->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @else
                        {{ $this->program->donor_attribution->label() }}
                    @endcan
                    <p class="dk-small dk-muted mt-1 font-normal">{{ __('programs.donor_attribution_help') }}</p>
                </dd>
            </div>
        </dl>
    </div>

    <div class="flex flex-wrap items-center gap-1 border-b border-secondary-soft dark:border-line" role="tablist" aria-label="{{ __('programs.connection_views') }}">
        @foreach ($this->tabs as $tabOption)
            @php $isActive = $tab === $tabOption['key']; @endphp
            <button type="button" role="tab" wire:key="program-tab-{{ $tabOption['key'] }}" wire:click="$set('tab', '{{ $tabOption['key'] }}')" aria-selected="{{ $isActive ? 'true' : 'false' }}" @class([
                'inline-flex min-h-11 items-center gap-1.5 border-b-2 px-3 text-sm font-semibold transition-colors',
                'border-primary text-secondary-dark dark:text-ink' => $isActive,
                'border-transparent text-secondary-medium hover:text-secondary-dark dark:text-ink-muted dark:hover:text-ink' => ! $isActive,
            ])>
                <span>{{ $tabOption['label'] }}</span>
                <span class="inline-flex min-w-5 items-center justify-center rounded-md border border-secondary-light px-1 py-0.5 text-[11px] font-semibold tabular-nums text-secondary-medium dark:border-line dark:text-ink-muted">{{ $tabOption['count'] }}</span>
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
        @include('pages.programs._show-tasks-tab')
    @elseif ($tab === 'plans')
        <x-ui.plan-years :slots="$this->planTimeline" :strategy="$this->program->strategy" />
    @else
        <x-ui.logframe :chain="$this->chain" :summary="$this->resultSummary">
            @can('results.create')
                <x-slot:start>
                    <x-ui.button tone="primary" icon="plus" :href="route('results.index')" wire:navigate>{{ __('results_framework.chain_start') }}</x-ui.button>
                </x-slot:start>
            @endcan
        </x-ui.logframe>
    @endif

    <section aria-labelledby="record-activity-heading" class="grid gap-3" data-record-activity>
        <h2 id="record-activity-heading" class="text-base font-bold">{{ __('activity.title') }}</h2>
        <livewire:activity.timeline :subjects="['program' => [$this->program->id]]" :key="'program-activity-'.$this->program->id" />
    </section>

    <x-tasks.add-task-modal :open="$showAddTaskModal" test-prefix="new-program-task" />
</section>
