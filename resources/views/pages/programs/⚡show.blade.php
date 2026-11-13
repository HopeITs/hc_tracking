<?php

use App\Actions\Targets\SetProgramDonorAttribution;
use App\Enums\DonorAttribution;
use App\Enums\MonthlyReportStatus;
use App\Livewire\Concerns\AddsQuickTask;
use App\Livewire\Concerns\Programs\LinksProgram;
use App\Livewire\Concerns\Programs\ManagesProgramPlans;
use App\Livewire\Concerns\Programs\ManagesProgramResults;
use App\Livewire\Concerns\Programs\ManagesProgramTasks;
use App\Livewire\Concerns\Programs\ReadsProgramDelivery;
use App\Livewire\Concerns\Programs\TracksProgramHealth;
use App\Livewire\Concerns\Results\FiltersResultsChain;
use App\Models\Milestone;
use App\Models\MonthlyReport;
use App\Models\Program;
use App\Models\Project;
use App\Models\Risk;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Carbon\CarbonImmutable;
use Livewire\Component;

new #[Layout('layouts::desk'), Title('titles.program')] class extends Component {
    use AddsQuickTask;
    use FiltersResultsChain;
    use LinksProgram;
    use ManagesProgramPlans;
    use ManagesProgramResults;
    use ManagesProgramTasks;
    use ReadsProgramDelivery;
    use TracksProgramHealth;

    #[Locked]
    public int $programId;

    /** The tabs the page offers, in order; the first is the default. */
    public const TABS = ['overview', 'tasks', 'plans', 'results'];

    #[Url(except: 'overview')]
    public string $tab = 'overview';

    public function mount(Program $program): void
    {
        $this->programId = $program->id;
        $this->tab = $this->resolveTab($this->tab);
    }

    public function updatedTab(): void
    {
        $this->tab = $this->resolveTab($this->tab);
    }

    /** A known tab, or the overview for anything else (old saved links included). */
    protected function resolveTab(string $tab): string
    {
        return in_array($tab, self::TABS, true) ? $tab : 'overview';
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

    /**
     * Last finished month's monthly reports across this program's projects:
     * how many exist and how many MEAL has validated. Null without report access.
     *
     * @return array{month: string, total: int, validated: int}|null
     */
    #[Computed]
    public function lastMonthReports(): ?array
    {
        if (auth()->user()?->can('reports.view') !== true) {
            return null;
        }

        $month = CarbonImmutable::today()->subMonthNoOverflow()->startOfMonth();
        $reports = MonthlyReport::query()
            ->whereIn('project_id', Project::query()->select('id')->where('program_id', $this->programId))
            ->whereDate('period', $month->toDateString());

        return [
            'month' => $month->translatedFormat('F'),
            'total' => (clone $reports)->count(),
            'validated' => (clone $reports)->where('status', MonthlyReportStatus::Validated->value)->count(),
        ];
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
            ->withCount(['annualPlans', 'results'])
            ->findOrFail($this->programId);
    }

    /**
     * Every project under this program — used to name and group tasks on
     * the Tasks tab.
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
        return $this->scopeToProgramTasks(Task::query())
            ->select(['id', 'parent_task_id', 'source_type', 'source_id', 'title', 'status', 'priority', 'due_date', 'created_by', 'kind', 'reportable_type', 'reportable_id'])
            ->with([
                'taskAssignees.employee:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
                'source' => fn (MorphTo $morphTo) => $morphTo->morphWith([Milestone::class => ['project:id']]),
            ])
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

    /**
     * "Goes to" starts on the project being viewed on the Tasks tab, else the program itself.
     */
    protected function quickTaskTarget(): string
    {
        if ($this->taskGroupBy === 'project' && str_starts_with($this->taskBucket, 'project-')) {
            return 'project:'.(int) substr($this->taskBucket, strlen('project-'));
        }

        return 'program';
    }

    protected function quickTaskCreated(): void
    {
        unset($this->tasks);
    }

    /**
     * The tab bar. `alert` marks a tab holding something past due.
     *
     * @return array<int, array{key: string, label: string, count: int|null, alert: bool}>
     */
    #[Computed]
    public function tabs(): array
    {
        $tones = array_column($this->attention, 'tone');

        return [
            ['key' => 'overview', 'label' => __('programs.tab_overview'), 'count' => null, 'alert' => in_array('critical', $tones, true)],
            ['key' => 'tasks', 'label' => __('common.tasks'), 'count' => $this->tasks->count(), 'alert' => $this->healthFigures['pastDueTasks'] > 0],
            ['key' => 'plans', 'label' => __('programs.annual_plans'), 'count' => $this->program->annual_plans_count, 'alert' => false],
            ['key' => 'results', 'label' => __('programs.results'), 'count' => $this->program->results_count, 'alert' => false],
        ];
    }
};
?>

<section class="dk-story dk-prog @container/detail flex flex-col gap-6" data-story>
    @php
        $program = $this->program;
        $figures = $this->healthFigures;
        $verdict = $this->healthVerdict;
        $attention = $this->attention;
        $alarms = count(array_filter($attention, fn (array $item): bool => $item['tone'] === 'critical'));
        $warnings = count($attention) - $alarms;
        $progress = $this->milestoneProgress;
        $funding = $this->programFunding;
        $openTaskCount = $this->tasks->where('status', '!=', \App\Enums\TaskStatus::Done)->count();
    @endphp

    <div>
        <a class="dk-back" href="{{ \App\Support\BackUrl::for(route('programs.index')) }}" wire:navigate data-story-back><x-desk.icon name="back" class="dk-flip" />{{ __('common.back') }}</a>

        <nav class="dk-crumb" aria-label="{{ __('ui.breadcrumb') }}">
            @if ($program->strategy)
                <a href="{{ route('strategies.index') }}" wire:navigate>{{ __('common.strategies') }}</a>
                ›
                <a href="{{ route('strategies.show', $program->strategy) }}" wire:navigate>{{ $program->strategy->name }}</a>
            @else
                <span>{{ __('common.strategy_not_assigned') }}</span>
            @endif
            ›
            <span aria-current="page">{{ $program->name }}</span>
        </nav>

        {{-- Who and what: the name, then the three facts a reader asks first. --}}
        <header class="dk-prog-h">
            <div class="min-w-0">
                <p class="dk-eyebrow">{{ __('common.program') }}</p>
                <h1 class="page-title">
                    {{ $program->name }}
                    @if (app(\App\Notifications\UnreadSubjects::class)->has(auth()->user(), 'program', $program->id))
                        <span class="inline-block size-2.5 rounded-full bg-primary align-middle dark:bg-ink" data-unread-dot title="{{ __('notifications.has_unread') }}"><span class="sr-only">{{ __('notifications.has_unread') }}</span></span>
                    @endif
                </h1>
                <dl class="dk-prog-meta">
                    <div>
                        <dt>{{ __('programs.linked_strategy') }}</dt>
                        <dd>
                            @if ($program->strategy)
                                <a href="{{ route('strategies.show', $program->strategy) }}" wire:navigate>{{ $program->strategy->name }}</a>
                            @else
                                <span class="dk-muted">{{ __('common.not_assigned') }}</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt>{{ __('programs.direct_manager') }}</dt>
                        <dd class="dk-prog-person">
                            @if ($program->manager)
                                <x-ui.avatar :src="\App\Models\HrEmployee::avatarUrl($program->manager->id)" class="dk-av" aria-hidden="true" data-program-manager-photo />
                            @endif
                            <span>{{ $program->manager?->name ?? __('common.not_assigned') }}</span>
                            @if ($program->manager)
                                <span class="sr-only" dir="auto" lang="{{ \App\Enums\Locale::current()->other()->value }}">{{ $program->manager->secondary_name }}</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt>{{ __('programs.updated') }}</dt>
                        <dd>{{ $program->updated_at?->translatedFormat(__('formats.date')) ?? '—' }}</dd>
                    </div>
                    {{-- The verdict: one word on a tint of its tone, and how much needs a hand. --}}
                    <div class="dk-prog-meta-verdict">
                        <dt>{{ __('programs.delivery_health') }}</dt>
                        <dd>
                            <p @class(['dk-verdict', 'dk-good' => $verdict === 'on_track', 'dk-warn' => $verdict === 'attention', 'dk-bad' => $verdict === 'at_risk', 'dk-mute' => $verdict === null]) data-program-verdict="{{ $verdict ?? 'none' }}">
                                @if ($verdict === null)
                                    <span>{{ __('programs.verdict_none') }}</span>
                                @else
                                    <strong>{{ match ($verdict) {
                                        'on_track' => __('programs.verdict_on_track'),
                                        'attention' => __('programs.verdict_attention'),
                                        default => __('programs.verdict_at_risk'),
                                    } }}</strong>
                                    <span>
                                        @if ($attention === [])
                                            {{ __('programs.verdict_all_clear') }}
                                        @else
                                            {!! trans_choice('programs.verdict_alarms', $alarms, ['count' => '<bdi>'.$alarms.'</bdi>']) !!}
                                            {!! trans_choice('programs.verdict_warnings', $warnings, ['count' => '<bdi>'.$warnings.'</bdi>']) !!}
                                        @endif
                                    </span>
                                @endif
                            </p>
                        </dd>
                    </div>
                </dl>
            </div>

            @can('update', $program)
                <div class="dk-prog-h-action">
                    <x-ui.button tone="ghost" size="sm" icon="link" wire:click="openLinkModal" data-test="link-program-button">{{ __('programs.link_strategy_and_manager') }}</x-ui.button>
                </div>
            @endcan
        </header>
    </div>

    {{-- The stat cards: one card per figure, the number first; a card that leads somewhere is a button. --}}
    <div class="dk-prog-stats" role="group" aria-label="{{ __('programs.delivery_health') }}">
        <div class="dk-prog-stat">
            <span class="dk-prog-stat-k"><x-desk.icon name="flag" />{{ __('programs.milestone_progress') }}</span>
            <span class="dk-prog-stat-n dk-num">{{ $progress['percentage'] === null ? '—' : number_format($progress['percentage'], 0).'%' }}</span>
            <span class="dk-prog-bar" aria-hidden="true"><i style="width: {{ $progress['percentage'] ?? 0 }}%"></i></span>
            <span class="dk-prog-stat-s">{{ $progress['percentage'] === null ? __('programs.no_weighted_milestones') : __('programs.weighted_progress_of_total', ['reached' => number_format($progress['reached'], 0), 'total' => number_format($progress['total'], 0)]) }}</span>
            @if ($progress['percentage'] !== null && $progress['measuredProjects'] < $progress['projects'])
                <span class="dk-prog-stat-s text-status-warn" data-milestone-coverage>{{ __('programs.milestone_progress_from_projects', ['measured' => $progress['measuredProjects'], 'count' => $progress['projects']]) }}</span>
            @endif
        </div>

        <div class="dk-prog-stat">
            <span class="dk-prog-stat-k"><x-desk.icon name="folder" />{{ __('programs.figure_active_projects') }}</span>
            <span class="dk-prog-stat-n dk-num">{{ $figures['active'] }}</span>
            <span class="dk-prog-stat-s">{{ trans_choice('programs.figure_of_projects', $figures['projects'], ['count' => $figures['projects']]) }}</span>
        </div>

        <button type="button" @class(['dk-prog-stat', 'dk-bad' => $figures['pastDueTasks'] > 0]) wire:click="$set('tab', 'tasks')" data-program-past-due>
            <span class="dk-prog-stat-k"><x-desk.icon name="check" />{{ __('programs.figure_tasks_past_due') }}</span>
            <span class="dk-prog-stat-n dk-num">{{ $figures['pastDueTasks'] }}</span>
            <span class="dk-prog-stat-s">{{ $figures['mine'] > 0 ? trans_choice('programs.figure_yours', $figures['mine'], ['count' => $figures['mine']]) : trans_choice('programs.figure_open_tasks', $openTaskCount, ['count' => $openTaskCount]) }}</span>
        </button>

        @if ($figures['targets'] !== null)
            <button type="button" class="dk-prog-stat" wire:click="$set('tab', 'results')" data-program-targets>
                <span class="dk-prog-stat-k"><x-desk.icon name="chart" />{{ __('programs.figure_targets_reached') }}</span>
                <span class="dk-prog-stat-n dk-num">{{ $figures['targets']['reached'] }}</span>
                <span class="dk-prog-stat-s">{{ trans_choice('programs.figure_of_targets', $figures['targets']['total'], ['count' => $figures['targets']['total']]) }}</span>
            </button>
        @endif

        @if ($this->lastMonthReports !== null)
            <a href="{{ route('monthly-reports.index') }}" wire:navigate class="dk-prog-stat dk-prog-stat-wide" data-program-reports>
                <span class="dk-prog-stat-k"><x-desk.icon name="file" />{{ __('programs.monthly_reports') }}</span>
                <span class="dk-prog-stat-n dk-num">{{ $this->lastMonthReports['validated'] }}</span>
                <span class="dk-prog-stat-s">{{ $this->lastMonthReports['total'] === 0 ? __('programs.card_month_no_reports', ['month' => $this->lastMonthReports['month']]) : __('programs.card_month_reports', ['month' => $this->lastMonthReports['month'], 'validated' => $this->lastMonthReports['validated'], 'total' => $this->lastMonthReports['total']]) }}</span>
            </a>
        @endif

        @if ($funding !== null)
            <div class="dk-prog-stat dk-prog-stat-wide" data-program-funding>
                <span class="dk-prog-stat-k"><x-desk.icon name="coins" />{{ __('programs.figure_funding_received') }}</span>
                @if ($funding === [])
                    <span class="dk-prog-stat-n dk-num dk-muted">—</span>
                    <span class="dk-prog-stat-s">{{ __('programs.figure_no_funding') }}</span>
                @else
                    <div class="dk-prog-fund">
                        @foreach ($funding as $row)
                            <div class="dk-prog-fund-row" wire:key="program-funding-{{ $row['currency'] }}">
                                <span class="dk-prog-stat-m"><x-ui.money :amount="$row['received']" :currency="$row['currency']" /></span>
                                <span class="dk-prog-bar" aria-hidden="true"><i style="width: {{ $row['committed'] > 0 ? min(100, round($row['received'] / $row['committed'] * 100, 1)) : 0 }}%"></i></span>
                                <span class="dk-prog-stat-s">{{ __('programs.figure_of_committed', ['amount' => number_format($row['committed'], 2), 'currency' => $row['currency']]) }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    </div>

    {{-- One bar of tabs; a red dot marks a tab holding something past due. --}}
    <div class="dk-pjtabs" role="tablist" aria-label="{{ __('programs.connection_views') }}">
        @foreach ($this->tabs as $tabOption)
            <button type="button" role="tab" id="program-tab-{{ $tabOption['key'] }}" aria-controls="program-panel" wire:key="program-tab-{{ $tabOption['key'] }}" wire:click="$set('tab', '{{ $tabOption['key'] }}')" aria-selected="{{ $tab === $tabOption['key'] ? 'true' : 'false' }}" data-test="program-tab-{{ $tabOption['key'] }}">
                <span>{{ $tabOption['label'] }}</span>
                @if ($tabOption['count'] !== null)
                    <span class="dk-num">{{ $tabOption['count'] }}</span>
                @endif
                @if ($tabOption['alert'])
                    <span class="dk-prog-alert" title="{{ __('programs.tab_has_alerts') }}"><span class="sr-only">{{ __('programs.tab_has_alerts') }}</span></span>
                @endif
            </button>
        @endforeach
    </div>

    <div id="program-panel" role="tabpanel" aria-labelledby="program-tab-{{ $tab }}" class="transition-opacity" wire:loading.class="opacity-60" wire:target="tab">
        @if ($tab === 'overview')
            @include('pages.programs._show-overview')
        @elseif ($tab === 'tasks')
            @include('pages.programs._show-tasks-tab')
        @elseif ($tab === 'plans')
            @include('pages.programs._show-plans-tab')
        @else
            @include('pages.programs._show-results-tab')
        @endif
    </div>

    {{-- Activity: folded by default; the browser remembers the choice. --}}
    <section class="dk-prog-fold" x-data="{ open: $persist(false).as('program-activity-open') }" :class="{ 'dk-prog-fold-on': open }" data-record-activity>
        <button type="button" class="dk-prog-fold-h" @click="open = ! open" :aria-expanded="open.toString()" aria-controls="program-activity-body">
            <h2 id="record-activity-heading">{{ __('activity.title') }}</h2>
            <span class="dk-prog-fold-hint" x-text="open ? @js(__('programs.fold_hide')) : @js(__('programs.fold_show'))"></span>
            <x-desk.icon name="chev" class="dk-prog-fold-i" />
        </button>
        <div id="program-activity-body" class="dk-prog-fold-b" x-show="open" x-collapse x-cloak>
            <livewire:activity.timeline :subjects="['program' => [$program->id]]" :limit="3" :step="3" :key="'program-activity-'.$program->id" />
        </div>
    </section>

    <x-tasks.add-task-modal :open="$showAddTaskModal" test-prefix="new-program-task" />

    <x-ui.modal
        wire:model="showProgramModal"
        size="md"
        :title="__('programs.assign_name', ['name' => $program->name])"
        :description="__('programs.the_program_name_is_managed_in_the_hr_system_link_it_to_a_st')"
        submit="saveProgram"
        close="closeProgramModal"
        :discard-guard="$showProgramModal ? ['fields' => ['strategyId', 'managerId'], 'original' => ['strategyId' => $originalStrategyId, 'managerId' => $originalManagerId]] : null"
    >
        @if ($showProgramModal)
            <div class="grid gap-y-4">
                <x-ui.form-select wire:model="strategyId" :label="__('programs.linked_strategy')" autofocus>
                    <option value="">{{ __('common.not_assigned') }}</option>
                    @foreach ($this->strategyOptions as $strategyOption)
                        <option value="{{ $strategyOption->id }}">{{ $this->strategyLabel($strategyOption) }}</option>
                    @endforeach
                </x-ui.form-select>

                <x-ui.form-select wire:model="managerId" :label="__('programs.direct_manager')" :help="__('programs.the_assigned_manager_is_the_hr_employee_directly_accountable')" employees>
                    <option value="">{{ __('common.not_assigned') }}</option>
                </x-ui.form-select>
            </div>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-program-button">{{ __('common.save_changes') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>
</section>
