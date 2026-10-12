<?php

use App\Support\Like;
use App\Enums\ReportStatus;
use App\Livewire\Concerns\ManagesReportForm;
use App\Livewire\Concerns\Reports\ReadsReportPortfolio;
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
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.reports')] class extends Component {
    use ManagesReportForm;
    use ReadsReportPortfolio;
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
        if (request()->boolean('create') && Gate::allows('create', Report::class)) {
            $this->openCreateModal($this->target === '' ? null : (int) $this->target);
        }

        $edit = request()->integer('edit');
        $editing = $edit > 0 ? Report::query()->find($edit) : null;

        // A deep link only opens someone else's draft for its author; anyone
        // else lands on the list.
        if ($editing !== null && (! $editing->isEditable() || Gate::allows('update', $editing))) {
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
                $like = Like::contains($search);

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
        'critical' => 'text-primary dark:text-brand-text',
        default => 'text-secondary-dark dark:text-ink',
    };

    $achievementBarClass = fn (?int $value): string => match ($achievementTone($value)) {
        'good' => 'bg-status-good',
        'critical' => 'bg-primary dark:bg-status-critical',
        default => 'bg-secondary-dark dark:bg-ink-muted',
    };

    $achievementBadgeClass = fn (?int $value): string => match ($achievementTone($value)) {
        'good' => 'bg-status-good-soft text-status-good',
        'critical' => 'bg-status-critical-soft text-primary dark:text-brand-text',
        default => 'bg-secondary-soft text-secondary-dark dark:bg-surface-2 dark:text-ink',
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
        ['key' => 'overdue', 'label' => __('reports.overdue_drafts'), 'value' => $insights['overdue'], 'suffix' => '', 'sub' => __('reports.drafts_past_the_period_they_cover'), 'tone' => $insights['overdue'] > 0 ? 'text-primary dark:text-brand-text' : 'text-status-good'],
    ];
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-6 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink" aria-labelledby="report-directory-heading">
    <x-ui.page-header
        heading-id="report-directory-heading"
        :title="__('common.reports')"
        :count="$insights['total']"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('reports.search_reports')" :placeholder="__('reports.indicator_or_narrative')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.header-filter-select :label="__('reports.reported_by')" wire:model.live="author" :value="$author">
                <option value="">{{ __('common.anyone') }}</option>
                @foreach ($this->authorOptions as $authorOption)
                    <option value="{{ $authorOption->id }}">{{ $authorOption->name }}</option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('common.project')" wire:model.live="project" :value="$project">
                <option value="">{{ __('targets.any_project') }}</option>
                <option value="none">{{ __('common.organisation_wide') }}</option>
                @foreach ($this->projectOptions as $projectOption)
                    <option value="{{ $projectOption->id }}">{{ $projectOption->name }}</option>
                @endforeach
            </x-ui.header-filter-select>
        </x-slot:filters>

        @can('create', App\Models\Report::class)
            <x-slot:actions>
                <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-report-button">
                    {{ __('reports.record_report') }}
                </x-ui.button>
            </x-slot:actions>
        @endcan

        <x-slot:summary>
            <div class="flex flex-col gap-6">
                {{-- The portfolio's figures, set directly on the page rather than boxed into cards: the numbers are the point. Dividers only apply once the grid is a single row — grid-cols-2 wrapping and a manual border produced an orphaned line at in-between widths. --}}
                <div class="grid grid-cols-2 gap-x-6 gap-y-6 border-y border-secondary-soft py-6 @xl/directory:grid-cols-4 @xl/directory:divide-x @xl/directory:divide-secondary-soft dark:border-line @xl/directory:dark:divide-line">
                    @foreach ($figures as $index => $figure)
                        <div
                            class="logframe-reveal @xl/directory:ps-6 @xl/directory:first:ps-0"
                            style="--logframe-order: {{ $index }}"
                            wire:key="figure-{{ $figure['key'] }}"
                        >
                            <p class="type-label text-secondary-medium dark:text-ink-muted">{{ $figure['label'] }}</p>
                            <p
                                class="mt-1.5 text-4xl leading-none font-extrabold tabular-nums {{ $figure['tone'] }}"
                                x-data="countUp({{ (int) $figure['value'] }})"
                                x-text="display + '{{ $figure['suffix'] }}'"
                            >{{ $figure['value'] }}{{ $figure['suffix'] }}</p>
                            @if ($figure['sub'] !== null)
                                <p class="mt-1 text-xs text-secondary-medium dark:text-ink-muted">{{ $figure['sub'] }}</p>
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
                                'border-primary bg-primary dark:bg-ink dark:text-surface-0 dark:border-ink text-white' => $overdue !== '',
                                'border-secondary-soft bg-white text-secondary-medium hover:border-secondary-light hover:text-secondary-dark dark:border-line dark:bg-surface-1 dark:text-ink-muted dark:hover:text-ink' => $overdue === '',
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
            <button type="button" wire:click="clearTarget" class="inline-flex max-w-full items-center gap-1.5 rounded-full border border-secondary-dark bg-secondary-dark px-3 py-1 text-xs font-semibold text-white hover:opacity-90 dark:border-ink dark:bg-ink dark:text-surface-0" data-test="target-scope-chip">
                <flux:icon.flag variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
                <span class="truncate">{{ $this->scopedTargetLabel }}</span>
                <flux:icon.x-mark variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
                <span class="sr-only">{{ __('common.reset_filters') }}</span>
            </button>
            @can('create', App\Models\Report::class)
                <x-ui.button size="sm" icon="plus" wire:click="openCreateModal({{ (int) $target }})">{{ __('reports.record_another_for_this_target') }}</x-ui.button>
            @endcan
        </div>
    @endif

    @if ($view === 'portfolio')
        @include('pages.reports._index-portfolio')
    @else
        @include('pages.reports._index-list')
    @endif

    <x-reports.form-modal
        :open="$showReportModal"
        :editing="$editingReportId !== null"
        :target-options="$showReportModal ? $this->targetOptions : []"
        :form-target="$showReportModal ? $this->formTarget : null"
        :window="$showReportModal ? $this->formWindow() : null"
    />
</section>
