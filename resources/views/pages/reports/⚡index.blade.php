<?php

use App\Enums\ReportStatus;
use App\Livewire\Concerns\ManagesReportForm;
use App\Models\AnnualPlan;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\User;
use App\Notifications\UnreadSubjects;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection as SupportCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.reports')] class extends Component {
    use ManagesReportForm;
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $seenByMeal = '';

    #[Url(except: '')]
    public string $author = '';

    #[Url(except: '')]
    public string $project = '';

    /**
     * Narrows the feed to one target. Set by the "Record a report" and report
     * trail links on the targets page, so it shows as a removable chip.
     */
    #[Url(except: '')]
    public string $target = '';

    /** '1' narrows the feed to drafts past the date they cover. */
    #[Url(except: '')]
    public string $overdue = '';

    /**
     * Which shape the report feed renders in: a flat searchable list, or
     * reports grouped by project and indicator to surface the connections
     * between delivery and what has been reported against it.
     */
    #[Url(except: 'list')]
    public string $view = 'list';

    /**
     * `?create=1` (with `?target=`) opens the form already tied to a target —
     * the "Record a report" step from the targets page and the indicator
     * chain. `?edit=<id>` opens a draft for editing.
     */
    public function mount(): void
    {
        if (request()->boolean('create')) {
            $this->openCreateModal($this->target === '' ? null : (int) $this->target);
        }

        $edit = request()->integer('edit');

        if ($edit > 0 && Report::query()->whereKey($edit)->exists()) {
            $this->openEditModal($edit);
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSeenByMeal(): void
    {
        $this->resetPage();
    }

    public function updatedAuthor(): void
    {
        $this->resetPage();
    }

    public function updatedProject(): void
    {
        $this->resetPage();
    }

    public function toggleOverdue(): void
    {
        $this->overdue = $this->overdue === '' ? '1' : '';
        $this->resetPage();
    }

    public function clearTarget(): void
    {
        $this->target = '';
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->status = '';
        $this->seenByMeal = '';
        $this->author = '';
        $this->project = '';
        $this->target = '';
        $this->overdue = '';
        $this->resetPage();
    }

    /**
     * Reports matching the search, author, project and target filters only.
     *
     * Status, MEAL visibility and the overdue chip are deliberately left off
     * this base query: it is shared by the paginated list (which applies them)
     * and the portfolio pulse (which counts every status and both MEAL states
     * side by side, so the strip stays a full breakdown rather than shrinking
     * to whichever status is currently selected).
     *
     * @return Builder<Report>
     */
    private function scopedReports(): Builder
    {
        $search = trim($this->search);

        return Report::query()
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.$search.'%';

                $query->where(function ($query) use ($like): void {
                    $query
                        ->where('narrative_report_notes', 'like', $like)
                        ->orWhereHas('planIndicator.indicator', fn ($query) => $query->where('name', 'like', $like))
                        ->orWhereHas('planIndicator.project', fn ($query) => $query->where('name', 'like', $like));
                });
            })
            ->when($this->author !== '', fn ($query) => $query->where('user_id', (int) $this->author))
            ->when($this->project === 'none', fn ($query) => $query->whereHas('planIndicator', fn ($query) => $query->whereNull('project_id')))
            ->when($this->project !== '' && $this->project !== 'none', fn ($query) => $query
                ->whereHas('planIndicator', fn ($query) => $query->where('project_id', (int) $this->project)))
            ->when($this->target !== '', fn ($query) => $query->where('plan_indicator_id', (int) $this->target));
    }

    /**
     * scopedReports() with the status, MEAL and overdue filters applied: what
     * the list and the portfolio actually show.
     *
     * @return Builder<Report>
     */
    private function visibleReports(): Builder
    {
        return $this->scopedReports()
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->when($this->seenByMeal === 'seen', fn ($query) => $query->where('is_seen_by_meal', true))
            ->when($this->seenByMeal === 'unseen', fn ($query) => $query->where('is_seen_by_meal', false))
            ->when($this->overdue !== '', fn ($query) => $query->overdue());
    }

    /**
     * The target each report is filed against, with everything reported on it
     * — not just the rows on this page — so its achievement is the target's
     * own, by PlanIndicator::achievedValue(), the rule every page shares.
     *
     * @return array<int, mixed>
     */
    private function targetRelations(): array
    {
        return [
            'planIndicator:id,indicator_id,target,period,source_type,source_id,project_id',
            'planIndicator.indicator:id,name,measure,unit',
            'planIndicator.project:id,name',
            'planIndicator.reports:id,plan_indicator_id,status,planned_date,value',
            'planIndicator.source' => fn (MorphTo $morphTo) => $morphTo->morphWith([AnnualPlan::class => [], Proposal::class => []]),
        ];
    }

    /**
     * Records with unread notifications for me, for the row dots.
     *
     * @return list<int>
     */
    #[Computed]
    public function unreadIds(): array
    {
        return app(UnreadSubjects::class)->ids(auth()->user(), 'report');
    }

    /** @return LengthAwarePaginator<int, Report> */
    #[Computed]
    public function reports(): LengthAwarePaginator
    {
        return $this->visibleReports()
            ->select(['id', 'plan_indicator_id', 'user_id', 'status', 'planned_date', 'value', 'submit_date', 'is_seen_by_meal'])
            ->with([...$this->targetRelations(), 'user:id,hr_user_id'])
            ->withCount('files')
            ->orderByDesc('planned_date')
            ->orderByDesc('id')
            ->paginate(15);
    }

    /**
     * The portfolio pulse: how the reports matching the filters break down by
     * status and MEAL visibility, how many drafts are late, and the average
     * achievement of the targets they report against.
     *
     * Achievement is averaged per target, not per report — a target reported
     * quarterly must not weigh four times as much as one reported yearly — and
     * each target is capped at 150% first, so one wildly over-achieved target
     * cannot drag the average somewhere meaningless.
     *
     * @return array{total: int, statusCounts: SupportCollection<string, int>, seen: int, unseen: int, overdue: int, avgAchievement: int|null}
     */
    #[Computed]
    public function insights(): array
    {
        $statusCounts = $this->scopedReports()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $total = (int) $statusCounts->sum();
        $unseen = (int) $this->scopedReports()->where('is_seen_by_meal', false)->count();
        $overdue = (int) $this->scopedReports()->overdue()->count();

        $achievements = PlanIndicator::query()
            ->whereIn('id', $this->scopedReports()->select('plan_indicator_id'))
            ->where('target', '>', 0)
            ->selectRaw('target, '.PlanIndicator::achievedValueSql().' as achieved')
            ->toBase()
            ->get()
            ->map(fn (object $row): float => min(150.0, (float) $row->achieved / (float) $row->target * 100));

        return [
            'total' => $total,
            'statusCounts' => $statusCounts,
            'seen' => $total - $unseen,
            'unseen' => $unseen,
            'overdue' => $overdue,
            'avgAchievement' => $achievements->isEmpty() ? null : (int) round($achievements->avg()),
        ];
    }

    /**
     * Reports grouped by project, then by target, for the portfolio view.
     *
     * Both levels sort worst-achievement-first: a manager opening the
     * portfolio should see what needs attention before anything else,
     * without having to hunt for it. A project or target with no scorable
     * achievement (no positive target) sorts after every scored one, then
     * alphabetically, so it is never mistaken for a top performer.
     *
     * Only queried when that view is active: the list view's paginated
     * `reports()` stays the cheap default. Every filter the list respects
     * applies here too, so switching views never changes what is being looked
     * at, only how it is shaped.
     *
     * @return SupportCollection<int, array{project: Project|null, reportCount: int, avgAchievement: int|null, indicators: SupportCollection<int, array{planIndicator: PlanIndicator, reports: SupportCollection<int, Report>, latest: Report, achievement: int|null, trend: array<int, float>}>}>
     */
    #[Computed]
    public function portfolio(): SupportCollection
    {
        if ($this->view !== 'portfolio') {
            return collect();
        }

        $reports = $this->visibleReports()
            ->select(['id', 'plan_indicator_id', 'user_id', 'status', 'planned_date', 'value', 'submit_date', 'is_seen_by_meal'])
            ->with($this->targetRelations())
            ->orderByDesc('planned_date')
            ->orderByDesc('id')
            ->get();

        return $reports
            ->groupBy(fn (Report $report): int => $report->planIndicator->project_id ?? 0)
            ->map(function (Collection $projectReports): array {
                $indicators = $projectReports
                    ->groupBy('plan_indicator_id')
                    ->map(function (Collection $rows): array {
                        /** @var PlanIndicator $planIndicator */
                        $planIndicator = $rows->first()->planIndicator;
                        $percent = $planIndicator->percentAchieved();

                        return [
                            'planIndicator' => $planIndicator,
                            'reports' => $rows->values(),
                            'latest' => $rows->first(),
                            // Oldest first, for a sparkline that reads left-to-right in time.
                            'trend' => $rows->sortBy('planned_date')->pluck('value')->map(fn ($value): float => (float) $value)->values()->all(),
                            'achievement' => $percent === null ? null : (int) floor($percent),
                        ];
                    })
                    // A stable sort: the alphabetical pass runs first so ties in
                    // achievement (including every unscored target) fall back
                    // to it, then the achievement pass reorders around that.
                    ->sortBy(fn (array $group): string => $group['planIndicator']->indicator->name)
                    ->sortBy(fn (array $group): int => $group['achievement'] ?? PHP_INT_MAX)
                    ->values();

                $scored = $indicators->pluck('achievement')->filter(fn (?int $value): bool => $value !== null);

                return [
                    'project' => $projectReports->first()->planIndicator->project,
                    'reportCount' => $projectReports->count(),
                    'avgAchievement' => $scored->isEmpty() ? null : (int) round($scored->average()),
                    'indicators' => $indicators,
                ];
            })
            ->sortBy(fn (array $group): string => $group['project']->name ?? '')
            ->sortBy(fn (array $group): int => $group['avgAchievement'] ?? PHP_INT_MAX)
            ->values();
    }

    /** @return Collection<int, User> */
    #[Computed]
    public function authorOptions(): Collection
    {
        return User::query()->select(['id', 'hr_user_id'])->orderByName()->get();
    }

    /** @return Collection<int, Project> */
    #[Computed]
    public function projectOptions(): Collection
    {
        return Project::query()
            ->select(['id', 'name'])
            ->whereIn('id', PlanIndicator::query()->select('project_id')->whereNotNull('project_id'))
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * The target the feed is narrowed to, labelled as the form labels it.
     */
    #[Computed]
    public function scopedTargetLabel(): ?string
    {
        if ($this->target === '') {
            return null;
        }

        $target = PlanIndicator::query()
            ->with(['indicator:id,name,measure', 'project:id,name', 'source'])
            ->find((int) $this->target);

        return $target === null ? null : $this->targetLabel($target);
    }

    protected function reportSaved(Report $report, bool $isCreate): void
    {
        $this->resetPage();
    }

    protected function reportDeleted(): void
    {
        $this->resetPage();
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $status !== '' || $seenByMeal !== '' || $author !== '' || $project !== '' || $target !== '' || $overdue !== '';
    $insights = $this->insights;

    $statusSegments = collect(ReportStatus::cases())->map(fn (ReportStatus $case): array => [
        'key' => $case->value,
        'label' => $case->label(),
        'count' => (int) ($insights['statusCounts'][$case->value] ?? 0),
        'tone' => match ($case) {
            ReportStatus::Draft => 'neutral',
            ReportStatus::Reviewed => 'warn',
            ReportStatus::Approved => 'good',
        },
    ])->all();

    // A badly missed target is 'critical' (the brand red), not 'warn': this
    // app's tone vocabulary already reserves red for exactly that signal
    // (see ui/stat-tile, ui/progress-ring), and a missed target is the one
    // thing here a projects/fundraising manager most needs to see at a glance.
    $achievementTone = fn (?int $value): string => match (true) {
        $value === null => 'neutral',
        $value >= 90 => 'good',
        $value < 50 => 'critical',
        default => 'neutral',
    };

    $achievementTextClass = fn (?int $value): string => match ($achievementTone($value)) {
        'good' => 'text-status-good',
        'critical' => 'text-primary',
        default => 'text-secondary-dark dark:text-white',
    };

    $achievementBarClass = fn (?int $value): string => match ($achievementTone($value)) {
        'good' => 'bg-status-good',
        'critical' => 'bg-primary',
        default => 'bg-secondary-dark dark:bg-white',
    };

    $achievementBadgeClass = fn (?int $value): string => match ($achievementTone($value)) {
        'good' => 'bg-status-good-soft text-status-good',
        'critical' => 'bg-status-critical-soft text-primary',
        default => 'bg-secondary-soft text-secondary-dark dark:bg-secondary-medium dark:text-white',
    };

    // Where the target was committed: the plan year or the proposal's name, not just its kind.
    $sourceLabel = fn (App\Models\PlanIndicator $planIndicator): string => match (true) {
        $planIndicator->source instanceof App\Models\AnnualPlan => __('common.fy_year', ['year' => $planIndicator->source->year]),
        $planIndicator->source instanceof App\Models\Proposal => $planIndicator->source->name,
        default => __('targets.source_no_longer_available'),
    };

    $formatFigure = fn (float $value): string => number_format($value, fmod($value, 1.0) === 0.0 ? 0 : 2);
    $unitOf = fn (App\Models\PlanIndicator $planIndicator): string => $planIndicator->indicator->measure === App\Enums\IndicatorMeasure::Percentage ? '%' : '';

    // A tiny inline sparkline: values normalised into a fixed 100x28 box, no
    // axes or chrome. Fewer than two points has no line to draw.
    $sparklinePoints = function (array $values): ?string {
        if (count($values) < 2) {
            return null;
        }

        $min = min($values);
        $max = max($values);
        $range = $max - $min;
        $step = 100 / (count($values) - 1);

        return collect($values)
            ->map(fn (float $value, int $index): string => round($index * $step, 1).','.round($range > 0 ? 28 - (($value - $min) / $range) * 28 : 14, 1))
            ->implode(' ');
    };

    $figures = [
        ['key' => 'total', 'label' => __('reports.total_reports'), 'value' => $insights['total'], 'suffix' => '', 'sub' => null, 'tone' => ''],
        ['key' => 'achievement', 'label' => __('reports.avg_target_achievement'), 'value' => $insights['avgAchievement'] ?? 0, 'suffix' => $insights['avgAchievement'] === null ? '' : '%', 'sub' => $insights['avgAchievement'] === null ? __('ui.no_records') : __('reports.approved_reports_only'), 'tone' => $achievementTextClass($insights['avgAchievement'])],
        ['key' => 'awaiting', 'label' => __('reports.awaiting_meal_review'), 'value' => $insights['unseen'], 'suffix' => '', 'sub' => __('reports.of_total', ['total' => $insights['total']]), 'tone' => $insights['unseen'] > 0 ? 'text-status-warn' : 'text-status-good'],
        // Status counts already sit in the strip below; what a manager is late on does not.
        ['key' => 'overdue', 'label' => __('reports.overdue_drafts'), 'value' => $insights['overdue'], 'suffix' => '', 'sub' => __('reports.drafts_past_the_period_they_cover'), 'tone' => $insights['overdue'] > 0 ? 'text-primary' : 'text-status-good'],
    ];
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-6 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="report-directory-heading">
    <x-ui.page-header
        heading-id="report-directory-heading"
        :title="__('common.reports')"
        :count="$insights['total']"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('reports.search_reports')" :placeholder="__('reports.indicator_or_narrative')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.header-filter-select :label="__('reports.reported_by')" wire:model.live="author" :value="$author">
                <flux:select.option value="">{{ __('common.anyone') }}</flux:select.option>
                @foreach ($this->authorOptions as $authorOption)
                    <flux:select.option :value="$authorOption->id" :wire:key="'rpt-author-'.$authorOption->id">{{ $authorOption->name }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('common.project')" wire:model.live="project" :value="$project">
                <flux:select.option value="">{{ __('targets.any_project') }}</flux:select.option>
                <flux:select.option value="none">{{ __('common.organisation_wide') }}</flux:select.option>
                @foreach ($this->projectOptions as $projectOption)
                    <flux:select.option :value="$projectOption->id" :wire:key="'rpt-project-'.$projectOption->id">{{ $projectOption->name }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>
        </x-slot:filters>

        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-report-button">
                {{ __('reports.record_report') }}
            </x-ui.button>
        </x-slot:actions>

        <x-slot:summary>
            <div class="flex flex-col gap-6">
                {{-- The portfolio's figures, set directly on the page rather than boxed into cards: the numbers are the point. Dividers only apply once the grid is a single row — grid-cols-2 wrapping and a manual border produced an orphaned line at in-between widths. --}}
                <div class="grid grid-cols-2 gap-x-6 gap-y-6 border-y border-secondary-soft py-6 @xl/directory:grid-cols-4 @xl/directory:divide-x @xl/directory:divide-secondary-soft dark:border-secondary-medium @xl/directory:dark:divide-secondary-medium">
                    @foreach ($figures as $index => $figure)
                        <div
                            class="logframe-reveal @xl/directory:ps-6 @xl/directory:first:ps-0"
                            style="--logframe-order: {{ $index }}"
                            wire:key="figure-{{ $figure['key'] }}"
                        >
                            <p class="type-label text-secondary-medium dark:text-secondary-soft">{{ $figure['label'] }}</p>
                            <p
                                class="mt-1.5 text-4xl leading-none font-extrabold tabular-nums {{ $figure['tone'] }}"
                                x-data="countUp({{ (int) $figure['value'] }})"
                                x-text="display + '{{ $figure['suffix'] }}'"
                            >{{ $figure['value'] }}{{ $figure['suffix'] }}</p>
                            @if ($figure['sub'] !== null)
                                <p class="mt-1 text-xs text-secondary-medium dark:text-secondary-soft">{{ $figure['sub'] }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>

                <div class="flex flex-col gap-3 @2xl/directory:flex-row @2xl/directory:items-center @2xl/directory:justify-between">
                    <x-ui.status-strip :label="__('common.status')" :segments="$statusSegments" :active="$status" wire-model="status" class="min-w-0 flex-1" />

                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            wire:click="toggleOverdue"
                            aria-pressed="{{ $overdue !== '' ? 'true' : 'false' }}"
                            data-test="overdue-chip"
                            @class([
                                'inline-flex min-h-9 items-center gap-1.5 rounded-full border px-3 type-meta font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                                'border-primary bg-primary text-white' => $overdue !== '',
                                'border-secondary-soft bg-white text-secondary-medium hover:border-secondary-light hover:text-secondary-dark dark:border-secondary-medium dark:bg-secondary-dark dark:text-secondary-soft dark:hover:text-white' => $overdue === '',
                            ])
                        >
                            <flux:icon.clock variant="micro" class="size-3.5" aria-hidden="true" />
                            {{ __('reports.overdue_drafts') }}
                            <span class="tabular-nums">{{ $insights['overdue'] }}</span>
                        </button>

                        <x-ui.view-toggle
                            :label="__('reports.meal_review')"
                            wire-model="seenByMeal"
                            :active="$seenByMeal"
                            :options="[
                                ['key' => '', 'label' => __('reports.any')],
                                ['key' => 'seen', 'label' => __('reports.seen_by_meal'), 'icon' => 'eye'],
                                ['key' => 'unseen', 'label' => __('reports.not_yet_seen'), 'icon' => 'eye-slash'],
                            ]"
                        />

                        <x-ui.view-toggle
                            :label="__('reports.report_view')"
                            wire-model="view"
                            :active="$view"
                            :options="[
                                ['key' => 'list', 'label' => __('reports.list_view'), 'icon' => 'queue-list'],
                                ['key' => 'portfolio', 'label' => __('reports.portfolio_view'), 'icon' => 'rectangle-group'],
                            ]"
                        />
                    </div>
                </div>
            </div>
        </x-slot:summary>
    </x-ui.page-header>

    @if ($this->scopedTargetLabel !== null)
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" wire:click="clearTarget" class="inline-flex max-w-full items-center gap-1.5 rounded-full border border-secondary-dark bg-secondary-dark px-3 py-1 text-xs font-semibold text-white hover:opacity-90 dark:border-white dark:bg-white dark:text-secondary-dark" data-test="target-scope-chip">
                <flux:icon.flag variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
                <span class="truncate">{{ $this->scopedTargetLabel }}</span>
                <flux:icon.x-mark variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
                <span class="sr-only">{{ __('common.reset_filters') }}</span>
            </button>
            <x-ui.button size="sm" icon="plus" wire:click="openCreateModal({{ (int) $target }})">{{ __('reports.record_another_for_this_target') }}</x-ui.button>
        </div>
    @endif

    @if ($view === 'portfolio')
        <x-ui.panel :subtitle="__('reports.reports_grouped_by_project_and_indicator_so_the_connection_b')">
            <x-slot:toolbar>
                @if ($filtersAreActive)
                    <flux:button size="sm" variant="ghost" class="text-secondary-medium! dark:text-secondary-soft!" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>
                @endif
            </x-slot:toolbar>

            @if ($this->portfolio->isEmpty())
                <x-ui.empty-state
                    icon="rectangle-group"
                    :heading="$filtersAreActive ? __('reports.no_matching_reports') : __('reports.no_reports_yet')"
                    :body="$filtersAreActive ? __('common.change_the_search_or_filters_and_try_again') : __('reports.record_the_first_figure_against_a_target')"
                />
            @else
                <div class="divide-y-4 divide-ground dark:divide-secondary-dark">
                    @foreach ($this->portfolio as $group)
                        <div wire:key="portfolio-project-{{ $group['project']?->id ?? 'org' }}">
                            {{-- Project header: name, indicator count, and a rollup badge so the manager can triage projects before opening one. --}}
                            <div class="flex flex-wrap items-center justify-between gap-3 bg-board-head px-4 py-3 dark:bg-secondary-medium/20">
                                <div class="flex items-baseline gap-2">
                                    @if ($group['project'] !== null)
                                        <a href="{{ route('projects.show', $group['project']) }}" wire:navigate class="text-base font-extrabold tracking-tight hover:underline">{{ $group['project']->name }}</a>
                                    @else
                                        <p class="text-base font-extrabold tracking-tight">{{ __('common.organisation_wide') }}</p>
                                    @endif
                                    <span class="type-meta text-secondary-medium dark:text-secondary-soft">
                                        {{ trans_choice('projects.count_indicator', $group['indicators']->count(), ['count' => $group['indicators']->count()]) }}
                                        · {{ trans_choice('common.count_report', $group['reportCount'], ['count' => $group['reportCount']]) }}
                                    </span>
                                </div>

                                @if ($group['avgAchievement'] !== null)
                                    <span class="inline-flex shrink-0 items-center gap-1 rounded-full px-2.5 py-1 text-xs font-bold tabular-nums {{ $achievementBadgeClass($group['avgAchievement']) }}">
                                        {{ __('reports.avg_target_achievement') }} {{ $group['avgAchievement'] }}%
                                    </span>
                                @endif
                            </div>

                            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                                @foreach ($group['indicators'] as $indicatorGroup)
                                    @php
                                        $planIndicator = $indicatorGroup['planIndicator'];
                                        $latest = $indicatorGroup['latest'];
                                        $achievement = $indicatorGroup['achievement'];
                                        $points = $sparklinePoints($indicatorGroup['trend']);
                                        $barWidth = $achievement === null ? 0 : max(2, min(100, $achievement));
                                        $unit = $unitOf($planIndicator);
                                    @endphp
                                    <div
                                        wire:key="portfolio-indicator-{{ $planIndicator->id }}"
                                        class="group grid grid-cols-1 gap-3 px-4 py-4 transition-colors duration-150 hover:bg-board-head @lg/directory:grid-cols-[1fr_auto] @lg/directory:items-center @lg/directory:gap-6"
                                    >
                                        <div class="min-w-0">
                                            <p class="type-meta text-secondary-medium dark:text-secondary-soft">
                                                {{ $sourceLabel($planIndicator) }} · {{ $planIndicator->period->label() }} · {{ trans_choice('common.count_report', $indicatorGroup['reports']->count(), ['count' => $indicatorGroup['reports']->count()]) }}
                                            </p>
                                            <p class="truncate text-base font-bold">{{ $planIndicator->indicator->name }}</p>

                                            <div class="mt-2.5 flex items-center gap-3">
                                                <div
                                                    class="h-1.5 w-full max-w-64 overflow-hidden rounded-full bg-secondary-soft dark:bg-secondary-medium"
                                                    role="progressbar"
                                                    aria-valuemin="0"
                                                    aria-valuemax="100"
                                                    aria-valuenow="{{ $barWidth }}"
                                                    aria-label="{{ __('reports.achievement') }}"
                                                >
                                                    <div class="h-full rounded-full {{ $achievementBarClass($achievement) }}" style="width: {{ $barWidth }}%"></div>
                                                </div>

                                                @if ($points !== null)
                                                    <svg viewBox="0 0 100 28" preserveAspectRatio="none" class="h-5 w-16 shrink-0" aria-hidden="true">
                                                        <polyline
                                                            points="{{ $points }}"
                                                            fill="none"
                                                            stroke-width="2.5"
                                                            stroke-linecap="round"
                                                            stroke-linejoin="round"
                                                            class="{{ str_replace('bg-', 'stroke-', $achievementBarClass($achievement)) }}"
                                                        />
                                                    </svg>
                                                @endif
                                            </div>
                                        </div>

                                        <div class="flex items-center justify-between gap-4 @lg/directory:flex-col @lg/directory:items-end @lg/directory:justify-center @lg/directory:gap-1.5">
                                            <div class="text-end">
                                                <p class="text-xl leading-none font-extrabold tabular-nums {{ $achievementTextClass($achievement) }}">{{ $achievement === null ? '—' : $achievement.'%' }}</p>
                                                <p class="text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">
                                                    {{ __('reports.achieved_of_target', ['value' => $formatFigure($planIndicator->achievedValue()).$unit, 'target' => $formatFigure((float) $planIndicator->target).$unit]) }}
                                                </p>
                                            </div>

                                            <div class="flex shrink-0 items-center gap-1.5">
                                                <x-ui.enum-badge :case="$latest->status" />
                                                <x-ui.button tone="ghost" size="sm" icon="eye" class="size-9!" :href="route('reports.show', $latest)" wire:navigate :aria-label="__('common.view_name', ['name' => $planIndicator->indicator->name])" />
                                                @if ($latest->is_seen_by_meal)
                                                    <flux:tooltip :content="__('reports.seen_by_meal')">
                                                        <flux:icon.eye variant="micro" class="size-4 text-status-good" />
                                                    </flux:tooltip>
                                                @endif
                                                <flux:icon.chevron-right variant="micro" class="size-4 shrink-0 text-secondary-light transition-transform duration-200 group-hover:translate-x-0.5 rtl:-scale-x-100 rtl:group-hover:-translate-x-0.5" />
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-ui.panel>
    @else
        <x-ui.panel :subtitle="trans_choice('reports.count_matching_report', $this->reports->total(), ['count' => $this->reports->total()])">
            <x-slot:toolbar>
                @if ($filtersAreActive)
                    <flux:button size="sm" variant="ghost" class="text-secondary-medium! dark:text-secondary-soft!" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>
                @endif
            </x-slot:toolbar>

            @if ($this->reports->isEmpty())
                <x-ui.empty-state
                    icon="document-chart-bar"
                    :heading="$filtersAreActive ? __('reports.no_matching_reports') : __('reports.no_reports_yet')"
                    :body="$filtersAreActive ? __('common.change_the_search_or_filters_and_try_again') : __('reports.record_the_first_figure_against_a_target')"
                />
            @else
                <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($this->reports as $report)
                        @php
                            $rowTarget = $report->planIndicator;
                            $rowUnit = $unitOf($rowTarget);
                            // The target's own achievement (approved reports only), not this one figure against it.
                            $rowPercent = $rowTarget->percentAchieved();
                            $rowAchievement = $rowPercent === null ? null : (int) floor($rowPercent);
                            $rowIsOverdue = $report->isOverdue();
                        @endphp
                        <article class="group flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-4 transition-colors duration-150 hover:bg-board-head" wire:key="report-{{ $report->id }}">
                            <div class="min-w-0 flex-1">
                                <p class="flex flex-wrap items-center gap-x-1.5 type-meta text-secondary-medium dark:text-secondary-soft">
                                    <span>{{ $sourceLabel($rowTarget) }}</span>
                                    <span aria-hidden="true">·</span>
                                    <span>{{ $report->planned_date?->translatedFormat(__('formats.date')) }}</span>
                                    @if ($rowIsOverdue)
                                        <span class="inline-flex items-center gap-1 rounded-md border border-primary/30 bg-status-critical-soft px-1.5 text-[11px] font-semibold text-primary">
                                            <flux:icon.clock variant="micro" class="size-3" aria-hidden="true" />
                                            {{ __('reports.overdue') }}
                                        </span>
                                    @endif
                                </p>
                                <p class="flex items-center gap-1.5 text-base font-bold">
                                    @if (in_array($report->id, $this->unreadIds, true))
                                        <span class="size-2 shrink-0 rounded-full bg-primary" data-unread-dot="report-{{ $report->id }}" title="{{ __('notifications.has_unread') }}"><span class="sr-only">{{ __('notifications.has_unread') }}</span></span>
                                    @endif
                                    {{ $rowTarget->indicator->name }}
                                </p>
                                <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft">
                                    @if ($rowTarget->project !== null)
                                        <a href="{{ route('projects.show', $rowTarget->project) }}" wire:navigate class="hover:text-secondary-dark hover:underline dark:hover:text-white">{{ $rowTarget->project->name }}</a>
                                    @else
                                        {{ __('common.organisation_wide') }}
                                    @endif
                                    · {{ trans_choice('reports.count_file', $report->files_count, ['count' => $report->files_count]) }}
                                </p>
                            </div>

                            <div class="text-end">
                                <p class="text-xl leading-none font-extrabold tabular-nums">{{ $formatFigure((float) $report->value) }}{{ $rowUnit }}</p>
                                <p class="text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">
                                    {{ __('reports.of_target', ['target' => $formatFigure((float) $rowTarget->target).$rowUnit]) }}
                                    @if ($rowAchievement !== null)
                                        <span class="font-semibold {{ $achievementTextClass($rowAchievement) }}" title="{{ __('reports.target_achieved_approved_only') }}">· {{ __('reports.percent_achieved', ['percent' => $rowAchievement]) }}</span>
                                    @endif
                                </p>
                            </div>

                            <div class="flex flex-wrap items-center gap-1.5">
                                <x-ui.enum-badge :case="$report->status" />

                                @if ($report->is_seen_by_meal)
                                    <span class="rounded-md border border-secondary-light px-2 py-0.5 text-[11px] font-semibold text-secondary-medium dark:text-secondary-soft">{{ __('reports.meal') }}</span>
                                @endif
                            </div>

                            <x-ui.user-chip :user="$report->user" compact class="w-40" />

                            {{-- Only a draft can change or go: a reviewed or approved figure is the record. --}}
                            <div class="flex items-center gap-0.5">
                                <x-ui.button tone="ghost" size="sm" icon="eye" class="size-9!" :href="route('reports.show', $report)" wire:navigate :aria-label="__('common.view_name', ['name' => $rowTarget->indicator->name])" />
                                @if ($report->isEditable())
                                    <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openEditModal({{ $report->id }})" :aria-label="__('reports.edit_report')" />
                                    <x-ui.button tone="ghost" size="sm" icon="trash" class="size-9!" wire:click="confirmDeleteReport({{ $report->id }})" :aria-label="__('reports.delete_report')" data-test="delete-report-{{ $report->id }}" />
                                @else
                                    <flux:tooltip :content="__('reports.only_draft_reports_can_be_edited')">
                                        <span class="inline-flex size-9 cursor-not-allowed items-center justify-center text-secondary-light" aria-disabled="true">
                                            <flux:icon.lock-closed class="size-4" />
                                        </span>
                                    </flux:tooltip>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif

            <x-slot:footer>
                <flux:pagination :paginator="$this->reports" class="text-secondary-medium! dark:text-secondary-soft!" scroll-to="#report-directory-heading" />
            </x-slot:footer>
        </x-ui.panel>
    @endif

    <x-reports.form-modal
        :open="$showReportModal"
        :editing="$editingReportId !== null"
        :target-options="$showReportModal ? $this->targetOptions : []"
        :form-target="$showReportModal ? $this->formTarget : null"
        :window="$showReportModal ? $this->formWindow() : null"
    />
</section>
