<?php

use App\Enums\IndicatorMeasure;
use App\Enums\Period;
use App\Enums\RagStatus;
use App\Enums\ReportStatus;
use App\Models\AnnualPlan;
use App\Models\Indicator;
use App\Models\IndicatorProgress;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Result;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.indicators')] class extends Component {
    use WithPagination;

    /**
     * How many indicator cards or rows one page holds.
     *
     * Twelve divides cleanly by the card grid's 1/2/3 columns, so the last row
     * is never a lone orphan card at any breakpoint.
     */
    private const PER_PAGE = 12;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $result = '';

    #[Url(except: '')]
    public string $measure = '';

    #[Url(except: '')]
    public string $program = '';

    /** One of the RagStatus values, or '' for every rating including unrated. */
    #[Url(except: '')]
    public string $rag = '';

    /**
     * Narrows the page to the indicators that need a hand.
     *
     * '' shows everything, 'offtrack' the amber and red ones, 'unmeasured'
     * those carrying no reading at all, 'stale' those whose last reading is
     * older than their own frequency, 'untargeted' those with no target to be
     * measured against.
     */
    #[Url(except: '')]
    public string $focus = '';

    /** 'cards' draws the trajectory cards, 'table' lists every figure in one grid. */
    #[Url(except: 'cards')]
    public string $view = 'cards';

    public bool $showIndicatorModal = false;
    #[Locked]
    public ?int $editingIndicatorId = null;
    public string $name = '';
    public string $indicatorResultId = '';
    public string $indicatorMeasure = 'numeric';
    public string $unit = '';
    public string $baseline = '';
    public string $target = '';
    public string $frequency = 'quarterly';
    public string $indicatorProjectId = '';

    public bool $showDeleteModal = false;
    #[Locked]
    public ?int $deletingIndicatorId = null;

    public bool $showProgressModal = false;
    #[Locked]
    public ?int $progressIndicatorId = null;
    #[Locked]
    public ?int $editingReadingId = null;
    #[Locked]
    public ?int $deletingReadingId = null;
    public string $progressDate = '';
    public string $progressValue = '';
    public string $progressNotes = '';
    public string $progressSourceOfVerification = '';

    public bool $showInspectModal = false;

    #[Locked]
    public ?int $inspectingIndicatorId = null;

    /**
     * Deep links from the rest of the chain.
     *
     * `?create=<result id>` opens the indicator form already tied to that
     * statement (the results framework's "Add indicator"); `?inspect=<id>`
     * opens one indicator's chain. Read once here rather than bound with #[Url],
     * so closing the dialog and refreshing does not reopen it.
     */
    public function mount(): void
    {
        $createForResult = request()->integer('create');

        if ($createForResult > 0 && Gate::allows('indicators.create') && Result::query()->whereKey($createForResult)->exists()) {
            $this->openCreateModal();
            $this->indicatorResultId = (string) $createForResult;
        }

        $inspect = request()->integer('inspect');

        if ($inspect > 0 && Indicator::query()->whereKey($inspect)->exists()) {
            $this->openInspectModal($inspect);
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedResult(): void
    {
        $this->resetPage();
    }

    public function updatedMeasure(): void
    {
        $this->resetPage();
    }

    public function updatedProgram(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->result = '';
        $this->measure = '';
        $this->program = '';
        $this->rag = '';
        $this->focus = '';
        $this->resetPage();
    }

    /**
     * Toggle one of the attention chips; clicking the active one clears it.
     */
    public function setFocus(string $focus): void
    {
        $this->focus = $this->focus === $focus ? '' : $focus;
        $this->resetPage();
    }

    /**
     * Toggle the rating filter from the distribution bar or its legend.
     */
    public function setRag(string $rag): void
    {
        $this->rag = $this->rag === $rag ? '' : $rag;
        $this->resetPage();
    }

    /**
     * Every indicator with the whole chain hanging off it: the result it
     * measures and that result's program, the project it is scoped to, the
     * readings behind it, the targets committed against it, the plan or
     * proposal each target was committed in, the donors behind that proposal,
     * and the reports filed.
     *
     * Read once and resolved in memory afterwards, exactly as
     * results/⚡index.blade.php does: an indicator set is small (one handful
     * per result, and a logframe is a handful of results per program), every
     * figure on this page — achievement, rating, staleness, the rating
     * distribution, the four attention counts — needs the readings anyway, and
     * doing it this way holds the page at a flat query count however it is
     * filtered, sorted or paged.
     *
     * @return Collection<int, Indicator>
     */
    #[Computed]
    public function allIndicators(): Collection
    {
        return Indicator::query()
            ->select(['id', 'name', 'result_id', 'project_id', 'measure', 'unit', 'baseline', 'target', 'frequency', 'updated_at'])
            ->with([
                // parent_id is selected so the statement's lineage can be walked.
                'result:id,statement,type,program_id,parent_id',
                // program_id is nullable on results, so a result can outlive its program.
                'result.program:id',
                // A logframe is three levels at most, so walking up costs a
                // fixed couple of queries for the whole page.
                'result.parentRecursive',
                'project:id,name',
                'progress' => fn ($query) => $query
                    ->select(['id', 'indicator_id', 'date', 'value', 'notes', 'source_of_verification', 'recorded_by'])
                    ->with('recorder:id,hr_user_id'),
                'planIndicators' => fn ($query) => $query
                    ->select(['id', 'indicator_id', 'source_type', 'source_id', 'target', 'project_id', 'period'])
                    ->with([
                        'project:id,name,program_id',
                        // The reported actuals themselves, not just how many
                        // there are: what was told to the donor is the figure
                        // the funding side is asked about.
                        'reports' => fn ($query) => $query
                            ->select(['id', 'plan_indicator_id', 'status', 'planned_date', 'period_start', 'period_end', 'value', 'submit_date'])
                            ->orderByDesc('planned_date')
                            ->orderByDesc('id'),
                    ])
                    ->orderBy('id'),
                // source_type/source_id carry no foreign key, so morphTo can
                // resolve to null; the view renders that explicitly.
                'planIndicators.source' => fn (MorphTo $morphTo) => $morphTo->morphWith([
                    AnnualPlan::class => [],
                    Proposal::class => ['donorNames:id,name', 'projects:id'],
                ]),
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * How a target reads on the page: where it was committed, by whom, against
     * which project, what has actually been reported against it, and whether
     * any of that reporting is late.
     *
     * `achieved` is PlanIndicator::achievedValue() — approved reports only, the
     * figure defensible to the donor — and `pending` what is reported but not
     * yet approved, drawn as a lighter band beside it. Every page reads a target
     * by that one rule.
     *
     * @return array{target: PlanIndicator, sourceType: string, sourceLabel: string|null, donors: list<string>, project: string|null, reports: int, approved: int, committed: float, achieved: float, pending: float, percent: float|null, status: string, overdue: int, issues: list<string>, reportRows: \Illuminate\Database\Eloquent\Collection<int, \App\Models\Report>}
     */
    protected function describeTarget(PlanIndicator $target, Indicator $indicator): array
    {
        $source = $target->source;
        $reports = $target->reports;

        // Already in memory; handing it over saves a query per target.
        $target->setRelation('indicator', $indicator);

        return [
            'target' => $target,
            'sourceType' => $target->source_type,
            // Null when the plan or proposal was deleted out from under it.
            'sourceLabel' => match (true) {
                $source instanceof AnnualPlan => __('common.fy_year', ['year' => $source->year]),
                $source instanceof Proposal => $source->name,
                default => null,
            },
            'donors' => $source instanceof Proposal
                ? $source->donorNames->pluck('name')->values()->all()
                : [],
            'project' => $target->project?->name,
            'reports' => $reports->count(),
            'approved' => $reports->where('status', ReportStatus::Approved)->count(),
            'committed' => (float) $target->target,
            'achieved' => $target->achievedValue(),
            'pending' => $target->pendingValue(),
            'percent' => $target->percentAchieved(),
            'status' => $target->achievementStatus(),
            // A report still in draft past the date it covers is the reporting
            // obligation the manager is actually late on.
            'overdue' => $reports->filter(fn (Report $report): bool => $report->isOverdue())->count(),
            'issues' => $target->chainIssues(),
            'reportRows' => $reports,
        ];
    }

    /**
     * The statements above this indicator's own, nearest first, root last.
     *
     * Walks the eager-loaded `parentRecursive` chain rather than `ancestors()`,
     * which reads `parent` and would fire a query per level per card.
     *
     * @return list<Result>
     */
    protected function lineageOf(Result $result): array
    {
        $lineage = [];
        $node = $result;

        while ($node->relationLoaded('parentRecursive') && ($node = $node->getRelation('parentRecursive')) !== null) {
            $lineage[] = $node;
        }

        return $lineage;
    }

    /**
     * How long an indicator may go unread before it counts as stale.
     *
     * An indicator promises a cadence; the number of days here is that promise
     * expressed as a deadline for the next reading.
     */
    protected function freshnessDays(Period $frequency): int
    {
        return match ($frequency) {
            Period::Monthly => 31,
            Period::Quarterly => 92,
            Period::SemiAnnual => 184,
            Period::Annual => 366,
        };
    }

    /**
     * Every indicator reduced to the figures the page draws, keyed by id.
     *
     * Achievement is rated against the calendar year — the same window
     * results/⚡index.blade.php uses — so a percentage means the same thing on
     * both pages.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function rows(): array
    {
        $windowStart = now()->startOfYear();
        $windowEnd = now()->endOfYear();
        $today = CarbonImmutable::today();
        $rows = [];

        foreach ($this->allIndicators as $indicator) {
            $readings = $indicator->progress;
            $latestReading = $readings->last();
            $previousReading = $readings->count() > 1 ? $readings[$readings->count() - 2] : null;
            $latest = $latestReading === null ? null : (float) $latestReading->value;
            $previous = $previousReading === null ? null : (float) $previousReading->value;

            $targetRows = [];
            $targetsAchieved = 0;
            $reports = 0;
            $approved = 0;
            $overdueReports = 0;
            $chainIssues = 0;
            $funders = [];

            foreach ($indicator->planIndicators as $target) {
                $described = $this->describeTarget($target, $indicator);
                $targetRows[] = $described;
                $targetsAchieved += $described['status'] === 'achieved' ? 1 : 0;
                $reports += $described['reports'];
                $approved += $described['approved'];
                $overdueReports += $described['overdue'];
                $chainIssues += $described['issues'] === [] ? 0 : 1;

                foreach ($described['donors'] as $donor) {
                    $funders[$donor] = ['label' => $donor, 'type' => 'donor'];
                }

                if ($described['donors'] === [] && $described['sourceLabel'] !== null) {
                    $funders[$described['sourceLabel']] = ['label' => $described['sourceLabel'], 'type' => $described['sourceType']];
                }
            }

            $daysSinceReading = $latestReading === null
                ? null
                : $latestReading->date->startOfDay()->diffInDays($today, false);

            $rows[$indicator->id] = [
                'indicator' => $indicator,
                'achieved' => $indicator->percentAchieved(),
                'rag' => $indicator->ragStatus($windowStart, $windowEnd),
                'baseline' => (float) ($indicator->baseline ?? 0),
                'targetValue' => $indicator->target === null ? null : (float) $indicator->target,
                'latest' => $latest,
                'latestDate' => $latestReading?->date,
                'previous' => $previous,
                'delta' => $latest === null || $previous === null ? null : $latest - $previous,
                'evidence' => $latestReading?->source_of_verification,
                'readings' => $readings->count(),
                'series' => $readings->map(fn (IndicatorProgress $reading): float => (float) $reading->value)->all(),
                // No reading at all is the most stale an indicator can be.
                'stale' => $daysSinceReading === null || $daysSinceReading > $this->freshnessDays($indicator->frequency),
                'daysSinceReading' => $daysSinceReading,
                'targets' => $targetRows,
                'targetsAchieved' => $targetsAchieved,
                'chainIssues' => $chainIssues,
                'reports' => $reports,
                'approved' => $approved,
                'overdueReports' => $overdueReports,
                'funders' => array_values($funders),
                'lineage' => $this->lineageOf($indicator->result),
            ];
        }

        return $rows;
    }

    /**
     * Whether one indicator survives the current filters.
     *
     * `$focus` is passed in so focusCounts() can ask the same question with a
     * different chip held, and the chip counts stay honest about what clicking
     * each one would actually show.
     *
     * @param  array<string, mixed>  $row
     */
    protected function matchesFilters(array $row, ?string $focus = null, ?string $rag = null): bool
    {
        $focus ??= $this->focus;
        $rag ??= $this->rag;
        $indicator = $row['indicator'];

        if ($this->result !== '' && $indicator->result_id !== (int) $this->result) {
            return false;
        }

        if ($this->measure !== '' && $indicator->measure->value !== $this->measure) {
            return false;
        }

        if ($this->program === 'none' && $indicator->result->program_id !== null) {
            return false;
        }

        if ($this->program !== '' && $this->program !== 'none' && $indicator->result->program_id !== (int) $this->program) {
            return false;
        }

        if ($rag === 'unrated' && $row['rag'] !== null) {
            return false;
        }

        if ($rag !== '' && $rag !== 'unrated' && $row['rag']?->value !== $rag) {
            return false;
        }

        if ($focus === 'offtrack' && ! in_array($row['rag'], [RagStatus::Amber, RagStatus::Red], true)) {
            return false;
        }

        if ($focus === 'unmeasured' && $row['readings'] > 0) {
            return false;
        }

        if ($focus === 'stale' && ! $row['stale']) {
            return false;
        }

        if ($focus === 'untargeted' && $row['targetValue'] !== null) {
            return false;
        }

        if ($focus === 'reporting' && $row['overdueReports'] === 0) {
            return false;
        }

        $search = trim($this->search);

        if ($search === '') {
            return true;
        }

        $needle = mb_strtolower($search);

        return str_contains(mb_strtolower($indicator->name), $needle)
            || str_contains(mb_strtolower($indicator->result->statement), $needle)
            || str_contains(mb_strtolower((string) $indicator->result->program?->name), $needle)
            || str_contains(mb_strtolower((string) $indicator->project?->name), $needle);
    }

    /**
     * The indicators the current filters leave on the page, in name order.
     *
     * @return BaseCollection<int, array<string, mixed>>
     */
    #[Computed]
    public function filteredRows(): BaseCollection
    {
        return collect($this->rows)
            ->filter(fn (array $row): bool => $this->matchesFilters($row))
            ->values();
    }

    /**
     * One page of the filtered indicators.
     *
     * Built by hand rather than by the query builder because the filters and
     * every derived figure are resolved in memory; see allIndicators().
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    #[Computed]
    public function pagedRows(): LengthAwarePaginator
    {
        $rows = $this->filteredRows;
        $page = max(1, (int) $this->getPage());

        return new LengthAwarePaginator(
            $rows->forPage($page, self::PER_PAGE)->values(),
            $rows->count(),
            self::PER_PAGE,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'page'],
        );
    }

    /**
     * The figures above the list: how much is being measured, how recently,
     * how far along it is, and how the ratings split.
     *
     * Counted across every indicator the filters leave, so the headline always
     * describes what is on screen rather than the whole database.
     *
     * Targets are counted achieved or not rather than summed: they span
     * different indicators, units and measures, so adding their values
     * together would produce a number that means nothing.
     *
     * @return array{indicators: int, results: int, readings: int, lastReading: CarbonImmutable|null, achieved: float|null, rated: int, rag: array<string, int>, unrated: int, targets: int, targetsAchieved: int, reports: int, approved: int, overdueReports: int, funders: list<array{label: string, type: string}>, elapsed: float}
     */
    #[Computed]
    public function overview(): array
    {
        $rows = $this->filteredRows;
        $rag = ['green' => 0, 'amber' => 0, 'red' => 0];
        $achievements = [];
        $readings = 0;
        $targets = 0;
        $targetsAchieved = 0;
        $reports = 0;
        $approved = 0;
        $overdueReports = 0;
        $unrated = 0;
        $funders = [];
        $lastReading = null;

        foreach ($rows as $row) {
            $readings += $row['readings'];
            $targets += count($row['targets']);
            $targetsAchieved += $row['targetsAchieved'];
            $reports += $row['reports'];
            $approved += $row['approved'];
            $overdueReports += $row['overdueReports'];

            if ($row['rag'] === null) {
                $unrated++;
            } else {
                $rag[$row['rag']->value]++;
            }

            if ($row['achieved'] !== null) {
                $achievements[] = $row['achieved'];
            }

            if ($row['latestDate'] !== null && ($lastReading === null || $row['latestDate']->greaterThan($lastReading))) {
                $lastReading = $row['latestDate'];
            }

            foreach ($row['funders'] as $funder) {
                $funders[$funder['label']] = $funder;
            }
        }

        $start = now()->startOfYear();
        $end = now()->endOfYear();
        $elapsed = (now()->getTimestamp() - $start->getTimestamp()) / max($end->getTimestamp() - $start->getTimestamp(), 1) * 100;

        return [
            'indicators' => $rows->count(),
            'results' => $rows->pluck('indicator.result_id')->unique()->count(),
            'readings' => $readings,
            'lastReading' => $lastReading,
            'achieved' => $achievements === [] ? null : array_sum($achievements) / count($achievements),
            'rated' => $rag['green'] + $rag['amber'] + $rag['red'],
            'rag' => $rag,
            'unrated' => $unrated,
            'targets' => $targets,
            'targetsAchieved' => $targetsAchieved,
            'reports' => $reports,
            'approved' => $approved,
            'overdueReports' => $overdueReports,
            'funders' => array_values($funders),
            'elapsed' => min(max($elapsed, 0.0), 100.0),
        ];
    }

    /**
     * How many indicators each attention chip would show.
     *
     * Counted with that chip held and the others released, so a chip never
     * promises a number the click cannot deliver.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function focusCounts(): array
    {
        $counts = [];

        foreach (['offtrack', 'stale', 'reporting', 'unmeasured', 'untargeted'] as $focus) {
            $counts[$focus] = collect($this->rows)
                ->filter(fn (array $row): bool => $this->matchesFilters($row, $focus))
                ->count();
        }

        return $counts;
    }

    /**
     * The indicator open in the chain inspector, if any.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function inspected(): ?array
    {
        return $this->inspectingIndicatorId === null
            ? null
            : ($this->rows[$this->inspectingIndicatorId] ?? null);
    }

    /**
     * Every reading on the inspected indicator, newest first.
     *
     * @return BaseCollection<int, array<string, mixed>>
     */
    #[Computed]
    public function inspectedReadings(): BaseCollection
    {
        $row = $this->inspected;

        if ($row === null) {
            return collect();
        }

        $readings = $row['indicator']->progress;

        return $readings
            ->map(fn (IndicatorProgress $reading, int $index): array => [
                'reading' => $reading,
                'value' => (float) $reading->value,
                'delta' => $index === 0 ? null : (float) $reading->value - (float) $readings[$index - 1]->value,
            ])
            ->reverse()
            ->values();
    }

    /** @return Collection<int, Result> */
    #[Computed]
    public function resultOptions(): Collection
    {
        return Result::query()->select(['id', 'statement', 'type'])->orderBy('id')->get();
    }

    /** @return Collection<int, Program> */
    #[Computed]
    public function programOptions(): Collection
    {
        return Program::options();
    }

    /**
     * The program of the result chosen in the form, or null when the result
     * belongs to none (or none is chosen yet).
     */
    protected function formResultProgramId(): ?int
    {
        if ($this->indicatorResultId === '') {
            return null;
        }

        return Result::query()->whereKey((int) $this->indicatorResultId)->value('program_id');
    }

    /**
     * Projects an indicator on the chosen result may be scoped to: those
     * delivering the result's program, or every project when the result sits
     * in no program.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projectOptions(): Collection
    {
        $programId = $this->formResultProgramId();

        return Project::query()
            ->select(['id', 'name', 'program_id'])
            ->when($programId !== null, fn ($query) => $query->where('program_id', $programId))
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * A project chosen for the old result may not deliver the new one's
     * program, so it is dropped rather than carried to the save.
     */
    public function updatedIndicatorResultId(): void
    {
        unset($this->projectOptions);

        if ($this->indicatorProjectId !== '' && ! $this->projectOptions->contains('id', (int) $this->indicatorProjectId)) {
            $this->indicatorProjectId = '';
        }
    }

    /**
     * A percentage is always read against 100, so it carries no unit of its own.
     */
    public function updatedIndicatorMeasure(): void
    {
        if ($this->indicatorMeasure === IndicatorMeasure::Percentage->value) {
            $this->unit = '';
        }
    }

    /**
     * Whether this indicator may be deleted.
     *
     * Refused once any target exists: plan_indicators cascades from indicators,
     * and reports and report_files cascade from those.
     */
    protected function canDelete(int $indicatorId): bool
    {
        $indicator = Indicator::query()->select(['id'])->withCount('planIndicators')->find($indicatorId);

        return $indicator !== null && $indicator->plan_indicators_count === 0;
    }

    public function openCreateModal(): void
    {
        Gate::authorize('indicators.create');

        $this->resetIndicatorForm();
        $this->showIndicatorModal = true;
    }

    public function openEditModal(int $indicatorId): void
    {
        Gate::authorize('indicators.update');

        $indicator = Indicator::query()
            ->select(['id', 'name', 'result_id', 'project_id', 'measure', 'unit', 'baseline', 'target', 'frequency'])
            ->findOrFail($indicatorId);

        $this->resetValidation();
        $this->editingIndicatorId = $indicator->id;
        $this->name = $indicator->name;
        $this->indicatorResultId = (string) $indicator->result_id;
        $this->indicatorMeasure = $indicator->measure->value;
        $this->unit = (string) ($indicator->unit ?? '');
        $this->baseline = $indicator->baseline === null ? '' : (string) $indicator->baseline;
        $this->target = $indicator->target === null ? '' : (string) $indicator->target;
        $this->frequency = $indicator->frequency->value;
        $this->indicatorProjectId = (string) ($indicator->project_id ?? '');
        $this->showIndicatorModal = true;
    }

    public function saveIndicator(): void
    {
        Gate::authorize($this->editingIndicatorId === null ? 'indicators.create' : 'indicators.update');

        $this->name = trim($this->name);
        $this->unit = trim($this->unit);
        $isPercentage = $this->indicatorMeasure === IndicatorMeasure::Percentage->value;
        $valueCeiling = $isPercentage ? 'max:100' : 'max:9999999999.99';

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'indicatorResultId' => ['required', 'integer', Rule::exists(Result::class, 'id')],
            'indicatorMeasure' => ['required', new Enum(IndicatorMeasure::class)],
            'unit' => ['nullable', 'string', 'max:50'],
            'baseline' => ['nullable', 'numeric', 'min:0', $valueCeiling],
            'target' => ['nullable', 'numeric', 'min:0', $valueCeiling],
            'frequency' => ['required', new Enum(Period::class)],
            'indicatorProjectId' => ['nullable', 'integer', Rule::exists(Project::class, 'id')],
        ], [
            'name.required' => __('indicators.enter_the_indicator_name'),
            'indicatorResultId.required' => __('indicators.select_the_result_this_indicator_measures'),
            'indicatorMeasure.required' => __('indicators.choose_whether_this_counts_or_is_a_percentage'),
            'frequency.required' => __('common.choose_how_often_this_is_checked'),
        ] + ($isPercentage ? [
            'baseline.max' => __('indicators.a_percentage_cannot_exceed_100'),
            'target.max' => __('indicators.a_percentage_cannot_exceed_100'),
        ] : []));

        $indicator = $this->editingIndicatorId === null
            ? new Indicator
            : Indicator::query()->findOrFail($this->editingIndicatorId);

        $result = Result::query()->select(['id', 'program_id'])->findOrFail((int) $validated['indicatorResultId']);
        $projectId = $validated['indicatorProjectId'] === '' || $validated['indicatorProjectId'] === null
            ? null
            : (int) $validated['indicatorProjectId'];

        if ($projectId !== null && $result->program_id !== null
            && Project::query()->whereKey($projectId)->value('program_id') !== $result->program_id) {
            $this->addError('indicatorProjectId', __('indicators.this_project_does_not_deliver_the_result_program'));

            return;
        }

        $indicator->fill([
            'name' => $validated['name'],
            'result_id' => $result->id,
            'measure' => $validated['indicatorMeasure'],
            'unit' => $isPercentage || $validated['unit'] === '' || $validated['unit'] === null ? null : $validated['unit'],
            'baseline' => $validated['baseline'] === '' || $validated['baseline'] === null ? null : $validated['baseline'],
            'target' => $validated['target'] === '' || $validated['target'] === null ? null : $validated['target'],
            'frequency' => $validated['frequency'],
            'project_id' => $projectId,
        ]);

        if ($indicator->exists && ! $this->changeKeepsTargetsInChain($indicator, $result)) {
            return;
        }

        $indicator->save();

        $wasRecentlyCreated = $indicator->wasRecentlyCreated;

        $this->showIndicatorModal = false;
        $this->resetIndicatorForm();
        $this->forgetIndicators();
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('indicators.indicator_created') : __('indicators.indicator_updated'),
        );
    }

    /**
     * Whether an edit leaves every target on this indicator fitting its chain.
     *
     * The measure is locked once targets exist: it decides how their reports
     * add up (summed or latest), so switching it would silently rewrite every
     * achievement figure. A new result or project is checked against each
     * target with PlanIndicator::chainIssuesFor(), and refused only when it
     * introduces a problem the target did not already have — a row that was
     * already out of step must not block an unrelated fix.
     */
    protected function changeKeepsTargetsInChain(Indicator $indicator, Result $result): bool
    {
        $targets = PlanIndicator::query()
            ->where('indicator_id', $indicator->id)
            ->with([
                'project:id,program_id',
                'source' => fn (MorphTo $morphTo) => $morphTo->morphWith([Proposal::class => ['projects:id']]),
            ])
            ->get();

        if ($targets->isEmpty()) {
            return true;
        }

        if ($indicator->isDirty('measure')) {
            $this->addError('indicatorMeasure', __('indicators.the_measure_is_locked_while_targets_exist'));

            return false;
        }

        if (! $indicator->isDirty(['result_id', 'project_id'])) {
            return true;
        }

        $before = Indicator::query()->with('result:id,program_id')->findOrFail($indicator->id);
        $after = (clone $indicator)->setRelation('result', $result);

        foreach ($targets as $target) {
            $introduced = array_diff(
                PlanIndicator::chainIssuesFor($target->source, $after, $target->project),
                PlanIndicator::chainIssuesFor($target->source, $before, $target->project),
            );

            if ($introduced !== []) {
                $this->addError(
                    $indicator->isDirty('result_id') ? 'indicatorResultId' : 'indicatorProjectId',
                    __('indicators.this_change_would_break_a_committed_target', ['issue' => __(reset($introduced))]),
                );

                return false;
            }
        }

        return true;
    }

    public function confirmDelete(int $indicatorId): void
    {
        Gate::authorize('indicators.delete');

        $this->deletingIndicatorId = $indicatorId;
        $this->showDeleteModal = true;
    }

    public function cancelDelete(): void
    {
        $this->deletingIndicatorId = null;
        $this->showDeleteModal = false;
    }

    public function deleteIndicator(): void
    {
        Gate::authorize('indicators.delete');

        if ($this->deletingIndicatorId === null) {
            return;
        }

        if (! $this->canDelete($this->deletingIndicatorId)) {
            $this->cancelDelete();

            Flux::toast(
                variant: 'danger',
                text: __('indicators.this_indicator_carries_targets_remove_them_first_deleting_it'),
            );

            return;
        }

        Indicator::query()->findOrFail($this->deletingIndicatorId)->delete();

        $this->cancelDelete();
        $this->forgetIndicators();
        $this->resetPage();

        Flux::toast(variant: 'success', text: __('indicators.indicator_deleted'));
    }

    public function closeIndicatorModal(): void
    {
        $this->showIndicatorModal = false;
        $this->resetIndicatorForm();
    }

    public function openProgressModal(int $indicatorId): void
    {
        Gate::authorize('indicators.update');

        $this->resetValidation();
        $this->progressIndicatorId = $indicatorId;
        $this->editingReadingId = null;
        $this->progressDate = now()->toDateString();
        $this->progressValue = '';
        $this->progressNotes = '';
        $this->progressSourceOfVerification = '';
        $this->showProgressModal = true;
    }

    /**
     * Correct a reading already logged: a mistyped figure should not be
     * permanent, and is not a reason to log a second, contradicting one.
     */
    public function openEditReadingModal(int $readingId): void
    {
        Gate::authorize('indicators.update');

        $reading = IndicatorProgress::query()->findOrFail($readingId);

        $this->resetValidation();
        $this->progressIndicatorId = $reading->indicator_id;
        $this->editingReadingId = $reading->id;
        $this->progressDate = $reading->date->toDateString();
        $this->progressValue = (string) $reading->value;
        $this->progressNotes = (string) ($reading->notes ?? '');
        $this->progressSourceOfVerification = (string) ($reading->source_of_verification ?? '');
        $this->showProgressModal = true;
    }

    public function saveProgress(): void
    {
        Gate::authorize('indicators.update');

        if ($this->progressIndicatorId === null) {
            return;
        }

        $isPercentage = Indicator::query()->whereKey($this->progressIndicatorId)->value('measure') === IndicatorMeasure::Percentage->value;

        $validated = $this->validate([
            // A reading records something that has happened, never a forecast.
            'progressDate' => ['required', 'date', 'before_or_equal:today'],
            'progressValue' => ['required', 'numeric', 'min:0', $isPercentage ? 'max:100' : 'max:9999999999.99'],
            'progressNotes' => ['nullable', 'string'],
            'progressSourceOfVerification' => ['nullable', 'string', 'max:255'],
        ], [
            'progressDate.required' => __('indicators.enter_the_date_of_this_reading'),
            'progressDate.before_or_equal' => __('indicators.a_reading_cannot_be_dated_in_the_future'),
            'progressValue.required' => __('indicators.enter_the_value_recorded'),
        ] + ($isPercentage ? ['progressValue.max' => __('indicators.a_percentage_cannot_exceed_100')] : []));

        $attributes = [
            'date' => $validated['progressDate'],
            'value' => $validated['progressValue'],
            'notes' => $validated['progressNotes'] === '' ? null : $validated['progressNotes'],
            'source_of_verification' => $validated['progressSourceOfVerification'] === '' ? null : $validated['progressSourceOfVerification'],
        ];

        if ($this->editingReadingId === null) {
            IndicatorProgress::query()->create($attributes + [
                'indicator_id' => $this->progressIndicatorId,
                'recorded_by' => auth()->id(),
            ]);
        } else {
            // Scoped to the indicator so a tampered id cannot edit another indicator's reading.
            IndicatorProgress::query()
                ->where('indicator_id', $this->progressIndicatorId)
                ->findOrFail($this->editingReadingId)
                ->fill($attributes)
                ->save();
        }

        $wasEdit = $this->editingReadingId !== null;

        $this->closeProgressModal();
        $this->forgetIndicators();

        Flux::toast(variant: 'success', text: $wasEdit ? __('indicators.reading_updated') : __('indicators.reading_logged'));
    }

    public function closeProgressModal(): void
    {
        $this->showProgressModal = false;
        $this->progressIndicatorId = null;
        $this->editingReadingId = null;
    }

    /**
     * Ask, inline in the reading history, whether to remove one reading.
     */
    public function confirmDeleteReading(int $readingId): void
    {
        Gate::authorize('indicators.update');

        $this->deletingReadingId = $readingId;
    }

    public function cancelDeleteReading(): void
    {
        $this->deletingReadingId = null;
    }

    public function deleteReading(): void
    {
        Gate::authorize('indicators.update');

        if ($this->deletingReadingId === null || $this->inspectingIndicatorId === null) {
            return;
        }

        IndicatorProgress::query()
            ->where('indicator_id', $this->inspectingIndicatorId)
            ->findOrFail($this->deletingReadingId)
            ->delete();

        $this->deletingReadingId = null;
        $this->forgetIndicators();

        Flux::toast(variant: 'success', text: __('indicators.reading_deleted'));
    }

    public function openInspectModal(int $indicatorId): void
    {
        $this->inspectingIndicatorId = $indicatorId;
        $this->showInspectModal = true;
    }

    public function closeInspectModal(): void
    {
        $this->showInspectModal = false;
        $this->inspectingIndicatorId = null;
        $this->deletingReadingId = null;
    }

    /**
     * Drop every cached read after a write, so the same request re-renders the
     * figures it just changed rather than the ones it read at the top.
     */
    protected function forgetIndicators(): void
    {
        foreach (['allIndicators', 'rows', 'filteredRows', 'pagedRows', 'overview', 'focusCounts', 'inspected', 'inspectedReadings'] as $property) {
            unset($this->{$property});
        }
    }

    protected function resetIndicatorForm(): void
    {
        $this->resetValidation();
        $this->editingIndicatorId = null;
        $this->name = '';
        $this->indicatorResultId = '';
        $this->indicatorMeasure = 'numeric';
        $this->unit = '';
        $this->baseline = '';
        $this->target = '';
        $this->frequency = 'quarterly';
        $this->indicatorProjectId = '';
    }
};
?>

@php
    use App\Enums\ResultType;

    $filtersAreActive = $search !== '' || $result !== '' || $measure !== '' || $program !== '' || $rag !== '' || $focus !== '';
    $overview = $this->overview;
    $paged = $this->pagedRows;
    $focusCounts = $this->focusCounts;

    // One fixed visual identity for Impact/Outcome/Output app-wide; see
    // .ai/rules/components-ui.md. Copied, never reinvented.
    $levelMarks = [
        ResultType::Impact->value => 'bg-secondary-dark ring-secondary-dark dark:bg-ink dark:ring-ink',
        ResultType::Outcome->value => 'bg-primary ring-primary',
        ResultType::Output->value => 'bg-board ring-secondary-light',
    ];

    $levelAccents = [
        ResultType::Impact->value => 'bg-secondary-dark dark:bg-ink',
        ResultType::Outcome->value => 'bg-primary',
        ResultType::Output->value => 'bg-secondary-light',
    ];

    /** Trim the trailing zeros off a measurement without losing real precision. */
    $figure = function (?float $value): string {
        if ($value === null) {
            return '—';
        }

        return number_format($value, fmod($value, 1.0) === 0.0 ? 0 : 2);
    };

    $ragTone = fn (?App\Enums\RagStatus $case): string => match ($case) {
        App\Enums\RagStatus::Green => 'good',
        App\Enums\RagStatus::Amber => 'warn',
        App\Enums\RagStatus::Red => 'critical',
        default => 'neutral',
    };

    $toneText = fn (string $tone): string => match ($tone) {
        'good' => 'text-status-good',
        'warn' => 'text-status-warn',
        'critical' => 'text-primary dark:text-brand-text',
        default => 'text-secondary-medium dark:text-ink-muted',
    };

    $toneFill = fn (string $tone): string => match ($tone) {
        'good' => 'bg-status-good',
        'warn' => 'bg-status-warn',
        'critical' => 'bg-primary dark:bg-status-critical',
        default => 'bg-secondary-medium dark:bg-ink-muted',
    };

    /**
     * A reading series drawn against its own target.
     *
     * The vertical scale spans the readings, the baseline and the target
     * together, so the line is always read against the thing it is chasing
     * rather than against itself — a series that crawls flat below its target
     * must not look like a series that is nearly there.
     */
    $spark = function (array $series, float $baseline, ?float $target): ?array {
        if (count($series) < 2) {
            return null;
        }

        $bounds = array_merge($series, [$baseline], $target === null ? [] : [$target]);
        $min = min($bounds);
        $max = max($bounds);
        $span = $max - $min;
        $width = 100.0;
        $height = 30.0;

        $y = fn (float $value): float => $span > 0.0
            ? round($height - (($value - $min) / $span) * $height, 2)
            : round($height / 2, 2);

        $points = [];
        foreach (array_values($series) as $index => $value) {
            $points[] = [round(($index / (count($series) - 1)) * $width, 2), $y((float) $value)];
        }

        $line = implode(' ', array_map(fn (array $point): string => $point[0].','.$point[1], $points));
        $last = $points[count($points) - 1];

        return [
            'line' => $line,
            'area' => 'M '.$points[0][0].','.$height.' L '.str_replace(' ', ' L ', $line).' L '.$last[0].','.$height.' Z',
            'last' => $last,
            // The end point as percentages of the box, so the "latest" marker
            // can be an HTML dot layered over the chart. Inside the SVG it
            // would inherit preserveAspectRatio="none" and render as a lozenge
            // whose shape changed with every card width.
            'lastPct' => [$last[0] / $width * 100, $last[1] / $height * 100],
            'targetY' => $target === null ? null : $y($target),
            'baselineY' => $y($baseline),
        ];
    };

    $ragSegments = [
        ['key' => App\Enums\RagStatus::Green->value, 'label' => App\Enums\RagStatus::Green->label(), 'count' => $overview['rag']['green'], 'fill' => 'bg-status-good', 'dot' => 'bg-status-good'],
        ['key' => App\Enums\RagStatus::Amber->value, 'label' => App\Enums\RagStatus::Amber->label(), 'count' => $overview['rag']['amber'], 'fill' => 'bg-status-warn', 'dot' => 'bg-status-warn'],
        ['key' => App\Enums\RagStatus::Red->value, 'label' => App\Enums\RagStatus::Red->label(), 'count' => $overview['rag']['red'], 'fill' => 'bg-primary dark:bg-status-critical', 'dot' => 'bg-primary dark:bg-status-critical'],
        ['key' => 'unrated', 'label' => __('indicators.unrated'), 'count' => $overview['unrated'], 'fill' => 'bg-secondary-soft dark:bg-line-strong', 'dot' => 'bg-secondary-light'],
    ];

    $focusChips = [
        ['key' => 'offtrack', 'label' => __('indicators.off_track'), 'icon' => 'arrow-trending-down', 'count' => $focusCounts['offtrack']],
        ['key' => 'stale', 'label' => __('indicators.needs_a_reading'), 'icon' => 'clock', 'count' => $focusCounts['stale']],
        ['key' => 'reporting', 'label' => __('indicators.reporting_overdue'), 'icon' => 'document-text', 'count' => $focusCounts['reporting']],
        ['key' => 'unmeasured', 'label' => __('indicators.never_measured'), 'icon' => 'exclamation-triangle', 'count' => $focusCounts['unmeasured']],
        ['key' => 'untargeted', 'label' => __('indicators.no_target'), 'icon' => 'flag', 'count' => $focusCounts['untargeted']],
    ];

    $reportStatusChip = fn (App\Enums\ReportStatus $status): string => match ($status) {
        App\Enums\ReportStatus::Approved => 'border-status-good/30 bg-status-good-soft text-status-good',
        App\Enums\ReportStatus::Reviewed => 'border-secondary-light text-secondary-medium dark:text-ink-muted',
        App\Enums\ReportStatus::Draft => 'border-secondary-light bg-secondary-soft text-secondary-medium dark:bg-surface-2 dark:text-ink-muted',
    };

    $onTrackShare = $overview['rated'] === 0 ? null : ($overview['rag']['green'] / $overview['rated']) * 100;
@endphp

<section
    class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink"
    aria-labelledby="indicator-directory-heading"
>
    <x-ui.page-header
        heading-id="indicator-directory-heading"
        :title="__('common.indicators')"
        :count="$overview['indicators']"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('indicators.search_indicators')" :placeholder="__('indicators.indicator_or_result_statement')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.header-filter-select :label="__('common.program')" wire:model.live="program" :value="$program">
                <flux:select.option value="">{{ __('common.all_programs') }}</flux:select.option>
                <flux:select.option value="none">{{ __('common.unassigned') }}</flux:select.option>
                @foreach ($this->programOptions as $programOption)
                    <flux:select.option :value="$programOption->id" :wire:key="'ind-program-'.$programOption->id">{{ $programOption->name }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('indicators.result')" wire:model.live="result" :value="$result">
                <flux:select.option value="">{{ __('indicators.all_results') }}</flux:select.option>
                @foreach ($this->resultOptions as $resultOption)
                    <flux:select.option :value="$resultOption->id" :wire:key="'ind-result-'.$resultOption->id">{{ Str::limit($resultOption->statement, 50) }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('indicators.measure')" wire:model.live="measure" :value="$measure">
                <flux:select.option value="">{{ __('indicators.all_measures') }}</flux:select.option>
                @foreach (IndicatorMeasure::cases() as $measureCase)
                    <flux:select.option :value="$measureCase->value" :wire:key="'ind-measure-'.$measureCase->value">{{ $measureCase->label() }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>
        </x-slot:filters>

        <x-slot:actions>
            @can('indicators.create')<x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-indicator-button">
                {{ __('indicators.add_indicator') }}
            </x-ui.button>@endcan
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Measurement at a glance: four figures, then how the ratings split. --}}
    <x-ui.panel data-test="indicator-overview">
        <div class="grid gap-px bg-secondary-soft @xl/directory:grid-cols-2 @4xl/directory:grid-cols-4 dark:bg-line">
            @php
                $achievementTone = match (true) {
                    $overview['achieved'] === null => 'neutral',
                    $overview['achieved'] >= $overview['elapsed'] * 0.9 => 'good',
                    $overview['achieved'] >= $overview['elapsed'] * 0.7 => 'warn',
                    default => 'critical',
                };

                $headline = [
                    [
                        'label' => __('common.indicators'),
                        'value' => number_format($overview['indicators']),
                        'sub' => __('indicators.measuring_count_of_total_results', ['count' => $overview['results'], 'total' => $this->resultOptions->count()]),
                        'icon' => 'chart-bar',
                        'tone' => 'neutral',
                    ],
                    [
                        'label' => __('indicators.readings_logged'),
                        'value' => number_format($overview['readings']),
                        'sub' => $overview['lastReading'] === null
                            ? __('indicators.no_readings_logged_yet')
                            : __('indicators.last_logged_date', ['date' => $overview['lastReading']->translatedFormat(__('formats.date'))]),
                        'icon' => 'clipboard-document-check',
                        'tone' => 'neutral',
                    ],
                    [
                        'label' => __('indicators.average_achievement'),
                        'value' => $overview['achieved'] === null ? '—' : number_format($overview['achieved'], 0).'%',
                        'sub' => __('indicators.against_percent_of_the_year_elapsed', ['percent' => number_format($overview['elapsed'], 0)]),
                        'icon' => 'presentation-chart-line',
                        'tone' => $achievementTone,
                    ],
                    [
                        'label' => __('indicators.on_track'),
                        'value' => $onTrackShare === null ? '—' : number_format($onTrackShare, 0).'%',
                        'sub' => __('indicators.rated_green_of_total', ['count' => $overview['rag']['green'], 'total' => $overview['rated']]),
                        'icon' => 'signal',
                        'tone' => match (true) {
                            $onTrackShare === null => 'neutral',
                            $onTrackShare >= 80 => 'good',
                            $onTrackShare >= 50 => 'warn',
                            default => 'critical',
                        },
                    ],
                ];
            @endphp

            @foreach ($headline as $cell)
                <div class="flex items-start justify-between gap-3 bg-white p-4 dark:bg-surface-1" wire:key="indicator-headline-{{ $loop->index }}">
                    <div class="min-w-0">
                        <p class="type-label text-secondary-medium dark:text-ink-muted">{{ $cell['label'] }}</p>
                        <p @class([
                            'type-figure mt-2',
                            'text-status-good' => $cell['tone'] === 'good',
                            'text-status-warn' => $cell['tone'] === 'warn',
                            'text-primary dark:text-brand-text' => $cell['tone'] === 'critical',
                            'text-secondary-dark dark:text-ink' => $cell['tone'] === 'neutral',
                        ])>{{ $cell['value'] }}</p>
                        <p class="mt-1 type-meta text-secondary-medium dark:text-ink-muted">{{ $cell['sub'] }}</p>
                    </div>
                    <div @class([
                        'flex size-10 shrink-0 items-center justify-center rounded-xl',
                        'bg-status-good-soft text-status-good' => $cell['tone'] === 'good',
                        'bg-status-warn-soft text-status-warn' => $cell['tone'] === 'warn',
                        'bg-status-critical-soft text-primary dark:text-brand-text' => $cell['tone'] === 'critical',
                        'bg-secondary-soft text-secondary-dark dark:bg-surface-2 dark:text-ink' => $cell['tone'] === 'neutral',
                    ])>
                        <flux:icon :name="$cell['icon']" class="size-5" />
                    </div>
                </div>
            @endforeach
        </div>

        {{-- The rating split, as one bar. Every band is a filter. --}}
        <div class="flex flex-col gap-3 border-t border-secondary-soft bg-board-head px-4 py-4 @3xl/directory:flex-row @3xl/directory:items-center @3xl/directory:gap-6 dark:border-line">
            <div class="min-w-0 flex-1">
                <div class="flex items-baseline justify-between gap-3">
                    <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('indicators.how_the_ratings_split') }}</p>
                    <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('indicators.rated_against_percent_of_the_year_elapsed', ['percent' => number_format($overview['elapsed'], 0)]) }}</p>
                </div>

                <div class="mt-2 flex h-2.5 w-full overflow-hidden rounded-full bg-secondary-soft dark:bg-surface-2" role="group" aria-label="{{ __('indicators.how_the_ratings_split') }}">
                    @foreach ($ragSegments as $segment)
                        @if ($segment['count'] > 0)
                            <button
                                type="button"
                                wire:click="setRag('{{ $segment['key'] }}')"
                                wire:key="rag-band-{{ $segment['key'] }}"
                                data-test="rag-band-{{ $segment['key'] }}"
                                aria-pressed="{{ $rag === $segment['key'] ? 'true' : 'false' }}"
                                style="width: {{ ($segment['count'] / max($overview['indicators'], 1)) * 100 }}%"
                                @class([
                                    'h-full transition-opacity focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary',
                                    $segment['fill'],
                                    'opacity-100' => $rag === '' || $rag === $segment['key'],
                                    'opacity-30' => $rag !== '' && $rag !== $segment['key'],
                                ])
                                aria-label="{{ $segment['label'].' · '.$segment['count'] }}"
                            ></button>
                        @endif
                    @endforeach
                </div>

                <div class="mt-2.5 flex flex-wrap items-center gap-x-4 gap-y-1.5">
                    @foreach ($ragSegments as $segment)
                        <button
                            type="button"
                            wire:click="setRag('{{ $segment['key'] }}')"
                            wire:key="rag-legend-{{ $segment['key'] }}"
                            aria-pressed="{{ $rag === $segment['key'] ? 'true' : 'false' }}"
                            @class([
                                'inline-flex items-center gap-1.5 rounded-md px-1 py-0.5 type-meta transition-colors focus-visible:outline-2 focus-visible:outline-primary',
                                'font-bold text-secondary-dark dark:text-ink' => $rag === $segment['key'],
                                'text-secondary-medium hover:text-secondary-dark dark:text-ink-muted dark:hover:text-ink' => $rag !== $segment['key'],
                            ])
                        >
                            <span aria-hidden="true" class="size-2 shrink-0 rounded-full {{ $segment['dot'] }}"></span>
                            {{ $segment['label'] }}
                            <span class="tabular-nums">{{ $segment['count'] }}</span>
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Who is paying for what is being measured. --}}
            <div class="flex shrink-0 items-center gap-3 border-secondary-soft @3xl/directory:border-s @3xl/directory:ps-6 dark:border-line">
                <x-ui.progress-ring
                    size="lg"
                    :value="$overview['achieved']"
                    :tone="$achievementTone"
                    :label="__('indicators.average_achievement')"
                />
                <div class="min-w-0">
                    <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('indicators.commitments_behind_these_indicators') }}</p>
                    <p class="mt-1 text-sm font-semibold text-secondary-dark dark:text-ink">
                        {{ trans_choice('common.count_target', $overview['targets'], ['count' => $overview['targets']]) }}
                        · {{ __('indicators.count_of_total_reports_approved', ['count' => $overview['approved'], 'total' => $overview['reports']]) }}
                    </p>

                    {{-- Counted, not summed: the targets span different units and measures. --}}
                    <p class="mt-0.5 type-meta tabular-nums text-secondary-medium dark:text-ink-muted">
                        {{ __('indicators.count_of_total_targets_achieved', ['count' => $overview['targetsAchieved'], 'total' => $overview['targets']]) }}
                        @if ($overview['overdueReports'] > 0)
                            · <span class="font-semibold text-status-warn">{{ trans_choice('indicators.count_report_overdue', $overview['overdueReports'], ['count' => $overview['overdueReports']]) }}</span>
                        @endif
                    </p>

                    @if ($overview['funders'] !== [])
                        <div class="mt-2 flex flex-wrap items-center gap-1">
                            <span class="sr-only">{{ __('indicators.funded_by') }}</span>
                            @foreach (array_slice($overview['funders'], 0, 4) as $funder)
                                <span @class([
                                    'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-semibold',
                                    'border-funding/30 bg-funding-soft text-funding' => $funder['type'] === 'donor',
                                    'border-secondary-light text-secondary-medium dark:text-ink-muted' => $funder['type'] !== 'donor',
                                ])>
                                    <flux:icon :name="$funder['type'] === 'donor' ? 'building-library' : 'calendar'" variant="micro" class="size-3" aria-hidden="true" />
                                    {{ $funder['label'] }}
                                </span>
                            @endforeach
                            @if (count($overview['funders']) > 4)
                                <span class="type-meta text-secondary-medium dark:text-ink-muted">+{{ count($overview['funders']) - 4 }}</span>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </x-ui.panel>

    {{-- Attention chips on one side, the way of reading the page on the other. --}}
    <div class="flex flex-wrap items-center gap-2">
        @foreach ($focusChips as $chip)
            <button
                type="button"
                wire:click="setFocus('{{ $chip['key'] }}')"
                wire:key="focus-chip-{{ $chip['key'] }}"
                aria-pressed="{{ $focus === $chip['key'] ? 'true' : 'false' }}"
                data-test="focus-chip-{{ $chip['key'] }}"
                @class([
                    'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 type-meta font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                    'border-primary bg-primary dark:bg-ink dark:text-surface-0 dark:border-ink text-white' => $focus === $chip['key'],
                    'border-secondary-soft bg-white text-secondary-medium hover:border-secondary-light hover:text-secondary-dark dark:border-line dark:bg-surface-1 dark:text-ink-muted dark:hover:text-ink' => $focus !== $chip['key'],
                ])
            >
                <flux:icon :name="$chip['icon']" variant="micro" class="size-3.5" aria-hidden="true" />
                {{ $chip['label'] }}
                <span @class([
                    'inline-flex min-w-5 items-center justify-center rounded-full px-1 text-[11px] font-bold tabular-nums',
                    'bg-white/20' => $focus === $chip['key'],
                    'bg-secondary-soft text-secondary-dark dark:bg-surface-2 dark:text-ink' => $focus !== $chip['key'],
                ])>{{ $chip['count'] }}</span>
            </button>
        @endforeach

        @if ($filtersAreActive)
            <flux:button size="sm" variant="ghost" class="text-secondary-medium! dark:text-ink-muted!" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>
        @endif

        <x-ui.view-toggle
            class="ms-auto"
            wire-model="view"
            :active="$view"
            :label="__('indicators.how_to_read_the_indicators')"
            :options="[
                ['key' => 'cards', 'label' => __('indicators.cards'), 'icon' => 'squares-2x2'],
                ['key' => 'table', 'label' => __('indicators.table'), 'icon' => 'table-cells'],
            ]"
        />
    </div>

    @if ($paged->isEmpty())
        <x-ui.panel>
            <x-ui.empty-state
                icon="chart-bar"
                :heading="$filtersAreActive ? __('indicators.no_matching_indicators') : __('indicators.no_indicators_yet')"
                :body="$filtersAreActive ? __('common.change_the_search_or_filters_and_try_again') : __('indicators.add_the_first_indicator_against_a_result')"
            >
                <x-slot:action>
                    @if ($filtersAreActive || auth()->user()->can('indicators.create'))<x-ui.button tone="primary" :icon="$filtersAreActive ? 'arrow-path' : 'plus'" wire:click="{{ $filtersAreActive ? 'clearFilters' : 'openCreateModal' }}">
                        {{ $filtersAreActive ? __('common.reset_filters') : __('indicators.add_indicator') }}
                    </x-ui.button>@endif
                </x-slot:action>
            </x-ui.empty-state>
        </x-ui.panel>
    @elseif ($view === 'table')
        {{-- Every figure side by side, for comparing many indicators at once. --}}
        <x-ui.panel
            :title="__('indicators.table')"
            :count="$overview['indicators']"
            :subtitle="__('indicators.every_indicator_with_its_trend_and_the_money_behind_it')"
        >
            <div class="overflow-x-auto">
                <div class="min-w-[68rem]">
                    <div class="grid grid-cols-[minmax(0,1fr)_8rem_7rem_7rem_9rem_5.5rem_5rem_9rem] items-center gap-3 border-b border-secondary-soft bg-board-head px-4 py-2 dark:border-line">
                        <span class="type-label text-secondary-medium dark:text-ink-muted">{{ __('common.indicator_name') }}</span>
                        <span class="type-label text-secondary-medium dark:text-ink-muted">{{ __('indicators.baseline_to_target') }}</span>
                        <span class="type-label text-end text-secondary-medium dark:text-ink-muted">{{ __('indicators.latest') }}</span>
                        <span class="type-label text-secondary-medium dark:text-ink-muted">{{ __('indicators.trend') }}</span>
                        <span class="type-label text-secondary-medium dark:text-ink-muted">{{ __('indicators.percent_achieved') }}</span>
                        <span class="type-label text-secondary-medium dark:text-ink-muted">{{ __('indicators.rating') }}</span>
                        <span class="type-label text-end text-secondary-medium dark:text-ink-muted">{{ __('common.targets') }}</span>
                        <span class="type-label text-end text-secondary-medium dark:text-ink-muted">{{ __('common.actions') }}</span>
                    </div>

                    <div class="divide-y divide-secondary-soft dark:divide-line">
                        @foreach ($paged as $row)
                            @php
                                $indicator = $row['indicator'];
                                $tone = $ragTone($row['rag']);
                                $sparkline = $spark($row['series'], $row['baseline'], $row['targetValue']);
                                $achievedPct = $row['achieved'] === null ? null : max(0.0, min(100.0, $row['achieved']));
                                $unit = $indicator->measure === IndicatorMeasure::Percentage ? '%' : $indicator->unit;
                            @endphp
                            <div class="grid grid-cols-[minmax(0,1fr)_8rem_7rem_7rem_9rem_5.5rem_5rem_9rem] items-center gap-3 px-4 py-3" wire:key="indicator-row-{{ $indicator->id }}">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span aria-hidden="true" class="size-2 shrink-0 rounded-full ring-2 ring-inset {{ $levelMarks[$indicator->result->type->value] }}"></span>
                                        <p class="truncate text-sm font-semibold">{{ $indicator->name }}</p>
                                    </div>
                                    <p class="mt-0.5 truncate type-meta text-secondary-medium dark:text-ink-muted">
                                        {{ Str::limit($indicator->result->statement, 60) }}
                                        · {{ $indicator->result->program?->name ?? __('common.unassigned') }}
                                    </p>
                                </div>

                                <p class="type-meta tabular-nums text-secondary-medium dark:text-ink-muted">
                                    {{ $figure($row['baseline']) }} <flux:icon.arrow-long-right variant="micro" class="inline size-3 rtl:rotate-180" aria-hidden="true" /> {{ $figure($row['targetValue']) }}
                                    @if ($unit !== null)
                                        <span class="text-secondary-light">{{ $unit }}</span>
                                    @endif
                                </p>

                                <p class="text-end text-sm font-semibold tabular-nums">{{ $figure($row['latest']) }}</p>

                                <div class="flex items-center">
                                    @if ($sparkline !== null)
                                        <div class="relative w-full {{ $toneText($tone) }}">
                                            <svg viewBox="0 0 100 30" preserveAspectRatio="none" class="h-6 w-full overflow-visible" aria-hidden="true">
                                                @if ($sparkline['targetY'] !== null)
                                                    <line x1="0" y1="{{ $sparkline['targetY'] }}" x2="100" y2="{{ $sparkline['targetY'] }}" stroke="currentColor" stroke-width="1" stroke-dasharray="3 3" opacity="0.35" vector-effect="non-scaling-stroke" />
                                                @endif
                                                <polyline points="{{ $sparkline['line'] }}" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                                            </svg>

                                            <span
                                                aria-hidden="true"
                                                class="pointer-events-none absolute size-1.5 rounded-full bg-current"
                                                style="inset-inline-start: calc({{ $sparkline['lastPct'][0] }}% - 0.1875rem); top: calc({{ $sparkline['lastPct'][1] }}% - 0.1875rem)"
                                            ></span>
                                        </div>
                                    @else
                                        <span class="type-meta text-secondary-light">{{ __('indicators.not_enough_readings') }}</span>
                                    @endif
                                </div>

                                <div class="min-w-0">
                                    <p class="text-sm font-bold tabular-nums {{ $toneText($tone) }}">
                                        {{ $row['achieved'] === null ? '—' : number_format($row['achieved'], 1).'%' }}
                                    </p>
                                    <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-secondary-soft dark:bg-surface-2">
                                        @if ($achievedPct !== null)
                                            <div class="h-full rounded-full {{ $toneFill($tone) }}" style="width: {{ $achievedPct }}%"></div>
                                        @endif
                                    </div>
                                </div>

                                <x-ui.enum-badge :case="$row['rag']" />

                                <p class="text-end text-sm tabular-nums text-secondary-medium dark:text-ink-muted">
                                    @if ($row['targets'] === [])
                                        0
                                    @else
                                        <a href="{{ route('targets.index', ['indicator' => $indicator->id]) }}" wire:navigate class="hover:text-primary dark:hover:text-brand-text hover:underline" title="{{ __('indicators.count_of_total_targets_achieved', ['count' => $row['targetsAchieved'], 'total' => count($row['targets'])]) }}">
                                            {{ $row['targetsAchieved'] }}/{{ count($row['targets']) }}
                                        </a>
                                    @endif
                                </p>

                                <div class="flex items-center justify-end gap-0.5">
                                    <x-ui.button tone="ghost" size="sm" icon="arrows-pointing-out" class="size-9!" wire:click="openInspectModal({{ $indicator->id }})" :aria-label="__('indicators.view_the_chain_for_name', ['name' => $indicator->name])" />
                                    @can('indicators.update')<x-ui.button tone="ghost" size="sm" icon="plus-circle" class="size-9!" wire:click="openProgressModal({{ $indicator->id }})" :aria-label="__('indicators.log_a_reading_for_name', ['name' => $indicator->name])" data-test="log-reading-button-{{ $indicator->id }}" />@endcan
                                    @can('indicators.update')<x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openEditModal({{ $indicator->id }})" :aria-label="__('common.edit_name', ['name' => $indicator->name])" />@endcan
                                    @if ($row['targets'] === [])
                                        @can('indicators.delete')<x-ui.button tone="ghost" size="sm" icon="trash" class="size-9!" wire:click="confirmDelete({{ $indicator->id }})" :aria-label="__('common.delete_name', ['name' => $indicator->name])" />@endcan
                                    @else
                                        <flux:tooltip :content="__('indicators.indicators_carrying_targets_cannot_be_deleted')">
                                            <span class="inline-flex size-9 cursor-not-allowed items-center justify-center text-secondary-light" aria-disabled="true">
                                                <flux:icon.lock-closed class="size-4" />
                                            </span>
                                        </flux:tooltip>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <x-slot:footer>
                <flux:pagination :paginator="$paged" class="text-secondary-medium! dark:text-ink-muted!" scroll-to="#indicator-directory-heading" />
            </x-slot:footer>
        </x-ui.panel>
    @else
        {{--
            One card per indicator. The rail under the name is the point of the
            page: it puts where the indicator stands and where the calendar says
            it should stand on the same line, so "behind" is visible before any
            number is read.
        --}}
        <div class="grid gap-3 @3xl/directory:grid-cols-2 @6xl/directory:grid-cols-3" data-test="indicator-cards">
            @foreach ($paged as $row)
                @php
                    $indicator = $row['indicator'];
                    $tone = $ragTone($row['rag']);
                    $sparkline = $spark($row['series'], $row['baseline'], $row['targetValue']);
                    $unit = $indicator->measure === IndicatorMeasure::Percentage ? '%' : $indicator->unit;
                    $span = $row['targetValue'] === null ? null : $row['targetValue'] - $row['baseline'];
                    $latestPct = $span === null || $span === 0.0 || $row['latest'] === null
                        ? null
                        : max(0.0, min(100.0, (($row['latest'] - $row['baseline']) / $span) * 100));
                @endphp

                <article
                    class="group relative flex flex-col overflow-hidden rounded-xl border border-secondary-soft bg-white shadow-[0_1px_2px_rgba(16,24,40,0.04),0_1px_3px_rgba(16,24,40,0.06)] transition-all hover:-translate-y-0.5 hover:border-secondary-light hover:shadow-[0_2px_4px_rgba(16,24,40,0.06),0_4px_8px_rgba(16,24,40,0.08)] dark:border-transparent dark:bg-surface-1 dark:shadow-raised dark:hover:shadow-raised"
                    wire:key="indicator-card-{{ $indicator->id }}"
                    data-result-type="{{ $indicator->result->type->value }}"
                >
                    <span aria-hidden="true" class="h-1 w-full {{ $levelAccents[$indicator->result->type->value] }}"></span>

                    <div class="flex min-w-0 flex-col gap-3 p-4">
                        {{-- Where this indicator sits in the chain. --}}
                        <div class="flex min-w-0 items-start gap-2">
                            <span aria-hidden="true" class="mt-1 size-2.5 shrink-0 rounded-full ring-2 ring-inset {{ $levelMarks[$indicator->result->type->value] }}"></span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate type-meta text-secondary-medium dark:text-ink-muted" title="{{ $indicator->result->statement }}">
                                    {{ Str::limit($indicator->result->statement, 64) }}
                                </p>
                                <p class="mt-0.5 truncate type-meta text-secondary-light">
                                    {{ $indicator->result->program?->name ?? __('common.unassigned') }}
                                    · {{ $indicator->project?->name ?? __('common.organisation_wide') }}
                                </p>
                            </div>
                            <x-ui.enum-badge :case="$row['rag']" class="shrink-0" />
                        </div>

                        <h3 class="text-base font-bold leading-snug text-secondary-dark dark:text-ink">{{ $indicator->name }}</h3>

                        {{-- The rail: baseline, where we are, where the calendar says we should be, target. --}}
                        <div>
                            <div class="flex items-baseline justify-between gap-2">
                                <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('indicators.trajectory') }}</p>
                                <p class="text-lg font-bold tabular-nums leading-none {{ $toneText($tone) }}">
                                    {{ $row['achieved'] === null ? '—' : number_format($row['achieved'], 1).'%' }}
                                </p>
                            </div>

                            <div class="relative mt-2 h-2.5 w-full overflow-visible rounded-full bg-secondary-soft dark:bg-surface-2">
                                @if ($latestPct !== null)
                                    <div class="h-full rounded-full transition-[width] duration-500 motion-reduce:transition-none {{ $toneFill($tone) }}" style="width: {{ $latestPct }}%"></div>
                                @endif

                                @if ($row['targetValue'] !== null)
                                    <span
                                        class="absolute -top-1 bottom-[-0.25rem] w-0.5 rounded-full bg-secondary-dark/50 dark:bg-white/50"
                                        style="inset-inline-start: {{ $overview['elapsed'] }}%"
                                        aria-hidden="true"
                                        title="{{ __('indicators.expected_by_now') }}"
                                    ></span>
                                @endif
                            </div>

                            <div class="mt-1.5 flex items-baseline justify-between gap-2 type-meta tabular-nums text-secondary-medium dark:text-ink-muted">
                                <span>{{ __('indicators.baseline') }} {{ $figure($row['baseline']) }}</span>
                                <span class="font-semibold text-secondary-dark dark:text-ink">
                                    {{ $figure($row['latest']) }}{{ $unit !== null ? ' '.$unit : '' }}
                                    @if ($row['delta'] !== null && $row['delta'] !== 0.0)
                                        <span @class([
                                            'ms-0.5 font-semibold',
                                            'text-status-good' => $row['delta'] > 0,
                                            'text-secondary-medium dark:text-ink-muted' => $row['delta'] < 0,
                                        ])>{{ $row['delta'] > 0 ? '+' : '−' }}{{ $figure(abs($row['delta'])) }}</span>
                                    @endif
                                </span>
                                <span>{{ __('indicators.target') }} {{ $figure($row['targetValue']) }}</span>
                            </div>
                        </div>

                        {{-- The readings themselves, against the target they chase. --}}
                        <div class="rounded-lg border border-secondary-soft bg-board-head p-2.5 dark:border-line ">
                            @if ($sparkline !== null)
                                <div class="relative {{ $toneText($tone) }}">
                                    <svg viewBox="0 0 100 30" preserveAspectRatio="none" class="h-12 w-full overflow-visible" aria-hidden="true">
                                        <path d="{{ $sparkline['area'] }}" fill="currentColor" opacity="0.10" />
                                        @if ($sparkline['targetY'] !== null)
                                            <line x1="0" y1="{{ $sparkline['targetY'] }}" x2="100" y2="{{ $sparkline['targetY'] }}" stroke="currentColor" stroke-width="1" stroke-dasharray="3 3" opacity="0.4" vector-effect="non-scaling-stroke" />
                                        @endif
                                        <polyline points="{{ $sparkline['line'] }}" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                                    </svg>

                                    {{-- The latest reading. Laid over the chart so it stays a circle at every width. --}}
                                    <span
                                        aria-hidden="true"
                                        class="pointer-events-none absolute size-2 rounded-full bg-current"
                                        style="inset-inline-start: calc({{ $sparkline['lastPct'][0] }}% - 0.25rem); top: calc({{ $sparkline['lastPct'][1] }}% - 0.25rem)"
                                    ></span>
                                </div>
                            @else
                                <div class="flex h-12 items-center justify-center">
                                    <p class="type-meta text-secondary-light">
                                        {{ $row['readings'] === 0 ? __('indicators.no_readings_logged_yet') : __('indicators.not_enough_readings') }}
                                    </p>
                                </div>
                            @endif

                            <p class="mt-1.5 truncate type-meta text-secondary-medium dark:text-ink-muted">
                                @if ($row['latestDate'] === null)
                                    {{ __('indicators.never_measured') }}
                                @else
                                    {{ __('indicators.last_read_date', ['date' => $row['latestDate']->translatedFormat(__('formats.date'))]) }}
                                    @if ($row['evidence'] !== null)
                                        · {{ Str::limit($row['evidence'], 28) }}
                                    @endif
                                @endif
                            </p>
                        </div>

                        {{-- What was committed to funders, and what has been reported back. --}}
                        {{-- Counted per target: two targets on one indicator can sit in different plans and years. --}}
                        @if ($row['targets'] !== [])
                            <a href="{{ route('targets.index', ['indicator' => $indicator->id]) }}" wire:navigate class="group/targets block rounded-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                                <div class="flex items-baseline justify-between gap-2">
                                    <p class="type-label text-secondary-medium group-hover/targets:text-primary dark:group-hover/targets:text-brand-text dark:text-ink-muted">{{ __('indicators.targets_achieved') }}</p>
                                    <p class="type-meta tabular-nums text-secondary-medium dark:text-ink-muted">
                                        {{ $row['targetsAchieved'] }} / {{ count($row['targets']) }}
                                    </p>
                                </div>
                                <x-ui.progress-bar
                                    class="mt-1.5"
                                    :value="$row['targetsAchieved']"
                                    :max="count($row['targets'])"
                                    tone="funding"
                                    :label="__('indicators.targets_achieved')"
                                    label-hidden
                                />
                            </a>
                        @endif

                        {{-- Cadence, commitments and the money behind them. --}}
                        <div class="flex flex-wrap items-center gap-1.5">
                            <x-ui.enum-badge :case="$indicator->frequency" />
                            <x-ui.enum-badge :case="$indicator->measure" />

                            @if ($row['stale'])
                                <span class="inline-flex items-center gap-1 rounded-md border border-status-warn/30 bg-status-warn-soft px-2 py-0.5 text-xs font-semibold text-status-warn">
                                    <flux:icon.clock variant="micro" class="size-3" aria-hidden="true" />
                                    {{ __('indicators.needs_a_reading') }}
                                </span>
                            @endif

                            @if ($row['overdueReports'] > 0)
                                <span class="inline-flex items-center gap-1 rounded-md border border-primary/30 bg-status-critical-soft px-2 py-0.5 text-xs font-semibold text-primary dark:text-brand-text">
                                    <flux:icon.document-text variant="micro" class="size-3" aria-hidden="true" />
                                    {{ trans_choice('indicators.count_report_overdue', $row['overdueReports'], ['count' => $row['overdueReports']]) }}
                                </span>
                            @endif

                            @if ($row['chainIssues'] > 0)
                                <span class="inline-flex items-center gap-1 rounded-md border border-primary/30 bg-status-critical-soft px-2 py-0.5 text-xs font-semibold text-primary dark:text-brand-text">
                                    <flux:icon.link-slash variant="micro" class="size-3" aria-hidden="true" />
                                    {{ trans_choice('indicators.count_target_out_of_step', $row['chainIssues'], ['count' => $row['chainIssues']]) }}
                                </span>
                            @endif

                            @foreach (array_slice($row['funders'], 0, 2) as $funder)
                                <span @class([
                                    'inline-flex max-w-full items-center gap-1 truncate rounded-full border px-2 py-0.5 text-[11px] font-semibold',
                                    'border-funding/30 bg-funding-soft text-funding' => $funder['type'] === 'donor',
                                    'border-secondary-light text-secondary-medium dark:text-ink-muted' => $funder['type'] !== 'donor',
                                ])>
                                    <flux:icon :name="$funder['type'] === 'donor' ? 'building-library' : 'calendar'" variant="micro" class="size-3 shrink-0" aria-hidden="true" />
                                    <span class="truncate">{{ $funder['label'] }}</span>
                                </span>
                            @endforeach

                            @if (count($row['funders']) > 2)
                                <span class="type-meta text-secondary-medium dark:text-ink-muted">+{{ count($row['funders']) - 2 }}</span>
                            @endif
                        </div>
                    </div>

                    <div class="mt-auto flex items-center gap-1 border-t border-secondary-soft px-2.5 py-2 dark:border-line">
                        <x-ui.button tone="ghost" size="sm" icon="arrows-pointing-out" class="min-h-9" wire:click="openInspectModal({{ $indicator->id }})" data-test="inspect-indicator-{{ $indicator->id }}">
                            {{ __('indicators.the_chain') }}
                        </x-ui.button>

                        <div class="ms-auto flex items-center gap-0.5">
                            @can('indicators.update')<x-ui.button tone="ghost" size="sm" icon="plus-circle" class="size-9!" wire:click="openProgressModal({{ $indicator->id }})" :aria-label="__('indicators.log_a_reading_for_name', ['name' => $indicator->name])" data-test="log-reading-button-{{ $indicator->id }}" />@endcan
                            @can('indicators.update')<x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openEditModal({{ $indicator->id }})" :aria-label="__('common.edit_name', ['name' => $indicator->name])" />@endcan
                            @if ($row['targets'] === [])
                                @can('indicators.delete')<x-ui.button tone="ghost" size="sm" icon="trash" class="size-9!" wire:click="confirmDelete({{ $indicator->id }})" :aria-label="__('common.delete_name', ['name' => $indicator->name])" />@endcan
                            @else
                                <flux:tooltip :content="__('indicators.indicators_carrying_targets_cannot_be_deleted')">
                                    <span class="inline-flex size-9 cursor-not-allowed items-center justify-center text-secondary-light" aria-disabled="true">
                                        <flux:icon.lock-closed class="size-4" />
                                    </span>
                                </flux:tooltip>
                            @endif
                        </div>
                    </div>
                </article>
            @endforeach
        </div>

        @if ($paged->hasPages())
            <x-ui.panel>
                <div class="px-4 py-2">
                    <flux:pagination :paginator="$paged" class="text-secondary-medium! dark:text-ink-muted!" scroll-to="#indicator-directory-heading" />
                </div>
            </x-ui.panel>
        @endif
    @endif

    {{-- The chain: every reading behind the number, and every commitment in front of it. --}}
    <x-ui.modal
        wire:model="showInspectModal"
        size="xl"
        close="closeInspectModal"
        :cancel-label="__('common.close')"
        :title="$showInspectModal && $this->inspected !== null ? $this->inspected['indicator']->name : ''"
    >
        @if ($showInspectModal && $this->inspected !== null)
            @php
                $row = $this->inspected;
                $indicator = $row['indicator'];
                $tone = $ragTone($row['rag']);
                $unit = $indicator->measure === IndicatorMeasure::Percentage ? '%' : $indicator->unit;
            @endphp

            <div class="space-y-5 py-5">
                <div class="border-b border-secondary-soft pb-4 dark:border-line">
                    {{-- The whole chain above this indicator: program, then every
                         statement it rolls up through, then what it is scoped to. --}}
                    <div class="flex flex-wrap items-center gap-x-1.5 gap-y-1 type-meta text-secondary-medium dark:text-ink-muted">
                        <span>{{ $indicator->result->program?->name ?? __('common.unassigned') }}</span>

                        @foreach (array_reverse($row['lineage']) as $ancestor)
                            <flux:icon.chevron-right variant="micro" class="size-3 rtl:rotate-180" aria-hidden="true" />
                            <span class="inline-flex items-center gap-1.5" title="{{ $ancestor->statement }}">
                                <span aria-hidden="true" class="size-2 rounded-full ring-2 ring-inset {{ $levelMarks[$ancestor->type->value] }}"></span>
                                {{ Str::limit($ancestor->statement, 40) }}
                            </span>
                        @endforeach

                        <flux:icon.chevron-right variant="micro" class="size-3 rtl:rotate-180" aria-hidden="true" />
                        <span class="inline-flex items-center gap-1.5" title="{{ $indicator->result->statement }}">
                            <span aria-hidden="true" class="size-2 rounded-full ring-2 ring-inset {{ $levelMarks[$indicator->result->type->value] }}"></span>
                            {{ Str::limit($indicator->result->statement, 50) }}
                        </span>

                        <flux:icon.chevron-right variant="micro" class="size-3 rtl:rotate-180" aria-hidden="true" />
                        <span class="font-semibold text-secondary-dark dark:text-ink">{{ $indicator->project?->name ?? __('common.organisation_wide') }}</span>
                    </div>


                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <x-ui.enum-badge :case="$row['rag']" />
                        <x-ui.enum-badge :case="$indicator->frequency" />
                        <x-ui.enum-badge :case="$indicator->measure" />
                        <span class="type-meta tabular-nums text-secondary-medium dark:text-ink-muted">
                            {{ __('indicators.baseline') }} {{ $figure($row['baseline']) }}
                            <flux:icon.arrow-long-right variant="micro" class="inline size-3 rtl:rotate-180" aria-hidden="true" />
                            {{ __('indicators.target') }} {{ $figure($row['targetValue']) }}{{ $unit !== null ? ' '.$unit : '' }}
                        </span>
                        <span class="ms-auto text-xl font-bold tabular-nums {{ $toneText($tone) }}">
                            {{ $row['achieved'] === null ? '—' : number_format($row['achieved'], 1).'%' }}
                        </span>
                    </div>
                </div>

                <div class="grid gap-5 lg:grid-cols-2">
                    <section aria-labelledby="inspect-readings-heading">
                        <h3 id="inspect-readings-heading" class="type-label text-secondary-medium dark:text-ink-muted">
                            {{ __('indicators.reading_history') }}
                            <span class="ms-1 tabular-nums">{{ $row['readings'] }}</span>
                        </h3>

                        @if ($this->inspectedReadings->isEmpty())
                            <p class="mt-3 rounded-lg border border-dashed border-secondary-light p-4 text-center type-meta text-secondary-medium dark:text-ink-muted">
                                {{ __('indicators.no_readings_logged_yet') }}
                            </p>
                        @else
                            <ol class="mt-3 max-h-80 space-y-0 overflow-y-auto border-s border-secondary-soft ps-4 dark:border-line">
                                @foreach ($this->inspectedReadings as $entry)
                                    <li class="relative pb-4 last:pb-0" wire:key="reading-{{ $entry['reading']->id }}">
                                        <span aria-hidden="true" class="absolute -start-[1.3125rem] top-1.5 size-2 rounded-full {{ $loop->first ? $toneFill($tone) : 'bg-secondary-light' }}"></span>

                                        <div class="flex items-baseline justify-between gap-2">
                                            <p class="text-sm font-semibold tabular-nums">
                                                {{ $figure($entry['value']) }}{{ $unit !== null ? ' '.$unit : '' }}
                                                @if ($entry['delta'] !== null && $entry['delta'] !== 0.0)
                                                    <span @class([
                                                        'ms-1 type-meta font-semibold',
                                                        'text-status-good' => $entry['delta'] > 0,
                                                        'text-secondary-medium dark:text-ink-muted' => $entry['delta'] < 0,
                                                    ])>{{ $entry['delta'] > 0 ? '+' : '−' }}{{ $figure(abs($entry['delta'])) }}</span>
                                                @endif
                                            </p>
                                            <p class="shrink-0 type-meta text-secondary-medium dark:text-ink-muted">
                                                {{ $entry['reading']->date->translatedFormat(__('formats.date')) }}
                                            </p>
                                        </div>

                                        @if ($entry['reading']->source_of_verification !== null)
                                            <p class="mt-0.5 type-meta text-secondary-medium dark:text-ink-muted">
                                                <flux:icon.document-check variant="micro" class="inline size-3" aria-hidden="true" />
                                                {{ $entry['reading']->source_of_verification }}
                                            </p>
                                        @endif

                                        @if ($entry['reading']->notes !== null)
                                            <p class="mt-0.5 type-meta text-secondary-medium dark:text-ink-muted">{{ $entry['reading']->notes }}</p>
                                        @endif

                                        <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1">
                                            @if ($entry['reading']->recorder !== null)
                                                <p class="me-auto type-meta text-secondary-light">
                                                    {{ __('indicators.recorded_by_name', ['name' => $entry['reading']->recorder->name]) }}
                                                </p>
                                            @endif

                                            @if ($deletingReadingId === $entry['reading']->id)
                                                <span class="ms-auto inline-flex items-center gap-1.5 type-meta">
                                                    <span class="font-semibold text-primary dark:text-brand-text">{{ __('indicators.delete_this_reading') }}</span>
                                                    <x-ui.button size="sm" tone="primary" class="min-h-7! px-2!" wire:click="deleteReading" data-test="confirm-delete-reading-{{ $entry['reading']->id }}">{{ __('common.delete') }}</x-ui.button>
                                                    <x-ui.button size="sm" class="min-h-7! px-2!" wire:click="cancelDeleteReading">{{ __('common.cancel') }}</x-ui.button>
                                                </span>
                                            @else
                                                <span class="ms-auto inline-flex items-center gap-0.5">
                                                    @can('indicators.update')<x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-7!" wire:click="openEditReadingModal({{ $entry['reading']->id }})" :aria-label="__('indicators.edit_reading_from_date', ['date' => $entry['reading']->date->translatedFormat(__('formats.date'))])" data-test="edit-reading-{{ $entry['reading']->id }}" />@endcan
                                                    @can('indicators.update')<x-ui.button tone="ghost" size="sm" icon="trash" class="size-7!" wire:click="confirmDeleteReading({{ $entry['reading']->id }})" :aria-label="__('indicators.delete_reading_from_date', ['date' => $entry['reading']->date->translatedFormat(__('formats.date'))])" data-test="delete-reading-{{ $entry['reading']->id }}" />@endcan
                                                </span>
                                            @endif
                                        </div>
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    </section>

                    <section aria-labelledby="inspect-targets-heading">
                        <div class="flex items-center justify-between gap-2">
                            <h3 id="inspect-targets-heading" class="type-label text-secondary-medium dark:text-ink-muted">
                                {{ __('indicators.committed_targets') }}
                                <span class="ms-1 tabular-nums">{{ count($row['targets']) }}</span>
                            </h3>
                            @if ($row['targets'] !== [])
                                <a href="{{ route('targets.index', ['indicator' => $indicator->id]) }}" wire:navigate class="type-meta font-semibold text-secondary-medium hover:text-primary dark:hover:text-brand-text hover:underline dark:text-ink-muted">
                                    {{ __('indicators.open_in_targets') }}
                                </a>
                            @endif
                        </div>

                        @if ($row['targets'] === [])
                            <div class="mt-3 flex flex-col items-center gap-3 rounded-lg border border-dashed border-secondary-light p-4 text-center">
                                <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('indicators.no_targets_committed_against_this_indicator_yet') }}</p>
                                <x-ui.button size="sm" tone="primary" icon="flag" :href="route('targets.index', ['indicator' => $indicator->id, 'create' => 1])" wire:navigate data-test="commit-target-for-indicator">
                                    {{ __('indicators.commit_a_target') }}
                                </x-ui.button>
                            </div>
                        @else
                            <ul class="mt-3 max-h-96 space-y-2 overflow-y-auto">
                                @foreach ($row['targets'] as $target)
                                    <li class="rounded-lg border border-secondary-soft bg-board-head p-3 dark:border-line" wire:key="target-{{ $target['target']->id }}">
                                        <div class="flex items-baseline justify-between gap-2">
                                            <p class="min-w-0 truncate text-sm font-semibold">
                                                {{ $target['sourceLabel'] ?? __('indicators.source_no_longer_available') }}
                                            </p>
                                            <p class="shrink-0 text-sm font-bold tabular-nums">{{ $figure((float) $target['target']->target) }}{{ $unit !== null ? ' '.$unit : '' }}</p>
                                        </div>

                                        <p class="mt-0.5 type-meta text-secondary-medium dark:text-ink-muted">
                                            {{ $target['target']->period->label() }}
                                            · {{ $target['project'] ?? __('common.organisation_wide') }}
                                        </p>

                                        @if ($target['donors'] !== [])
                                            <div class="mt-2 flex flex-wrap gap-1">
                                                @foreach ($target['donors'] as $donor)
                                                    <span class="inline-flex items-center gap-1 rounded-full border border-funding/30 bg-funding-soft px-2 py-0.5 text-[11px] font-semibold text-funding">
                                                        <flux:icon.building-library variant="micro" class="size-3" aria-hidden="true" />
                                                        {{ $donor }}
                                                    </span>
                                                @endforeach
                                            </div>
                                        @endif

                                        @foreach ($target['issues'] as $issue)
                                            <p class="mt-2 flex items-start gap-1.5 type-meta font-semibold text-primary dark:text-brand-text">
                                                <flux:icon.link-slash variant="micro" class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                                                {{ __($issue) }}
                                            </p>
                                        @endforeach

                                        {{-- Approved reports only (PlanIndicator::achievedValue()); the rest is shown as pending. --}}
                                        <div class="mt-2.5 border-t border-secondary-soft pt-2.5 dark:border-line">
                                            <div class="flex items-baseline justify-between gap-2">
                                                <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('indicators.achieved') }}</p>
                                                <p class="type-meta tabular-nums text-secondary-medium dark:text-ink-muted">
                                                    <span class="font-semibold text-secondary-dark dark:text-ink">{{ $figure($target['achieved']) }}</span> / {{ $figure($target['committed']) }}
                                                    @if ($target['percent'] !== null)
                                                        · {{ number_format($target['percent'], 0) }}%
                                                    @endif
                                                </p>
                                            </div>

                                            <x-ui.progress-bar
                                                class="mt-1.5"
                                                :value="min($target['achieved'], $target['committed'])"
                                                :max="max($target['committed'], 0.01)"
                                                tone="funding"
                                                :label="__('indicators.achieved')"
                                                label-hidden
                                            />

                                            <p class="mt-1 type-meta tabular-nums text-secondary-medium dark:text-ink-muted">
                                                {{ __('indicators.count_of_total_reports_approved', ['count' => $target['approved'], 'total' => $target['reports']]) }}
                                                @if ($target['pending'] > 0)
                                                    · {{ __('indicators.value_pending_approval', ['value' => $figure($target['pending'])]) }}
                                                @endif
                                            </p>

                                            @if ($target['reportRows']->isEmpty())
                                                <p class="mt-2 type-meta text-secondary-light">{{ __('indicators.no_reports_filed_yet') }}</p>
                                            @else
                                                <ul class="mt-2 space-y-0.5">
                                                    @foreach ($target['reportRows'] as $report)
                                                        <li wire:key="report-{{ $report->id }}">
                                                            <a href="{{ route('reports.show', $report) }}" wire:navigate class="-mx-1 flex items-center gap-2 rounded px-1 py-0.5 type-meta transition-colors hover:bg-white dark:hover:bg-white/5">
                                                                <span class="inline-flex shrink-0 items-center rounded border px-1.5 py-px text-[10px] font-semibold {{ $reportStatusChip($report->status) }}">
                                                                    {{ $report->status->label() }}
                                                                </span>
                                                                <span class="tabular-nums font-semibold text-secondary-dark dark:text-ink">{{ $figure((float) $report->value) }}</span>
                                                                <span class="min-w-0 truncate text-secondary-medium dark:text-ink-muted">
                                                                    @if ($report->period_start !== null && $report->period_end !== null)
                                                                        {{ $report->period_start->translatedFormat(__('formats.date_short')) }}–{{ $report->period_end->translatedFormat(__('formats.date_short')) }}
                                                                    @else
                                                                        {{ $report->planned_date->translatedFormat(__('formats.date')) }}
                                                                    @endif
                                                                </span>
                                                                @if ($report->isOverdue())
                                                                    <span class="ms-auto shrink-0 font-semibold text-primary dark:text-brand-text">
                                                                        {{ __('ui.schedule_due_date', ['date' => $report->planned_date->translatedFormat(__('formats.date_short'))]) }}
                                                                    </span>
                                                                @endif
                                                            </a>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @endif

                                            <a href="{{ route('reports.index', ['create' => 1, 'target' => $target['target']->id]) }}" wire:navigate class="mt-2 inline-flex items-center gap-1 type-meta font-semibold text-primary dark:text-brand-text hover:underline" data-test="record-report-for-target-{{ $target['target']->id }}">
                                                <flux:icon.plus variant="micro" class="size-3.5" aria-hidden="true" />
                                                {{ __('indicators.record_a_report') }}
                                            </a>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                </div>
            </div>

            <x-slot:actions>
                @if ($row['targets'] !== [])
                    <x-ui.button icon="flag" :href="route('targets.index', ['indicator' => $indicator->id, 'create' => 1])" wire:navigate>
                        {{ __('indicators.commit_a_target') }}
                    </x-ui.button>
                @endif
                @can('indicators.update')<x-ui.button type="button" tone="primary" icon="plus-circle" wire:click="openProgressModal({{ $indicator->id }})">
                    {{ __('indicators.log_reading') }}
                </x-ui.button>@endcan
            </x-slot:actions>
        @endif
    </x-ui.modal>

    <x-ui.modal
        wire:model="showIndicatorModal"
        size="xl"
        :title="$editingIndicatorId === null ? __('indicators.add_indicator') : __('indicators.edit_indicator')"
        :description="__('indicators.an_indicator_measures_exactly_one_result')"
        submit="saveIndicator"
        close="closeIndicatorModal"
        required-note
    >
        @if ($showIndicatorModal)
            @php
                $formIsPercentage = $indicatorMeasure === IndicatorMeasure::Percentage->value;
            @endphp

            <x-ui.form-section :title="__('common.section_details')" :fields="['name', 'indicatorResultId', 'indicatorProjectId']">
                <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                    <x-ui.form-input wire:model="name" :label="__('common.indicator_name')" maxlength="255" required autofocus class="@2xl/modal:col-span-2" />

                    <x-ui.form-select wire:model.live="indicatorResultId" :label="__('indicators.result')" :placeholder="__('indicators.select_a_result')" required>
                        @foreach ($this->resultOptions as $resultOption)
                            <flux:select.option :value="$resultOption->id">{{ $resultOption->type->label() }} · {{ Str::limit($resultOption->statement, 60) }}</flux:select.option>
                        @endforeach
                    </x-ui.form-select>

                    <x-ui.form-select
                        wire:model="indicatorProjectId"
                        :label="__('common.project')"
                        :help="$indicatorResultId !== '' ? __('indicators.only_projects_delivering_the_result_program_are_offered') : null"
                    >
                        <flux:select.option value="">{{ __('common.organisation_wide') }}</flux:select.option>
                        @foreach ($this->projectOptions as $projectOption)
                            <flux:select.option :value="$projectOption->id">{{ $projectOption->name }}</flux:select.option>
                        @endforeach
                    </x-ui.form-select>
                </div>
            </x-ui.form-section>

            <x-ui.form-section :title="__('indicators.section_measurement')" :fields="['indicatorMeasure', 'frequency', 'unit', 'baseline', 'target']">
                <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                    <x-ui.form-segmented
                        wire:model.live="indicatorMeasure"
                        :label="__('indicators.measure')"
                        required
                        :options="collect(IndicatorMeasure::cases())->map(fn (IndicatorMeasure $measureCase): array => ['value' => $measureCase->value, 'label' => $measureCase->label()])->all()"
                    />

                    <x-ui.form-segmented
                        wire:model="frequency"
                        :label="__('indicators.frequency')"
                        required
                        :options="collect(Period::cases())->map(fn (Period $periodCase): array => ['value' => $periodCase->value, 'label' => $periodCase->label()])->all()"
                    />

                    <div @class(['grid gap-x-4 gap-y-3 @2xl/modal:col-span-2', 'grid-cols-3' => ! $formIsPercentage, 'grid-cols-2' => $formIsPercentage])>
                        @unless ($formIsPercentage)
                            <x-ui.form-input wire:model="unit" :label="__('indicators.unit')" maxlength="50" :placeholder="__('indicators.unit_placeholder')" />
                        @endunless
                        <x-ui.form-input wire:model="baseline" :label="$formIsPercentage ? __('indicators.baseline_percent') : __('indicators.baseline')" type="number" step="0.01" min="0" :max="$formIsPercentage ? 100 : null" :suffix="$formIsPercentage ? '%' : null" />
                        <x-ui.form-input wire:model="target" :label="$formIsPercentage ? __('indicators.lifetime_target_percent') : __('indicators.lifetime_target')" type="number" step="0.01" min="0" :max="$formIsPercentage ? 100 : null" :suffix="$formIsPercentage ? '%' : null" />
                    </div>
                </div>
            </x-ui.form-section>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-indicator-button">
                {{ $editingIndicatorId === null ? __('indicators.create_indicator') : __('common.save_changes') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    <x-ui.confirm-modal
        wire:model="showDeleteModal"
        close="cancelDelete"
        :title="__('indicators.delete_this_indicator')"
        :message="__('indicators.only_an_indicator_carrying_no_targets_can_be_deleted')"
        confirm="deleteIndicator"
        :confirm-label="__('indicators.delete_indicator')"
        confirm-test="confirm-delete-indicator"
    />

    <x-ui.modal
        wire:model="showProgressModal"
        size="md"
        :title="$editingReadingId === null ? __('indicators.log_a_reading') : __('indicators.edit_reading')"
        :description="__('indicators.one_dated_number_with_the_evidence_it_came_from')"
        submit="saveProgress"
        close="closeProgressModal"
        required-note
    >
        @if ($showProgressModal)
            <div class="grid grid-cols-2 gap-x-4 gap-y-4">
                <x-ui.form-input wire:model="progressDate" :label="__('indicators.date')" type="date" :max="now()->toDateString()" required autofocus />
                <x-ui.form-input wire:model="progressValue" :label="__('indicators.value')" type="number" step="0.01" required />
                <x-ui.form-input wire:model="progressSourceOfVerification" :label="__('indicators.source_of_verification')" maxlength="255" class="col-span-2" />
                <x-ui.form-textarea wire:model="progressNotes" :label="__('common.notes')" class="col-span-2" />
            </div>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-progress-button">
                {{ $editingReadingId === null ? __('indicators.log_reading') : __('common.save_changes') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.modal>
</section>
