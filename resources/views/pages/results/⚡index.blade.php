<?php

use App\Enums\Period;
use App\Enums\RagStatus;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Models\AnnualPlan;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Proposal;
use App\Models\Result;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('titles.results_framework')] class extends Component {
    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $program = '';

    #[Url(except: '')]
    public string $type = '';

    /**
     * Narrows the framework to the statements that need attention.
     *
     * '' shows everything, 'unmeasured' only statements carrying no indicator,
     * 'offtrack' only statements whose indicators are rating amber or red.
     */
    #[Url(except: '')]
    public string $focus = '';

    /** 'cascade' draws the chain, 'matrix' lists every statement in one grid. */
    #[Url(except: 'cascade')]
    public string $view = 'cascade';

    public bool $showResultModal = false;

    public bool $showDeleteModal = false;

    #[Locked]
    public ?int $editingResultId = null;

    #[Locked]
    public ?int $deletingResultId = null;

    public string $statement = '';

    public string $resultType = 'outcome';

    public string $checkoutPeriod = 'quarterly';

    public string $resultProgramId = '';

    public string $parentId = '';

    public function clearFilters(): void
    {
        $this->search = '';
        $this->program = '';
        $this->type = '';
        $this->focus = '';
    }

    /**
     * Toggle one of the attention chips; clicking the active one clears it.
     */
    public function setFocus(string $focus): void
    {
        $this->focus = $this->focus === $focus ? '' : $focus;
    }

    /**
     * Toggle the level filter from a chain card; clicking the active one clears it.
     */
    public function setType(string $type): void
    {
        $this->type = $this->type === $type ? '' : $type;
    }

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
        return Result::query()
            ->select(['id', 'statement', 'parent_id', 'program_id', 'type', 'checkout_period'])
            ->with([
                // program_id is nullable, so a result can outlive its program.
                'program:id',
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
     *     rows: list<array{indicator: \App\Models\Indicator, achieved: float|null, rag: RagStatus|null, targets: int, latest: float|null, latestDate: \Carbon\CarbonImmutable|null, series: list<float>, targetRows: list<array<string, mixed>>}>,
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

    /**
     * Whether one statement survives the current filters.
     *
     * The search reaches the indicators too: looking for "people reached"
     * should find the statement that measure hangs from, not nothing.
     */
    protected function matchesFilters(Result $result, ?string $focus = null): bool
    {
        $focus ??= $this->focus;

        if ($this->program === 'none' && $result->program_id !== null) {
            return false;
        }

        if ($this->program !== '' && $this->program !== 'none' && $result->program_id !== (int) $this->program) {
            return false;
        }

        if ($this->type !== '' && $result->type->value !== $this->type) {
            return false;
        }

        $measurement = $this->measurements[$result->id];

        if ($focus === 'unmeasured' && $measurement['rows'] !== []) {
            return false;
        }

        if ($focus === 'offtrack' && $measurement['rag']['amber'] + $measurement['rag']['red'] === 0) {
            return false;
        }

        $search = trim($this->search);

        if ($search === '') {
            return true;
        }

        if (str_contains(mb_strtolower($result->statement), mb_strtolower($search))) {
            return true;
        }

        return $result->indicators->contains(
            fn ($indicator): bool => str_contains(mb_strtolower($indicator->name), mb_strtolower($search))
        );
    }

    /**
     * The statements above this one that the filters hid, outermost first.
     *
     * A statement re-rooted because its parent was filtered out still has to
     * say what it contributes to, or the chain reads as a flat list.
     *
     * @param  array<int, true>  $visibleIds
     * @return list<Result>
     */
    protected function hiddenLineage(Result $result, array $visibleIds): array
    {
        $lineage = [];
        $parentId = $result->parent_id;

        while ($parentId !== null && ! isset($visibleIds[$parentId])) {
            $parent = $this->resultsById[$parentId] ?? null;

            if ($parent === null) {
                break;
            }

            array_unshift($lineage, $parent);
            $parentId = $parent->parent_id;
        }

        return $lineage;
    }

    /**
     * Build one node: the statement, its measurement, its rollup and its
     * visible children.
     *
     * @param  array<int, list<Result>>  $childrenByParent
     * @param  array<int, true>  $visibleIds
     * @return array<string, mixed>
     */
    protected function buildNode(Result $result, array $childrenByParent, array $visibleIds, int $depth): array
    {
        $children = [];

        foreach ($childrenByParent[$result->id] ?? [] as $child) {
            $children[] = $this->buildNode($child, $childrenByParent, $visibleIds, $depth + 1);
        }

        $measurement = $this->measurements[$result->id];
        $ownIndicators = count($measurement['rows']);

        $rollup = [
            'statements' => 1,
            'indicators' => $ownIndicators,
            'targets' => $measurement['targets'],
            'reports' => $measurement['reports'],
            'unmeasured' => $ownIndicators === 0 ? 1 : 0,
        ];

        foreach ($children as $child) {
            foreach (array_keys($rollup) as $key) {
                $rollup[$key] += $child['rollup'][$key];
            }
        }

        return [
            'result' => $result,
            'depth' => $depth,
            'deletable' => ($this->childCounts[$result->id] ?? 0) === 0 && $ownIndicators === 0,
            'measurement' => $measurement,
            'children' => $children,
            'rollup' => $rollup,
            'lineage' => $depth === 0 ? $this->hiddenLineage($result, $visibleIds) : [],
        ];
    }

    /**
     * The visible framework as root nodes grouped by program name, with an
     * explicit Unassigned group last.
     *
     * A statement whose parent the filters hid is promoted to a root of its
     * own so nothing disappears silently; it carries the hidden lineage.
     *
     * @return BaseCollection<string, list<array<string, mixed>>>
     */
    #[Computed]
    public function groupedRoots(): BaseCollection
    {
        $visible = $this->allResults->filter(fn (Result $result): bool => $this->matchesFilters($result));
        $visibleIds = $visible->mapWithKeys(fn (Result $result): array => [$result->id => true])->all();

        $childrenByParent = [];
        $roots = [];

        foreach ($visible as $result) {
            if ($result->parent_id !== null && isset($visibleIds[$result->parent_id])) {
                $childrenByParent[$result->parent_id][] = $result;

                continue;
            }

            $roots[] = $result;
        }

        $unassigned = __('common.unassigned');

        return collect($roots)
            ->map(fn (Result $result): array => $this->buildNode($result, $childrenByParent, $visibleIds, 0))
            ->groupBy(fn (array $node): string => $node['result']->program?->name ?? $unassigned)
            ->map(fn (BaseCollection $nodes): array => $nodes->all())
            ->sortKeysUsing(fn (string $left, string $right): int => match (true) {
                $left === $unassigned => 1,
                $right === $unassigned => -1,
                default => strcasecmp($left, $right),
            });
    }

    /**
     * Every visible node flattened into reading order, each tagged with the
     * program it sits under. Drives the matrix view and every headline figure.
     *
     * @return list<array{node: array<string, mixed>, program: string}>
     */
    #[Computed]
    public function visibleNodes(): array
    {
        $rows = [];

        $walk = function (array $node, string $program) use (&$walk, &$rows): void {
            $rows[] = ['node' => $node, 'program' => $program];

            foreach ($node['children'] as $child) {
                $walk($child, $program);
            }
        };

        foreach ($this->groupedRoots as $program => $nodes) {
            foreach ($nodes as $node) {
                $walk($node, $program);
            }
        }

        return $rows;
    }

    /**
     * Headline figures for the visible framework.
     *
     * @return array{
     *     statements: int,
     *     measured: int,
     *     indicators: int,
     *     targets: int,
     *     reports: int,
     *     approved: int,
     *     funders: list<array{label: string, type: string}>,
     *     coverage: float|null,
     *     achieved: float|null,
     *     rag: array{green: int, amber: int, red: int},
     *     rated: int,
     *     levels: array<string, array{statements: int, measured: int, indicators: int}>,
     * }
     */
    #[Computed]
    public function overview(): array
    {
        $levels = collect(ResultType::cases())
            ->mapWithKeys(fn (ResultType $case): array => [
                $case->value => ['statements' => 0, 'measured' => 0, 'indicators' => 0],
            ])
            ->all();

        $statements = 0;
        $measured = 0;
        $indicators = 0;
        $targets = 0;
        $reports = 0;
        $approved = 0;
        $funders = [];
        $rag = ['green' => 0, 'amber' => 0, 'red' => 0];
        $achievements = [];

        foreach ($this->visibleNodes as $row) {
            $result = $row['node']['result'];
            $measurement = $row['node']['measurement'];
            $ownIndicators = count($measurement['rows']);
            $level = $result->type->value;

            $statements++;
            $indicators += $ownIndicators;
            $targets += $measurement['targets'];
            $reports += $measurement['reports'];
            $approved += $measurement['approved'];
            $levels[$level]['statements']++;
            $levels[$level]['indicators'] += $ownIndicators;

            foreach ($measurement['funders'] as $funder) {
                $funders[$funder['label']] = $funder;
            }

            if ($ownIndicators > 0) {
                $measured++;
                $levels[$level]['measured']++;
            }

            foreach (array_keys($rag) as $band) {
                $rag[$band] += $measurement['rag'][$band];
            }

            if ($measurement['achieved'] !== null) {
                $achievements[] = $measurement['achieved'];
            }
        }

        return [
            'statements' => $statements,
            'measured' => $measured,
            'indicators' => $indicators,
            'targets' => $targets,
            'reports' => $reports,
            'approved' => $approved,
            'funders' => array_values($funders),
            'coverage' => $statements === 0 ? null : ($measured / $statements) * 100,
            'achieved' => $achievements === [] ? null : array_sum($achievements) / count($achievements),
            'rag' => $rag,
            'rated' => $rag['green'] + $rag['amber'] + $rag['red'],
            'levels' => $levels,
        ];
    }

    /**
     * What each attention chip would select, counted under the OTHER filters
     * that are active.
     *
     * Counting across the whole table instead would have a chip read "2" while
     * the program filter leaves only one of them reachable — the number and
     * the page would disagree the moment anything else was narrowed.
     *
     * @return array{unmeasured: int, offtrack: int}
     */
    #[Computed]
    public function focusCounts(): array
    {
        $counts = ['unmeasured' => 0, 'offtrack' => 0];

        foreach ($this->allResults as $result) {
            // Every filter but the focus itself, so each chip counts what it would select.
            if (! $this->matchesFilters($result, '')) {
                continue;
            }

            $measurement = $this->measurements[$result->id];

            if ($measurement['rows'] === []) {
                $counts['unmeasured']++;
            }

            if ($measurement['rag']['amber'] + $measurement['rag']['red'] > 0) {
                $counts['offtrack']++;
            }
        }

        return $counts;
    }

    /** @return Collection<int, Program> */
    #[Computed]
    public function programOptions(): Collection
    {
        return Program::options();
    }

    /**
     * The level a statement of this type contributes to, or null for an impact,
     * which sits at the top of the chain.
     */
    protected function parentLevelOf(string $type): ?ResultType
    {
        return match (ResultType::tryFrom($type)) {
            ResultType::Output => ResultType::Outcome,
            ResultType::Outcome => ResultType::Impact,
            default => null,
        };
    }

    /**
     * Results this one may contribute to: only statements one level up the
     * chain — an output under an outcome, an outcome under an impact.
     *
     * Excludes the node and its descendants so ResultObserver's
     * InvalidParentException stays a backstop. Unlike milestones there is no
     * project constraint.
     *
     * @return Collection<int, Result>
     */
    #[Computed]
    public function parentOptions(): Collection
    {
        $parentLevel = $this->parentLevelOf($this->resultType);

        if ($parentLevel === null) {
            return new Collection;
        }

        $excludedIds = [];

        if ($this->editingResultId !== null) {
            $result = Result::query()
                ->whereKey($this->editingResultId)
                ->with('childrenRecursive')
                ->first();

            if ($result !== null) {
                $excludedIds = $result->descendants()->pluck('id')->push($result->id)->all();
            }
        }

        return Result::query()
            ->select(['id', 'statement', 'type', 'program_id'])
            ->with('program:id')
            ->where('type', $parentLevel->value)
            ->when($excludedIds !== [], fn ($query) => $query->whereNotIn('id', $excludedIds))
            ->orderBy('program_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * A parent chosen for the old level cannot hold the new one, so a change
     * of type drops it rather than carrying an illegal link to the save.
     */
    public function updatedResultType(): void
    {
        unset($this->parentOptions);

        if ($this->parentId !== '' && ! $this->parentOptions->contains('id', (int) $this->parentId)) {
            $this->parentId = '';
        }
    }

    /**
     * A contributing statement lives in its parent's program, so choosing a
     * parent sets the program to match.
     */
    public function updatedParentId(): void
    {
        if ($this->parentId === '') {
            return;
        }

        $parent = Result::query()->select(['id', 'program_id'])->find((int) $this->parentId);

        if ($parent !== null) {
            $this->resultProgramId = (string) ($parent->program_id ?? '');
        }
    }

    /**
     * Whether this result may be deleted.
     *
     * Refused when it has children (parent_id cascades, taking the subtree) or
     * indicators (indicators cascade, and plan_indicators and reports cascade
     * from those — three tables lost behind one click).
     */
    protected function canDelete(int $resultId): bool
    {
        $result = Result::query()
            ->select(['id'])
            ->withCount(['children', 'indicators'])
            ->find($resultId);

        return $result !== null && $result->children_count === 0 && $result->indicators_count === 0;
    }

    /**
     * Open the create form, optionally pre-wired under an existing statement.
     */
    public function openCreateModal(?int $parentResultId = null): void
    {
        Gate::authorize('results.create');

        $this->resetResultForm();

        if ($parentResultId !== null) {
            $parent = Result::query()
                ->select(['id', 'program_id', 'type'])
                ->find($parentResultId);

            // An output is the bottom of the chain: nothing can contribute to it.
            if ($parent !== null && $parent->type !== ResultType::Output) {
                $this->parentId = (string) $parent->id;
                $this->resultProgramId = (string) ($parent->program_id ?? '');
                $this->resultType = $parent->type === ResultType::Impact
                    ? ResultType::Outcome->value
                    : ResultType::Output->value;
            }
        }

        $this->showResultModal = true;
    }

    public function openEditModal(int $resultId): void
    {
        Gate::authorize('results.update');

        $result = Result::query()
            ->select(['id', 'statement', 'parent_id', 'program_id', 'type', 'checkout_period'])
            ->findOrFail($resultId);

        $this->resetValidation();
        $this->editingResultId = $result->id;
        $this->statement = $result->statement;
        $this->resultType = $result->type->value;
        $this->checkoutPeriod = $result->checkout_period->value;
        $this->resultProgramId = (string) ($result->program_id ?? '');
        $this->parentId = (string) ($result->parent_id ?? '');
        $this->showResultModal = true;
    }

    public function saveResult(): void
    {
        Gate::authorize($this->editingResultId === null ? 'results.create' : 'results.update');

        $this->statement = trim($this->statement);

        $validated = $this->validate([
            'statement' => ['required', 'string'],
            'resultType' => ['required', new Enum(ResultType::class)],
            'checkoutPeriod' => ['required', new Enum(Period::class)],
            'resultProgramId' => ['nullable', 'integer', Rule::exists(Program::class, 'id')],
            'parentId' => ['nullable', 'integer', Rule::exists(Result::class, 'id')],
        ], [
            'statement.required' => __('results_framework.write_the_result_statement'),
            'resultType.required' => __('results_framework.choose_impact_outcome_or_output'),
            'checkoutPeriod.required' => __('common.choose_how_often_this_is_checked'),
        ]);

        $result = $this->editingResultId === null
            ? new Result
            : Result::query()->findOrFail($this->editingResultId);

        $parentId = $validated['parentId'] === '' || $validated['parentId'] === null ? null : (int) $validated['parentId'];
        $programId = $validated['resultProgramId'] === '' || $validated['resultProgramId'] === null ? null : (int) $validated['resultProgramId'];
        $type = ResultType::from($validated['resultType']);

        if ($parentId !== null) {
            $parent = Result::query()->select(['id', 'type', 'program_id'])->findOrFail($parentId);

            if ($parent->type !== $this->parentLevelOf($type->value)) {
                $this->addError('parentId', __('results_framework.a_result_contributes_only_to_the_level_directly_above'));

                return;
            }

            // A contributing statement always lives in its parent's program.
            $programId = $parent->program_id;
        }

        $subtreeIds = [];

        if ($result->exists) {
            $children = Result::query()->select(['id', 'type'])->where('parent_id', $result->id)->get();

            if ($children->isNotEmpty() && $type !== $result->type
                && $children->contains(fn (Result $child): bool => $this->parentLevelOf($child->type->value) !== $type)) {
                $this->addError('resultType', __('results_framework.nested_results_still_sit_at_the_level_below'));

                return;
            }

            if ($programId !== $result->program_id) {
                $subtreeIds = $result->descendants()->pluck('id')->push($result->id)->all();

                if ($programId !== null && $this->programChangeBreaksChain($subtreeIds, $programId)) {
                    $this->addError('resultProgramId', __('results_framework.moving_this_result_would_break_its_indicators_or_targets'));

                    return;
                }
            }
        }

        DB::transaction(function () use ($result, $validated, $type, $programId, $parentId, $subtreeIds): void {
            $result->fill([
                'statement' => $validated['statement'],
                'type' => $type,
                'checkout_period' => $validated['checkoutPeriod'],
                'program_id' => $programId,
                'parent_id' => $parentId,
            ])->save();

            // The statements below move with it, so the tree never straddles programs.
            if ($subtreeIds !== []) {
                Result::query()->whereIn('id', $subtreeIds)->whereKeyNot($result->id)->update(['program_id' => $programId]);
            }
        });

        $wasRecentlyCreated = $result->wasRecentlyCreated;

        $this->showResultModal = false;
        $this->resetResultForm();
        $this->forgetFramework();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('results_framework.result_added') : __('results_framework.result_updated'),
        );
    }

    /**
     * Whether moving these statements into a program would strand what hangs
     * off them: an indicator scoped to a project in another program, or a
     * target committed in another program's annual plan.
     *
     * @param  list<int>  $resultIds
     */
    protected function programChangeBreaksChain(array $resultIds, int $programId): bool
    {
        $indicatorIds = Indicator::query()->whereIn('result_id', $resultIds)->select('id');

        $strandedIndicator = Indicator::query()
            ->whereIn('result_id', $resultIds)
            ->whereHas('project', fn ($query) => $query->where('program_id', '!=', $programId))
            ->exists();

        $strandedTarget = PlanIndicator::query()
            ->whereIn('indicator_id', $indicatorIds)
            ->where('source_type', 'annual_plan')
            ->whereIn('source_id', AnnualPlan::query()->select('id')->where('program_id', '!=', $programId))
            ->exists();

        return $strandedIndicator || $strandedTarget;
    }

    public function confirmDelete(int $resultId): void
    {
        Gate::authorize('results.delete');

        $this->deletingResultId = $resultId;
        $this->showDeleteModal = true;
    }

    public function cancelDelete(): void
    {
        $this->deletingResultId = null;
        $this->showDeleteModal = false;
    }

    public function deleteResult(): void
    {
        Gate::authorize('results.delete');

        if ($this->deletingResultId === null) {
            return;
        }

        if (! $this->canDelete($this->deletingResultId)) {
            $this->cancelDelete();

            Flux::toast(
                variant: 'danger',
                text: __('results_framework.this_result_still_has_nested_results_or_indicators_remove_th'),
            );

            return;
        }

        Result::query()->findOrFail($this->deletingResultId)->delete();

        $this->cancelDelete();
        $this->forgetFramework();

        Flux::toast(variant: 'success', text: __('results_framework.result_deleted'));
    }

    public function closeResultModal(): void
    {
        $this->showResultModal = false;
        $this->resetResultForm();
    }

    /**
     * Drop every cached read of the framework after a write, so the same
     * request re-renders the tree it just changed rather than the old one.
     */
    protected function forgetFramework(): void
    {
        foreach (['allResults', 'resultsById', 'childCounts', 'measurements', 'groupedRoots', 'visibleNodes', 'overview', 'focusCounts', 'parentOptions'] as $property) {
            unset($this->{$property});
        }
    }

    protected function resetResultForm(): void
    {
        $this->resetValidation();
        $this->editingResultId = null;
        $this->statement = '';
        $this->resultType = 'outcome';
        $this->checkoutPeriod = 'quarterly';
        $this->resultProgramId = '';
        $this->parentId = '';
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $program !== '' || $type !== '' || $focus !== '';
    $overview = $this->overview;
    $programCount = $this->groupedRoots->count();

    $levelMarks = [
        ResultType::Impact->value => 'bg-secondary-dark ring-secondary-dark dark:bg-ink dark:ring-ink',
        ResultType::Outcome->value => 'bg-primary ring-primary',
        ResultType::Output->value => 'bg-board ring-secondary-light',
    ];

    $coverageTone = match (true) {
        $overview['coverage'] === null => 'neutral',
        $overview['coverage'] >= 80 => 'good',
        $overview['coverage'] >= 50 => 'warn',
        default => 'critical',
    };

    $onTrackShare = $overview['rated'] === 0 ? null : ($overview['rag']['green'] / $overview['rated']) * 100;

    $focusCounts = $this->focusCounts;

    $focusChips = [
        [
            'key' => 'unmeasured',
            'label' => __('results_framework.needs_indicators'),
            'icon' => 'exclamation-triangle',
            'count' => $focusCounts['unmeasured'],
        ],
        [
            'key' => 'offtrack',
            'label' => __('results_framework.off_track'),
            'icon' => 'arrow-trending-down',
            'count' => $focusCounts['offtrack'],
        ],
    ];
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink" aria-labelledby="results-framework-heading">
    <x-ui.page-header
        heading-id="results-framework-heading"
        :title="__('common.results_framework')"
        :count="$overview['statements']"
    >
        <x-slot:filters>
            <x-ui.input
                type="search"
                icon="magnifying-glass"
                :label="__('results_framework.search_the_framework')"
                :placeholder="__('results_framework.statement_or_indicator_name')"
                wire:model.live.debounce.300ms="search"
                clearable
            />

            <x-ui.header-filter-select :label="__('common.program')" wire:model.live="program" :value="$program">
                <option value="">{{ __('common.all_programs') }}</option>
                <option value="none">{{ __('common.unassigned') }}</option>
                @foreach ($this->programOptions as $programOption)
                    <option value="{{ $programOption->id }}">{{ $programOption->name }}</option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('common.type')" wire:model.live="type" :value="$type">
                <option value="">{{ __('results_framework.all_types') }}</option>
                @foreach (ResultType::cases() as $typeCase)
                    <option value="{{ $typeCase->value }}">{{ $typeCase->label() }}</option>
                @endforeach
            </x-ui.header-filter-select>
        </x-slot:filters>

        <x-slot:actions>
            @can('results.create')<x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-result-button">
                {{ __('results_framework.add_result') }}
            </x-ui.button>@endcan
        </x-slot:actions>
    </x-ui.page-header>

    {{-- The framework at a glance: four figures, then the chain they describe. --}}
    <x-ui.panel data-test="framework-overview">
        <div class="grid gap-px bg-secondary-soft @xl/directory:grid-cols-2 @4xl/directory:grid-cols-4 dark:bg-line">
            @php
                $headline = [
                    [
                        'label' => __('results_framework.statements'),
                        'value' => number_format($overview['statements']),
                        'sub' => trans_choice('results_framework.count_across_program', $programCount, ['count' => $programCount]),
                        'icon' => 'rectangle-stack',
                        'tone' => 'neutral',
                    ],
                    [
                        'label' => __('common.indicators'),
                        'value' => number_format($overview['indicators']),
                        'sub' => __('results_framework.measuring_count_of_total_statements', ['count' => $overview['measured'], 'total' => $overview['statements']]),
                        'icon' => 'chart-bar',
                        'tone' => 'neutral',
                    ],
                    [
                        'label' => __('results_framework.measurement_coverage'),
                        'value' => $overview['coverage'] === null ? '—' : number_format($overview['coverage'], 0).'%',
                        'sub' => trans_choice('results_framework.count_statement_without_an_indicator', $overview['statements'] - $overview['measured'], ['count' => $overview['statements'] - $overview['measured']]),
                        'icon' => 'check-badge',
                        'tone' => $coverageTone,
                    ],
                    [
                        'label' => __('results_framework.on_track'),
                        'value' => $onTrackShare === null ? '—' : number_format($onTrackShare, 0).'%',
                        'sub' => __('results_framework.rated_green_of_total', ['count' => $overview['rag']['green'], 'total' => $overview['rated']]),
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
                <div class="flex items-start justify-between gap-3 bg-white p-4 dark:bg-surface-1" wire:key="framework-headline-{{ $loop->index }}">
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

        {{-- The chain itself. Each rung is a filter: click a level to narrow the page to it. --}}
        <div class="flex flex-col gap-4 border-t border-secondary-soft bg-board-head px-4 py-4 @4xl/directory:flex-row @4xl/directory:items-center dark:border-line">
            <ol class="flex min-w-0 flex-1 flex-col gap-2 @2xl/directory:flex-row @2xl/directory:items-stretch" aria-label="{{ __('programs.logframe_results_chain') }}">
                @foreach (ResultType::cases() as $levelIndex => $levelCase)
                    @php
                        $level = $overview['levels'][$levelCase->value];
                        $isActiveLevel = $type === $levelCase->value;
                    @endphp
                    <li class="flex min-w-0 flex-1 items-stretch gap-2" wire:key="framework-level-{{ $levelCase->value }}">
                        <button
                            type="button"
                            wire:click="setType('{{ $levelCase->value }}')"
                            aria-pressed="{{ $isActiveLevel ? 'true' : 'false' }}"
                            data-test="level-filter-{{ $levelCase->value }}"
                            @class([
                                'group flex min-w-0 flex-1 flex-col rounded-xl border p-3 text-start transition-all focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                                'border-primary bg-white shadow-card dark:bg-surface-1' => $isActiveLevel,
                                'border-secondary-soft bg-white hover:-translate-y-0.5 hover:border-secondary-light dark:border-line dark:bg-surface-1' => ! $isActiveLevel,
                            ])
                        >
                            <span class="flex items-center gap-2">
                                <span aria-hidden="true" class="size-2.5 shrink-0 rounded-full ring-2 ring-inset {{ $levelMarks[$levelCase->value] }}"></span>
                                <span class="type-label truncate text-secondary-medium dark:text-ink-muted">{{ $levelCase->label() }}</span>
                                <span class="ms-auto text-xl font-bold tabular-nums text-secondary-dark dark:text-ink">{{ $level['statements'] }}</span>
                            </span>

                            <span class="mt-1.5 block type-meta text-secondary-medium dark:text-ink-muted">
                                {{ __('results_framework.measured_count_of_total', ['count' => $level['measured'], 'total' => $level['statements']]) }}
                                · {{ trans_choice('programs.logframe_count_indicators', $level['indicators'], ['count' => $level['indicators']]) }}
                            </span>

                            {{-- Pinned to the bottom so the three bars line up however the copy above them wraps. --}}
                            <x-ui.progress-bar
                                class="mt-auto pt-2.5"
                                :value="$level['measured']"
                                :max="max($level['statements'], 1)"
                                :tone="$level['statements'] > 0 && $level['measured'] === $level['statements'] ? 'good' : ($level['measured'] === 0 ? 'warn' : 'neutral')"
                                :label="__('results_framework.measurement_coverage')"
                                label-hidden
                            />
                        </button>

                        @unless ($loop->last)
                            <flux:icon.arrow-long-right variant="micro" class="hidden size-4 shrink-0 self-center text-secondary-light @2xl/directory:block rtl:rotate-180" aria-hidden="true" />
                            <flux:icon.arrow-long-down variant="micro" class="mx-auto size-4 shrink-0 self-center text-secondary-light @2xl/directory:hidden" aria-hidden="true" />
                        @endunless
                    </li>
                @endforeach
            </ol>

            <div class="flex shrink-0 items-center gap-3 border-secondary-soft @4xl/directory:border-s @4xl/directory:ps-5 dark:border-line">
                <x-ui.progress-ring
                    size="lg"
                    :value="$overview['coverage']"
                    :tone="$coverageTone"
                    :label="__('results_framework.measurement_coverage')"
                />
                <div class="min-w-0">
                    <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('results_framework.measurement_coverage') }}</p>
                    <p class="mt-1 text-sm font-semibold text-secondary-dark dark:text-ink">
                        {{ __('results_framework.measured_count_of_total', ['count' => $overview['measured'], 'total' => $overview['statements']]) }}
                    </p>
                    <p class="mt-0.5 type-meta text-secondary-medium dark:text-ink-muted">
                        {{ trans_choice('common.count_target', $overview['targets'], ['count' => $overview['targets']]) }}
                        · {{ __('results_framework.count_reports_count_approved', ['count' => $overview['reports'], 'approved' => $overview['approved']]) }}
                    </p>

                    @if ($overview['funders'] !== [])
                        <div class="mt-2 flex flex-wrap items-center gap-1">
                            <span class="sr-only">{{ __('results_framework.funded_by') }}</span>
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

    {{-- Attention chips on one side, the way of reading the framework on the other. --}}
    <div class="flex flex-wrap items-center gap-2">
        @foreach ($focusChips as $chip)
            <button
                type="button"
                wire:click="setFocus('{{ $chip['key'] }}')"
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
            :label="__('results_framework.how_to_read_the_framework')"
            :options="[
                ['key' => 'cascade', 'label' => __('results_framework.cascade'), 'icon' => 'share'],
                ['key' => 'matrix', 'label' => __('results_framework.matrix'), 'icon' => 'table-cells'],
            ]"
        />
    </div>

    @if ($this->groupedRoots->isEmpty())
        <x-ui.panel>
            <x-ui.empty-state
                icon="chart-bar"
                :heading="$filtersAreActive ? __('results_framework.no_matching_results') : __('common.no_results_yet')"
                :body="$filtersAreActive ? __('common.change_the_search_or_filters_and_try_again') : __('results_framework.add_the_first_impact_statement_to_start_the_logframe')"
            >
                <x-slot:action>
                    @if ($filtersAreActive || auth()->user()->can('results.create'))<x-ui.button tone="primary" :icon="$filtersAreActive ? 'arrow-path' : 'plus'" wire:click="{{ $filtersAreActive ? 'clearFilters' : 'openCreateModal' }}">
                        {{ $filtersAreActive ? __('common.reset_filters') : __('results_framework.add_result') }}
                    </x-ui.button>@endif
                </x-slot:action>
            </x-ui.empty-state>
        </x-ui.panel>
    @elseif ($view === 'matrix')
        {{-- One row per statement, indented by level, with every figure side by side. --}}
        <x-ui.panel :title="__('results_framework.matrix')" :count="$overview['statements']" :subtitle="__('results_framework.every_statement_with_what_measures_it_and_how_it_is_tracking')">
            <div class="overflow-x-auto">
                <div class="min-w-[64rem]">
                    <div class="grid grid-cols-[minmax(0,1fr)_7rem_5.5rem_5.5rem_12rem_6rem_6rem] items-center gap-3 border-b border-secondary-soft bg-board-head px-4 py-2 dark:border-line">
                        <span class="type-label text-secondary-medium dark:text-ink-muted">{{ __('results_framework.statement') }}</span>
                        <span class="type-label text-secondary-medium dark:text-ink-muted">{{ __('common.type') }}</span>
                        <span class="type-label text-end text-secondary-medium dark:text-ink-muted">{{ __('common.indicators') }}</span>
                        <span class="type-label text-end text-secondary-medium dark:text-ink-muted">{{ __('common.targets') }}</span>
                        <span class="type-label text-secondary-medium dark:text-ink-muted">{{ __('indicators.percent_achieved') }}</span>
                        <span class="type-label text-secondary-medium dark:text-ink-muted">{{ __('results_framework.rating') }}</span>
                        <span class="type-label text-end text-secondary-medium dark:text-ink-muted">{{ __('common.actions') }}</span>
                    </div>

                    <div class="divide-y divide-secondary-soft dark:divide-line">
                        @foreach ($this->visibleNodes as $row)
                            @php
                                $node = $row['node'];
                                $matrixResult = $node['result'];
                                $matrixMeasurement = $node['measurement'];
                                $matrixIndicators = count($matrixMeasurement['rows']);
                                $matrixRag = $matrixMeasurement['rag'];
                                $matrixRated = $matrixRag['green'] + $matrixRag['amber'] + $matrixRag['red'];
                                $matrixTone = match (true) {
                                    $matrixRag['red'] > 0 => 'critical',
                                    $matrixRag['amber'] > 0 => 'warn',
                                    $matrixRated > 0 => 'good',
                                    default => 'neutral',
                                };
                            @endphp
                            <div
                                data-tree-depth="{{ $node['depth'] }}"
                                data-result-type="{{ $matrixResult->type->value }}"
                                class="grid grid-cols-[minmax(0,1fr)_7rem_5.5rem_5.5rem_12rem_6rem_6rem] items-center gap-3 px-4 py-2.5 transition-colors hover:bg-board-head"
                                wire:key="matrix-row-{{ $matrixResult->id }}"
                            >
                                <div class="flex min-w-0 items-center gap-2" style="padding-inline-start: {{ $node['depth'] * 1.25 }}rem">
                                    <span aria-hidden="true" class="size-2 shrink-0 rounded-full ring-2 ring-inset {{ $levelMarks[$matrixResult->type->value] }}"></span>
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-secondary-dark dark:text-ink">{{ $matrixResult->statement }}</p>
                                        <p class="truncate type-meta text-secondary-medium dark:text-ink-muted">
                                            {{ $row['program'] }} · {{ __('programs.logframe_checkout', ['period' => $matrixResult->checkout_period->label()]) }}
                                            @foreach ($matrixMeasurement['funders'] as $funder)
                                                · {{ $funder['label'] }}
                                            @endforeach
                                        </p>
                                    </div>
                                </div>

                                <x-ui.enum-badge :case="$matrixResult->type" class="justify-self-start" />

                                <span @class([
                                    'text-end text-sm font-semibold tabular-nums',
                                    'text-status-warn' => $matrixIndicators === 0,
                                    'text-secondary-dark dark:text-ink' => $matrixIndicators > 0,
                                ])>{{ $matrixIndicators }}</span>

                                <span class="text-end text-sm font-semibold tabular-nums text-secondary-dark dark:text-ink">{{ $matrixMeasurement['targets'] }}</span>

                                <div class="flex items-center gap-2">
                                    {{-- Clamped: progress-bar flags anything over its max as a warning, and over-achievement is not one. --}}
                                    <x-ui.progress-bar
                                        class="min-w-16 flex-1"
                                        :value="$matrixMeasurement['achieved'] === null ? null : min($matrixMeasurement['achieved'], 100)"
                                        :max="100"
                                        :tone="$matrixTone"
                                        :label="__('indicators.percent_achieved')"
                                        label-hidden
                                    />
                                    <span class="w-11 shrink-0 text-end type-meta font-semibold tabular-nums text-secondary-dark dark:text-ink">
                                        {{ $matrixMeasurement['achieved'] === null ? '—' : number_format($matrixMeasurement['achieved'], 0).'%' }}
                                    </span>
                                </div>

                                <div class="flex items-center gap-1">
                                    @if ($matrixRated === 0)
                                        <span class="text-secondary-light">—</span>
                                    @else
                                        @foreach (['green' => 'bg-status-good', 'amber' => 'bg-status-warn', 'red' => 'bg-primary dark:bg-status-critical'] as $band => $fill)
                                            @if ($matrixRag[$band] > 0)
                                                <span class="inline-flex items-center gap-1 text-[11px] font-bold tabular-nums text-secondary-dark dark:text-ink">
                                                    <span aria-hidden="true" class="size-1.5 rounded-full {{ $fill }}"></span>{{ $matrixRag[$band] }}
                                                </span>
                                            @endif
                                        @endforeach
                                    @endif
                                </div>

                                <div class="flex items-center justify-end gap-0.5">
                                    @can('results.update')<x-ui.button
                                        tone="ghost"
                                        size="sm"
                                        icon="pencil-square"
                                        class="size-8!"
                                        wire:click="openEditModal({{ $matrixResult->id }})"
                                        :aria-label="__('common.edit_name', ['name' => $matrixResult->statement])"
                                    />@endcan
                                    @if ($node['deletable'])
                                        @can('results.delete')<x-ui.button
                                            tone="ghost"
                                            size="sm"
                                            icon="trash"
                                            class="size-8!"
                                            wire:click="confirmDelete({{ $matrixResult->id }})"
                                            :aria-label="__('common.delete_name', ['name' => $matrixResult->statement])"
                                        />@endcan
                                    @else
                                        <flux:tooltip :content="__('results_framework.locked_while_it_carries_nested_results_or_indicators')">
                                            <span class="inline-flex size-8 cursor-not-allowed items-center justify-center text-secondary-light" aria-disabled="true">
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
        </x-ui.panel>
    @else
        @foreach ($this->groupedRoots as $programName => $nodes)
            @php
                $groupStatements = collect($nodes)->sum(fn (array $node): int => $node['rollup']['statements']);
                $groupIndicators = collect($nodes)->sum(fn (array $node): int => $node['rollup']['indicators']);
                $groupTargets = collect($nodes)->sum(fn (array $node): int => $node['rollup']['targets']);
                $groupUnmeasured = collect($nodes)->sum(fn (array $node): int => $node['rollup']['unmeasured']);
                $groupProgram = collect($nodes)->map(fn (array $node) => $node['result']->program)->filter()->first();
            @endphp
            <x-ui.panel
                :title="$programName"
                :count="$groupStatements"
                :subtitle="trans_choice('programs.logframe_count_indicators', $groupIndicators, ['count' => $groupIndicators]).' · '.trans_choice('common.count_target', $groupTargets, ['count' => $groupTargets])"
                wire:key="result-group-{{ $loop->index }}-{{ Str::slug($programName) }}"
            >
                <x-slot:toolbar>
                    <div class="flex flex-wrap items-center gap-3">
                        <span @class([
                            'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 type-meta font-semibold',
                            'bg-status-warn-soft text-status-warn' => $groupUnmeasured > 0,
                            'bg-status-good-soft text-status-good' => $groupUnmeasured === 0,
                        ])>
                            <flux:icon :name="$groupUnmeasured > 0 ? 'exclamation-triangle' : 'check-circle'" variant="micro" class="size-3.5" aria-hidden="true" />
                            {{ trans_choice('programs.logframe_count_unmeasured', $groupUnmeasured, ['count' => $groupUnmeasured]) }}
                        </span>

                        @if ($groupProgram !== null)
                            <a href="{{ route('programs.show', $groupProgram) }}" wire:navigate class="type-meta font-semibold text-secondary-medium hover:text-primary dark:hover:text-brand-text hover:underline dark:text-ink-muted">
                                {{ __('common.view_name', ['name' => $groupProgram->name]) }}
                            </a>
                        @endif
                    </div>
                </x-slot:toolbar>

                <div class="space-y-5 bg-ground px-3 py-5 @2xl/directory:px-5 @2xl/directory:py-6">
                    @foreach ($nodes as $node)
                        <x-ui.framework-node :node="$node" :depth="0" :order="$loop->index" wire:key="framework-root-{{ $node['result']->id }}" />
                    @endforeach
                </div>
            </x-ui.panel>
        @endforeach
    @endif

    <x-ui.modal
        wire:model="showResultModal"
        size="xl"
        :title="$editingResultId === null ? __('results_framework.add_result') : __('results_framework.edit_result')"
        :description="__('results_framework.an_output_contributes_to_an_outcome_which_contributes_to_an')"
        submit="saveResult"
        close="closeResultModal"
        required-note
    >
        @if ($showResultModal)
            <x-ui.form-section :title="__('common.section_details')" :fields="['statement', 'resultType', 'checkoutPeriod']">
                <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                    <x-ui.form-textarea wire:model="statement" :label="__('results_framework.statement')" required autofocus class="@2xl/modal:col-span-2" />

                    {{-- The Impact / Outcome / Output marks are the logframe's own (see components-ui rules). --}}
                    <x-ui.form-segmented
                        wire:model.live="resultType"
                        :label="__('common.type')"
                        required
                        :options="[
                            ['value' => ResultType::Impact->value, 'label' => ResultType::Impact->label(), 'dot' => 'bg-secondary-dark dark:bg-ink'],
                            ['value' => ResultType::Outcome->value, 'label' => ResultType::Outcome->label(), 'dot' => 'bg-primary'],
                            ['value' => ResultType::Output->value, 'label' => ResultType::Output->label(), 'dot' => 'bg-board ring-1 ring-secondary-light'],
                        ]"
                    />

                    <x-ui.form-segmented
                        wire:model="checkoutPeriod"
                        :label="__('results_framework.checked')"
                        required
                        :options="collect(Period::cases())->map(fn (Period $periodCase): array => ['value' => $periodCase->value, 'label' => $periodCase->label()])->all()"
                    />
                </div>
            </x-ui.form-section>

            <x-ui.form-section :title="__('common.section_placement')" :fields="['parentId', 'resultProgramId']">
                <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                    <x-ui.form-select
                        wire:model.live="parentId"
                        :label="__('results_framework.contributes_to')"
                        :disabled="$resultType === ResultType::Impact->value"
                        :locked-reason="__('results_framework.an_impact_sits_at_the_top_of_the_chain')"
                        :help="__('results_framework.only_the_level_directly_above_is_offered')"
                    >
                        <option value="">{{ __('common.top_level') }}</option>
                        @foreach ($this->parentOptions as $parentOption)
                            <option value="{{ $parentOption->id }}">{{ Str::limit($parentOption->statement, 60) }} · {{ $parentOption->program?->name ?? __('common.unassigned') }}</option>
                        @endforeach
                    </x-ui.form-select>

                    <x-ui.form-select
                        wire:model="resultProgramId"
                        :label="__('common.program')"
                        :disabled="$parentId !== ''"
                        :locked-reason="__('results_framework.follows_the_program_of_the_result_it_contributes_to')"
                        :help="$editingResultId !== null ? __('results_framework.nested_results_move_with_it') : null"
                    >
                        <option value="">{{ __('common.unassigned') }}</option>
                        @foreach ($this->programOptions as $programOption)
                            <option value="{{ $programOption->id }}">{{ $programOption->name }}</option>
                        @endforeach
                    </x-ui.form-select>
                </div>
            </x-ui.form-section>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-result-button">
                {{ $editingResultId === null ? __('results_framework.add_result') : __('common.save_changes') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    <x-ui.confirm-modal
        wire:model="showDeleteModal"
        close="cancelDelete"
        :title="__('results_framework.delete_this_result')"
        :message="__('results_framework.only_a_result_with_no_nested_results_and_no_indicators_can_b')"
        confirm="deleteResult"
        :confirm-label="__('results_framework.delete_result')"
        confirm-test="confirm-delete-result"
    />
</section>
