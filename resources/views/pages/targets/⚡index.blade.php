<?php

use App\Enums\IndicatorMeasure;
use App\Enums\Period;
use App\Models\Donor;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\AnnualPlan;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.targets')] class extends Component {
    use WithPagination;

    /**
     * The only two sources a target may be committed in.
     *
     * These are the morph map keys registered in AppServiceProvider, not class
     * names: plan_indicators.source_type is VARCHAR(50) and
     * Relation::enforceMorphMap() is global.
     */
    private const SOURCE_TYPES = ['annual_plan', 'proposal'];

    /**
     * Achievement statuses, derived from approved reports against the target.
     */
    public const STATUSES = ['achieved', 'in_progress', 'not_started'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $sourceType = '';

    #[Url(except: '')]
    public string $project = '';

    #[Url(except: '')]
    public string $period = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $donor = '';

    #[Url(except: '')]
    public string $program = '';

    #[Url(except: '')]
    public string $year = '';

    /**
     * Narrows the page to one indicator, or to every indicator on one result.
     *
     * Set by deep links from the results framework and the indicator
     * directory rather than by a header picker, so it is shown as a removable
     * chip above the list.
     */
    #[Url(except: '')]
    public string $indicator = '';

    #[Url(except: '')]
    public string $result = '';

    public bool $showTargetModal = false;
    public ?int $editingTargetId = null;
    public ?int $deletingTargetId = null;
    public bool $showDeleteModal = false;
    public ?int $expandedTargetId = null;

    public string $targetSourceType = 'annual_plan';
    public string $targetSourceId = '';
    public string $targetIndicatorId = '';
    public string $targetValue = '';
    public string $targetProjectId = '';
    public string $targetPeriod = 'quarterly';

    /**
     * `?create=1` (with `?indicator=`) opens the commit form, already tied to
     * that indicator — the "Commit a target" step from the indicator chain.
     */
    public function mount(): void
    {
        if (request()->boolean('create')) {
            $this->openCreateModal();

            if ($this->indicator !== '' && Indicator::query()->whereKey((int) $this->indicator)->exists()) {
                $this->targetIndicatorId = $this->indicator;
                $this->updatedTargetIndicatorId();
            }
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function clearIndicatorScope(): void
    {
        $this->indicator = '';
        $this->result = '';
        $this->resetPage();
    }

    public function updatedSourceType(): void
    {
        $this->resetPage();
    }

    public function updatedProject(): void
    {
        $this->resetPage();
    }

    public function updatedPeriod(): void
    {
        $this->resetPage();
    }

    public function updatedDonor(): void
    {
        $this->resetPage();
    }

    public function updatedProgram(): void
    {
        $this->resetPage();
    }

    public function updatedYear(): void
    {
        $this->resetPage();
    }

    /**
     * Toggle the achievement filter from the pulse; choosing the active one clears it.
     */
    public function filterStatus(string $status): void
    {
        $this->status = $this->status === $status || ! in_array($status, self::STATUSES, true) ? '' : $status;
        $this->resetPage();
    }

    /**
     * A source chosen under one type is meaningless under the other, so
     * switching type clears it rather than carrying an id across.
     */
    public function updatedTargetSourceType(): void
    {
        $this->targetSourceId = '';
        unset($this->sourceOptions, $this->formProjectOptions);
        $this->syncFormProject();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->sourceType = '';
        $this->project = '';
        $this->period = '';
        $this->status = '';
        $this->donor = '';
        $this->program = '';
        $this->year = '';
        $this->indicator = '';
        $this->result = '';
        $this->resetPage();
    }

    /**
     * Achievement is PlanIndicator's one shared rule — approved reports only;
     * numeric summed, percentage the latest level — in its SQL form, so the
     * status filter and the pulse can count on it.
     */
    private function statusSql(string $status): string
    {
        return PlanIndicator::achievementStatusSql($status);
    }

    /**
     * Targets matching every filter except achievement status.
     *
     * @return Builder<PlanIndicator>
     */
    protected function filteredTargets(): Builder
    {
        $search = trim($this->search);

        return PlanIndicator::query()
            // The indicator, the result it measures, or the proposal it was committed in.
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.$search.'%';

                $query->where(fn ($query) => $query
                    ->whereHas('indicator', fn ($query) => $query
                        ->where('name', 'like', $like)
                        ->orWhereHas('result', fn ($query) => $query->where('statement', 'like', $like)))
                    ->orWhere(fn ($query) => $query
                        ->where('source_type', 'proposal')
                        ->whereIn('source_id', Proposal::query()->select('id')->where('name', 'like', $like))));
            })
            ->when($this->indicator !== '', fn ($query) => $query->where('indicator_id', (int) $this->indicator))
            ->when($this->result !== '', fn ($query) => $query
                ->whereIn('indicator_id', Indicator::query()->select('id')->where('result_id', (int) $this->result)))
            ->when($this->sourceType !== '', fn ($query) => $query->where('source_type', $this->sourceType))
            ->when($this->project === 'none', fn ($query) => $query->whereNull('project_id'))
            ->when(
                $this->project !== '' && $this->project !== 'none',
                fn ($query) => $query->where('project_id', (int) $this->project)
            )
            ->when($this->period !== '', fn ($query) => $query->where('period', $this->period))
            // A donor funds proposals, so only proposal targets can carry one.
            ->when($this->donor !== '', fn ($query) => $query
                ->where('source_type', 'proposal')
                ->whereIn('source_id', DB::table('donor_proposal')->select('proposal_id')->where('donor_id', (int) $this->donor)))
            // A program owns a target through its annual plan, the project delivering
            // it, or the result it measures — so an organisation-wide proposal target
            // still shows under the program whose logframe it serves.
            ->when($this->program !== '', fn ($query) => $query->where(function ($query): void {
                $programId = (int) $this->program;

                $query
                    ->where(fn ($query) => $query
                        ->where('source_type', 'annual_plan')
                        ->whereIn('source_id', AnnualPlan::query()->select('id')->where('program_id', $programId)))
                    ->orWhereIn('project_id', Project::query()->select('id')->where('program_id', $programId))
                    ->orWhereIn('indicator_id', Indicator::query()->select('indicators.id')
                        ->join('results', 'results.id', '=', 'indicators.result_id')
                        ->where('results.program_id', $programId));
            }))
            // A plan belongs to one year; a proposal covers every year its planned dates span.
            ->when($this->year !== '', fn ($query) => $query->where(function ($query): void {
                $year = (int) $this->year;

                $query
                    ->where(fn ($query) => $query
                        ->where('source_type', 'annual_plan')
                        ->whereIn('source_id', AnnualPlan::query()->select('id')->where('year', $year)))
                    ->orWhere(fn ($query) => $query
                        ->where('source_type', 'proposal')
                        ->whereIn('source_id', Proposal::query()
                            ->select('id')
                            ->whereYear('planned_date_begin', '<=', $year)
                            ->whereYear('planned_date_end', '>=', $year)));
            }));
    }

    /** @return Collection<int, Donor> */
    #[Computed]
    public function donorOptions(): Collection
    {
        return Donor::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get();
    }

    /** @return Collection<int, Program> */
    #[Computed]
    public function programOptions(): Collection
    {
        return Program::options();
    }

    /**
     * Every year a plan or proposal covers, newest first.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function yearOptions(): array
    {
        $planYears = AnnualPlan::query()->distinct()->pluck('year')->map(fn ($year): int => (int) $year);

        $proposalYears = Proposal::query()
            ->select(['planned_date_begin', 'planned_date_end'])
            ->get()
            ->flatMap(fn (Proposal $proposal): array => range(
                $proposal->planned_date_begin->year,
                max($proposal->planned_date_begin->year, $proposal->planned_date_end->year)
            ));

        return $planYears->merge($proposalYears)->unique()->sortDesc()->values()->all();
    }

    /**
     * Targets matching every filter, including achievement status.
     *
     * @return Builder<PlanIndicator>
     */
    protected function matchingTargets(): Builder
    {
        return $this->filteredTargets()->when(
            in_array($this->status, self::STATUSES, true),
            fn ($query) => $query->whereRaw($this->statusSql($this->status))
        );
    }

    /**
     * Portfolio-level counts for the pulse, over the filtered set.
     *
     * @return array{total: int, achieved: int, in_progress: int, not_started: int, plans: int, proposals: int, reviewed: int, drafts: int}
     */
    #[Computed]
    public function pulse(): array
    {
        $row = $this->filteredTargets()
            ->toBase()
            ->selectRaw('count(*) as total')
            ->selectRaw('coalesce(sum(case when '.$this->statusSql('achieved').' then 1 else 0 end), 0) as achieved')
            ->selectRaw('coalesce(sum(case when '.$this->statusSql('in_progress').' then 1 else 0 end), 0) as in_progress')
            ->selectRaw("coalesce(sum(case when source_type = 'annual_plan' then 1 else 0 end), 0) as plans")
            ->selectRaw("coalesce(sum(case when source_type = 'proposal' then 1 else 0 end), 0) as proposals")
            ->selectRaw("coalesce(sum((select count(*) from reports r where r.plan_indicator_id = plan_indicators.id and r.status = 'reviewed')), 0) as reviewed")
            ->selectRaw("coalesce(sum((select count(*) from reports r where r.plan_indicator_id = plan_indicators.id and r.status = 'draft')), 0) as drafts")
            ->first();

        $total = (int) $row->total;
        $achieved = (int) $row->achieved;
        $inProgress = (int) $row->in_progress;

        return [
            'total' => $total,
            'achieved' => $achieved,
            'in_progress' => $inProgress,
            'not_started' => max(0, $total - $achieved - $inProgress),
            'plans' => (int) $row->plans,
            'proposals' => (int) $row->proposals,
            'reviewed' => (int) $row->reviewed,
            'drafts' => (int) $row->drafts,
        ];
    }

    /**
     * How many targets match every filter, for the header count.
     */
    #[Computed]
    public function matchingCount(): int
    {
        return $this->matchingTargets()->count();
    }

    /**
     * One page of results that have matching targets.
     *
     * The page is cut by result, not by target, so a result's targets are
     * never split across two pages.
     *
     * @return LengthAwarePaginator<int, Indicator>
     */
    #[Computed]
    public function resultPage(): LengthAwarePaginator
    {
        return Indicator::query()
            ->whereIn('id', $this->matchingTargets()->select('indicator_id'))
            ->select('result_id')
            ->distinct()
            ->orderBy('result_id')
            ->paginate(6, ['result_id']);
    }

    /** @return Collection<int, PlanIndicator> */
    #[Computed]
    public function targets(): Collection
    {
        $resultIds = $this->resultPage->pluck('result_id')->all();

        return $this->matchingTargets()
            ->select(['id', 'source_type', 'source_id', 'indicator_id', 'target', 'project_id', 'period', 'updated_at'])
            ->selectRaw(PlanIndicator::achievedValueSql().' as actual_value')
            ->selectRaw(PlanIndicator::pendingValueSql().' as pending_value')
            ->selectRaw("(select count(*) from reports r where r.plan_indicator_id = plan_indicators.id and r.status = 'reviewed') as reviewed_count")
            ->selectRaw("(select count(*) from reports r where r.plan_indicator_id = plan_indicators.id and r.status = 'draft') as draft_count")
            ->whereIn('indicator_id', Indicator::query()->select('id')->whereIn('result_id', $resultIds))
            ->with([
                // morphTo resolves to AnnualPlan or Proposal — or to null, when
                // the source was removed by a write that fired no model events.
                'source' => fn (MorphTo $morphTo) => $morphTo->morphWith([
                    AnnualPlan::class => ['program:id'],
                    // projects: which projects the proposal funds, for chainIssues().
                    Proposal::class => ['donors:id,name', 'projects:id'],
                ]),
                'indicator:id,name,result_id,project_id,measure,unit,baseline',
                'indicator.result:id,statement,type,program_id',
                'project:id,name,city_id,program_id',
                'project.city:id,name_en,name_ar',
            ])
            ->withCount('reports')
            // Keep a result's indicators together so the page reads as groups.
            ->orderBy(Indicator::query()->select('result_id')->whereColumn('indicators.id', 'plan_indicators.indicator_id'))
            ->orderBy('indicator_id')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The reports behind the expanded target, newest period first.
     *
     * @return Collection<int, Report>
     */
    #[Computed]
    public function expandedReports(): Collection
    {
        if ($this->expandedTargetId === null) {
            return new Collection;
        }

        return Report::query()
            ->select(['id', 'plan_indicator_id', 'user_id', 'status', 'period_start', 'period_end', 'planned_date', 'value', 'is_seen_by_meal'])
            ->with('user:id,hr_user_id')
            ->withCount('files')
            ->where('plan_indicator_id', $this->expandedTargetId)
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Open or close the report trail under one target.
     */
    public function toggleReports(int $targetId): void
    {
        $this->expandedTargetId = $this->expandedTargetId === $targetId ? null : $targetId;
    }

    /**
     * The indicator chosen in the form, with the result it measures.
     */
    #[Computed]
    public function formIndicator(): ?Indicator
    {
        if ($this->targetIndicatorId === '') {
            return null;
        }

        return Indicator::query()
            ->select(['id', 'name', 'result_id', 'project_id', 'measure', 'unit', 'frequency'])
            ->with(['result:id,statement,program_id', 'project:id,name'])
            ->find((int) $this->targetIndicatorId);
    }

    /**
     * The sources selectable under the currently chosen source type.
     *
     * An annual plan belongs to one program, so once the indicator's result
     * sits in a program only that program's plans are offered.
     *
     * @return array<int, array{id: int, label: string}>
     */
    #[Computed]
    public function sourceOptions(): array
    {
        if ($this->targetSourceType === 'proposal') {
            return Proposal::query()
                ->select(['id', 'name'])
                ->orderBy('name')
                ->get()
                ->map(fn (Proposal $proposal): array => ['id' => $proposal->id, 'label' => $proposal->name])
                ->all();
        }

        $programId = $this->formIndicator?->result?->program_id;

        return AnnualPlan::query()
            ->select(['id', 'program_id', 'year'])
            ->with('program:id')
            ->when($programId !== null, fn ($query) => $query->where('program_id', $programId))
            ->orderByDesc('year')
            ->get()
            ->map(fn (AnnualPlan $plan): array => [
                'id' => $plan->id,
                'label' => __('common.fy_year_program', ['year' => $plan->year, 'program' => $plan->program->name]),
            ])
            ->all();
    }

    /** @return Collection<int, Indicator> */
    #[Computed]
    public function indicatorOptions(): Collection
    {
        return Indicator::query()
            ->select(['id', 'name', 'result_id', 'measure'])
            ->with('result:id,statement')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * Projects that may deliver the target being committed.
     *
     * An indicator scoped to one project allows only that project. Otherwise
     * an annual-plan target is delivered inside the plan's program, and a
     * proposal target by a project the proposal funds.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function formProjectOptions(): Collection
    {
        $indicator = $this->formIndicator;
        $sourceId = $this->targetSourceId === '' ? null : (int) $this->targetSourceId;

        return Project::query()
            ->select(['id', 'name', 'program_id'])
            ->when($indicator?->project_id !== null, fn ($query) => $query->whereKey($indicator->project_id))
            ->when($sourceId !== null && $this->targetSourceType === 'annual_plan', fn ($query) => $query
                ->whereIn('program_id', AnnualPlan::query()->select('program_id')->whereKey($sourceId)))
            ->when($sourceId !== null && $this->targetSourceType === 'proposal', fn ($query) => $query
                ->whereIn('id', DB::table('project_proposal')->select('project_id')->where('proposal_id', $sourceId)))
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * Choosing an indicator narrows the plans and projects that fit it: a
     * project-scoped indicator fixes the project, and a source or project
     * that no longer fits is dropped rather than carried to the save.
     */
    public function updatedTargetIndicatorId(): void
    {
        unset($this->formIndicator, $this->sourceOptions, $this->formProjectOptions);

        if ($this->targetSourceId !== '' && ! collect($this->sourceOptions)->contains('id', (int) $this->targetSourceId)) {
            $this->targetSourceId = '';
        }

        // A new target reports on the indicator's own cadence unless told otherwise.
        if ($this->editingTargetId === null && $this->formIndicator !== null) {
            $this->targetPeriod = $this->formIndicator->frequency->value;
        }

        $this->syncFormProject();
    }

    public function updatedTargetSourceId(): void
    {
        unset($this->formProjectOptions);

        $this->syncFormProject();
    }

    /**
     * Keep the chosen project inside the options that fit, and fill it in
     * when the indicator leaves exactly one.
     */
    protected function syncFormProject(): void
    {
        $options = $this->formProjectOptions;

        if ($this->targetProjectId !== '' && ! $options->contains('id', (int) $this->targetProjectId)) {
            $this->targetProjectId = '';
        }

        if ($this->formIndicator?->project_id !== null && $options->count() === 1) {
            $this->targetProjectId = (string) $options->first()->id;
        }
    }

    /** @return Collection<int, Project> */
    #[Computed]
    public function projectOptions(): Collection
    {
        return Project::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get();
    }

    /**
     * Whether this target may be deleted.
     *
     * Refused once reported: reports cascade from plan_indicators, and
     * report_files cascade from reports. A measurement is not discarded to tidy
     * up a target.
     */
    public function canDelete(int $targetId): bool
    {
        $target = PlanIndicator::query()->select(['id'])->withCount('reports')->find($targetId);

        return $target !== null && $target->reports_count === 0;
    }

    /**
     * A human label for a target's source.
     *
     * Returns null when the source no longer exists. source_id carries no
     * foreign key, so a raw delete of a plan or proposal leaves this dangling
     * and the caller must render an explicit fallback.
     */
    public function sourceLabel(PlanIndicator $target): ?string
    {
        $source = $target->source;

        if ($source === null) {
            return null;
        }

        if ($source instanceof AnnualPlan) {
            return __('common.fy_year', ['year' => $source->year]);
        }

        if ($source instanceof Proposal) {
            return $source->name;
        }

        return null;
    }

    public function openCreateModal(): void
    {
        $this->resetTargetForm();
        $this->showTargetModal = true;
    }

    public function openEditModal(int $targetId): void
    {
        $target = PlanIndicator::query()
            ->select(['id', 'source_type', 'source_id', 'indicator_id', 'target', 'project_id', 'period'])
            ->findOrFail($targetId);

        $this->resetValidation();
        $this->editingTargetId = $target->id;
        $this->targetSourceType = $target->source_type;
        $this->targetSourceId = (string) $target->source_id;
        $this->targetIndicatorId = (string) $target->indicator_id;
        $this->targetValue = (string) $target->target;
        $this->targetProjectId = (string) ($target->project_id ?? '');
        $this->targetPeriod = $target->period->value;
        unset($this->formIndicator, $this->sourceOptions, $this->formProjectOptions);
        $this->showTargetModal = true;
    }

    public function saveTarget(): void
    {
        $validated = $this->validate([
            'targetSourceType' => ['required', Rule::in(self::SOURCE_TYPES)],
            'targetSourceId' => ['required', 'integer'],
            'targetIndicatorId' => ['required', 'integer', Rule::exists(Indicator::class, 'id')],
            'targetValue' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'targetProjectId' => ['nullable', 'integer', Rule::exists(Project::class, 'id')],
            'targetPeriod' => ['required', new Enum(Period::class)],
        ], [
            'targetSourceType.required' => __('targets.choose_where_this_target_was_committed'),
            'targetSourceType.in' => __('targets.a_target_can_only_be_committed_in_an_annual_plan_or_a_propos'),
            'targetSourceId.required' => __('targets.select_the_annual_plan_or_proposal'),
            'targetIndicatorId.required' => __('targets.select_the_indicator_being_targeted'),
            'targetValue.required' => __('targets.enter_the_target_value'),
            'targetValue.min' => __('targets.a_target_cannot_be_negative'),
            'targetValue.max' => __('targets.the_target_value_is_too_large_to_record'),
        ]);

        // The chosen source must actually exist under the chosen type. A single
        // exists rule cannot express this, because the table depends on the type.
        $source = $validated['targetSourceType'] === 'proposal'
            ? Proposal::query()->with('projects:id')->find((int) $validated['targetSourceId'])
            : AnnualPlan::query()->find((int) $validated['targetSourceId']);

        if ($source === null) {
            $this->addError('targetSourceId', __('targets.the_selected_source_is_no_longer_available'));

            return;
        }

        $indicator = Indicator::query()->with('result:id,program_id')->findOrFail((int) $validated['targetIndicatorId']);
        $projectId = $validated['targetProjectId'] === '' || $validated['targetProjectId'] === null
            ? null
            : (int) $validated['targetProjectId'];
        $project = $projectId === null ? null : Project::query()->select(['id', 'program_id'])->find($projectId);

        // A percentage is read against 100; a target above it can never be met.
        if ($indicator->measure === IndicatorMeasure::Percentage && (float) $validated['targetValue'] > 100) {
            $this->addError('targetValue', __('targets.a_percentage_target_cannot_exceed_100'));

            return;
        }

        $target = $this->editingTargetId === null
            ? new PlanIndicator
            : PlanIndicator::query()->withCount('reports')->findOrFail($this->editingTargetId);

        $target->fill([
            // The map key, never the class name.
            'source_type' => $validated['targetSourceType'],
            'source_id' => $source->id,
            'indicator_id' => $indicator->id,
            'target' => $validated['targetValue'],
            'project_id' => $projectId,
            'period' => $validated['targetPeriod'],
        ]);

        // Reports were filed against this indicator in this plan or proposal;
        // moving the target would re-label every one of them.
        if ($target->exists && $target->reports_count > 0 && $target->isDirty(['indicator_id', 'source_type', 'source_id'])) {
            $this->addError('targetIndicatorId', __('targets.the_indicator_and_source_are_locked_once_reported_against'));

            return;
        }

        $issues = PlanIndicator::chainIssuesFor($source, $indicator, $project);

        // Only what this edit introduces: a row already out of step can still
        // have its value or period corrected.
        if ($target->exists && $issues !== []) {
            $original = PlanIndicator::query()
                ->with(['source' => fn (MorphTo $morphTo) => $morphTo->morphWith([Proposal::class => ['projects:id']]), 'project:id,program_id'])
                ->findOrFail($target->id);
            $issues = array_values(array_diff($issues, PlanIndicator::chainIssuesFor($original->source, $indicator, $original->project)));
        }

        if ($issues !== []) {
            foreach ($issues as $issue) {
                $this->addError($issue === 'targets.issue_plan_program_differs_from_result' ? 'targetSourceId' : 'targetProjectId', __($issue));
            }

            return;
        }

        $duplicate = PlanIndicator::query()
            ->where('source_type', $target->source_type)
            ->where('source_id', $target->source_id)
            ->where('indicator_id', $target->indicator_id)
            ->where(fn ($query) => $projectId === null ? $query->whereNull('project_id') : $query->where('project_id', $projectId))
            ->when($target->exists, fn ($query) => $query->whereKeyNot($target->id))
            ->exists();

        if ($duplicate) {
            $this->addError('targetIndicatorId', __('targets.this_indicator_already_has_a_target_here'));

            return;
        }

        $target->save();

        $wasRecentlyCreated = $target->wasRecentlyCreated;

        $this->showTargetModal = false;
        $this->resetTargetForm();
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('targets.target_committed') : __('targets.target_updated'),
        );
    }

    public function confirmDelete(int $targetId): void
    {
        $this->deletingTargetId = $targetId;
        $this->showDeleteModal = true;
    }

    public function cancelDelete(): void
    {
        $this->deletingTargetId = null;
        $this->showDeleteModal = false;
    }

    public function deleteTarget(): void
    {
        if ($this->deletingTargetId === null) {
            return;
        }

        if (! $this->canDelete($this->deletingTargetId)) {
            $this->cancelDelete();

            Flux::toast(
                variant: 'danger',
                text: __('targets.this_target_has_been_reported_against_deleting_it_would_remo'),
            );

            return;
        }

        PlanIndicator::query()->findOrFail($this->deletingTargetId)->delete();

        $this->cancelDelete();
        $this->resetPage();

        Flux::toast(variant: 'success', text: __('targets.target_deleted'));
    }

    public function closeTargetModal(): void
    {
        $this->showTargetModal = false;
        $this->resetTargetForm();
    }

    protected function resetTargetForm(): void
    {
        $this->resetValidation();
        $this->editingTargetId = null;
        $this->targetSourceType = 'annual_plan';
        $this->targetSourceId = '';
        $this->targetIndicatorId = '';
        $this->targetValue = '';
        $this->targetProjectId = '';
        $this->targetPeriod = 'quarterly';
        unset($this->formIndicator, $this->sourceOptions, $this->formProjectOptions);
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $sourceType !== '' || $project !== '' || $period !== '' || $status !== '' || $donor !== '' || $program !== '' || $year !== '' || $indicator !== '' || $result !== '';
    $scopeLabel = match (true) {
        $indicator !== '' => __('targets.indicator_scope', ['name' => App\Models\Indicator::query()->whereKey((int) $indicator)->value('name') ?? '—']),
        $result !== '' => __('targets.result_scope', ['statement' => Str::limit(App\Models\Result::query()->whereKey((int) $result)->value('statement') ?? '—', 60)]),
        default => null,
    };
    $sourceTypeLabels = ['annual_plan' => __('common.annual_plan'), 'proposal' => __('common.proposal')];
    $pulse = $this->pulse;
    $statusMeta = [
        'achieved' => ['label' => __('targets.status_achieved'), 'count' => $pulse['achieved'], 'fill' => 'bg-status-good', 'text' => 'text-status-good', 'dot' => 'bg-status-good'],
        'in_progress' => ['label' => __('targets.status_in_progress'), 'count' => $pulse['in_progress'], 'fill' => 'bg-secondary-dark dark:bg-white', 'text' => 'text-secondary-dark dark:text-white', 'dot' => 'bg-secondary-dark dark:bg-white'],
        'not_started' => ['label' => __('targets.status_not_started'), 'count' => $pulse['not_started'], 'fill' => 'bg-secondary-light', 'text' => 'text-secondary-light', 'dot' => 'bg-secondary-light'],
    ];
    $shareOf = fn (int $part): float => $pulse['total'] > 0 ? round($part / $pulse['total'] * 100, 1) : 0;
    $formatValue = fn (float $value): string => fmod($value, 1.0) === 0.0 ? number_format($value) : number_format($value, 2);
    $resultMark = fn ($type): string => match ($type?->value) {
        'impact' => 'bg-secondary-dark ring-secondary-dark dark:bg-white dark:ring-white',
        'outcome' => 'bg-primary ring-primary',
        default => 'bg-white ring-secondary-light dark:bg-secondary-dark',
    };
    $groups = $this->targets->groupBy(fn ($target) => $target->indicator->result_id);
@endphp

<section class="@container/directory admin-directory flex min-h-full w-full flex-1 flex-col gap-8 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="target-directory-heading">
    <x-ui.page-header
        heading-id="target-directory-heading"
        :title="__('common.targets')"
        :count="$this->matchingCount"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('targets.search_targets')" :placeholder="__('common.indicator_name')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.header-filter-select :label="__('targets.committed_in')" wire:model.live="sourceType" :value="$sourceType">
                <flux:select.option value="">{{ __('targets.any_source') }}</flux:select.option>
                @foreach ($sourceTypeLabels as $sourceKey => $sourceLabel)
                    <flux:select.option :value="$sourceKey" :wire:key="'tgt-source-'.$sourceKey">{{ $sourceLabel }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('common.project')" wire:model.live="project" :value="$project">
                <flux:select.option value="">{{ __('targets.any_project') }}</flux:select.option>
                <flux:select.option value="none">{{ __('common.organisation_wide') }}</flux:select.option>
                @foreach ($this->projectOptions as $projectOption)
                    <flux:select.option :value="$projectOption->id" :wire:key="'tgt-project-'.$projectOption->id">{{ $projectOption->name }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('common.period')" wire:model.live="period" :value="$period">
                <flux:select.option value="">{{ __('targets.all_periods') }}</flux:select.option>
                @foreach (Period::cases() as $periodCase)
                    <flux:select.option :value="$periodCase->value" :wire:key="'tgt-period-'.$periodCase->value">{{ $periodCase->label() }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('common.program')" wire:model.live="program" :value="$program">
                <flux:select.option value="">{{ __('targets.any_program') }}</flux:select.option>
                @foreach ($this->programOptions as $programOption)
                    <flux:select.option :value="$programOption->id" :wire:key="'tgt-program-'.$programOption->id">{{ $programOption->name }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('targets.donor')" wire:model.live="donor" :value="$donor">
                <flux:select.option value="">{{ __('targets.any_donor') }}</flux:select.option>
                @foreach ($this->donorOptions as $donorOption)
                    <flux:select.option :value="$donorOption->id" :wire:key="'tgt-donor-'.$donorOption->id">{{ $donorOption->name }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('targets.year')" wire:model.live="year" :value="$year">
                <flux:select.option value="">{{ __('targets.all_years') }}</flux:select.option>
                @foreach ($this->yearOptions as $yearOption)
                    <flux:select.option :value="$yearOption" :wire:key="'tgt-year-'.$yearOption">{{ $yearOption }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>
        </x-slot:filters>

        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-target-button">
                {{ __('targets.commit_target') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Pulse: the filtered portfolio as one sentence and one proportional bar. --}}
    <div class="grid gap-6 @5xl/directory:grid-cols-[minmax(0,1fr)_auto] @5xl/directory:items-end">
        <div class="flex min-w-0 flex-col gap-4">
            <h2 id="target-pulse-heading" class="max-w-3xl text-balance text-xl font-semibold leading-snug @3xl/directory:text-2xl">
                @if ($pulse['total'] === 0)
                    {{ __('targets.pulse_empty') }}
                @else
                    {!! __('targets.pulse_sentence', [
                        'achieved' => '<span class="tabular-nums text-status-good">'.e(number_format($pulse['achieved'])).'</span>',
                        'total' => '<span class="tabular-nums">'.e(number_format($pulse['total'])).'</span>',
                    ]) !!}
                @endif
            </h2>

            <div class="flex h-2.5 w-full gap-0.5 overflow-hidden rounded-full bg-secondary-soft/60 dark:bg-secondary-medium/50" role="img" aria-label="{{ __('targets.status_breakdown') }}: {{ collect($statusMeta)->map(fn ($meta) => $meta['label'].' '.$meta['count'])->join(', ') }}">
                @foreach ($statusMeta as $statusKey => $meta)
                    @if ($meta['count'] > 0)
                        <span @class(['h-full transition-opacity duration-300 ease-out', $meta['fill'], 'opacity-25' => $status !== '' && $status !== $statusKey]) style="width: {{ $shareOf($meta['count']) }}%"></span>
                    @endif
                @endforeach
            </div>

            <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('targets.filter_by_achievement') }}">
                @foreach ($statusMeta as $statusKey => $meta)
                    <button
                        type="button"
                        wire:click="filterStatus('{{ $statusKey }}')"
                        aria-pressed="{{ $status === $statusKey ? 'true' : 'false' }}"
                        data-test="status-filter-{{ $statusKey }}"
                        @class([
                            'inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-full border px-3.5 text-sm font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                            'border-secondary-dark bg-secondary-dark text-white dark:border-white dark:bg-white dark:text-secondary-dark' => $status === $statusKey,
                            'border-secondary-soft hover:border-secondary-light dark:border-secondary-medium dark:hover:border-secondary-light' => $status !== $statusKey,
                        ])
                    >
                        <span aria-hidden="true" class="size-2 rounded-full {{ $meta['dot'] }}"></span>
                        {{ $meta['label'] }}
                        <span class="font-semibold tabular-nums">{{ number_format($meta['count']) }}</span>
                        <span class="text-xs tabular-nums opacity-70">{{ number_format($shareOf($meta['count'])) }}%</span>
                    </button>
                @endforeach
            </div>
        </div>

        <dl class="flex flex-wrap gap-x-10 gap-y-4 @5xl/directory:justify-end">
            <div class="flex flex-col gap-1.5">
                <dt class="type-label text-secondary-medium dark:text-secondary-soft">{{ __('targets.committed_in_plans') }}</dt>
                <dd class="text-3xl font-bold leading-none tabular-nums">{{ number_format($pulse['plans']) }}</dd>
            </div>
            <div class="flex flex-col gap-1.5">
                <dt class="type-label text-secondary-medium dark:text-secondary-soft">{{ __('targets.committed_in_proposals') }}</dt>
                <dd class="text-3xl font-bold leading-none tabular-nums text-funding dark:text-white">{{ number_format($pulse['proposals']) }}</dd>
            </div>
            <div class="flex flex-col gap-1.5">
                <dt class="type-label text-secondary-medium dark:text-secondary-soft">{{ __('targets.reports_awaiting_approval') }}</dt>
                <dd class="flex items-baseline gap-2">
                    <span class="text-3xl font-bold leading-none tabular-nums">{{ number_format($pulse['reviewed']) }}</span>
                    @if ($pulse['drafts'] > 0)
                        <span class="text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">{{ trans_choice('targets.count_draft', $pulse['drafts'], ['count' => number_format($pulse['drafts'])]) }}</span>
                    @endif
                </dd>
            </div>
        </dl>
    </div>

    <div class="flex flex-col">
        <div class="flex min-h-11 flex-wrap items-center justify-between gap-3 border-b border-secondary-soft pb-3 dark:border-secondary-medium">
            <p class="type-meta text-secondary-medium dark:text-secondary-soft" aria-live="polite">
                {{ trans_choice('targets.count_matching_target', $this->matchingCount, ['count' => $this->matchingCount]) }}
                @if ($this->resultPage->lastPage() > 1)
                    · {{ __('targets.results_page', ['from' => $this->resultPage->firstItem(), 'to' => $this->resultPage->lastItem(), 'total' => $this->resultPage->total()]) }}
                @endif
            </p>
            @if ($scopeLabel !== null)
                <button type="button" wire:click="clearIndicatorScope" class="inline-flex max-w-full items-center gap-1.5 rounded-full border border-secondary-dark bg-secondary-dark px-3 py-1 text-xs font-semibold text-white hover:opacity-90 dark:border-white dark:bg-white dark:text-secondary-dark" data-test="indicator-scope-chip">
                    <span class="truncate">{{ $scopeLabel }}</span>
                    <flux:icon.x-mark variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
                    <span class="sr-only">{{ __('common.reset_filters') }}</span>
                </button>
            @endif
            <div class="flex items-center gap-4">
                <span class="hidden items-center gap-4 text-xs text-secondary-medium @3xl/directory:inline-flex dark:text-secondary-soft">
                    <span class="inline-flex items-center gap-1.5"><span aria-hidden="true" class="h-1.5 w-4 rounded-full bg-secondary-dark dark:bg-white"></span>{{ __('targets.legend_approved') }}</span>
                    <span class="inline-flex items-center gap-1.5"><span aria-hidden="true" class="target-pending h-1.5 w-4 rounded-full"></span>{{ __('targets.legend_pending') }}</span>
                </span>
                @if ($filtersAreActive)
                    <flux:button size="sm" variant="ghost" class="text-secondary-medium! dark:text-secondary-soft!" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>
                @endif
            </div>
        </div>

        @if ($this->targets->isEmpty())
            <x-ui.empty-state
                icon="chart-bar"
                :heading="$filtersAreActive ? __('targets.no_matching_targets') : __('common.no_targets_yet')"
                :body="$filtersAreActive ? __('common.change_the_search_or_filters_and_try_again') : __('targets.commit_the_first_target_in_an_annual_plan_or_a_proposal')"
            />
        @else
            <div class="flex flex-col gap-10 pt-6 transition-opacity duration-200" wire:loading.class="opacity-50" wire:target="search,sourceType,project,period,donor,program,year,filterStatus,clearFilters,gotoPage,nextPage,previousPage">
                @foreach ($groups as $resultId => $groupTargets)
                    @php
                        $groupResult = $groupTargets->first()->indicator->result;
                    @endphp
                    <section class="target-group flex flex-col" style="--group-order: {{ $loop->index }}" wire:key="target-group-{{ $resultId }}" aria-label="{{ $groupResult->statement }}">
                        <header class="flex items-start gap-3 pb-3">
                            <span aria-hidden="true" class="mt-1 size-3 shrink-0 rounded-full ring-2 ring-inset {{ $resultMark($groupResult->type) }}"></span>
                            <div class="flex min-w-0 flex-col gap-0.5">
                                <p class="type-label text-secondary-medium dark:text-secondary-soft">
                                    {{ $groupResult->type?->label() }}
                                    <span class="normal-case tracking-normal">· {{ trans_choice('targets.count_target', $groupTargets->count(), ['count' => $groupTargets->count()]) }}</span>
                                </p>
                                <p class="max-w-4xl text-pretty text-base font-semibold leading-snug">{{ $groupResult->statement }}</p>
                            </div>
                        </header>

                        {{-- The thread: every target hangs off its result. --}}
                        <ol class="ms-1.5 flex flex-col border-s border-secondary-soft dark:border-secondary-medium">
                            @foreach ($groupTargets as $target)
                                @php
                                    $source = $target->source;
                                    $sourceLabel = $this->sourceLabel($target);
                                    $isProposal = $target->source_type === 'proposal';
                                    $sourceUrl = match (true) {
                                        $source instanceof AnnualPlan => route('annual-plans.show', $source),
                                        $source instanceof Proposal => route('proposals.show', $source),
                                        default => null,
                                    };
                                    $targetIndicator = $target->indicator;
                                    $isPercentage = $targetIndicator->measure?->value === 'percentage';
                                    $unitSuffix = $isPercentage ? '%' : '';
                                    $unitWord = $isPercentage ? null : (filled($targetIndicator->unit) ? $targetIndicator->unit : null);
                                    $targetAmount = (float) $target->target;
                                    $actualAmount = (float) $target->actual_value;
                                    $pendingAmount = (float) $target->pending_value;
                                    $rowStatus = $actualAmount <= 0 ? 'not_started' : ($actualAmount >= $targetAmount ? 'achieved' : 'in_progress');
                                    $achievedPercent = $targetAmount > 0 ? $actualAmount / $targetAmount * 100 : ($actualAmount > 0 ? 100 : 0);
                                    $approvedWidth = min(100, $achievedPercent);
                                    $pendingWidth = $targetAmount > 0 ? max(0, min(100 - $approvedWidth, $pendingAmount / $targetAmount * 100)) : 0;
                                    $isExpanded = $expandedTargetId === $target->id;
                                    $chainIssues = $sourceLabel === null ? [] : $target->chainIssues();
                                    $linkClass = 'rounded-sm font-medium text-secondary-dark underline decoration-secondary-soft decoration-1 underline-offset-[3px] transition-colors hover:decoration-current focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:text-white dark:decoration-secondary-medium';
                                @endphp
                                <li class="relative" wire:key="target-{{ $target->id }}">
                                    <span aria-hidden="true" class="absolute top-[1.4rem] -start-[5.5px] z-10 size-2.5 rounded-full ring-4 ring-white {{ $statusMeta[$rowStatus]['dot'] }} dark:ring-secondary-dark"></span>

                                    <div @class([
                                        'grid gap-4 rounded-e-lg py-4 ps-6 pe-2 transition-colors duration-150 @4xl/directory:grid-cols-[minmax(0,1fr)_minmax(16rem,20rem)_auto] @4xl/directory:items-center @4xl/directory:gap-10',
                                        'bg-ground dark:bg-white/[0.04]' => $isExpanded,
                                        'hover:bg-ground dark:hover:bg-white/[0.04]' => ! $isExpanded,
                                    ])>
                                        <div class="flex min-w-0 flex-col gap-2">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <a href="{{ route('indicators.index', ['inspect' => $targetIndicator->id]) }}" wire:navigate class="text-[15px] font-semibold leading-snug hover:text-primary hover:underline">{{ $targetIndicator->name }}</a>
                                                <x-ui.enum-badge :case="$target->period" />
                                            </div>

                                            {{-- A row saved before the chain rules existed, or changed around them. --}}
                                            @foreach ($chainIssues as $issue)
                                                <p class="flex items-start gap-1.5 text-xs font-semibold text-primary" data-test="chain-issue-{{ $target->id }}">
                                                    <flux:icon.link-slash variant="micro" class="mt-px size-3.5 shrink-0" aria-hidden="true" />
                                                    {{ __($issue) }}
                                                </p>
                                            @endforeach

                                            {{-- Lineage: where it was committed, the money or plan behind it, then who delivers it. --}}
                                            <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px] text-secondary-medium dark:text-secondary-soft">
                                                {{-- Rendered from the morph map key, never a class name. --}}
                                                <span @class(['inline-flex items-center gap-1 font-medium', 'text-funding dark:text-secondary-soft' => $isProposal])>
                                                    @if ($isProposal)
                                                        <flux:icon.document-text variant="micro" class="size-3.5" aria-hidden="true" />
                                                    @else
                                                        <flux:icon.calendar variant="micro" class="size-3.5" aria-hidden="true" />
                                                    @endif
                                                    {{ $sourceTypeLabels[$target->source_type] ?? $target->source_type }}
                                                </span>
                                                @if ($sourceLabel === null)
                                                    {{-- source_id has no foreign key: the plan or proposal can be
                                                         removed out from under this row. --}}
                                                    <span class="rounded-md border border-primary px-2 py-0.5 text-[11px] font-semibold text-primary">
                                                        {{ __('targets.source_no_longer_available') }}
                                                    </span>
                                                @else
                                                    <a href="{{ $sourceUrl }}" wire:navigate class="{{ $linkClass }}">{{ $sourceLabel }}</a>
                                                    @if ($source instanceof AnnualPlan && $source->program !== null)
                                                        <span class="text-secondary-light" aria-hidden="true">/</span>
                                                        <span class="sr-only">{{ __('common.program') }}:</span>
                                                        <a href="{{ route('programs.show', $source->program) }}" wire:navigate class="{{ $linkClass }}">{{ $source->program->name }}</a>
                                                    @elseif ($source instanceof Proposal)
                                                        <x-ui.enum-badge :case="$source->status" />
                                                        <span class="inline-flex flex-wrap items-center gap-x-1.5">
                                                            <flux:icon.banknotes variant="micro" class="size-3.5 text-funding dark:text-secondary-soft" aria-hidden="true" />
                                                            <span class="sr-only">{{ __('common.donors') }}:</span>
                                                            @forelse ($source->donors as $donor)
                                                                <span class="inline-flex items-baseline gap-1.5">
                                                                    <a href="{{ route('donors.show', $donor) }}" wire:navigate class="{{ $linkClass }}">{{ $donor->name }}</a>
                                                                    @if ($donor->pivot?->budget !== null)
                                                                        <x-ui.money :amount="$donor->pivot->budget" :currency="$donor->pivot->currency" class="text-xs text-funding dark:text-secondary-soft" />
                                                                    @endif
                                                                </span>@if (! $loop->last)<span aria-hidden="true">,</span>@endif
                                                            @empty
                                                                <span class="italic">{{ __('targets.no_donor_linked') }}</span>
                                                            @endforelse
                                                        </span>
                                                    @endif
                                                @endif
                                                <flux:icon.arrow-long-right variant="micro" class="size-3.5 text-secondary-light rtl:-scale-x-100" aria-hidden="true" />
                                                @if ($target->project === null)
                                                    <span>{{ __('common.organisation_wide') }}</span>
                                                @else
                                                    <a href="{{ route('projects.show', $target->project) }}" wire:navigate class="{{ $linkClass }}">{{ $target->project->name }}</a>
                                                    <span class="inline-flex items-center gap-1">
                                                        <flux:icon.map-pin variant="micro" class="size-3.5 text-secondary-light" aria-hidden="true" />
                                                        {{ $target->project->city?->name ?? __('common.unknown_city') }}
                                                    </span>
                                                @endif
                                            </p>
                                        </div>

                                        {{-- Achievement: the figure first, then the bar that explains it. --}}
                                        <div class="flex flex-col gap-2">
                                            <div class="flex items-baseline justify-between gap-3">
                                                <span class="text-3xl font-bold leading-none tracking-tight tabular-nums {{ $statusMeta[$rowStatus]['text'] }}">{{ number_format($achievedPercent) }}<span class="text-lg font-semibold">%</span></span>
                                                <span class="min-w-0 text-end text-[13px] tabular-nums text-secondary-medium dark:text-secondary-soft">
                                                    <span class="font-semibold text-secondary-dark dark:text-white">{{ $formatValue($actualAmount) }}{{ $unitSuffix }}</span>
                                                    {{ __('targets.of_target', ['target' => $formatValue($targetAmount).$unitSuffix]) }}
                                                    @if ($unitWord !== null)
                                                        <span class="break-words">{{ $unitWord }}</span>
                                                    @endif
                                                </span>
                                            </div>
                                            <div class="flex h-2 w-full overflow-hidden rounded-full bg-secondary-soft/60 dark:bg-secondary-medium/50" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) round($approvedWidth) }}" aria-label="{{ __('targets.achievement_of', ['indicator' => $targetIndicator->name]) }}">
                                                <span class="target-bar h-full rounded-full {{ $statusMeta[$rowStatus]['fill'] }}" style="width: {{ $approvedWidth }}%"></span>
                                                @if ($pendingWidth > 0)
                                                    <span class="target-pending h-full" style="width: {{ $pendingWidth }}%"></span>
                                                @endif
                                            </div>
                                            <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-xs text-secondary-medium dark:text-secondary-soft">
                                                @if ($target->reports_count > 0)
                                                    <button
                                                        type="button"
                                                        wire:click="toggleReports({{ $target->id }})"
                                                        aria-expanded="{{ $isExpanded ? 'true' : 'false' }}"
                                                        aria-controls="target-reports-{{ $target->id }}"
                                                        data-test="toggle-reports-{{ $target->id }}"
                                                        class="-mx-1 inline-flex min-h-7 cursor-pointer items-center gap-1 rounded-md px-1 font-medium tabular-nums text-secondary-dark transition-colors hover:bg-secondary-soft/60 focus-visible:outline-2 focus-visible:outline-primary dark:text-white dark:hover:bg-white/10"
                                                    >
                                                        {{ trans_choice('targets.count_report', $target->reports_count, ['count' => $target->reports_count]) }}
                                                        <flux:icon.chevron-down variant="micro" @class(['size-3.5 transition-transform duration-200', 'rotate-180' => $isExpanded]) aria-hidden="true" />
                                                    </button>
                                                @else
                                                    <span>{{ trans_choice('targets.count_report', 0, ['count' => 0]) }}</span>
                                                @endif

                                                <span class="flex flex-wrap items-center gap-x-3 gap-y-1 tabular-nums">
                                                    @if ($achievedPercent > 100)
                                                        <span class="font-semibold text-status-good">{{ __('targets.exceeded_by', ['percent' => number_format($achievedPercent - 100)]) }}</span>
                                                    @endif
                                                    @if ($target->reviewed_count > 0)
                                                        <span>{{ trans_choice('targets.count_awaiting_approval', $target->reviewed_count, ['count' => $target->reviewed_count]) }}</span>
                                                    @endif
                                                    @if ($target->draft_count > 0)
                                                        <span>{{ trans_choice('targets.count_draft', $target->draft_count, ['count' => $target->draft_count]) }}</span>
                                                    @endif
                                                    @if (filled($targetIndicator->baseline))
                                                        <span>{{ __('targets.baseline_value', ['value' => $targetIndicator->baseline.$unitSuffix]) }}</span>
                                                    @endif
                                                </span>
                                            </div>
                                        </div>

                                        <div class="flex items-center gap-1 justify-self-end">
                                            @if ($sourceLabel !== null)
                                                <flux:tooltip :content="__('targets.record_a_report')">
                                                    <x-ui.button tone="ghost" size="sm" icon="document-plus" class="size-9!" :href="route('reports.index', ['create' => 1, 'target' => $target->id])" wire:navigate :aria-label="__('targets.record_a_report_for', ['indicator' => $targetIndicator->name])" data-test="record-report-{{ $target->id }}" />
                                                </flux:tooltip>
                                            @endif
                                            <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openEditModal({{ $target->id }})" :aria-label="__('targets.edit_target')" />

                                            @if ($target->reports_count === 0)
                                                <x-ui.button tone="ghost" size="sm" icon="trash" class="size-9!" wire:click="confirmDelete({{ $target->id }})" :aria-label="__('targets.delete_target')" />
                                            @else
                                                <flux:tooltip :content="__('targets.targets_that_have_been_reported_against_cannot_be_deleted')">
                                                    <span class="inline-flex size-9 cursor-not-allowed items-center justify-center text-secondary-light" aria-disabled="true">
                                                        <flux:icon.lock-closed class="size-4" />
                                                    </span>
                                                </flux:tooltip>
                                            @endif
                                        </div>
                                    </div>

                                    @if ($isExpanded)
                                        {{-- The report trail: every measurement behind the figure, newest first. --}}
                                        <div id="target-reports-{{ $target->id }}" class="target-trail ms-6 me-2 mb-3 mt-1">
                                            <ol class="flex flex-col divide-y divide-secondary-soft dark:divide-secondary-medium">
                                                @foreach ($this->expandedReports as $report)
                                                    <li wire:key="target-report-{{ $report->id }}">
                                                        <a href="{{ route('reports.show', $report) }}" wire:navigate class="group grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1 rounded-md px-3 py-2.5 transition-colors hover:bg-ground focus-visible:outline-2 focus-visible:outline-primary @2xl/directory:grid-cols-[minmax(0,1fr)_auto_auto_auto] dark:hover:bg-white/[0.04]">
                                                            <span class="flex min-w-0 flex-col text-[13px] font-medium">
                                                                <span class="truncate">
                                                                @if ($report->period_start && $report->period_end)
                                                                    {{ $report->period_start->translatedFormat(__('formats.date')) }} – {{ $report->period_end->translatedFormat(__('formats.date')) }}
                                                                @else
                                                                    {{ $report->planned_date?->translatedFormat(__('formats.date')) }}
                                                                @endif
                                                                <span class="font-normal text-secondary-medium dark:text-secondary-soft">· {{ $report->user?->name }}</span>
                                                                </span>
                                                                <span class="mt-0.5 flex flex-wrap items-center gap-x-3 text-xs font-normal text-secondary-medium dark:text-secondary-soft">
                                                                    <span class="inline-flex items-center gap-1">
                                                                        <flux:icon.paper-clip variant="micro" class="size-3.5" aria-hidden="true" />
                                                                        {{ trans_choice('targets.count_evidence_file', $report->files_count, ['count' => $report->files_count]) }}
                                                                    </span>
                                                                    <span @class(['inline-flex items-center gap-1', 'text-status-good' => $report->is_seen_by_meal])>
                                                                        @if ($report->is_seen_by_meal)
                                                                            <flux:icon.eye variant="micro" class="size-3.5" aria-hidden="true" />
                                                                            {{ __('reports.seen_by_meal') }}
                                                                        @else
                                                                            <flux:icon.eye-slash variant="micro" class="size-3.5" aria-hidden="true" />
                                                                            {{ __('targets.not_yet_seen_by_meal') }}
                                                                        @endif
                                                                    </span>
                                                                </span>
                                                            </span>
                                                            <span class="justify-self-end text-sm font-semibold tabular-nums @2xl/directory:order-3">{{ $formatValue((float) $report->value) }}{{ $unitSuffix }}</span>
                                                            <span class="@2xl/directory:order-2"><x-ui.enum-badge :case="$report->status" /></span>
                                                            <flux:icon.arrow-up-right variant="micro" class="hidden size-3.5 justify-self-end text-secondary-light transition-transform group-hover:-translate-y-px group-hover:translate-x-px @2xl/directory:order-4 @2xl/directory:block rtl:-scale-x-100" aria-hidden="true" />
                                                        </a>
                                                    </li>
                                                @endforeach
                                            </ol>
                                        </div>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    </section>
                @endforeach
            </div>
        @endif

        <flux:pagination :paginator="$this->resultPage" class="pt-4 text-secondary-medium! dark:text-secondary-soft!" scroll-to="#target-directory-heading" />
    </div>

    <flux:modal wire:model="showTargetModal" class="w-full max-w-2xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        @if ($showTargetModal)
        <form wire:submit="saveTarget" class="space-y-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ $editingTargetId === null ? __('targets.commit_target') : __('targets.edit_target') }}</flux:heading>
                <flux:subheading>{{ __('targets.a_target_is_committed_either_in_an_annual_operational_plan_o') }}</flux:subheading>
            </div>

            @php
                $formIndicator = $this->formIndicator;
                $formIsPercentage = $formIndicator?->measure === IndicatorMeasure::Percentage;
            @endphp

            {{-- The indicator comes first: it decides which plans and projects fit. --}}
            <div>
                <x-ui.select wire:model.live="targetIndicatorId" :label="__('targets.indicator')" required>
                    <flux:select.option value="">{{ __('targets.select_an_indicator') }}</flux:select.option>
                    @foreach ($this->indicatorOptions as $indicatorOption)
                        <flux:select.option :value="$indicatorOption->id" :wire:key="'tgt-indicator-'.$indicatorOption->id">{{ $indicatorOption->name }} — {{ Str::limit($indicatorOption->result?->statement, 50) }}</flux:select.option>
                    @endforeach
                </x-ui.select>
                @if ($formIndicator !== null)
                    <p class="mt-1.5 type-meta text-secondary-medium dark:text-secondary-soft">
                        {{ $formIndicator->measure->label() }}
                        @if ($formIndicator->project !== null)
                            · {{ __('targets.scoped_to_project', ['project' => $formIndicator->project->name]) }}
                        @endif
                    </p>
                @endif
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.select wire:model.live="targetSourceType" :label="__('targets.committed_in')" required>
                    @foreach ($sourceTypeLabels as $sourceKey => $sourceLabel)
                        <flux:select.option :value="$sourceKey" :wire:key="'tgt-source-option-'.$sourceKey">{{ $sourceLabel }}</flux:select.option>
                    @endforeach
                </x-ui.select>

                <div>
                    <x-ui.select wire:model.live="targetSourceId" :label="$targetSourceType === 'proposal' ? __('common.proposal') : __('common.annual_plan')" required>
                        <flux:select.option value="">{{ __('targets.select_a_source') }}</flux:select.option>
                        @foreach ($this->sourceOptions as $sourceOption)
                            <flux:select.option :value="$sourceOption['id']" :wire:key="'tgt-src-'.$targetSourceType.'-'.$sourceOption['id']">
                                {{ $sourceOption['label'] }}
                            </flux:select.option>
                        @endforeach
                    </x-ui.select>
                    @if ($targetSourceType === 'annual_plan' && $formIndicator?->result?->program_id !== null)
                        <p class="mt-1.5 type-meta text-secondary-medium dark:text-secondary-soft">{{ __('targets.only_plans_of_the_result_program_are_offered') }}</p>
                    @endif
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.input wire:model="targetValue" :label="$formIsPercentage ? __('targets.target_value_percent') : __('targets.target_value')" type="number" step="0.01" min="0" :max="$formIsPercentage ? 100 : null" required />

                <x-ui.select wire:model="targetPeriod" :label="__('targets.reporting_period')" required>
                    @foreach (Period::cases() as $periodCase)
                        <flux:select.option :value="$periodCase->value" :wire:key="'tgt-period-option-'.$periodCase->value">{{ $periodCase->label() }}</flux:select.option>
                    @endforeach
                </x-ui.select>

                <x-ui.select wire:model="targetProjectId" :label="__('common.project')" :disabled="$formIndicator?->project_id !== null">
                    @if ($formIndicator?->project_id === null)
                        <flux:select.option value="">{{ __('common.organisation_wide') }}</flux:select.option>
                    @endif
                    @foreach ($this->formProjectOptions as $projectOption)
                        <flux:select.option :value="$projectOption->id" :wire:key="'tgt-project-option-'.$projectOption->id">{{ $projectOption->name }}</flux:select.option>
                    @endforeach
                </x-ui.select>
            </div>

            @if ($targetSourceId !== '')
                <p class="-mt-2 type-meta text-secondary-medium dark:text-secondary-soft">
                    {{ $targetSourceType === 'proposal' ? __('targets.only_projects_the_proposal_funds_are_offered') : __('targets.only_projects_in_the_plan_program_are_offered') }}
                </p>
            @endif

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="closeTargetModal">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-target-button">
                    {{ $editingTargetId === null ? __('targets.commit_target') : __('common.save_changes') }}
                </x-ui.button>
            </div>
        </form>
        @endif
    </flux:modal>

    <flux:modal wire:model="showDeleteModal" wire:close="cancelDelete" class="w-full max-w-md border border-secondary-light! bg-white! dark:bg-secondary-dark!">
        @if ($deletingTargetId !== null)
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('targets.delete_this_target') }}</flux:heading>
            <flux:subheading>{{ __('targets.only_a_target_with_no_reports_can_be_deleted_otherwise_every') }}</flux:subheading>
            <div class="flex justify-end gap-2 border-t border-secondary-soft pt-4 dark:border-secondary-medium">
                <x-ui.button type="button" wire:click="cancelDelete">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="button" tone="primary" wire:click="deleteTarget" data-test="confirm-delete-target">{{ __('targets.delete_target') }}</x-ui.button>
            </div>
        </div>
        @endif
    </flux:modal>
</section>
