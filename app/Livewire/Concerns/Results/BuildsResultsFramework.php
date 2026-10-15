<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Results;

use App\Enums\RagStatus;
use App\Enums\ReportStatus;
use App\Models\AnnualPlan;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Proposal;
use App\Models\Result;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Livewire\Attributes\Computed;

/**
 * The one read of the results framework and the measurement attached to
 * every statement in it.
 *
 * Used by results/index (every framework) and the proposal Logical Framework
 * tab (one proposal's framework, see fixedProposalId()). The tree, the
 * filters and the headline figures (BuildsResultsTree, SummarisesResults)
 * resolve in memory from what is loaded here, so the page holds a fixed
 * query count however it is filtered.
 *
 * @property-read Collection<int, Result> $allResults
 * @property-read array<int, Result> $resultsById
 * @property-read array<int, int> $childCounts
 */
trait BuildsResultsFramework
{
    /**
     * Every statement in the framework, with the whole chain that hangs off
     * it: the indicators that measure it, the readings behind them, the
     * targets committed against them, the plan or proposal each target was
     * committed in, the donors behind that proposal, and the reports filed.
     *
     * A logframe is small — a handful of statements per program, three levels
     * at most (see .ai/rules/concerns.md) — so the table is read once and the
     * tree, the rollups and every filter are resolved in memory afterwards.
     * That holds the page at a fixed query count however it is filtered, and
     * leaves the lineage of a parent hidden by a filter available to render.
     *
     * @return Collection<int, Result>
     */
    #[Computed]
    public function allResults(): Collection
    {
        return $this->frameworkResults()
            ->select(['id', 'statement', 'parent_id', 'program_id', 'proposal_id', 'contributes_to_result_id', 'type', 'checkout_period'])
            ->with([
                // program_id is nullable, so a result can outlive its program.
                'program:id',
                'proposal:id,name',
                'contributesTo:id,statement',
                'indicators' => fn ($query) => $query
                    ->select(['id', 'name', 'result_id', 'project_id', 'measure', 'unit', 'baseline', 'target', 'frequency'])
                    ->with([
                        'project:id,name',
                        'progress:id,indicator_id,date,value',
                        'planIndicators' => fn ($query) => $query
                            ->select(['id', 'indicator_id', 'source_type', 'source_id', 'target', 'project_id', 'period'])
                            ->with([
                                'project:id,name',
                                // Rows, not counts: achievement is measured from the
                                // approved values themselves (PlanIndicator::achievedValue()).
                                'reports:id,plan_indicator_id,status,planned_date,value',
                            ])
                            ->orderBy('id'),
                        // source_type/source_id carry no foreign key, so morphTo
                        // can resolve to null; the view renders that explicitly.
                        'planIndicators.source' => fn (MorphTo $morphTo) => $morphTo->morphWith([
                            AnnualPlan::class => [],
                            Proposal::class => ['donorNames:id,name'],
                        ]),
                    ])
                    ->orderBy('name')
                    ->orderBy('id'),
            ])
            ->orderBy('id')
            ->get();
    }

    /**
     * The one proposal whose framework this page is pinned to, or null for a
     * page that reads every framework (results/index).
     */
    protected function fixedProposalId(): ?int
    {
        return null;
    }

    /**
     * Results this page may read and write: the pinned proposal's framework,
     * or every framework. Every id a page receives is loaded through it, so
     * another proposal's node is not found from a pinned page (BOLA).
     *
     * @return Builder<Result>
     */
    protected function frameworkResults(): Builder
    {
        $proposalId = $this->fixedProposalId();

        return $proposalId === null
            ? Result::query()
            : Result::query()->proposalFramework($proposalId);
    }

    /**
     * Every statement keyed by id, for walking lineage without another query.
     *
     * @return array<int, Result>
     */
    #[Computed]
    public function resultsById(): array
    {
        return $this->allResults->keyBy('id')->all();
    }

    /**
     * How many statements are nested directly under each one, before filters.
     *
     * Deletion is refused on a statement that still carries a subtree, and
     * that answer must not change because a filter hid the children.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function childCounts(): array
    {
        $counts = [];

        foreach ($this->allResults as $result) {
            if ($result->parent_id !== null) {
                $counts[$result->parent_id] = ($counts[$result->parent_id] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * How a target reads on the page: where it was committed, by whom, against
     * which project, and what has been reported against it.
     *
     * @return array{target: PlanIndicator, sourceType: string, sourceLabel: string|null, donors: list<string>, project: string|null, reports: int, approved: int, percent: float|null, status: string}
     */
    protected function describeTarget(PlanIndicator $target, Indicator $indicator): array
    {
        $source = $target->source;

        // Already in memory; handing it over saves achievedValue() a query per target.
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
            'reports' => $target->reports->count(),
            'approved' => $target->reports->where('status', ReportStatus::Approved)->count(),
            'percent' => $target->percentAchieved(),
            'status' => $target->achievementStatus(),
        ];
    }

    /**
     * The measurement attached to every statement, keyed by statement id.
     *
     * Achievement is rated against the calendar year, the same window the
     * indicator directory uses, so a figure means the same thing on both pages.
     *
     * `funders` is the distinct set of money sources reaching this statement —
     * a donor name where the target sits in a proposal, the plan year where it
     * sits in an annual plan. It is what makes the page answer "who is paying
     * for this result", which is the question the funding side actually asks.
     *
     * @return array<int, array{
     *     rows: list<array{indicator: Indicator, achieved: float|null, rag: RagStatus|null, targets: int, latest: float|null, latestDate: CarbonImmutable|null, series: array<int, float>, targetRows: list<array<string, mixed>>}>,
     *     achieved: float|null,
     *     targets: int,
     *     committed: float,
     *     reports: int,
     *     approved: int,
     *     funders: list<array{label: string, type: string}>,
     *     rag: array{green: int, amber: int, red: int},
     * }>
     */
    #[Computed]
    public function measurements(): array
    {
        $windowStart = now()->startOfYear();
        $windowEnd = now()->endOfYear();
        $measurements = [];

        foreach ($this->allResults as $result) {
            $rows = [];
            $rag = ['green' => 0, 'amber' => 0, 'red' => 0];
            $achievements = [];
            $targets = 0;
            $committed = 0.0;
            $reports = 0;
            $approved = 0;
            $funders = [];

            foreach ($result->indicators as $indicator) {
                $achieved = $indicator->percentAchieved();
                $status = $indicator->ragStatus($windowStart, $windowEnd);
                $latestReading = $indicator->progress->last();
                $targetRows = [];

                foreach ($indicator->planIndicators as $target) {
                    $described = $this->describeTarget($target, $indicator);
                    $targetRows[] = $described;
                    $targets++;
                    $committed += (float) $target->target;
                    $reports += $described['reports'];
                    $approved += $described['approved'];

                    foreach ($described['donors'] as $donor) {
                        $funders[$donor] = ['label' => $donor, 'type' => 'donor'];
                    }

                    if ($described['donors'] === [] && $described['sourceLabel'] !== null) {
                        $funders[$described['sourceLabel']] = ['label' => $described['sourceLabel'], 'type' => $described['sourceType']];
                    }
                }

                if ($achieved !== null) {
                    $achievements[] = $achieved;
                }

                if ($status !== null) {
                    $rag[$status->value]++;
                }

                $rows[] = [
                    'indicator' => $indicator,
                    'achieved' => $achieved,
                    'rag' => $status,
                    'targets' => count($targetRows),
                    'latest' => $indicator->latestValue(),
                    'latestDate' => $latestReading?->date,
                    'series' => $indicator->progress->map(fn ($reading): float => (float) $reading->value)->all(),
                    'targetRows' => $targetRows,
                ];
            }

            $measurements[$result->id] = [
                'rows' => $rows,
                'achieved' => $achievements === [] ? null : array_sum($achievements) / count($achievements),
                'targets' => $targets,
                'committed' => $committed,
                'reports' => $reports,
                'approved' => $approved,
                'funders' => array_values($funders),
                'rag' => $rag,
            ];
        }

        return $measurements;
    }
}
