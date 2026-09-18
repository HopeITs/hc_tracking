<?php

use App\Enums\ReportStatus;
use App\Models\PlanIndicator;
use App\Models\Report;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.reports')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $seenByMeal = '';

    #[Url(except: '')]
    public string $author = '';

    /**
     * Which shape the report feed renders in: a flat searchable list, or
     * reports grouped by project and indicator to surface the connections
     * between delivery and what has been reported against it.
     */
    #[Url(except: 'list')]
    public string $view = 'list';

    public bool $showReportModal = false;
    public ?int $editingReportId = null;

    public string $reportTargetId = '';
    public string $plannedDate = '';
    public string $periodStart = '';
    public string $periodEnd = '';
    public string $value = '';
    public string $narrativeNotes = '';
    public string $executiveSummary = '';
    public string $keyAchievements = '';
    public string $challenges = '';
    public string $lessonsLearned = '';
    public string $nextPeriodPlans = '';
    public string $submitDate = '';

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

    public function clearFilters(): void
    {
        $this->search = '';
        $this->status = '';
        $this->seenByMeal = '';
        $this->author = '';
        $this->resetPage();
    }

    /**
     * Reports matching the search and author filters only.
     *
     * Status and MEAL visibility are deliberately left off this base query: it
     * is shared by the paginated list (which applies them) and the portfolio
     * pulse (which counts every status and both MEAL states side by side, so
     * the strip stays a full breakdown rather than shrinking to whichever
     * status is currently selected).
     *
     * @return Builder<Report>
     */
    private function scopedReports(): Builder
    {
        $search = trim($this->search);

        return Report::query()
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('narrative_report_notes', 'like', '%'.$search.'%')
                        ->orWhereHas(
                            'planIndicator.indicator',
                            fn ($query) => $query->where('name', 'like', '%'.$search.'%')
                        );
                });
            })
            ->when($this->author !== '', fn ($query) => $query->where('user_id', (int) $this->author));
    }

    /** @return LengthAwarePaginator<int, Report> */
    #[Computed]
    public function reports(): LengthAwarePaginator
    {
        return $this->scopedReports()
            ->select(['id', 'plan_indicator_id', 'user_id', 'status', 'planned_date', 'value', 'submit_date', 'is_seen_by_meal'])
            ->with([
                'planIndicator:id,indicator_id,target,period,source_type,source_id,project_id',
                'planIndicator.indicator:id,name,measure',
                'planIndicator.project:id,name',
                'user:id,name,email',
            ])
            ->withCount('files')
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->when($this->seenByMeal === 'seen', fn ($query) => $query->where('is_seen_by_meal', true))
            ->when($this->seenByMeal === 'unseen', fn ($query) => $query->where('is_seen_by_meal', false))
            ->orderByDesc('planned_date')
            ->orderByDesc('id')
            ->paginate(15);
    }

    /**
     * The portfolio pulse: how the reports matching search/author break down
     * by status and MEAL visibility, plus the average achievement across every
     * reported figure that carries a positive target.
     *
     * Each contributing report's own achievement is capped at 150% before
     * averaging, so one wildly over-achieved figure cannot single-handedly
     * drag the portfolio average somewhere meaningless.
     *
     * @return array{total: int, statusCounts: SupportCollection<string, int>, seen: int, unseen: int, avgAchievement: int|null}
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

        $achievementRows = $this->scopedReports()
            ->join('plan_indicators', 'plan_indicators.id', '=', 'reports.plan_indicator_id')
            ->where('plan_indicators.target', '>', 0)
            ->select('reports.value as value', 'plan_indicators.target as target')
            ->get();

        $avgAchievement = $achievementRows->isEmpty()
            ? null
            : (int) round($achievementRows->avg(fn ($row) => min(150.0, ((float) $row->value / (float) $row->target) * 100)));

        return [
            'total' => $total,
            'statusCounts' => $statusCounts,
            'seen' => $total - $unseen,
            'unseen' => $unseen,
            'avgAchievement' => $avgAchievement,
        ];
    }

    /**
     * Reports grouped by project, then by indicator, for the portfolio view.
     *
     * Both levels sort worst-achievement-first: a manager opening the
     * portfolio should see what needs attention before anything else,
     * without having to hunt for it. A project or indicator with no scorable
     * achievement (no positive target) sorts after every scored one, then
     * alphabetically, so it is never mistaken for a top performer.
     *
     * Only queried when that view is active: the list view's paginated
     * `reports()` stays the cheap default. Every filter the list respects
     * (search, status, MEAL visibility, author) applies here too, so switching
     * views never changes what is being looked at, only how it is shaped.
     *
     * @return SupportCollection<int, array{project: \App\Models\Project|null, reportCount: int, avgAchievement: int|null, indicators: SupportCollection<int, array{planIndicator: PlanIndicator, reports: SupportCollection<int, Report>, latest: Report, achievement: int|null, trend: array<int, float>}>}>
     */
    #[Computed]
    public function portfolio(): SupportCollection
    {
        if ($this->view !== 'portfolio') {
            return collect();
        }

        $reports = $this->scopedReports()
            ->select(['id', 'plan_indicator_id', 'user_id', 'status', 'planned_date', 'value', 'submit_date', 'is_seen_by_meal'])
            ->with([
                'planIndicator:id,indicator_id,target,period,source_type,project_id',
                'planIndicator.indicator:id,name,measure',
                'planIndicator.project:id,name',
            ])
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->when($this->seenByMeal === 'seen', fn ($query) => $query->where('is_seen_by_meal', true))
            ->when($this->seenByMeal === 'unseen', fn ($query) => $query->where('is_seen_by_meal', false))
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
                        $latest = $rows->first();
                        $target = (float) $planIndicator->target;

                        return [
                            'planIndicator' => $planIndicator,
                            'reports' => $rows->values(),
                            'latest' => $latest,
                            // Oldest first, for a sparkline that reads left-to-right in time.
                            'trend' => $rows->sortBy('planned_date')->pluck('value')->map(fn ($value): float => (float) $value)->values()->all(),
                            'achievement' => $target > 0 ? (int) floor(((float) $latest->value / $target) * 100) : null,
                        ];
                    })
                    // A stable sort: the alphabetical pass runs first so ties in
                    // achievement (including every unscored indicator) fall back
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

    /** @return Collection<int, PlanIndicator> */
    #[Computed]
    public function targetOptions(): Collection
    {
        return PlanIndicator::query()
            ->select(['id', 'indicator_id', 'target', 'period'])
            ->with('indicator:id,name')
            ->orderByDesc('id')
            ->get();
    }

    /** @return Collection<int, User> */
    #[Computed]
    public function authorOptions(): Collection
    {
        return User::query()->select(['id', 'name', 'email'])->orderBy('name')->orderBy('id')->get();
    }

    public function openCreateModal(): void
    {
        $this->resetReportForm();
        $this->showReportModal = true;
    }

    public function openEditModal(int $reportId): void
    {
        $report = Report::query()
            ->select([
                'id', 'status', 'plan_indicator_id', 'planned_date', 'period_start', 'period_end', 'value',
                'narrative_report_notes', 'executive_summary', 'key_achievements', 'challenges',
                'lessons_learned', 'next_period_plans', 'submit_date',
            ])
            ->findOrFail($reportId);

        if (! $report->isEditable()) {
            Flux::toast(
                variant: 'danger',
                text: __('reports.only_draft_reports_can_be_edited_return_it_to_draft_on_the_r'),
            );

            return;
        }

        $this->resetValidation();
        $this->editingReportId = $report->id;
        $this->reportTargetId = (string) $report->plan_indicator_id;
        $this->plannedDate = $report->planned_date?->toDateString() ?? '';
        $this->periodStart = $report->period_start?->toDateString() ?? '';
        $this->periodEnd = $report->period_end?->toDateString() ?? '';
        $this->value = (string) $report->value;
        $this->narrativeNotes = $report->narrative_report_notes ?? '';
        $this->executiveSummary = $report->executive_summary ?? '';
        $this->keyAchievements = $report->key_achievements ?? '';
        $this->challenges = $report->challenges ?? '';
        $this->lessonsLearned = $report->lessons_learned ?? '';
        $this->nextPeriodPlans = $report->next_period_plans ?? '';
        $this->submitDate = $report->submit_date?->toDateString() ?? '';
        $this->showReportModal = true;
    }

    public function saveReport(): void
    {
        $this->narrativeNotes = trim($this->narrativeNotes);
        $this->executiveSummary = trim($this->executiveSummary);
        $this->keyAchievements = trim($this->keyAchievements);
        $this->challenges = trim($this->challenges);
        $this->lessonsLearned = trim($this->lessonsLearned);
        $this->nextPeriodPlans = trim($this->nextPeriodPlans);

        $isCreate = $this->editingReportId === null;

        $report = $isCreate
            ? new Report
            : Report::query()->select(['id', 'status', 'user_id'])->findOrFail($this->editingReportId);

        if (! $isCreate && ! $report->isEditable()) {
            $this->showReportModal = false;
            $this->resetReportForm();

            Flux::toast(
                variant: 'danger',
                text: __('reports.only_draft_reports_can_be_edited_return_it_to_draft_on_the_r'),
            );

            return;
        }

        // periodEnd's after_or_equal comparison is only meaningful once
        // periodStart is actually set — an empty periodStart cannot be parsed
        // as a date, so the rule is added only when there is something to
        // compare against.
        $periodEndRules = ['nullable', 'date'];

        if ($this->periodStart !== '') {
            $periodEndRules[] = 'after_or_equal:periodStart';
        }

        $validated = $this->validate([
            'reportTargetId' => ['required', 'integer', Rule::exists(PlanIndicator::class, 'id')],
            'plannedDate' => ['required', 'date'],
            'periodStart' => ['nullable', 'date'],
            'periodEnd' => $periodEndRules,
            'value' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'narrativeNotes' => ['nullable', 'string'],
            'executiveSummary' => ['nullable', 'string'],
            'keyAchievements' => ['nullable', 'string'],
            'challenges' => ['nullable', 'string'],
            'lessonsLearned' => ['nullable', 'string'],
            'nextPeriodPlans' => ['nullable', 'string'],
            'submitDate' => ['nullable', 'date'],
        ], [
            'reportTargetId.required' => __('reports.select_the_target_this_reports_against'),
            'plannedDate.required' => __('reports.set_the_period_this_figure_covers'),
            'value.required' => __('reports.enter_the_reported_value'),
            'value.min' => __('reports.a_reported_value_cannot_be_negative'),
            'value.max' => __('reports.the_reported_value_is_too_large_to_record'),
            'periodEnd.after_or_equal' => __('reports.the_narrative_window_end_cannot_be_before_its_start'),
        ]);

        $attributes = [
            'plan_indicator_id' => (int) $validated['reportTargetId'],
            'planned_date' => $validated['plannedDate'],
            'period_start' => $validated['periodStart'] === '' ? null : $validated['periodStart'],
            'period_end' => $validated['periodEnd'] === '' ? null : $validated['periodEnd'],
            'value' => $validated['value'],
            'narrative_report_notes' => $validated['narrativeNotes'] === '' ? null : $validated['narrativeNotes'],
            'executive_summary' => $validated['executiveSummary'] === '' ? null : $validated['executiveSummary'],
            'key_achievements' => $validated['keyAchievements'] === '' ? null : $validated['keyAchievements'],
            'challenges' => $validated['challenges'] === '' ? null : $validated['challenges'],
            'lessons_learned' => $validated['lessonsLearned'] === '' ? null : $validated['lessonsLearned'],
            'next_period_plans' => $validated['nextPeriodPlans'] === '' ? null : $validated['nextPeriodPlans'],
            'submit_date' => $validated['submitDate'] === '' ? null : $validated['submitDate'],
        ];

        // Authorship is set once. reports.user_id RESTRICTs on delete because
        // who reported a figure is part of the record.
        // status is deliberately absent here: it is written only by the
        // explicit workflow actions on the detail page (submitForReview,
        // approve, returnToDraft), never from this form.
        if ($isCreate) {
            $attributes['user_id'] = (int) auth()->id();
        }

        $report->fill($attributes)->save();

        $this->showReportModal = false;
        $this->resetReportForm();
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $isCreate ? __('reports.report_recorded') : __('reports.report_updated'),
        );
    }

    public function closeReportModal(): void
    {
        $this->showReportModal = false;
        $this->resetReportForm();
    }

    protected function resetReportForm(): void
    {
        $this->resetValidation();
        $this->editingReportId = null;
        $this->reportTargetId = '';
        $this->plannedDate = '';
        $this->periodStart = '';
        $this->periodEnd = '';
        $this->value = '';
        $this->narrativeNotes = '';
        $this->executiveSummary = '';
        $this->keyAchievements = '';
        $this->challenges = '';
        $this->lessonsLearned = '';
        $this->nextPeriodPlans = '';
        $this->submitDate = '';
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $status !== '' || $seenByMeal !== '' || $author !== '';
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

    $sourceLabel = fn (string $sourceType): string => $sourceType === 'annual_plan' ? __('common.annual_plan') : __('common.proposal');

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

    $approvedCount = (int) ($insights['statusCounts'][ReportStatus::Approved->value] ?? 0);

    $figures = [
        ['key' => 'total', 'label' => __('reports.total_reports'), 'value' => $insights['total'], 'suffix' => '', 'sub' => null, 'tone' => ''],
        ['key' => 'achievement', 'label' => __('reports.avg_achievement'), 'value' => $insights['avgAchievement'] ?? 0, 'suffix' => $insights['avgAchievement'] === null ? '' : '%', 'sub' => $insights['avgAchievement'] === null ? __('ui.no_records') : null, 'tone' => $achievementTextClass($insights['avgAchievement'])],
        ['key' => 'awaiting', 'label' => __('reports.awaiting_meal_review'), 'value' => $insights['unseen'], 'suffix' => '', 'sub' => __('reports.of_total', ['total' => $insights['total']]), 'tone' => $insights['unseen'] > 0 ? 'text-status-warn' : 'text-status-good'],
        ['key' => 'approved', 'label' => __('reports.approved_reports'), 'value' => $approvedCount, 'suffix' => '', 'sub' => __('reports.of_total', ['total' => $insights['total']]), 'tone' => 'text-status-good'],
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
                                        {{ __('reports.avg_achievement') }} {{ $group['avgAchievement'] }}%
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
                                    @endphp
                                    <a
                                        href="{{ route('reports.show', $latest) }}"
                                        wire:navigate
                                        wire:key="portfolio-indicator-{{ $planIndicator->id }}"
                                        class="group grid grid-cols-1 gap-3 px-4 py-4 transition-colors duration-150 hover:bg-board-head focus-visible:bg-board-head focus-visible:outline-none @lg/directory:grid-cols-[1fr_auto] @lg/directory:items-center @lg/directory:gap-6"
                                    >
                                        <div class="min-w-0">
                                            <p class="type-meta text-secondary-medium dark:text-secondary-soft">
                                                {{ $sourceLabel($planIndicator->source_type) }} · {{ $planIndicator->period->label() }} · {{ trans_choice('common.count_report', $indicatorGroup['reports']->count(), ['count' => $indicatorGroup['reports']->count()]) }}
                                            </p>
                                            <p class="truncate text-base font-bold group-hover:underline">{{ $planIndicator->indicator->name }}</p>

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
                                                    {{ __('reports.latest_of_target', ['value' => number_format((float) $latest->value, 2), 'target' => number_format((float) $planIndicator->target, 2)]) }}
                                                </p>
                                            </div>

                                            <div class="flex shrink-0 items-center gap-1.5">
                                                <x-ui.enum-badge :case="$latest->status" />
                                                @if ($latest->is_seen_by_meal)
                                                    <flux:tooltip :content="__('reports.seen_by_meal')">
                                                        <flux:icon.eye variant="micro" class="size-4 text-status-good" />
                                                    </flux:tooltip>
                                                @endif
                                                <flux:icon.chevron-right variant="micro" class="size-4 shrink-0 text-secondary-light transition-transform duration-200 group-hover:translate-x-0.5 rtl:-scale-x-100 rtl:group-hover:-translate-x-0.5" />
                                            </div>
                                        </div>
                                    </a>
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
                            $rowTarget = (float) $report->planIndicator->target;
                            $rowAchievement = $rowTarget > 0 ? (int) floor(((float) $report->value / $rowTarget) * 100) : null;
                        @endphp
                        <article class="group flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-4 transition-colors duration-150 hover:bg-board-head" wire:key="report-{{ $report->id }}">
                            <div class="min-w-0 flex-1">
                                <p class="type-meta text-secondary-medium dark:text-secondary-soft">
                                    {{ $sourceLabel($report->planIndicator->source_type) }} · {{ $report->planned_date?->translatedFormat(__('formats.date')) }}
                                </p>
                                <a href="{{ route('reports.show', $report) }}" wire:navigate class="block text-base font-bold group-hover:underline">
                                    {{ $report->planIndicator->indicator->name }}
                                </a>
                                <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft">
                                    @if ($report->planIndicator->project !== null)
                                        <a href="{{ route('projects.show', $report->planIndicator->project) }}" wire:navigate class="hover:text-secondary-dark hover:underline dark:hover:text-white">{{ $report->planIndicator->project->name }}</a>
                                    @else
                                        {{ __('common.organisation_wide') }}
                                    @endif
                                    · {{ trans_choice('reports.count_file', $report->files_count, ['count' => $report->files_count]) }}
                                </p>
                            </div>

                            <div class="text-end">
                                <p class="text-xl leading-none font-extrabold tabular-nums">{{ number_format((float) $report->value, 2) }}</p>
                                <p class="text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">
                                    {{ __('reports.of_target', ['target' => number_format((float) $report->planIndicator->target, 2)]) }}
                                    @if ($rowAchievement !== null)
                                        <span class="font-semibold {{ $achievementTextClass($rowAchievement) }}">· {{ $rowAchievement }}%</span>
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

                            {{-- No delete: a measurement is the record. --}}
                            @if ($report->isEditable())
                                <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openEditModal({{ $report->id }})" :aria-label="__('reports.edit_report')" />
                            @else
                                <flux:tooltip :content="__('reports.only_draft_reports_can_be_edited')">
                                    <span class="inline-flex size-9 cursor-not-allowed items-center justify-center text-secondary-light" aria-disabled="true">
                                        <flux:icon.lock-closed class="size-4" />
                                    </span>
                                </flux:tooltip>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif

            <x-slot:footer>
                <flux:pagination :paginator="$this->reports" class="text-secondary-medium! dark:text-secondary-soft!" scroll-to="#report-directory-heading" />
            </x-slot:footer>
        </x-ui.panel>
    @endif

    <flux:modal wire:model="showReportModal" class="w-full max-w-2xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        @if ($showReportModal)
        <form wire:submit="saveReport" class="space-y-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ $editingReportId === null ? __('reports.record_report') : __('reports.edit_report') }}</flux:heading>
                <flux:subheading>{{ __('reports.status_and_meal_review_are_recorded_on_the_report_page_not_h') }}</flux:subheading>
            </div>

            <x-ui.select wire:model="reportTargetId" :label="__('reports.target')" required>
                <flux:select.option value="">{{ __('reports.select_a_target') }}</flux:select.option>
                @foreach ($this->targetOptions as $targetOption)
                    <flux:select.option :value="$targetOption->id" :wire:key="'rpt-target-'.$targetOption->id">
                        {{ $targetOption->indicator->name }} — {{ number_format((float) $targetOption->target, 2) }}
                    </flux:select.option>
                @endforeach
            </x-ui.select>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.input wire:model="plannedDate" :label="__('reports.period_covered')" type="date" required />
                <x-ui.input wire:model="value" :label="__('reports.reported_value')" type="number" step="0.01" required />
                <x-ui.input wire:model="submitDate" :label="__('reports.submitted_on')" type="date" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input wire:model="periodStart" :label="__('reports.narrative_window_start')" type="date" />
                <x-ui.input wire:model="periodEnd" :label="__('reports.narrative_window_end')" type="date" />
            </div>

            <flux:textarea wire:model="narrativeNotes" :label="__('reports.narrative_notes')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" />

            <flux:textarea wire:model="executiveSummary" :label="__('reports.executive_summary')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" />
            <flux:textarea wire:model="keyAchievements" :label="__('reports.key_achievements')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" />
            <flux:textarea wire:model="challenges" :label="__('reports.challenges')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" />
            <flux:textarea wire:model="lessonsLearned" :label="__('reports.lessons_learned')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" />
            <flux:textarea wire:model="nextPeriodPlans" :label="__('reports.next_period_plans')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" />

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="closeReportModal">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-report-button">
                    {{ $editingReportId === null ? __('reports.record_report') : __('common.save_changes') }}
                </x-ui.button>
            </div>
        </form>
        @endif
    </flux:modal>
</section>
