<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\MilestoneStatus;
use App\Enums\ResultType;
use App\Models\ActivitySchedule;
use App\Models\AnnualPlan;
use App\Models\Donor;
use App\Models\Indicator;
use App\Models\Milestone;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Result;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The read-only results chain (T16): impact → outcome → output → activity,
 * cross-cutting roots in their own group, each node with its indicators, the
 * per-center targets and their achievement, and the gaps in the line.
 *
 * One builder for the proposal Logical Framework tab, the program page and
 * the annual plan page. Each loader reads a fixed set of queries (results with
 * indicators, library entries, targets and reports; the target sources; the
 * milestones of the scope), then the tree, the filters, the gaps and the
 * options are resolved in memory, so the count never grows with the chain.
 * Achievement is read only through PlanIndicator::achievedValue() /
 * percentAchieved() (approved reports), milestone progress only through
 * Milestone::weightedProgress() and link flags through
 * Milestone::activityLinkIssuesFor(). No money is read.
 *
 * @phpstan-type ChainTarget array{id: int, center: string, centerId: int|null, source: string|null, target: float, achieved: float, percent: float|null, status: string, issues: list<string>}
 * @phpstan-type ChainIndicator array{indicator: Indicator, code: string|null, name: string, linked: bool, inactive: bool, gaps: list<string>, targets: list<ChainTarget>}
 * @phpstan-type ChainProposal array{name: string, url: string|null, donor: string|null, donorUrl: string|null}
 * @phpstan-type ChainMilestone array{milestone: Milestone, issues: list<string>, cancelled: bool}
 * @phpstan-type ChainMilestoneGroup array{centerId: int|null, center: string|null, progress: float|null, hasCancelled: bool, milestones: list<ChainMilestone>}
 * @phpstan-type ChainNode array{result: Result, depth: int, gaps: list<string>, indicators: list<ChainIndicator>, lineage: list<Result>, proposal: ChainProposal|null, milestoneGroups: list<ChainMilestoneGroup>, schedule: array<string, list<int>|null>, children: list<array<string, mixed>>, contributions: list<array<string, mixed>>}
 * @phpstan-type ChainOption array{value: string, label: string}
 * @phpstan-type ChainFilters array{indicator: string, center: string, donor: string}
 * @phpstan-type Chain array{
 *     tree: list<ChainNode>,
 *     crossCutting: list<ChainNode>,
 *     unlinkedMilestones: list<Milestone>,
 *     options: array{indicators: list<ChainOption>, centers: list<ChainOption>, donors: list<ChainOption>},
 *     filters: ChainFilters,
 *     summary: array{levels: array<string, int>, indicators: int, unmeasured: int},
 *     gaps: array{no_indicators: int, no_target: int, no_milestone: int, unlinked_milestone: int},
 *     isEmpty: bool,
 * }
 */
final class ResultsChain
{
    /**
     * @param  Collection<int, Result>  $results
     * @param  Collection<int, Milestone>  $milestones
     * @param  array<int, true>  $ownResultIds  the scope's own framework (summary counts)
     * @param  array<string, string>  $filters
     */
    private function __construct(
        private readonly Collection $results,
        private readonly Collection $milestones,
        private readonly array $ownResultIds,
        private array $filters,
    ) {}

    /**
     * Activity-link flags of every milestone in scope, keyed by milestone id.
     *
     * @var array<int, list<string>>
     */
    private array $linkIssues = [];

    /**
     * The proposal's own framework, its milestones and those of its centers.
     *
     * @param  array<string, string>  $filters
     * @return Chain
     */
    public static function forProposal(Proposal $proposal, array $filters): array
    {
        $results = self::resultsQuery()->proposalFramework($proposal->id)->get();

        $milestones = self::milestonesQuery()
            ->where(fn (Builder $query) => $query
                ->where('proposal_id', $proposal->id)
                ->orWhereIn('project_id', DB::table('project_proposal')->select('project_id')->where('proposal_id', $proposal->id)))
            ->get();

        return (new self($results, $milestones, self::idSet($results), $filters))->build();
    }

    /**
     * The program framework, with every proposal framework whose roots
     * contribute to one of its results (a multi-program proposal shows under
     * each program it contributes to).
     *
     * @param  array<string, string>  $filters
     * @return Chain
     */
    public static function forProgram(int $programId, array $filters): array
    {
        $programResultIds = Result::query()->programFramework()->select('id')->where('program_id', $programId);

        $results = self::resultsQuery()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $own) => $own->whereNull('proposal_id')->where('program_id', $programId))
                ->orWhereIn('proposal_id', Result::query()->select('proposal_id')->whereIn('contributes_to_result_id', $programResultIds)))
            ->get();

        $results = self::contributingToProgram($results);

        $milestones = self::milestonesQuery()
            ->where(fn (Builder $query) => $query
                ->whereIn('project_id', Project::query()->select('id')->where('program_id', $programId))
                ->orWhereIn('result_id', $results->modelKeys()))
            ->get();

        $own = $results->filter(fn (Result $result): bool => $result->proposal_id === null);

        return (new self($results, $milestones, self::idSet($own), $filters))->build();
    }

    /**
     * Keep the program framework and, of each proposal framework, only the
     * subtrees whose root contributes to a program result loaded here: a
     * multi-program proposal's other roots belong to the other program's page.
     *
     * @param  Collection<int, Result>  $results
     * @return Collection<int, Result>
     */
    private static function contributingToProgram(Collection $results): Collection
    {
        $byId = $results->keyBy('id');
        $programIds = $results->whereNull('proposal_id')->modelKeys();

        return $results
            ->filter(function (Result $result) use ($byId, $programIds): bool {
                if ($result->proposal_id === null) {
                    return true;
                }

                $root = $result;
                $seen = [];

                while ($root->parent_id !== null && $byId->has($root->parent_id) && ! isset($seen[$root->id])) {
                    $seen[$root->id] = true;
                    $root = $byId->get($root->parent_id);
                }

                return in_array($root->contributes_to_result_id, $programIds, true);
            })
            ->values();
    }

    /**
     * The annual plan's program framework, measured by this plan's targets
     * only, with the activities whose linked milestones are planned in the
     * plan year (no annual_plan_activities table before T24, D-18).
     *
     * @param  array<string, string>  $filters
     * @return Chain
     */
    public static function forAnnualPlan(AnnualPlan $plan, array $filters): array
    {
        $yearStart = CarbonImmutable::create($plan->year)->startOfYear()->toDateString();
        $yearEnd = CarbonImmutable::create($plan->year)->endOfYear()->toDateString();

        $results = self::resultsQuery(fn (HasMany $query) => $query->where('source_type', 'annual_plan')->where('source_id', $plan->id))
            ->programFramework()
            ->where('program_id', $plan->program_id)
            ->get();

        $milestones = self::milestonesQuery()
            ->whereIn('project_id', Project::query()->select('id')->where('program_id', $plan->program_id))
            ->where(fn (Builder $query) => $query
                ->whereRaw('coalesce(planned_start_date, planned_end_date) <= ?', [$yearEnd])
                ->whereRaw('coalesce(planned_end_date, planned_start_date) >= ?', [$yearStart]))
            ->get();

        $linkedActivityIds = $milestones->pluck('result_id')->filter()->flip();
        $results = $results
            ->reject(fn (Result $result): bool => $result->type === ResultType::Activity && ! $linkedActivityIds->has($result->id))
            ->values();

        return (new self($results, $milestones, self::idSet($results), $filters))->build();
    }

    /**
     * Statements with their indicators, library entries, targets (+ project)
     * and the reports achievement is measured from.
     *
     * @param  (callable(HasMany<PlanIndicator, Indicator>): mixed)|null  $targetScope
     * @return Builder<Result>
     */
    private static function resultsQuery(?callable $targetScope = null): Builder
    {
        return Result::query()
            ->select(['id', 'statement', 'parent_id', 'program_id', 'proposal_id', 'contributes_to_result_id', 'type', 'activity_kind', 'checkout_period'])
            ->with([
                'schedules' => fn ($query) => $query->select(['id', 'result_id', 'project_id', 'month', 'weeks'])->orderBy('month'),
                'proposal:id,name,slug,donor_id',
                'proposal.donor:id,name,slug',
                'indicators' => fn ($query) => $query
                    ->select(['id', 'name', 'result_id', 'library_indicator_id', 'code', 'project_id', 'measure', 'unit'])
                    ->with([
                        'libraryIndicator:id,code,name_ar,name_en,is_active',
                        'planIndicators' => function ($query) use ($targetScope): void {
                            $query->select(['id', 'indicator_id', 'source_type', 'source_id', 'target', 'project_id', 'period'])
                                ->with([
                                    'project:id,name,center_id,program_id',
                                    'reports:id,plan_indicator_id,status,planned_date,value',
                                ])
                                ->orderBy('id');

                            if ($targetScope !== null) {
                                $targetScope($query);
                            }
                        },
                    ])
                    ->orderBy('name')
                    ->orderBy('id'),
            ])
            ->orderBy('id');
    }

    /**
     * @return Builder<Milestone>
     */
    private static function milestonesQuery(): Builder
    {
        return Milestone::query()
            ->select(['id', 'slug', 'name', 'status', 'project_id', 'proposal_id', 'result_id', 'planned_start_date', 'planned_end_date', 'sequence', 'weight_percentage'])
            ->with('project:id,name,center_id')
            ->orderBy('planned_start_date')
            ->orderBy('id');
    }

    /**
     * @param  Collection<int, Result>  $results
     * @return array<int, true>
     */
    private static function idSet(Collection $results): array
    {
        return $results->mapWithKeys(fn (Result $result): array => [$result->id => true])->all();
    }

    /**
     * @return Chain
     */
    private function build(): array
    {
        $this->attachSources();

        $options = $this->options();
        $this->filters = $this->validFilters($options);
        $this->linkIssues = Milestone::activityLinkIssuesFor($this->milestones);

        $resultsById = $this->results->keyBy('id')->all();
        $milestonesByActivity = [];

        foreach ($this->milestones as $milestone) {
            if ($milestone->result_id !== null) {
                $milestonesByActivity[$milestone->result_id][] = $milestone;
            }
        }

        $visibleIds = [];

        foreach ($this->results as $result) {
            if ($this->matchesFilters($result, $milestonesByActivity[$result->id] ?? [])) {
                $visibleIds[$result->id] = true;
            }
        }

        $childrenOf = [];
        $roots = [];

        foreach ($this->results as $result) {
            if (! isset($visibleIds[$result->id])) {
                continue;
            }

            $parentId = $this->parentIdOf($result, $resultsById);

            if ($parentId !== null && isset($visibleIds[$parentId])) {
                $childrenOf[$parentId][] = $result;

                continue;
            }

            $roots[] = $result;
        }

        $nodes = array_map(
            fn (Result $root): array => $this->node($root, 0, $childrenOf, $resultsById, $visibleIds, $milestonesByActivity),
            $roots,
        );

        $tree = array_values(array_filter($nodes, fn (array $node): bool => $node['result']->type !== ResultType::CrossCutting));
        $crossCutting = array_values(array_filter($nodes, fn (array $node): bool => $node['result']->type === ResultType::CrossCutting));
        $unlinked = $this->milestones->whereNull('result_id')->values();

        return [
            'tree' => $tree,
            'crossCutting' => $crossCutting,
            'unlinkedMilestones' => array_values($unlinked->all()),
            'options' => $options,
            'filters' => [
                'indicator' => $this->filters['indicator'],
                'center' => $this->filters['center'],
                'donor' => $this->filters['donor'],
            ],
            'summary' => $this->summary(),
            'gaps' => $this->gapCounts($milestonesByActivity, $unlinked->count()),
            'isEmpty' => $this->results->isEmpty(),
        ];
    }

    /**
     * Resolve every target's source (proposal or annual plan) in two reads,
     * without loading a proposal's money columns, and hand each target its
     * indicator (and the indicator its result) from memory.
     */
    private function attachSources(): void
    {
        $targets = $this->results->flatMap(fn (Result $result) => $result->indicators->flatMap(function (Indicator $indicator) use ($result) {
            $indicator->setRelation('result', $result);

            return $indicator->planIndicators->each(fn (PlanIndicator $target) => $target->setRelation('indicator', $indicator));
        }));

        $proposalIds = $targets->where('source_type', 'proposal')->pluck('source_id')->unique()->values()->all();
        $planIds = $targets->where('source_type', 'annual_plan')->pluck('source_id')->unique()->values()->all();

        $proposals = $proposalIds === [] ? collect() : Proposal::query()
            ->select(['id', 'name', 'slug', 'donor_id'])
            ->with(['donor:id,name,slug', 'projects:id'])
            ->whereIn('id', $proposalIds)
            ->get()
            ->keyBy('id');

        $plans = $planIds === [] ? collect() : AnnualPlan::query()
            ->select(['id', 'program_id', 'year'])
            ->whereIn('id', $planIds)
            ->get()
            ->keyBy('id');

        foreach ($targets as $target) {
            $target->setRelation('source', match ($target->source_type) {
                'proposal' => $proposals->get($target->source_id),
                'annual_plan' => $plans->get($target->source_id),
                default => null,
            });
        }
    }

    /**
     * The parent a node hangs from in this chain: its own parent, or (for a
     * proposal root on the program page) the program result it contributes to.
     *
     * @param  array<int, Result>  $resultsById
     */
    private function parentIdOf(Result $result, array $resultsById): ?int
    {
        if ($result->parent_id !== null) {
            return $result->parent_id;
        }

        $contributesTo = $result->contributes_to_result_id;

        return $contributesTo !== null && isset($resultsById[$contributesTo]) ? $contributesTo : null;
    }

    /**
     * Whether one statement survives the filters, decided per node, never per
     * subtree (.ai/rules/results.md).
     *
     * @param  list<Milestone>  $milestones  the milestones linked to this result
     */
    private function matchesFilters(Result $result, array $milestones): bool
    {
        ['indicator' => $indicator, 'center' => $center, 'donor' => $donor] = $this->filters;

        if ($indicator !== '' && ! $result->indicators->contains(fn (Indicator $row): bool => (string) $row->library_indicator_id === $indicator)) {
            return false;
        }

        if ($center !== '') {
            $hasTarget = $result->indicators->contains(fn (Indicator $row): bool => $row->planIndicators->contains(fn (PlanIndicator $target): bool => (string) $target->project_id === $center));
            $hasMilestone = collect($milestones)->contains(fn (Milestone $milestone): bool => (string) $milestone->project_id === $center);

            if (! $hasTarget && ! $hasMilestone) {
                return false;
            }
        }

        if ($donor !== '') {
            $ownDonor = (string) $result->proposal?->donor_id === $donor;
            $targetDonor = $result->indicators->contains(fn (Indicator $row): bool => $row->planIndicators->contains(fn (PlanIndicator $target): bool => $this->donorIdOf($target) === $donor));

            if (! $ownDonor && ! $targetDonor) {
                return false;
            }
        }

        return true;
    }

    private function donorIdOf(PlanIndicator $target): ?string
    {
        $source = $target->getRelation('source');

        return $source instanceof Proposal ? (string) $source->donor_id : null;
    }

    /**
     * @param  array<int, list<Result>>  $childrenOf
     * @param  array<int, Result>  $resultsById
     * @param  array<int, true>  $visibleIds
     * @param  array<int, list<Milestone>>  $milestonesByActivity
     * @return ChainNode
     */
    private function node(Result $result, int $depth, array $childrenOf, array $resultsById, array $visibleIds, array $milestonesByActivity): array
    {
        $children = [];
        $contributions = [];

        foreach ($childrenOf[$result->id] ?? [] as $child) {
            $node = $this->node($child, $depth + 1, $childrenOf, $resultsById, $visibleIds, $milestonesByActivity);

            if ($child->parent_id === $result->id) {
                $children[] = $node;
            } else {
                $contributions[] = $node;
            }
        }

        $gaps = [];

        if ($result->indicators->isEmpty()) {
            $gaps[] = 'no_indicators';
        }

        if ($result->type === ResultType::Activity && ! isset($milestonesByActivity[$result->id])) {
            $gaps[] = 'no_milestone';
        }

        return [
            'result' => $result,
            'depth' => $depth,
            'gaps' => $gaps,
            'indicators' => $this->indicatorRows($result),
            'lineage' => $depth === 0 ? $this->hiddenLineage($result, $resultsById, $visibleIds) : [],
            'proposal' => $this->proposalTag($result, $resultsById),
            'milestoneGroups' => $result->type === ResultType::Activity ? $this->milestoneGroups($milestonesByActivity[$result->id] ?? []) : [],
            'schedule' => $result->type === ResultType::Activity ? $this->scheduleOf($result) : [],
            'children' => $children,
            'contributions' => $contributions,
        ];
    }

    /**
     * An activity's milestones grouped by center in delivery order, each
     * group with its weighted progress (cancelled milestones listed but not
     * counted). A proposal-level milestone without a project (T31) forms
     * a proposal-wide group, first. A center filter keeps that center's
     * group and the proposal-wide one.
     *
     * @param  list<Milestone>  $milestones
     * @return list<ChainMilestoneGroup>
     */
    private function milestoneGroups(array $milestones): array
    {
        $center = $this->filters['center'];
        $groups = [];

        foreach ($milestones as $milestone) {
            if ($center !== '' && $milestone->project_id !== null && (string) $milestone->project_id !== $center) {
                continue;
            }

            $key = $milestone->project_id === null ? 'proposal' : (string) $milestone->project_id;

            $groups[$key] ??= [
                'centerId' => $milestone->project_id,
                'center' => $milestone->project === null ? null : ($milestone->project->center()->name ?? $milestone->project->name),
                'items' => [],
            ];
            $groups[$key]['items'][] = $milestone;
        }

        if (isset($groups['proposal'])) {
            $groups = ['proposal' => $groups['proposal']] + $groups;
        }

        return array_values(array_map(fn (array $group): array => [
            'center' => $group['center'],
            'centerId' => $group['centerId'],
            'progress' => Milestone::weightedProgress($group['items']),
            'hasCancelled' => collect($group['items'])->contains(fn (Milestone $milestone): bool => $milestone->status === MilestoneStatus::Cancelled),
            'milestones' => array_map(fn (Milestone $milestone): array => [
                'milestone' => $milestone,
                'issues' => $this->linkIssues[$milestone->id] ?? [],
                'cancelled' => $milestone->status === MilestoneStatus::Cancelled,
            ], $group['items']),
        ], $groups));
    }

    /**
     * The months an activity is planned in: the proposal calendar, or under
     * a center filter that center's own calendar when it has one (the rule
     * of ActivitySchedule::effectiveFor(), read from the eager-loaded rows).
     *
     * @return array<string, list<int>|null>
     */
    private function scheduleOf(Result $result): array
    {
        $center = $this->filters['center'];
        $override = $center === '' ? collect() : $result->schedules->filter(fn (ActivitySchedule $row): bool => (string) $row->project_id === $center);

        return ActivitySchedule::calendarOf($override->isNotEmpty() ? $override : $result->schedules->whereNull('project_id'));
    }

    /**
     * The statements above a promoted node that the filters hid, outermost first.
     *
     * @param  array<int, Result>  $resultsById
     * @param  array<int, true>  $visibleIds
     * @return list<Result>
     */
    private function hiddenLineage(Result $result, array $resultsById, array $visibleIds): array
    {
        $lineage = [];
        $parentId = $this->parentIdOf($result, $resultsById);

        while ($parentId !== null && ! isset($visibleIds[$parentId]) && isset($resultsById[$parentId])) {
            $parent = $resultsById[$parentId];
            array_unshift($lineage, $parent);
            $parentId = $this->parentIdOf($parent, $resultsById);
        }

        return $lineage;
    }

    /**
     * The proposal and donor names on a proposal root shown inside another
     * framework (the program page). Links only for their view permission.
     *
     * @param  array<int, Result>  $resultsById
     * @return ChainProposal|null
     */
    private function proposalTag(Result $result, array $resultsById): ?array
    {
        $proposal = $result->proposal;

        if ($proposal === null || $result->parent_id !== null || $this->ownResultIds !== [] && isset($this->ownResultIds[$result->id])) {
            return null;
        }

        $donor = $proposal->donor;

        return [
            'name' => $proposal->name,
            'url' => Gate::allows('proposals.view') ? route('proposals.show', $proposal) : null,
            'donor' => $donor?->name,
            'donorUrl' => $donor instanceof Donor && Gate::allows('donors.view') ? route('donors.show', $donor) : null,
        ];
    }

    /**
     * @return list<ChainIndicator>
     */
    private function indicatorRows(Result $result): array
    {
        $rows = [];

        foreach ($result->indicators as $indicator) {
            if ($this->filters['indicator'] !== '' && (string) $indicator->library_indicator_id !== $this->filters['indicator']) {
                continue;
            }

            $entry = $indicator->libraryIndicator;
            $targets = [];

            foreach ($indicator->planIndicators as $target) {
                if ($this->filters['center'] !== '' && (string) $target->project_id !== $this->filters['center']) {
                    continue;
                }

                if ($this->filters['donor'] !== '' && $this->donorIdOf($target) !== $this->filters['donor']) {
                    continue;
                }

                $targets[] = $this->targetRow($target);
            }

            $rows[] = [
                'indicator' => $indicator,
                'code' => $entry->code ?? $indicator->code,
                'name' => $entry?->localizedName() ?? $indicator->name,
                'linked' => $entry !== null,
                'inactive' => $entry !== null && ! $entry->is_active,
                'gaps' => $indicator->planIndicators->isEmpty() ? ['no_target'] : [],
                'targets' => $targets,
            ];
        }

        return $rows;
    }

    /**
     * @return ChainTarget
     */
    private function targetRow(PlanIndicator $target): array
    {
        $project = $target->project;
        $source = $target->getRelation('source');

        return [
            'id' => $target->id,
            'center' => $project === null ? self::label('results_framework.chain_org_wide') : ($project->center()->name ?? $project->name),
            'centerId' => $project?->id,
            'source' => match (true) {
                $source instanceof Proposal => $source->name,
                $source instanceof AnnualPlan => self::label('common.fy_year', ['year' => $source->year]),
                default => null,
            },
            'target' => (float) $target->target,
            'achieved' => $target->achievedValue(),
            'percent' => $target->percentAchieved(),
            'status' => $target->achievementStatus(),
            'issues' => $target->chainIssues(),
        ];
    }

    /**
     * A translated line; the key itself when the group returns an array.
     *
     * @param  array<string, mixed>  $replace
     */
    private static function label(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }

    /**
     * The filter choices, from the loaded chain only.
     *
     * @return array{indicators: list<ChainOption>, centers: list<ChainOption>, donors: list<ChainOption>}
     */
    private function options(): array
    {
        $indicators = [];
        $centers = [];
        $donors = [];

        foreach ($this->results as $result) {
            if ($result->proposal?->donor !== null) {
                $donors[$result->proposal->donor->id] = $result->proposal->donor->name;
            }

            foreach ($result->indicators as $indicator) {
                $entry = $indicator->libraryIndicator;

                if ($entry !== null) {
                    $indicators[$entry->id] = $entry->code.' · '.$entry->localizedName();
                }

                foreach ($indicator->planIndicators as $target) {
                    if ($target->project !== null) {
                        $centers[$target->project->id] = $target->project->center()->name ?? $target->project->name;
                    }

                    $source = $target->getRelation('source');

                    if ($source instanceof Proposal && $source->donor !== null) {
                        $donors[$source->donor->id] = $source->donor->name;
                    }
                }
            }
        }

        foreach ($this->milestones as $milestone) {
            if ($milestone->project !== null) {
                $centers[$milestone->project->id] = $milestone->project->center()->name ?? $milestone->project->name;
            }
        }

        return [
            'indicators' => self::sortedOptions($indicators),
            'centers' => self::sortedOptions($centers),
            'donors' => self::sortedOptions($donors),
        ];
    }

    /**
     * @param  array<int, string>  $labels
     * @return list<ChainOption>
     */
    private static function sortedOptions(array $labels): array
    {
        asort($labels, SORT_NATURAL | SORT_FLAG_CASE);

        return array_map(
            fn (int $value, string $label): array => ['value' => (string) $value, 'label' => $label],
            array_keys($labels),
            $labels,
        );
    }

    /**
     * Filter values checked against the loaded sets; an unknown value
     * falls back to "All".
     *
     * @param  array{indicators: list<ChainOption>, centers: list<ChainOption>, donors: list<ChainOption>}  $options
     * @return ChainFilters
     */
    private function validFilters(array $options): array
    {
        $input = [
            'indicator' => (string) ($this->filters['indicator'] ?? ''),
            'center' => (string) ($this->filters['center'] ?? ''),
            'donor' => (string) ($this->filters['donor'] ?? ''),
        ];

        $rules = [
            'indicator' => ['nullable', Rule::in(array_column($options['indicators'], 'value'))],
            'center' => ['nullable', Rule::in(array_column($options['centers'], 'value'))],
            'donor' => ['nullable', Rule::in(array_column($options['donors'], 'value'))],
        ];

        $failed = Validator::make($input, $rules)->errors();

        foreach (array_keys($input) as $key) {
            if ($failed->has($key)) {
                $input[$key] = '';
            }
        }

        return $input;
    }

    /**
     * Level counts and measurement over the scope's own framework.
     *
     * @return array{levels: array<string, int>, indicators: int, unmeasured: int}
     */
    private function summary(): array
    {
        $own = $this->results->filter(fn (Result $result): bool => isset($this->ownResultIds[$result->id]));

        return [
            'levels' => collect(ResultType::cases())
                ->mapWithKeys(fn (ResultType $type): array => [$type->value => $own->where('type', $type)->count()])
                ->all(),
            'indicators' => $own->sum(fn (Result $result): int => $result->indicators->count()),
            'unmeasured' => $own->filter(fn (Result $result): bool => $result->indicators->isEmpty())->count(),
        ];
    }

    /**
     * @param  array<int, list<Milestone>>  $milestonesByActivity
     * @return array{no_indicators: int, no_target: int, no_milestone: int, unlinked_milestone: int}
     */
    private function gapCounts(array $milestonesByActivity, int $unlinked): array
    {
        return [
            'no_indicators' => $this->results->filter(fn (Result $result): bool => $result->indicators->isEmpty())->count(),
            'no_target' => $this->results->sum(fn (Result $result): int => $result->indicators->filter(fn (Indicator $indicator): bool => $indicator->planIndicators->isEmpty())->count()),
            'no_milestone' => $this->results->filter(fn (Result $result): bool => $result->type === ResultType::Activity && ! isset($milestonesByActivity[$result->id]))->count(),
            'unlinked_milestone' => $unlinked,
        ];
    }
}
