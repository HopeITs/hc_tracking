<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Indicators;

use App\Enums\Period;
use App\Enums\RagStatus;
use App\Enums\ReportStatus;
use App\Models\AnnualPlan;
use App\Models\Indicator;
use App\Models\IndicatorProgress;
use App\Models\PlanIndicator;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Result;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection as BaseCollection;
use Livewire\Attributes\Computed;

/**
 * The indicator directory's one read and the rows it is reduced to: every
 * indicator with its whole chain loaded once, each one rated and described,
 * then filtered and paged in memory (see .ai/rules/indicators.md).
 *
 * Used by indicators/index only. The page provides its six #[Url] filters
 * and WithPagination's getPage().
 *
 * @property string $search #[Url] filter on the page
 * @property string $result #[Url] filter on the page
 * @property string $measure #[Url] filter on the page
 * @property string $program #[Url] filter on the page
 * @property string $rag #[Url] filter on the page
 * @property string $focus #[Url] filter on the page
 * @property-read Collection<int, Indicator> $allIndicators  #[Computed] allIndicators()
 * @property-read array<int, array<string, mixed>> $rows  #[Computed] rows()
 * @property-read BaseCollection<int, array<string, mixed>> $filteredRows  #[Computed] filteredRows()
 */
trait BuildsIndicatorRows
{
    /**
     * How many indicator cards or rows one page holds.
     *
     * Twelve divides cleanly by the card grid's 1/2/3 columns, so the last row
     * is never a lone orphan card at any breakpoint.
     */
    private const PER_PAGE = 12;

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
     * @return array{target: PlanIndicator, sourceType: string, sourceLabel: string|null, donors: list<string>, project: string|null, reports: int, approved: int, committed: float, achieved: float, pending: float, percent: float|null, status: string, overdue: int, issues: list<string>, reportRows: Collection<int, Report>}
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
}
