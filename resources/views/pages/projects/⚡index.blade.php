<?php

use App\Enums\MediaCollection;
use App\Support\Like;
use App\Actions\Projects\ArchiveProject;
use App\Actions\Projects\RestoreProject;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\RagStatus;
use App\Models\HrCity;
use App\Models\HrEmployee;
use App\Models\HrProgram;
use App\Models\Program;
use App\Models\Project;
use App\Notifications\UnreadSubjects;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.projects')] class extends Component {
    use WithPagination;

    /** The views this page can render the filtered portfolio in. */
    private const VIEWS = ['board', 'timeline', 'table'];

    /** The orders the portfolio can be read in. */
    private const SORTS = ['name', 'start_date', 'end_date', 'updated_at'];

    /** The single-select triage lenses of the cockpit strip. */
    private const LENSES = ['unfunded', 'overdue', 'slipping', 'risks'];

    /** Cards are smaller than rows, so the board shows more of them. */
    private const BOARD_PAGE_SIZE = 24;

    private const LIST_PAGE_SIZE = 15;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $city = '';

    #[Url(except: '')]
    public string $program = '';

    #[Url(except: '')]
    public string $strategy = '';

    #[Url(except: '')]
    public string $owner = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: 'board')]
    public string $view = 'board';

    #[Url(except: 'name')]
    public string $sort = 'name';

    #[Url(except: '')]
    public string $lens = '';

    public bool $showProjectModal = false;

    #[Locked]
    public ?int $editingProjectId = null;

    #[Locked]
    public ?int $archivingProjectId = null;

    /**
     * Carried separately from archivingProjectId because a flux:modal only opens
     * from a boolean bound with wire:model; :open never reopens on a round trip.
     */
    public bool $showArchiveModal = false;

    public string $name = '';

    public string $description = '';

    public string $projectStatus = 'draft';

    public string $startDate = '';

    public string $endDate = '';

    public string $targetBeneficiaries = '';

    public string $targetMen = '';

    public string $targetWomen = '';

    public string $targetBoys = '';

    public string $targetGirls = '';

    public string $cityId = '';

    public string $programId = '';

    public string $userId = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCity(): void
    {
        $this->resetPage();
    }

    public function updatedProgram(): void
    {
        $this->resetPage();
    }

    public function updatedStrategy(): void
    {
        $this->resetPage();
    }

    public function updatedOwner(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /**
     * Fall back to the board rather than rendering nothing for a view name
     * that is not one of ours.
     */
    public function updatedView(): void
    {
        if (! in_array($this->view, self::VIEWS, true)) {
            $this->view = 'board';
        }
    }

    public function updatedSort(): void
    {
        if (! in_array($this->sort, self::SORTS, true)) {
            $this->sort = 'name';
        }

        $this->resetPage();
    }

    public function updatedLens(): void
    {
        if (! in_array($this->lens, self::LENSES, true)) {
            $this->lens = '';
        }

        $this->resetPage();
    }

    /**
     * Select a lifecycle segment, or clear it when it is already selected.
     */
    public function toggleStatus(string $status): void
    {
        $this->status = $this->status === $status ? '' : $status;
        $this->resetPage();
    }

    /**
     * Lenses are single-select: combining them produces empty sets that read
     * as a bug rather than as an answer.
     */
    public function toggleLens(string $lens): void
    {
        $this->lens = $this->lens === $lens || ! in_array($lens, self::LENSES, true) ? '' : $lens;
        $this->resetPage();
    }

    /**
     * Reset every filter, including the lens.
     *
     * `view` and `sort` survive: they are reading preferences, not filters.
     */
    public function clearFilters(): void
    {
        $this->search = '';
        $this->city = '';
        $this->program = '';
        $this->strategy = '';
        $this->owner = '';
        $this->status = '';
        $this->lens = '';
        $this->resetPage();
    }

    /**
     * Records with unread notifications for me, for the row dots.
     *
     * @return list<int>
     */
    #[Computed]
    public function unreadIds(): array
    {
        return app(UnreadSubjects::class)->ids(auth()->user(), 'project');
    }

    /**
     * The HR ids a name search resolves to, read once per request.
     *
     * Cities and employees live in the HR database, so a name search resolves
     * to ids here instead of a whereHas() across connections. Memoising them
     * keeps `applyFilters()` free to run for the list, the rail and the
     * triage strip without paying for the same two reads each time.
     *
     * @return array{cities: list<int>, owners: list<int>}
     */
    #[Computed]
    public function searchMatches(): array
    {
        $search = trim($this->search);

        if ($search === '') {
            return ['cities' => [], 'owners' => []];
        }

        return [
            'cities' => HrCity::query()
                ->where('name_en', 'like', Like::contains($search))
                ->orWhere('name_ar', 'like', Like::contains($search))
                ->pluck('id')
                ->all(),
            'owners' => HrEmployee::idsMatchingName($search),
        ];
    }

    /**
     * The one place the portfolio is narrowed down.
     *
     * The list, the lifecycle rail and the triage tiles all read through this
     * method, so a filter can never mean one thing in the board and another in
     * the counts above it.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    protected function applyFilters(Builder $query, bool $includeStatus = true, bool $includeLens = true): Builder
    {
        $search = trim($this->search);
        $matches = $this->searchMatches;

        return $query
            ->when($search !== '', function (Builder $query) use ($search, $matches): void {
                $query->where(function (Builder $query) use ($search, $matches): void {
                    $query
                        ->where('name', 'like', Like::contains($search))
                        ->orWhereIn('city_id', $matches['cities'])
                        ->orWhereIn('program_id', HrProgram::idsMatchingName($search))
                        ->orWhereIn('user_id', $matches['owners']);
                });
            })
            ->when($this->city !== '', fn (Builder $query) => $query->where('city_id', (int) $this->city))
            ->when($this->program !== '', fn (Builder $query) => $query->where('program_id', (int) $this->program))
            ->when($this->strategy !== '', fn (Builder $query) => $query->whereHas('program', fn ($query) => $query->where('strategy_id', (int) $this->strategy)))
            ->when($this->owner !== '', fn (Builder $query) => $query->where('user_id', (int) $this->owner))
            ->when($includeStatus && $this->status !== '', fn (Builder $query) => $query->where('status', $this->status))
            ->when($includeLens && $this->lens !== '', fn (Builder $query) => $this->applyLens($query));
    }

    /**
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    protected function applyLens(Builder $query): Builder
    {
        return match ($this->lens) {
            'unfunded' => $query->doesntHave('proposals'),
            'overdue' => $query->where('status', ProjectStatus::Active->value)->whereDate('end_date', '<', today()),
            'slipping' => $query->whereHas('milestones', fn ($query) => $query
                ->whereDate('planned_start_date', '<', today())
                ->whereNotIn('status', [MilestoneStatus::Reached->value, MilestoneStatus::Cancelled->value])),
            'risks' => $query->whereHas('risks', fn ($query) => $query->where('happened', true)),
            default => $query,
        };
    }

    /**
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    protected function applySort(Builder $query): Builder
    {
        if ($this->view === 'board') {
            $cases = ProjectStatus::cases();
            $arms = [];

            foreach (array_keys($cases) as $position) {
                $arms[] = 'when status = ? then '.$position;
            }

            $query->orderByRaw('case '.implode(' ', $arms).' else '.count($cases).' end', array_column($cases, 'value'));
        }

        // Nulls sort last in both date orders, which is what the raw
        // "is null" ordering in front of each column buys.
        return match ($this->sort) {
            'start_date' => $query->orderByRaw('start_date is null')->orderBy('start_date')->orderBy('name'),
            'end_date' => $query->orderByRaw('end_date is null')->orderBy('end_date')->orderBy('name'),
            'updated_at' => $query->orderByDesc('updated_at')->orderByDesc('id'),
            default => $query->orderBy('name')->orderBy('id'),
        };
    }

    /**
     * Whether the viewer may see committed budgets and currencies. They are
     * agreement data, so they need funding-agreements.view; donor and proposal
     * names stay visible either way.
     */
    protected function canViewMoney(): bool
    {
        return auth()->user()?->can('funding-agreements.view') ?? false;
    }

    /**
     * The file collections this viewer may not count: signed agreements and
     * MOUs are agreement data, so they need funding-agreements.view.
     *
     * @return list<string>
     */
    protected function hiddenCollections(): array
    {
        if (auth()->user()?->can('funding-agreements.view') ?? false) {
            return [];
        }

        return array_values(array_map(
            fn (MediaCollection $collection): string => $collection->value,
            array_filter(MediaCollection::cases(), fn (MediaCollection $collection): bool => $collection->isFundingPaper()),
        ));
    }

    /** @return LengthAwarePaginator<int, Project> */
    #[Computed]
    public function projects(): LengthAwarePaginator
    {
        $query = Project::query()
            ->select([
                'id',
                'slug',
                'name',
                'status',
                'start_date',
                'end_date',
                'target_beneficiaries',
                'target_men',
                'target_women',
                'target_boys',
                'target_girls',
                'city_id',
                'program_id',
                'user_id',
                'updated_at',
            ])
            ->with([
                'city:id,name_en,name_ar',
                'program:id,strategy_id',
                'program.strategy:id,name',
                'user:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
                'ownerAssignment',
            ])
            ->when($this->view === 'timeline', fn (Builder $query) => $query->with([
                'milestones' => fn ($query) => $query
                    ->select(['id', 'project_id', 'name', 'planned_start_date', 'planned_end_date', 'actual_end_date', 'status'])
                    ->where('status', '!=', MilestoneStatus::Cancelled->value)
                    ->reorder()
                    ->orderBy('planned_start_date'),
            ]))
            ->withCount([
                'risks',
                'milestones',
                'planIndicators',
                'proposals',
                'indicators',
                'media' => fn ($query) => $query->whereNotIn('collection', $this->hiddenCollections()),
                'risks as materialised_risks_count' => fn ($query) => $query->where('happened', true),
                'milestones as reached_milestones_count' => fn ($query) => $query->where('status', MilestoneStatus::Reached->value),
            ])
            ->withSum(['milestones as reached_weight' => fn ($query) => $query->where('status', MilestoneStatus::Reached->value)], 'weight_percentage')
            ->withSum(['milestones as planned_weight' => fn ($query) => $query->where('status', '!=', MilestoneStatus::Cancelled->value)], 'weight_percentage');

        return $this->applySort($this->applyFilters($query))
            ->paginate($this->view === 'board' ? self::BOARD_PAGE_SIZE : self::LIST_PAGE_SIZE);
    }

    /**
     * Weighted milestone progress, or null when nothing carries a weight.
     *
     * Weights that add up to more than 100 are capped rather than rejected:
     * the figure is a reading of the record, not a validation of it.
     */
    protected function progressFor(Project $project): ?float
    {
        $planned = (float) ($project->planned_weight ?? 0);

        return $planned > 0 ? round(min(100, ((float) ($project->reached_weight ?? 0) / $planned) * 100), 1) : null;
    }

    /**
     * How many projects sit in each lifecycle state.
     *
     * The rail ignores the status filter so it always shows where the rest of
     * the filtered portfolio sits; every other filter still applies.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function statusCounts(): array
    {
        return $this->applyFilters(Project::query(), includeStatus: false)
            ->toBase()
            ->groupBy('status')
            ->selectRaw('status, count(*) as aggregate')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * The four triage counts, in one read.
     *
     * They ignore both the status filter and the active lens: a lens that
     * shrank its own tile would make the number jump the moment it is clicked
     * and the strip would read as broken.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function triageCounts(): array
    {
        $today = today()->toDateString();

        $row = $this->applyFilters(Project::query(), includeStatus: false, includeLens: false)
            ->toBase()
            ->selectRaw(
                'sum(case when not exists (select 1 from project_proposal where project_proposal.project_id = projects.id) then 1 else 0 end) as unfunded_count, '
                .'sum(case when projects.status = ? and projects.end_date < ? then 1 else 0 end) as overdue_count, '
                .'sum(case when exists (select 1 from milestones where milestones.project_id = projects.id and milestones.planned_start_date < ? and milestones.status not in (?, ?)) then 1 else 0 end) as slipping_count, '
                .'sum(case when exists (select 1 from risks where risks.project_id = projects.id and risks.happened = 1) then 1 else 0 end) as risks_count',
                [
                    ProjectStatus::Active->value,
                    $today,
                    $today,
                    MilestoneStatus::Reached->value,
                    MilestoneStatus::Cancelled->value,
                ],
            )
            ->first();

        return [
            'unfunded' => (int) ($row->unfunded_count ?? 0),
            'overdue' => (int) ($row->overdue_count ?? 0),
            'slipping' => (int) ($row->slipping_count ?? 0),
            'risks' => (int) ($row->risks_count ?? 0),
        ];
    }

    /**
     * Committed funding for the filtered portfolio, per currency.
     *
     * Every proposal in this database is shared by two or three projects, so
     * the total is taken over the DISTINCT proposals the filtered set reaches.
     * Adding up a per-project figure instead overcounts by roughly 2.25x.
     *
     * Currencies are never added together: USD, EUR and SYP have no conversion
     * anywhere in this system.
     *
     * @return array<string, float>
     */
    #[Computed]
    public function committedFunding(): array
    {
        if (! $this->canViewMoney()) {
            return [];
        }

        $projectIds = $this->applyFilters(Project::query())->toBase()->select('projects.id');

        return DB::table('donor_proposal')
            ->whereIn('proposal_id', fn ($query) => $query
                ->select('proposal_id')
                ->from('project_proposal')
                ->whereIn('project_id', $projectIds))
            ->groupBy('currency')
            ->selectRaw('currency, sum(budget) as total')
            ->pluck('total', 'currency')
            ->map(fn ($total): float => (float) $total)
            ->all();
    }

    /**
     * The headline beneficiary commitment across the filtered portfolio.
     */
    #[Computed]
    public function committedBeneficiaries(): int
    {
        return (int) $this->applyFilters(Project::query())->sum('target_beneficiaries');
    }

    /**
     * The funding envelope of every project on the visible page, in one read.
     *
     * A shared proposal's budget is not attributable to one project, so the
     * card reports the envelope covering it and says how many other projects
     * the same proposal covers.
     *
     * @return array<int, array{currencies: array<string, float>, donors: list<string>, proposals: list<string>, shared: int}>
     */
    #[Computed]
    public function fundingByProject(): array
    {
        $visibleIds = $this->projects->getCollection()->modelKeys();

        if ($visibleIds === []) {
            return [];
        }

        $canViewMoney = $this->canViewMoney();

        $rows = DB::table('project_proposal')
            ->join('donor_proposal', 'donor_proposal.proposal_id', '=', 'project_proposal.proposal_id')
            ->join('donors', 'donors.id', '=', 'donor_proposal.donor_id')
            ->join('proposals', 'proposals.id', '=', 'project_proposal.proposal_id')
            ->whereIn('project_proposal.project_id', $visibleIds)
            ->select([
                'project_proposal.project_id',
                'project_proposal.proposal_id',
                'donors.name as donor_name',
                'proposals.name as proposal_name',
            ])
            ->when($canViewMoney, fn ($query) => $query->addSelect([
                'donor_proposal.currency',
                'donor_proposal.budget',
            ]))
            ->selectSub(
                DB::table('project_proposal as shares')
                    ->selectRaw('count(*)')
                    ->whereColumn('shares.proposal_id', 'project_proposal.proposal_id'),
                'share_count',
            )
            ->get();

        $funding = [];

        foreach ($rows as $row) {
            $projectId = (int) $row->project_id;

            $funding[$projectId] ??= ['currencies' => [], 'donors' => [], 'proposals' => [], 'shared' => 0];

            if ($canViewMoney) {
                $funding[$projectId]['currencies'][$row->currency] = ($funding[$projectId]['currencies'][$row->currency] ?? 0.0) + (float) $row->budget;
            }

            $funding[$projectId]['shared'] = max($funding[$projectId]['shared'], (int) $row->share_count - 1);

            if (! in_array($row->donor_name, $funding[$projectId]['donors'], true)) {
                $funding[$projectId]['donors'][] = $row->donor_name;
            }

            if (! in_array($row->proposal_name, $funding[$projectId]['proposals'], true)) {
                $funding[$projectId]['proposals'][] = $row->proposal_name;
            }
        }

        return $funding;
    }

    /**
     * The date range the timeline plots every bar against.
     *
     * It is read from the projects already on the page, so switching to the
     * timeline costs no extra query.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}|null
     */
    #[Computed]
    public function timelineWindow(): ?array
    {
        $dates = $this->projects
            ->getCollection()
            ->flatMap(fn (Project $project): array => [$project->start_date, $project->end_date])
            ->filter()
            ->values();

        if ($dates->isEmpty()) {
            return null;
        }

        $start = $dates->min();
        $end = $dates->max();

        return $end->lte($start)
            ? ['start' => $start, 'end' => $start->addMonth()]
            : ['start' => $start, 'end' => $end];
    }

    /** @return Collection<int, HrCity> */
    #[Computed]
    public function cityOptions(): Collection
    {
        return HrCity::query()->select(['id', 'name_en', 'name_ar'])->orderBy(HrCity::nameColumn())->orderBy('id')->get();
    }

    /** @return Collection<int, Program> */
    #[Computed]
    public function programOptions(): Collection
    {
        return Program::options();
    }

    /**
     * Who can own a project: the managers of programs (loaded from HR into
     * programs.manager_id), plus, when editing, the project's present owner so
     * the form still shows them.
     *
     * @return Collection<int, HrEmployee>
     */
    #[Computed]
    public function ownerOptions(): Collection
    {
        $ids = $this->programOptions->pluck('manager_id');

        if ($this->editingProjectId !== null) {
            $ids->push(Project::query()->whereKey($this->editingProjectId)->value('user_id'));
        }

        return $this->userOptions->whereIn('id', $ids->filter()->map(fn (mixed $id): int => (int) $id)->unique()->all())->values();
    }

    /**
     * Choosing a program proposes its manager as the owner; the person filling
     * the form can still pick another program manager. Left alone when the
     * program has no manager or the editor may not change the owner.
     */
    public function updatedProgramId(string $value): void
    {
        if ($value === '' || ($this->editingProjectId !== null && ! Gate::allows('reassign', Project::query()->findOrFail($this->editingProjectId)))) {
            return;
        }

        $managerId = Program::query()->whereKey((int) $value)->value('manager_id');

        if ($managerId !== null) {
            $this->userId = (string) $managerId;
        }
    }

    /** @return Collection<int, HrEmployee> */
    #[Computed]
    public function userOptions(): Collection
    {
        return HrEmployee::pickerOptions();
    }

    public function openCreateModal(): void
    {
        Gate::authorize('create', Project::class);

        $this->resetProjectForm();
        $this->showProjectModal = true;
    }

    public function openEditModal(int $projectId): void
    {
        $project = Project::query()
            ->select([
                'id',
                'name',
                'description',
                'status',
                'start_date',
                'end_date',
                'target_beneficiaries',
                'target_men',
                'target_women',
                'target_boys',
                'target_girls',
                'city_id',
                'program_id',
                'user_id',
            ])
            ->findOrFail($projectId);

        Gate::authorize('update', $project);

        $this->resetValidation();
        $this->editingProjectId = $project->id;
        $this->name = $project->name;
        $this->description = $project->description ?? '';
        $this->projectStatus = $project->status->value;
        $this->startDate = $project->start_date?->toDateString() ?? '';
        $this->endDate = $project->end_date?->toDateString() ?? '';
        $this->targetBeneficiaries = self::formInteger($project->target_beneficiaries);
        $this->targetMen = self::formInteger($project->target_men);
        $this->targetWomen = self::formInteger($project->target_women);
        $this->targetBoys = self::formInteger($project->target_boys);
        $this->targetGirls = self::formInteger($project->target_girls);
        $this->cityId = (string) $project->city_id;
        $this->programId = (string) $project->program_id;
        $this->userId = (string) $project->user_id;
        $this->showProjectModal = true;
    }

    public function saveProject(): void
    {
        if ($this->editingProjectId === null) {
            Gate::authorize('create', Project::class);
        } else {
            Gate::authorize('update', Project::query()->findOrFail($this->editingProjectId));
        }

        $this->name = trim($this->name);
        $this->description = trim($this->description);

        // A new project always starts as a draft; the status only opens up
        // once its owner has acknowledged (see statusLocked()).
        if ($this->editingProjectId === null) {
            $this->projectStatus = ProjectStatus::Draft->value;
        }

        // Only someone who may change the owner has to pick a program manager;
        // for anyone else the submitted owner is ignored below.
        $mustPickProgramManager = $this->editingProjectId === null
            || Gate::allows('reassign', Project::query()->findOrFail($this->editingProjectId));

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'projectStatus' => ['required', Rule::enum(ProjectStatus::class)],
            'startDate' => ['nullable', 'date'],
            'endDate' => [
                'nullable',
                'date',
                Rule::when($this->startDate !== '', ['after_or_equal:startDate']),
            ],
            'targetBeneficiaries' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'targetMen' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'targetWomen' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'targetBoys' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'targetGirls' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'cityId' => ['required', 'integer', Rule::exists(HrCity::class, 'id')],
            'programId' => ['required', 'integer', Rule::exists(Program::class, 'id')],
            'userId' => ['required', 'integer', Rule::exists(HrEmployee::class, 'id'), Rule::when($mustPickProgramManager, [Rule::in($this->ownerOptions->pluck('id')->all())])],
        ], [
            'name.required' => __('projects.enter_a_project_name'),
            'cityId.required' => __('projects.select_a_city_2'),
            'cityId.exists' => __('projects.the_selected_city_is_no_longer_available'),
            'programId.required' => __('projects.select_a_program_2'),
            'programId.exists' => __('projects.the_selected_program_is_no_longer_available'),
            'userId.required' => __('projects.select_a_project_owner'),
            'userId.exists' => __('projects.the_selected_owner_is_no_longer_available'),
            'userId.in' => __('projects.owner_must_be_program_manager'),
            'endDate.after_or_equal' => __('projects.the_end_date_must_be_on_or_after_the_start_date'),
        ]);

        $project = $this->editingProjectId === null
            ? new Project
            : Project::query()->findOrFail($this->editingProjectId);

        // An owner edits their project but does not move it to another
        // program or owner, and archiving goes through archiveProject().
        $mayReassign = ! $project->exists || Gate::allows('reassign', $project);

        if ($project->exists && ProjectStatus::from($validated['projectStatus']) === ProjectStatus::Archived && $project->status !== ProjectStatus::Archived) {
            Gate::authorize('delete', $project);
        }

        $this->ensureOwnerAcknowledgedToLeaveDraft($project, ProjectStatus::from($validated['projectStatus']), $mayReassign ? (int) $validated['userId'] : (int) $project->user_id);

        $project->fill([
            'name' => $validated['name'],
            'description' => $validated['description'] === '' ? null : $validated['description'],
            'status' => ProjectStatus::from($validated['projectStatus']),
            'start_date' => self::nullableString($validated['startDate']),
            'end_date' => self::nullableString($validated['endDate']),
            'target_beneficiaries' => self::nullableInteger($validated['targetBeneficiaries']),
            'target_men' => self::nullableInteger($validated['targetMen']),
            'target_women' => self::nullableInteger($validated['targetWomen']),
            'target_boys' => self::nullableInteger($validated['targetBoys']),
            'target_girls' => self::nullableInteger($validated['targetGirls']),
            'city_id' => (int) $validated['cityId'],
            'program_id' => $mayReassign ? (int) $validated['programId'] : $project->program_id,
            'user_id' => $mayReassign ? (int) $validated['userId'] : $project->user_id,
        ])->save();

        $wasRecentlyCreated = $project->wasRecentlyCreated;

        $this->showProjectModal = false;
        $this->resetProjectForm();
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('projects.project_created') : __('projects.project_updated'),
        );
    }

    /**
     * Whether the status dropdown in the project form is read-only: always for
     * a new project, and for a draft until its owner has acknowledged (and the
     * form still names that same owner). Other states move freely.
     */
    #[Computed]
    public function statusLocked(): bool
    {
        if ($this->editingProjectId === null) {
            return true;
        }

        $project = Project::query()->find($this->editingProjectId);

        if ($project === null || $project->status !== ProjectStatus::Draft) {
            return false;
        }

        return ! ($project->isAssignmentAcknowledged('user_id') && (int) $this->userId === (int) $project->user_id);
    }

    /**
     * A draft stays a draft until its owner has acknowledged being in charge
     * (see Assignment). Putting yourself in charge counts as acknowledging.
     */
    protected function ensureOwnerAcknowledgedToLeaveDraft(Project $project, ProjectStatus $target, int $ownerEmployeeId): void
    {
        $leavesDraft = ! $project->exists || $project->status === ProjectStatus::Draft;

        if (! $leavesDraft || in_array($target, [ProjectStatus::Draft, ProjectStatus::Archived], true)) {
            return;
        }

        $acknowledged = $project->exists && $ownerEmployeeId === (int) $project->user_id
            ? $project->isAssignmentAcknowledged('user_id')
            : (int) Auth::user()?->employee_id === $ownerEmployeeId;

        if (! $acknowledged) {
            throw ValidationException::withMessages(['projectStatus' => __('projects.owner_must_acknowledge_first')]);
        }
    }

    /**
     * The inbox changed (poll, push, or an action done from the bell): the
     * unread dots and the Rejected badges are read fresh on this re-render.
     */
    #[On('notification-received')]
    public function refreshFromInbox(): void {}

    public function closeProjectModal(): void
    {
        $this->showProjectModal = false;
        $this->resetProjectForm();
    }

    public function confirmArchive(int $projectId): void
    {
        Gate::authorize('delete', Project::query()->findOrFail($projectId));

        $this->archivingProjectId = $projectId;
        $this->showArchiveModal = true;
    }

    public function cancelArchive(): void
    {
        $this->archivingProjectId = null;
        $this->showArchiveModal = false;
    }

    /**
     * The project under the archive confirmation, with the work it carries, so
     * the dialog can say what leaves the active board. Nothing is destroyed.
     *
     * @return array{project: Project, carries: array<string, int>}|null
     */
    #[Computed]
    public function archivingProject(): ?array
    {
        if ($this->archivingProjectId === null) {
            return null;
        }

        $project = Project::query()
            ->select(['id', 'name', 'status'])
            ->withCount(['milestones', 'risks', 'proposals'])
            ->find($this->archivingProjectId);

        if ($project === null) {
            return null;
        }

        return [
            'project' => $project,
            'carries' => array_filter([
                __('common.milestones') => $project->milestones_count,
                __('common.risks') => $project->risks_count,
                __('common.proposals') => $project->proposals_count,
            ]),
        ];
    }

    /**
     * Retire the project into the Archived lifecycle state.
     *
     * Nothing in this application is soft-deleted, so archiving is how a
     * project leaves the active portfolio without destroying its delivery
     * record. restoreProject() puts it back.
     */
    public function archiveProject(): void
    {
        if ($this->archivingProjectId === null) {
            return;
        }

        $project = Project::query()->find($this->archivingProjectId);

        if ($project === null) {
            $this->cancelArchive();

            return;
        }

        app(ArchiveProject::class)->handle($project);

        $this->archivingProjectId = null;
        $this->showArchiveModal = false;
        $this->resetPage();

        Flux::toast(variant: 'success', text: __('projects.project_archived'));
    }

    /**
     * Put an archived project back into the portfolio as a draft, which is the
     * one lifecycle state that carries no claim about where the work stands.
     */
    public function restoreProject(int $projectId): void
    {
        $project = Project::query()->find($projectId);

        if ($project === null || ! app(RestoreProject::class)->handle($project)) {
            return;
        }

        $this->resetPage();

        Flux::toast(variant: 'success', text: __('projects.project_restored'));
    }

    protected function resetProjectForm(): void
    {
        $this->resetValidation();
        $this->editingProjectId = null;
        $this->name = '';
        $this->description = '';
        $this->projectStatus = ProjectStatus::Draft->value;
        $this->startDate = '';
        $this->endDate = '';
        $this->targetBeneficiaries = '';
        $this->targetMen = '';
        $this->targetWomen = '';
        $this->targetBoys = '';
        $this->targetGirls = '';
        $this->cityId = '';
        $this->programId = '';
        $this->userId = '';
    }

    protected static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    protected static function nullableInteger(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    protected static function formInteger(?int $value): string
    {
        return $value === null ? '' : (string) $value;
    }
};
?>

@php
    /* The class block above already imports every symbol this view uses; a
       single-file page compiles both halves into one PHP file, so repeating
       the `use` statements here would be a redeclaration. */
    $filtersAreActive = $search !== '' || $city !== '' || $program !== '' || $owner !== '' || $status !== '' || $lens !== '';
    $today = CarbonImmutable::today();

    $statusCounts = $this->statusCounts;
    $triageCounts = $this->triageCounts;
    $fundingByProject = $this->fundingByProject;
    $canViewMoney = $this->canViewMoney();
    $projects = $this->projects;

    $loadingTargets = 'search, city, program, owner, status, lens, sort, view, toggleStatus, toggleLens, clearFilters, gotoPage, nextPage, previousPage';

    /* Tabs, tiles, panels and card chrome are lifted from pages/milestones so
       the two directories read as one product. */
    $viewOptions = [
        'table' => ['icon' => 'queue-list', 'label' => __('projects.view_table')],
        'timeline' => ['icon' => 'chart-bar', 'label' => __('projects.view_timeline')],
        'board' => ['icon' => 'view-columns', 'label' => __('projects.view_board')],
    ];

    $sortOptions = [
        'name' => __('projects.sort_name'),
        'start_date' => __('projects.sort_start_date'),
        'end_date' => __('projects.sort_end_date'),
        'updated_at' => __('projects.sort_recently_updated'),
    ];

    $lensTiles = [
        'unfunded' => ['label' => __('projects.lens_unfunded'), 'hint' => __('projects.lens_unfunded_hint'), 'icon' => 'banknotes'],
        'overdue' => ['label' => __('projects.lens_overdue'), 'hint' => __('projects.lens_overdue_hint'), 'icon' => 'calendar-days'],
        'slipping' => ['label' => __('projects.lens_slipping'), 'hint' => __('projects.lens_slipping_hint'), 'icon' => 'exclamation-triangle'],
        'risks' => ['label' => __('projects.lens_risks'), 'hint' => __('projects.lens_risks_hint'), 'icon' => 'bolt'],
    ];

    /* The fill mirrors the pill, so a verdict never disagrees with its own bar. */
    $progressFillClasses = fn (string $tone): string => match ($tone) {
        'critical' => 'bg-primary dark:bg-status-critical',
        'warn' => 'bg-status-warn',
        'good' => 'bg-status-good',
        default => 'bg-secondary-dark dark:bg-ink-muted',
    };

    /* The board card reads the same verdict at full RAG resolution. Critical
       fills solid so white type carries it in both themes; the softer tones
       already flip their own tokens in dark mode. */
    $tonePillClasses = fn (string $tone): string => match ($tone) {
        'critical' => 'border-primary bg-primary dark:border-transparent dark:bg-status-critical-soft dark:text-status-critical font-semibold text-white',
        'warn' => 'border-status-warn/40 bg-status-warn-soft font-semibold text-status-warn',
        'good' => 'border-status-good/40 bg-status-good-soft font-semibold text-status-good',
        default => 'border-board-line text-secondary-medium dark:text-ink-muted',
    };

    /* How much of a project's own window has passed, as a percentage. */
    $elapsedOf = function (Project $project) use ($today): ?float {
        if ($project->start_date === null || $project->end_date === null || $project->end_date->lt($project->start_date)) {
            return null;
        }

        $span = max(1, $project->end_date->getTimestamp() - $project->start_date->getTimestamp());

        return min(100, max(0, ($today->getTimestamp() - $project->start_date->getTimestamp()) / $span * 100));
    };

    /* The schedule verdict, rated with RagStatus so this page and the program
       timeline can never disagree about what "slipping" means. Only two
       appearances exist — needs attention (primary) and calm (grey) — so the
       words carry the meaning and the colour only raises a hand. */
    $scheduleOf = function (Project $project) use ($today, $elapsedOf): array {
        $progress = $this->progressFor($project);
        $elapsed = $elapsedOf($project);
        $isFinished = in_array($project->status, [ProjectStatus::Closed, ProjectStatus::Archived], true);

        return match (true) {
            $project->start_date === null || $project->end_date === null => ['label' => __('projects.unscheduled'), 'icon' => 'calendar', 'alert' => false, 'tone' => 'neutral'],
            $isFinished => ['label' => $project->status->label(), 'icon' => 'archive-box', 'alert' => false, 'tone' => 'neutral'],
            $today->gt($project->end_date) && ($progress ?? 0) < 100 => ['label' => __('programs.timeline_past_end'), 'icon' => 'exclamation-circle', 'alert' => true, 'tone' => 'critical'],
            $progress === null => ['label' => __('programs.timeline_unmeasured'), 'icon' => 'minus-circle', 'alert' => false, 'tone' => 'neutral'],
            $progress >= 100 => ['label' => __('programs.timeline_complete'), 'icon' => 'check-circle', 'alert' => false, 'tone' => 'good'],
            $today->lt($project->start_date) => ['label' => __('programs.timeline_not_started'), 'icon' => 'clock', 'alert' => false, 'tone' => 'neutral'],
            default => match (RagStatus::rate($progress, (float) $elapsed)) {
                RagStatus::Green => ['label' => __('programs.timeline_on_track'), 'icon' => 'check-circle', 'alert' => false, 'tone' => 'good'],
                RagStatus::Amber => ['label' => __('programs.timeline_slipping'), 'icon' => 'exclamation-triangle', 'alert' => true, 'tone' => 'warn'],
                RagStatus::Red => ['label' => __('programs.timeline_behind'), 'icon' => 'exclamation-circle', 'alert' => true, 'tone' => 'critical'],
            },
        };
    };

    /* Every relation a project carries, counted in the list query and shown in
       one fixed order so the same chip sits in the same place on every record. */
    $connectionsOf = function (Project $project): array {
        $openRisks = $project->risks_count - $project->materialised_risks_count;

        /* An empty relation says nothing worth a chip; a materialised risk always does. */
        return array_values(array_filter([
            ['icon' => 'flag', 'count' => $project->milestones_count, 'label' => trans_choice('common.count_milestone', $project->milestones_count, ['count' => $project->milestones_count]), 'alert' => false],
            ['icon' => 'chart-bar', 'count' => $project->plan_indicators_count, 'label' => trans_choice('common.count_target', $project->plan_indicators_count, ['count' => $project->plan_indicators_count]), 'alert' => false],
            ['icon' => 'presentation-chart-line', 'count' => $project->indicators_count, 'label' => trans_choice('projects.count_indicator', $project->indicators_count, ['count' => $project->indicators_count]), 'alert' => false],
            ['icon' => 'document-text', 'count' => $project->proposals_count, 'label' => trans_choice('projects.count_proposal', $project->proposals_count, ['count' => $project->proposals_count]), 'alert' => false],
            ['icon' => 'paper-clip', 'count' => $project->media_count, 'label' => trans_choice('projects.count_file', $project->media_count, ['count' => $project->media_count]), 'alert' => false],
            [
                'icon' => 'shield-exclamation',
                'count' => $project->risks_count,
                'label' => $project->materialised_risks_count > 0
                    ? trans_choice('projects.count_risks_materialised', $project->risks_count, ['count' => $project->risks_count, 'materialised' => $project->materialised_risks_count])
                    : trans_choice('programs.timeline_open_risks', $openRisks, ['count' => $openRisks]),
                'alert' => $project->materialised_risks_count > 0,
            ],
        ], fn (array $connection): bool => $connection['count'] > 0 || $connection['alert']));
    };

    /* The four demographic commitments, shown only where they were recorded. */
    $demographicsOf = fn (Project $project): array => array_filter([
        __('projects.men') => $project->target_men,
        __('projects.women') => $project->target_women,
        __('projects.boys') => $project->target_boys,
        __('projects.girls') => $project->target_girls,
    ], fn (?int $value): bool => $value !== null);

    /* Plural forms with the count left as a marker, so the modal's Alpine
       hint can pick the right Arabic range without a round trip. */
    $durationForms = [
        1 => trans_choice('projects.duration_days', 1, ['count' => '__N__']),
        2 => trans_choice('projects.duration_days', 2, ['count' => '__N__']),
        3 => trans_choice('projects.duration_days', 3, ['count' => '__N__']),
        11 => trans_choice('projects.duration_days', 11, ['count' => '__N__']),
    ];

    /* Month names in the reader's language (Syrian names in Arabic), so the
       project modal's live duration range matches every other date here. */
    $monthNames = collect(range(1, 12))
        ->map(fn (int $month): string => CarbonImmutable::create(2000, $month, 1)->translatedFormat(__('formats.month_short')))
        ->all();
@endphp

<section class="admin-directory @container/directory flex min-h-full w-full flex-1 flex-col gap-5 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink" aria-labelledby="project-directory-heading">
    <x-ui.page-header
        heading-id="project-directory-heading"
        :title="__('common.projects')"
        :count="$projects->total()"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('projects.search_projects')" :placeholder="__('projects.project_city_program_or_owner')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.header-filter-select :label="__('common.city')" wire:model.live="city" :value="$city">
                <option value="">{{ __('projects.all_cities') }}</option>
                @foreach ($this->cityOptions as $cityOption)
                    <option value="{{ $cityOption->id }}">{{ $cityOption->name }} · {{ $cityOption->secondary_name }}</option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('common.program')" wire:model.live="program" :value="$program">
                <option value="">{{ __('common.all_programs') }}</option>
                @foreach ($this->programOptions as $programOption)
                    <option value="{{ $programOption->id }}">{{ $programOption->name }}</option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('projects.owner')" wire:model.live="owner" :value="$owner" employees>
                <option value="">{{ __('projects.all_owners') }}</option>
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('projects.sort_by')" wire:model.live="sort" :value="$sort">
                @foreach ($sortOptions as $sortValue => $sortLabel)
                    <option value="{{ $sortValue }}">{{ $sortLabel }}</option>
                @endforeach
            </x-ui.header-filter-select>
        </x-slot:filters>

        <x-slot:actions>
            @can('create', App\Models\Project::class)<x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-project-button">
                {{ __('projects.add_project') }}
            </x-ui.button>@endcan
        </x-slot:actions>
    </x-ui.page-header>

    {{-- The pipeline, which is also the status filter. Six equal segments on
         one baseline; the selected one is the only large red area here. --}}
    <div role="group" aria-label="{{ __('projects.lifecycle') }}" class="grid grid-flow-col auto-cols-[minmax(7.5rem,1fr)] snap-x gap-px overflow-x-auto rounded-xl border border-secondary-soft bg-secondary-soft dark:border-line dark:bg-line">
        @foreach (ProjectStatus::cases() as $case)
            @php
                $segmentCount = $statusCounts[$case->value] ?? 0;
                $isSelected = $status === $case->value;
            @endphp

            <button
                type="button"
                wire:click="toggleStatus('{{ $case->value }}')"
                aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
                data-test="lifecycle-{{ $case->value }}"
                @class([
                    'flex min-h-16 snap-start cursor-pointer flex-col justify-center gap-1 px-4 py-3 text-start transition-colors focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary',
                    'bg-primary text-white dark:bg-brand/10 dark:text-ink' => $isSelected,
                    'bg-white text-secondary-dark hover:bg-secondary-soft/50 dark:bg-surface-1 dark:text-ink dark:hover:bg-surface-2' => ! $isSelected,
                ])
            >
                <span class="sr-only">{{ trans_choice('projects.lifecycle_segment', $segmentCount, ['status' => $case->label(), 'count' => $segmentCount]) }}</span>
                <span aria-hidden="true" class="type-label truncate {{ $isSelected ? 'text-white/80' : 'text-secondary-medium dark:text-ink-muted' }}">{{ $case->label() }}</span>
                <span aria-hidden="true" class="text-2xl leading-none font-bold tabular-nums">{{ $segmentCount }}</span>
            </button>
        @endforeach
    </div>

    {{-- Two readings of the same filtered portfolio, then the four decisions
         waiting on it. Kept apart because they answer different questions. --}}
    <div class="grid gap-4 @4xl/directory:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
        <section aria-labelledby="portfolio-figures-heading">
            <h2 id="portfolio-figures-heading" class="type-label mb-2 text-secondary-medium dark:text-ink-muted">{{ __('projects.group_portfolio') }}</h2>

            <div @class([
                'grid min-h-[calc(100%-1.75rem)] gap-px overflow-hidden rounded-xl border border-secondary-soft bg-secondary-soft dark:border-line dark:bg-line',
                'grid-cols-2' => $canViewMoney,
                'grid-cols-1' => ! $canViewMoney,
            ])>
                @if ($canViewMoney)
                <div class="flex flex-col justify-between gap-2 bg-white p-4 dark:bg-surface-1">
                    <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('projects.tile_committed_funding') }}</p>
                    <div>
                        @forelse ($this->committedFunding as $fundingCurrency => $fundingTotal)
                            <p class="type-figure" wire:key="committed-{{ $fundingCurrency }}">
                                <x-ui.money :amount="$fundingTotal" :currency="$fundingCurrency" />
                            </p>
                        @empty
                            <p class="type-figure text-secondary-light">—</p>
                        @endforelse
                    </div>
                    <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('projects.tile_committed_funding_hint') }}</p>
                </div>
                @endif

                <div class="flex flex-col justify-between gap-2 bg-white p-4 dark:bg-surface-1">
                    <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('projects.tile_beneficiaries') }}</p>
                    <p class="type-figure">{{ number_format($this->committedBeneficiaries) }}</p>
                    <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('projects.tile_beneficiaries_hint') }}</p>
                </div>
            </div>
        </section>

        <section aria-labelledby="portfolio-triage-heading">
            <h2 id="portfolio-triage-heading" class="type-label mb-2 text-secondary-medium dark:text-ink-muted">{{ __('projects.group_attention') }}</h2>

            <div class="grid min-h-[calc(100%-1.75rem)] grid-cols-2 gap-px overflow-hidden rounded-xl border border-secondary-soft bg-secondary-soft @2xl/directory:grid-cols-4 dark:border-line dark:bg-line">
                @foreach ($lensTiles as $lensValue => $lensTile)
                    @php
                        $lensCount = $triageCounts[$lensValue] ?? 0;
                        $lensIsActive = $lens === $lensValue;
                        $lensIsQuiet = $lensCount === 0;

                        // The grid is 2x2 below @2xl/directory and a single row of 4 from
                        // it up, so which corners each tile owns changes at that breakpoint.
                        // Match the container's rounded-xl so an active tile's own border
                        // isn't squared off where the container clips it round.
                        $lensCornerClasses = match ($loop->index) {
                            0 => 'rounded-tl-xl @2xl/directory:rounded-bl-xl',
                            1 => 'rounded-tr-xl @2xl/directory:rounded-tr-none',
                            2 => 'rounded-bl-xl @2xl/directory:rounded-bl-none',
                            3 => 'rounded-br-xl @2xl/directory:rounded-tr-xl',
                        };
                    @endphp

                    <button
                        type="button"
                        wire:click="toggleLens('{{ $lensValue }}')"
                        @disabled($lensIsQuiet)
                        aria-pressed="{{ $lensIsActive ? 'true' : 'false' }}"
                        data-test="lens-{{ $lensValue }}"
                        wire:key="tile-lens-{{ $lensValue }}"
                        @class([
                            'box-border flex flex-col justify-between gap-2 border-2 p-4 text-start transition-colors focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary',
                            $lensCornerClasses,
                            'cursor-pointer border-white bg-white hover:border-secondary-soft/50 hover:bg-secondary-soft/50 dark:border-surface-1 dark:bg-surface-1 dark:hover:border-surface-2 dark:hover:bg-surface-2' => ! $lensIsQuiet && ! $lensIsActive,
                            'cursor-pointer border-primary bg-white dark:bg-surface-1' => $lensIsActive,
                            'cursor-default border-white bg-white dark:border-surface-1 dark:bg-surface-1' => $lensIsQuiet,
                        ])
                    >
                        <span class="type-label flex items-center gap-1.5 text-secondary-medium dark:text-ink-muted">
                            <flux:icon :name="$lensTile['icon']" variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
                            <span class="truncate">{{ $lensTile['label'] }}</span>
                        </span>
                        <span class="type-figure {{ $lensIsQuiet ? 'text-secondary-light' : 'text-primary dark:text-brand-text' }}">{{ $lensCount }}</span>
                        <span class="type-meta text-secondary-medium dark:text-ink-muted">{{ $lensTile['hint'] }}</span>
                    </button>
                @endforeach
            </div>
        </section>
    </div>

    {{-- Tabs, exactly as the milestones directory renders them. --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="inline-flex flex-wrap gap-1 rounded-lg border border-secondary-soft bg-secondary-soft/40 p-1 dark:border-line dark:bg-surface-2" role="group" aria-label="{{ __('projects.views') }}">
            @foreach ($viewOptions as $viewValue => $viewMeta)
                <button
                    type="button"
                    wire:click="$set('view', '{{ $viewValue }}')"
                    aria-pressed="{{ $view === $viewValue ? 'true' : 'false' }}"
                    data-test="project-view-{{ $viewValue }}"
                    @class([
                        'inline-flex min-h-9 cursor-pointer items-center gap-1.5 rounded-md px-3 text-sm font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                        'bg-white text-secondary-dark shadow-sm dark:bg-surface-3 dark:shadow-raised dark:text-ink' => $view === $viewValue,
                        'text-secondary-medium hover:text-secondary-dark dark:text-ink-muted dark:hover:text-ink' => $view !== $viewValue,
                    ])
                >
                    <flux:icon :name="$viewMeta['icon']" variant="micro" class="size-4" aria-hidden="true" />
                    {{ $viewMeta['label'] }}
                </button>
            @endforeach
        </div>

        <div class="flex shrink-0 items-center gap-2">
            @if ($filtersAreActive)
                <flux:button size="sm" variant="ghost" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>
            @endif
        </div>
    </div>

    @if ($projects->isEmpty())
        <x-ui.panel>
            <x-ui.empty-state
                icon="rectangle-stack"
                :heading="$filtersAreActive ? __('projects.no_matching_projects') : __('common.no_projects_yet')"
                :body="$filtersAreActive ? __('common.change_the_search_or_filters_and_try_again') : __('projects.add_the_first_project_and_place_it_in_a_city_and_program')"
            >
                <x-slot:action>
                    @if ($filtersAreActive)
                        <x-ui.button size="sm" wire:click="clearFilters">{{ __('common.reset_filters') }}</x-ui.button>
                    @else
                        @can('create', App\Models\Project::class)<x-ui.button size="sm" tone="primary" icon="plus" wire:click="openCreateModal">{{ __('projects.add_project') }}</x-ui.button>@endcan
                    @endif
                </x-slot:action>
            </x-ui.empty-state>
        </x-ui.panel>
    @else
        <div class="flex flex-col gap-4 transition-opacity motion-reduce:transition-none" wire:loading.class="opacity-60" wire:target="{{ $loadingTargets }}">
            @if ($view === 'board')
                @foreach ($projects->getCollection()->groupBy(fn (Project $project): string => $project->status->value) as $bandStatus => $bandProjects)
                    @php $bandCase = ProjectStatus::from($bandStatus); @endphp

                    <x-ui.panel :title="$bandCase->label()" :count="$bandProjects->count()" wire:key="project-band-{{ $bandStatus }}">
                        <div class="grid grid-cols-1 items-stretch gap-3 p-3 @2xl/directory:grid-cols-2 @5xl/directory:grid-cols-3">
                            @foreach ($bandProjects as $project)
                                @php
                                    $progress = $this->progressFor($project);
                                    $schedule = $scheduleOf($project);
                                    $funding = $fundingByProject[$project->id] ?? null;
                                    $breakdown = $project->targetDemographicTotal();
                                    $demographics = $demographicsOf($project);

                                    /* The progress fill carries the same verdict as the pill above it. */
                                    $progressTone = $schedule['tone'];

                                    /* Three label rows collapse into one breadcrumb; the labels move to the tooltip. */
                                    $projectPath = [
                                        $project->city?->name ?? __('common.unknown_city'),
                                        $project->program->name,
                                        $project->program->strategy?->name ?? __('common.strategy_not_assigned'),
                                    ];
                                    $pathTitle = implode(' · ', [
                                        __('common.city').': '.$projectPath[0],
                                        __('common.program').': '.$projectPath[1],
                                        __('common.strategy').': '.$projectPath[2],
                                    ]);

                                    $demographicsTitle = implode(' · ', array_map(
                                        fn (string $label, int $value): string => $label.' '.number_format($value),
                                        array_keys($demographics),
                                        array_values($demographics),
                                    ));

                                    $connections = $connectionsOf($project);
                                @endphp

                                <article
                                    @class([
                                        'group/card relative isolate flex h-full flex-col rounded-xl border bg-board p-4',
                                        'transition-[transform,box-shadow,border-color] duration-200 ease-[cubic-bezier(0.16,1,0.3,1)] motion-reduce:transition-none',
                                        'hover:-translate-y-0.5 hover:shadow-[0_10px_24px_-12px_rgb(16_24_40/0.28),0_2px_6px_-2px_rgb(16_24_40/0.10)] motion-reduce:hover:translate-y-0',
                                        'border-primary/40' => $schedule['tone'] === 'critical',
                                        'border-status-warn/45' => $schedule['tone'] === 'warn',
                                        'border-board-line' => ! in_array($schedule['tone'], ['critical', 'warn'], true),
                                    ])
                                    wire:key="project-card-{{ $project->id }}"
                                >
                                    {{-- Identity. Open and edit sit together as icon actions, exactly as on programs. --}}
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <div class="flex min-w-0 items-center gap-1.5">
                                                @if (in_array($project->id, $this->unreadIds, true))
                                                    <span class="size-2 shrink-0 rounded-full bg-primary dark:bg-ink" data-unread-dot="project-{{ $project->id }}" title="{{ __('notifications.has_unread') }}"><span class="sr-only">{{ __('notifications.has_unread') }}</span></span>
                                                @endif
                                                <h3 class="line-clamp-2 min-w-0 text-[15px] leading-snug font-semibold text-secondary-dark dark:text-ink" title="{{ $project->name }}">{{ $project->name }}</h3>
                                            </div>

                                            {{-- City, program and strategy as one breadcrumb instead of three label rows. --}}
                                            <p class="type-meta mt-1 truncate text-secondary-medium dark:text-ink-muted" title="{{ $pathTitle }}">
                                                {{ $projectPath[0] }} <span class="text-secondary-soft dark:text-ink-faint">&middot;</span> {{ $projectPath[1] }} <span class="text-secondary-soft dark:text-ink-faint">&middot;</span> {{ $projectPath[2] }}
                                            </p>
                                        </div>

                                        <div class="flex shrink-0 items-center gap-1">
                                            <a
                                                href="{{ route('projects.show', $project) }}"
                                                wire:navigate
                                                class="grid size-11 place-items-center rounded-md border-0! bg-transparent! text-secondary-dark! transition-colors hover:bg-secondary-soft! focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:text-ink! dark:hover:bg-surface-2! @3xl/directory:size-8!"
                                                aria-label="{{ __('projects.open_project', ['name' => $project->name]) }}"
                                            >
                                                <flux:icon.eye class="size-4.5" />
                                            </a>

                                            @can('update', $project)<x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-11! touch-manipulation @3xl/directory:size-8!" wire:click="openEditModal({{ $project->id }})" :aria-label="__('common.edit_name', ['name' => $project->name])" />@endcan
                                            @if ($project->status === ProjectStatus::Archived)
                                                @can('delete', $project)<x-ui.button tone="ghost" size="sm" icon="arrow-uturn-left" class="size-11! shrink-0 touch-manipulation @3xl/directory:size-8!" wire:click="restoreProject({{ $project->id }})" :aria-label="__('projects.restore_name', ['name' => $project->name])" />@endcan
                                            @else
                                                @can('delete', $project)<x-ui.button tone="ghost" size="sm" icon="archive-box" class="size-11! shrink-0 touch-manipulation @3xl/directory:size-8!" wire:click="confirmArchive({{ $project->id }})" :aria-label="__('projects.archive_name', ['name' => $project->name])" />@endcan
                                            @endif
                                        </div>
                                    </div>

                                    {{-- Delivery. The verdict, the number and the window read as one signal. --}}
                                    <div class="mt-4">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs whitespace-nowrap {{ $tonePillClasses($schedule['tone']) }}">
                                                <flux:icon :name="$schedule['icon']" variant="micro" class="size-3.5" aria-hidden="true" />{{ $schedule['label'] }}
                                            </span>

                                            <p class="shrink-0 text-xl leading-none font-bold tracking-tight tabular-nums text-secondary-dark dark:text-ink">
                                                @if ($progress === null)
                                                    <span class="text-base font-semibold text-secondary-medium dark:text-ink-muted">&mdash;</span>
                                                @else
                                                    {{ round($progress) }}<span class="text-xs font-semibold text-secondary-medium dark:text-ink-muted">%</span>
                                                @endif
                                            </p>
                                        </div>

                                        <x-ui.progress-bar class="mt-2.5" :value="$progress" :tone="$progressTone" :label="__('projects.milestone_progress_for', ['name' => $project->name])" label-hidden />

                                        <div class="mt-2 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                            <p class="type-meta text-secondary-medium dark:text-ink-muted">
                                                {{ $progress === null ? __('projects.no_weighted_milestones') : __('projects.milestones_reached', ['reached' => $project->reached_milestones_count, 'total' => $project->milestones_count]) }}
                                            </p>
                                            <p class="type-meta shrink-0 tabular-nums text-secondary-medium dark:text-ink-muted">
                                                {{ $project->start_date?->translatedFormat(__('formats.date')) ?? '—' }}
                                                <flux:icon.arrow-long-right variant="micro" class="inline size-3.5 rtl:-scale-x-100" aria-hidden="true" />
                                                {{ $project->end_date?->translatedFormat(__('formats.date')) ?? '—' }}
                                            </p>
                                        </div>
                                    </div>

                                    {{-- Money and people: the two commitments, on one shared baseline. --}}
                                    <div class="mt-4 grid grid-cols-2 gap-x-4 gap-y-1 border-t border-board-line pt-3.5">
                                        <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('projects.funding_envelope') }}</p>
                                        <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('projects.col_beneficiaries') }}</p>

                                        <div class="min-w-0">
                                            @if ($funding === null)
                                                <p class="text-base font-semibold text-secondary-medium dark:text-ink-muted">{{ $project->proposals_count === 0 ? __('projects.not_funded_yet') : '—' }}</p>
                                            @else
                                                @foreach ($funding['currencies'] as $cardCurrency => $cardTotal)
                                                    <p class="text-base text-secondary-dark dark:text-ink" wire:key="funding-{{ $project->id }}-{{ $cardCurrency }}">
                                                        <x-ui.money :amount="$cardTotal" :currency="$cardCurrency" />
                                                    </p>
                                                @endforeach
                                                <p class="type-meta mt-0.5 truncate text-secondary-medium dark:text-ink-muted" title="{{ implode(' · ', $funding['donors']) }}">{{ implode(' · ', $funding['donors']) }}</p>
                                                <p class="type-meta truncate text-secondary-medium dark:text-ink-muted" title="{{ implode(' · ', $funding['proposals']) }}">{{ implode(' · ', $funding['proposals']) }}</p>
                                            @endif
                                        </div>

                                        <div class="min-w-0">
                                            <p class="text-base font-semibold tabular-nums text-secondary-dark dark:text-ink">{{ $project->target_beneficiaries === null ? '—' : number_format($project->target_beneficiaries) }}</p>
                                            @if ($demographics !== [])
                                                <p class="type-meta mt-0.5 truncate tabular-nums text-secondary-medium dark:text-ink-muted" title="{{ $demographicsTitle }}">
                                                    @foreach ($demographics as $demographicLabel => $demographicValue)
                                                        {{ $demographicLabel }} {{ number_format($demographicValue) }}@if (! $loop->last) <span class="text-secondary-soft dark:text-ink-faint">&middot;</span> @endif
                                                    @endforeach
                                                </p>
                                            @endif
                                            @if ($breakdown !== null && $breakdown !== $project->target_beneficiaries)
                                                <p class="type-meta tabular-nums text-secondary-medium dark:text-ink-muted">{{ __('projects.breakdown_count', ['count' => number_format($breakdown)]) }}</p>
                                            @endif
                                        </div>

                                        @if ($funding !== null && $funding['shared'] > 0)
                                            <span class="type-meta col-span-2 mt-1.5 inline-flex w-fit items-center gap-1 rounded-full border border-board-line px-2 py-0.5 text-secondary-medium dark:text-ink-muted">
                                                <flux:icon.arrows-right-left variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
                                                {{ trans_choice('projects.shared_with_other_projects', $funding['shared'], ['count' => $funding['shared']]) }}
                                            </span>
                                        @endif
                                    </div>

                                    {{-- Footer: what this project is linked to, and who owns it. --}}
                                    <div class="mt-auto pt-4">
                                        <div class="flex items-center justify-between gap-3 border-t border-board-line pt-3.5">
                                            @if ($connections !== [])
                                                <div class="flex min-w-0 flex-wrap items-center gap-1.5">
                                                    @foreach ($connections as $connection)
                                                        <span
                                                            @class([
                                                                'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs tabular-nums',
                                                                'border-primary bg-primary dark:border-transparent dark:bg-status-critical-soft dark:text-status-critical font-semibold text-white' => $connection['alert'],
                                                                'border-board-line text-secondary-medium dark:text-ink-muted' => ! $connection['alert'],
                                                            ])
                                                            title="{{ $connection['label'] }}"
                                                        >
                                                            <flux:icon :name="$connection['icon']" variant="micro" class="size-3.5" aria-hidden="true" />
                                                            <span aria-hidden="true">{{ $connection['count'] }}</span>
                                                            <span class="sr-only">{{ $connection['label'] }}</span>
                                                        </span>
                                                    @endforeach
                                                </div>
                                            @else
                                                <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('projects.no_connections_yet') }}</p>
                                            @endif

                                            <div class="flex shrink-0 flex-wrap items-center justify-end gap-1.5">
                                                <x-ui.employee-chip :employee="$project->user" compact />
                                                <x-ui.assignment-badge :project="$project" />
                                            </div>
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    </x-ui.panel>
                @endforeach
            @elseif ($view === 'table')
                <x-ui.data-table :caption="__('projects.project_directory')">
                    <x-slot:head>
                        <th scope="col" class="w-32">{{ __('projects.col_scheduled') }}</th>
                        <th scope="col">{{ __('common.projects') }}</th>
                        <th scope="col" class="w-44">{{ __('projects.col_progress') }}</th>
                        <th scope="col" class="w-32 text-end! @max-6xl:hidden">{{ __('projects.col_beneficiaries') }}</th>
                        <th scope="col" class="w-44 text-end! @max-5xl:hidden">{{ __('projects.col_funding') }}</th>
                        <th scope="col" class="w-44 @max-6xl:hidden">{{ __('projects.connections') }}</th>
                        <th scope="col" class="w-40 @max-4xl:hidden">{{ __('projects.owner') }}</th>
                        <th scope="col" class="w-36">{{ __('projects.col_schedule') }}</th>
                        <th scope="col" class="w-28">{{ __('common.status') }}</th>
                        <th scope="col" class="w-28"><span class="sr-only">{{ __('common.actions') }}</span></th>
                    </x-slot:head>

                    @foreach ($projects as $project)
                        @php
                            $progress = $this->progressFor($project);
                            $schedule = $scheduleOf($project);
                            $funding = $fundingByProject[$project->id] ?? null;
                            $breakdown = $project->targetDemographicTotal();
                            $connections = $connectionsOf($project);
                        @endphp

                        <tr wire:key="project-{{ $project->id }}">
                            <x-ui.data-table.cell :label="__('projects.col_scheduled')">
                                <span class="block text-xs tabular-nums text-secondary-medium dark:text-ink-muted">{{ $project->start_date?->translatedFormat(__('formats.date')) ?? '—' }}</span>
                                <span class="block text-xs tabular-nums text-secondary-medium dark:text-ink-muted">
                                    <flux:icon.arrow-long-right variant="micro" class="inline size-3.5 rtl:-scale-x-100" aria-hidden="true" />
                                    {{ $project->end_date?->translatedFormat(__('formats.date')) ?? '—' }}
                                </span>
                            </x-ui.data-table.cell>

                            <x-ui.data-table.cell>
                                <span class="flex min-w-0 items-center gap-1.5">
                                    @if (in_array($project->id, $this->unreadIds, true))
                                        <span class="size-2 shrink-0 rounded-full bg-primary dark:bg-ink" data-unread-dot="project-{{ $project->id }}" title="{{ __('notifications.has_unread') }}"><span class="sr-only">{{ __('notifications.has_unread') }}</span></span>
                                    @endif
                                    <span class="line-clamp-2 min-w-0 text-sm font-semibold" title="{{ $project->name }}">{{ $project->name }}</span>
                                </span>
                                <span class="block truncate text-xs text-secondary-medium dark:text-ink-muted">{{ $project->city?->name ?? __('common.unknown_city') }} · {{ $project->program->name }}</span>
                                <span class="block truncate text-xs text-secondary-medium dark:text-ink-muted">{{ $project->program->strategy?->name ?? __('common.strategy_not_assigned') }}</span>
                                <span class="hidden truncate text-xs tabular-nums text-secondary-medium @max-6xl:block @max-3xl:hidden! dark:text-ink-muted">
                                    {{ $project->target_beneficiaries === null ? '—' : number_format($project->target_beneficiaries) }}
                                    @foreach ($connections as $connection)
                                        <span aria-hidden="true"> · </span><span aria-label="{{ $connection['label'] }}" class="{{ $connection['alert'] ? 'font-semibold text-primary dark:text-brand-text' : '' }}">{{ $connection['count'] }}</span>
                                    @endforeach
                                </span>
                            </x-ui.data-table.cell>

                            <x-ui.data-table.cell :label="__('projects.col_progress')">
                                <x-ui.progress-bar :value="$progress" :tone="$schedule['tone']" :label="__('projects.milestone_progress_for', ['name' => $project->name])" label-hidden :show-value="$progress !== null" />
                                <span class="mt-1 block text-xs tabular-nums text-secondary-medium dark:text-ink-muted">
                                    {{ $progress === null ? __('projects.no_weighted_milestones') : __('projects.milestones_reached', ['reached' => $project->reached_milestones_count, 'total' => $project->milestones_count]) }}
                                </span>
                            </x-ui.data-table.cell>

                            <x-ui.data-table.cell :label="__('projects.col_beneficiaries')" numeric class="@max-6xl:hidden @max-3xl:flex!">
                                <span class="block font-semibold">{{ $project->target_beneficiaries === null ? '—' : number_format($project->target_beneficiaries) }}</span>
                                @if ($breakdown !== null && $breakdown !== $project->target_beneficiaries)
                                    <span class="block text-xs text-secondary-medium dark:text-ink-muted">{{ __('projects.breakdown_count', ['count' => number_format($breakdown)]) }}</span>
                                @endif
                            </x-ui.data-table.cell>

                            <x-ui.data-table.cell :label="__('projects.col_funding')" numeric class="@max-5xl:hidden @max-3xl:flex!">
                                @if ($funding === null)
                                    <span class="text-xs text-secondary-light">{{ $project->proposals_count === 0 ? __('projects.not_funded_yet') : '—' }}</span>
                                @else
                                    @foreach ($funding['currencies'] as $rowCurrency => $rowTotal)
                                        <span class="block" wire:key="row-funding-{{ $project->id }}-{{ $rowCurrency }}">
                                            <x-ui.money :amount="$rowTotal" :currency="$rowCurrency" />
                                        </span>
                                    @endforeach
                                    <span class="block truncate text-xs text-secondary-medium dark:text-ink-muted" title="{{ implode(' · ', $funding['donors']) }}">{{ implode(' · ', $funding['donors']) }}</span>
                                    @if ($funding['shared'] > 0)
                                        <span class="block text-xs text-secondary-medium dark:text-ink-muted">{{ trans_choice('projects.shared_with_other_projects', $funding['shared'], ['count' => $funding['shared']]) }}</span>
                                    @endif
                                @endif
                            </x-ui.data-table.cell>

                            <x-ui.data-table.cell :label="__('projects.connections')" class="@max-6xl:hidden @max-3xl:flex!">
                                <span class="flex flex-wrap items-center gap-1">
                                    @foreach ($connections as $connection)
                                        <span
                                            @class([
                                                'inline-flex items-center gap-1 rounded-full border px-1.5 py-0.5 text-xs tabular-nums',
                                                'border-primary bg-primary dark:border-transparent dark:bg-status-critical-soft dark:text-status-critical font-semibold text-white' => $connection['alert'],
                                                'border-board-line text-secondary-medium dark:text-ink-muted' => ! $connection['alert'],
                                            ])
                                            title="{{ $connection['label'] }}"
                                            aria-label="{{ $connection['label'] }}"
                                        >
                                            <flux:icon :name="$connection['icon']" variant="micro" class="size-3.5" aria-hidden="true" />{{ $connection['count'] }}
                                        </span>
                                    @endforeach
                                </span>
                            </x-ui.data-table.cell>

                            <x-ui.data-table.cell :label="__('projects.owner')" class="@max-4xl:hidden @max-3xl:flex!">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <x-ui.employee-chip :employee="$project->user" compact />
                                    <x-ui.assignment-badge :project="$project" />
                                </div>
                            </x-ui.data-table.cell>

                            <x-ui.data-table.cell :label="__('projects.col_schedule')">
                                <span class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs whitespace-nowrap {{ $tonePillClasses($schedule['tone']) }}">
                                    <flux:icon :name="$schedule['icon']" variant="micro" class="size-3.5" aria-hidden="true" />{{ $schedule['label'] }}
                                </span>
                            </x-ui.data-table.cell>

                            <x-ui.data-table.cell :label="__('common.status')">
                                <x-ui.enum-badge :case="$project->status" />
                            </x-ui.data-table.cell>

                            <x-ui.data-table.cell>
                                <div class="flex items-center justify-end gap-1">
                                    <a
                                        href="{{ route('projects.show', $project) }}"
                                        wire:navigate
                                        class="grid size-11 shrink-0 place-items-center rounded-md border-0! bg-transparent! text-secondary-dark! transition-colors hover:bg-secondary-soft! focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:text-ink! dark:hover:bg-surface-2! @3xl/directory:size-8!"
                                        aria-label="{{ __('projects.open_project', ['name' => $project->name]) }}"
                                    >
                                        <flux:icon.eye class="size-4.5" />
                                    </a>
                                    @can('update', $project)<x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-11! shrink-0 touch-manipulation @3xl/directory:size-8!" wire:click="openEditModal({{ $project->id }})" :aria-label="__('common.edit_name', ['name' => $project->name])" />@endcan
                                    @if ($project->status === ProjectStatus::Archived)
                                        @can('delete', $project)<x-ui.button tone="ghost" size="sm" icon="arrow-uturn-left" class="size-11! shrink-0 touch-manipulation @3xl/directory:size-8!" wire:click="restoreProject({{ $project->id }})" :aria-label="__('projects.restore_name', ['name' => $project->name])" />@endcan
                                    @else
                                        @can('delete', $project)<x-ui.button tone="ghost" size="sm" icon="archive-box" class="size-11! shrink-0 touch-manipulation @3xl/directory:size-8!" wire:click="confirmArchive({{ $project->id }})" :aria-label="__('projects.archive_name', ['name' => $project->name])" />@endcan
                                    @endif
                                </div>
                            </x-ui.data-table.cell>
                        </tr>
                    @endforeach
                </x-ui.data-table>
            @else
                @php
                    $window = $this->timelineWindow;
                    $windowStart = $window['start'] ?? null;
                    $windowSpan = $window === null ? 1 : max(1, $window['end']->getTimestamp() - $windowStart->getTimestamp());
                    $position = fn (CarbonImmutable $date): float => $window === null
                        ? 0.0
                        : round(min(100, max(0, ($date->getTimestamp() - $windowStart->getTimestamp()) / $windowSpan * 100)), 3);

                    $ticks = [];
                    if ($window !== null) {
                        $months = (int) $windowStart->diffInMonths($window['end']) + 1;
                        $step = max(1, (int) ceil($months / 8));
                        for ($tick = $windowStart; $tick <= $window['end']; $tick = $tick->addMonthsNoOverflow($step)) {
                            $ticks[] = ['label' => $tick->translatedFormat(__('formats.month_year')), 'at' => $position($tick), 'nearToday' => abs($position($tick) - $position($today)) < 7];
                        }
                    }
                    $todayAt = $position($today);
                    $timelineColumns = '@4xl/directory:grid-cols-[minmax(15rem,20rem)_minmax(0,1fr)_10rem]';
                @endphp

                <x-ui.panel>
                    {{-- Same legend grammar as the milestones timeline. --}}
                    <div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-b border-secondary-soft px-4 py-3 text-xs text-secondary-medium dark:border-line dark:text-ink-muted" aria-hidden="true">
                        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rotate-45 rounded-[2px] border-2 border-secondary-dark bg-secondary-dark dark:border-ink-muted dark:bg-ink-muted"></span>{{ MilestoneStatus::Reached->label() }}</span>
                        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rotate-45 rounded-[2px] border-2 border-secondary-dark bg-white dark:border-ink-muted dark:bg-surface-1"></span>{{ MilestoneStatus::Planned->label() }}</span>
                        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rotate-45 rounded-[2px] border-2 border-primary bg-white dark:bg-surface-1"></span>{{ __('programs.timeline_overdue') }}</span>
                        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rotate-45 rounded-[2px] border-2 border-primary bg-primary dark:bg-status-critical dark:border-status-critical"></span>{{ __('milestones.missed') }}</span>
                        <span class="inline-flex items-center gap-1.5"><span class="h-3.5 w-0.5 bg-primary dark:bg-ink"></span>{{ __('programs.timeline_today') }}</span>
                    </div>

                    @if ($window !== null)
                        <div class="hidden gap-6 border-b border-secondary-soft px-4 {{ $timelineColumns }} @4xl/directory:grid dark:border-line">
                            <span></span>
                            <div class="relative h-9">
                                @foreach ($ticks as $tick)
                                    @continue($tick['nearToday'])
                                    <span class="absolute bottom-2 -translate-x-1/2 text-xs whitespace-nowrap tabular-nums text-secondary-light rtl:translate-x-1/2 dark:text-ink-faint" style="inset-inline-start: {{ $tick['at'] }}%">{{ $tick['label'] }}</span>
                                @endforeach
                                <span class="absolute bottom-1.5 -translate-x-1/2 rounded-full bg-primary dark:bg-ink dark:text-surface-0 px-2 py-0.5 text-[0.6875rem] font-bold whitespace-nowrap text-white rtl:translate-x-1/2" style="inset-inline-start: {{ $todayAt }}%">{{ __('programs.timeline_today') }}</span>
                            </div>
                            <span></span>
                        </div>
                    @endif

                    <ol class="divide-y divide-secondary-soft dark:divide-line">
                        @foreach ($projects as $project)
                            @php
                                $progress = $this->progressFor($project);
                                $schedule = $scheduleOf($project);
                                $elapsed = $elapsedOf($project);
                                $connections = $connectionsOf($project);
                                $funding = $fundingByProject[$project->id] ?? null;
                                $hasDates = $project->start_date !== null && $project->end_date !== null && $project->end_date->gte($project->start_date);
                                $hasWindow = $window !== null && $hasDates;
                                $barStart = $hasWindow ? $position($project->start_date) : 0;
                                $barWidth = $hasWindow ? max(1.5, $position($project->end_date) - $barStart) : 0;
                                $isLive = $project->status === ProjectStatus::Active;
                                $isFinished = in_array($project->status, [ProjectStatus::Closed, ProjectStatus::Archived], true);
                                $barLabel = ($progress === null ? __('programs.timeline_unmeasured') : $progress.'% '.__('programs.timeline_weighted_complete'))
                                    .($elapsed === null ? '' : ' · '.__('programs.timeline_expected', ['percent' => (int) round($elapsed)]));
                            @endphp

                            <li class="bg-white transition-colors hover:bg-secondary-soft/30 dark:bg-surface-1 dark:hover:bg-surface-2" wire:key="project-timeline-{{ $project->id }}">
                                <div class="grid gap-4 px-4 py-4 {{ $timelineColumns }} @4xl/directory:items-center @4xl/directory:gap-6">
                                    <div class="min-w-0">
                                        <div class="flex items-start justify-between gap-2">
                                            <span class="line-clamp-2 min-w-0 text-sm font-semibold" title="{{ $project->name }}">{{ $project->name }}</span>

                                            <div class="flex shrink-0 items-center gap-1">
                                                <a
                                                href="{{ route('projects.show', $project) }}"
                                                wire:navigate
                                                class="grid size-11 place-items-center rounded-md border-0! bg-transparent! text-secondary-dark! transition-colors hover:bg-secondary-soft! focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:text-ink! dark:hover:bg-surface-2! @3xl/directory:size-8!"
                                                aria-label="{{ __('projects.open_project', ['name' => $project->name]) }}"
                                            >
                                                <flux:icon.eye class="size-4.5" />
                                            </a>
                                            @can('update', $project)<x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-11! shrink-0 touch-manipulation @3xl/directory:size-8!" wire:click="openEditModal({{ $project->id }})" :aria-label="__('common.edit_name', ['name' => $project->name])" />@endcan
                                                @if ($project->status === ProjectStatus::Archived)
                                                    @can('delete', $project)<x-ui.button tone="ghost" size="sm" icon="arrow-uturn-left" class="size-11! shrink-0 touch-manipulation @3xl/directory:size-8!" wire:click="restoreProject({{ $project->id }})" :aria-label="__('projects.restore_name', ['name' => $project->name])" />@endcan
                                                @else
                                                    @can('delete', $project)<x-ui.button tone="ghost" size="sm" icon="archive-box" class="size-11! shrink-0 touch-manipulation @3xl/directory:size-8!" wire:click="confirmArchive({{ $project->id }})" :aria-label="__('projects.archive_name', ['name' => $project->name])" />@endcan
                                                @endif
                                            </div>
                                        </div>

                                        <p class="mt-1 truncate text-xs text-secondary-medium dark:text-ink-muted">{{ $project->city?->name ?? __('common.unknown_city') }} · {{ $project->program->name }}</p>
                                        <p class="truncate text-xs text-secondary-medium dark:text-ink-muted">{{ $project->program->strategy?->name ?? __('common.strategy_not_assigned') }}</p>

                                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                            <x-ui.enum-badge :case="$project->status" />
                                            <x-ui.employee-chip :employee="$project->user" compact />
                                            <x-ui.assignment-badge :project="$project" />
                                        </div>

                                        <div class="mt-2 flex flex-wrap items-center gap-1">
                                            @foreach ($connections as $connection)
                                                <span
                                                    @class([
                                                        'inline-flex items-center gap-1 rounded-full border px-1.5 py-0.5 text-xs tabular-nums',
                                                        'border-primary/30 bg-primary/10 font-semibold text-primary dark:text-brand-text' => $connection['alert'],
                                                        'border-secondary-soft text-secondary-medium dark:border-line dark:text-ink-muted' => ! $connection['alert'] && $connection['count'] > 0,
                                                        'border-secondary-soft text-secondary-light dark:border-line' => ! $connection['alert'] && $connection['count'] === 0,
                                                    ])
                                                    aria-label="{{ $connection['label'] }}"
                                                >
                                                    <flux:icon :name="$connection['icon']" variant="micro" class="size-3.5" aria-hidden="true" />{{ $connection['count'] }}
                                                </span>
                                            @endforeach
                                        </div>
                                    </div>

                                    <div class="min-w-0">
                                        <div class="relative h-11">
                                            @if ($window !== null)
                                                @foreach ($ticks as $tick)
                                                    <span aria-hidden="true" class="absolute inset-y-0 hidden w-px bg-secondary-soft @4xl/directory:block dark:bg-line" style="inset-inline-start: {{ $tick['at'] }}%"></span>
                                                @endforeach
                                                <span aria-hidden="true" class="absolute inset-y-0 z-10 w-0.5 -translate-x-1/2 bg-primary dark:bg-ink rtl:translate-x-1/2" style="inset-inline-start: {{ $todayAt }}%"></span>
                                            @endif

                                            @if ($hasWindow)
                                                <div
                                                    class="absolute top-1/2 h-6 -translate-y-1/2 overflow-hidden rounded-full border {{ $isFinished ? 'border-secondary-light bg-secondary-soft/60 dark:bg-white/10' : ($isLive ? 'border-secondary-light bg-secondary-soft dark:bg-surface-2' : 'border-dashed border-secondary-light bg-white dark:bg-surface-1') }}"
                                                    style="inset-inline-start: {{ $barStart }}%; width: {{ $barWidth }}%"
                                                    role="img"
                                                    aria-label="{{ $barLabel }}"
                                                >
                                                    <div class="h-full {{ $progressFillClasses($schedule['tone']) }}" style="width: {{ $progress ?? 0 }}%"></div>
                                                </div>

                                                @if ($elapsed !== null && $isLive)
                                                    <span aria-hidden="true" class="absolute top-1/2 h-8 w-0.5 -translate-x-1/2 -translate-y-1/2 bg-secondary-dark/40 rtl:translate-x-1/2 dark:bg-white/40" style="inset-inline-start: {{ round($barStart + ($barWidth * $elapsed / 100), 3) }}%"></span>
                                                @endif

                                                @foreach ($project->milestones as $milestone)
                                                    @php
                                                        $isMissedMarker = $milestone->isMissed();
                                                        $markerTone = match (true) {
                                                            $milestone->status === MilestoneStatus::Reached => 'border-secondary-dark bg-secondary-dark dark:border-ink-muted dark:bg-ink-muted',
                                                            $isMissedMarker || $milestone->isOverdue() => 'border-primary bg-primary dark:bg-status-critical dark:border-status-critical',
                                                            default => 'border-secondary-dark bg-white dark:border-ink-muted dark:bg-surface-1',
                                                        };
                                                        $markerLabel = __('programs.timeline_marker', [
                                                            'name' => $milestone->name,
                                                            'date' => $milestone->planned_start_date->translatedFormat(__('formats.date')),
                                                            'status' => $isMissedMarker ? __('milestones.missed') : $milestone->status->label(),
                                                        ]);
                                                    @endphp

                                                    <span
                                                        class="absolute top-1/2 z-20 block size-3 -translate-x-1/2 -translate-y-1/2 rotate-45 rounded-[2px] border-2 shadow-sm ring-2 ring-white rtl:translate-x-1/2 dark:ring-surface-1 {{ $markerTone }}"
                                                        style="inset-inline-start: {{ $position($milestone->planned_start_date) }}%"
                                                        role="img"
                                                        aria-label="{{ $markerLabel }}"
                                                        wire:key="timeline-marker-{{ $milestone->id }}"
                                                    ></span>
                                                @endforeach
                                            @else
                                                <span class="absolute inset-x-0 top-1/2 flex h-7 -translate-y-1/2 items-center justify-center rounded-full border border-dashed border-secondary-light bg-white text-xs text-secondary-medium dark:bg-surface-1 dark:text-ink-muted">{{ __('projects.unscheduled') }}</span>
                                            @endif
                                        </div>

                                        <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs tabular-nums text-secondary-medium dark:text-ink-muted">
                                            <span>
                                                {{ $project->start_date?->translatedFormat(__('formats.date')) ?? '—' }}
                                                <flux:icon.arrow-long-right variant="micro" class="inline size-3.5 rtl:-scale-x-100" aria-hidden="true" />
                                                {{ $project->end_date?->translatedFormat(__('formats.date')) ?? '—' }}
                                            </span>
                                            @if ($funding !== null)
                                                @foreach ($funding['currencies'] as $barCurrency => $barTotal)
                                                    <span wire:key="timeline-funding-{{ $project->id }}-{{ $barCurrency }}"><x-ui.money :amount="$barTotal" :currency="$barCurrency" /></span>
                                                @endforeach
                                            @endif
                                            @if ($project->target_beneficiaries !== null)
                                                <span>{{ number_format($project->target_beneficiaries) }} · {{ __('projects.col_beneficiaries') }}</span>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="flex items-end justify-between gap-3 @4xl/directory:flex-col @4xl/directory:items-end @4xl/directory:gap-2">
                                        <span class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs whitespace-nowrap {{ $tonePillClasses($schedule['tone']) }}">
                                            <flux:icon :name="$schedule['icon']" variant="micro" class="size-3.5" aria-hidden="true" />{{ $schedule['label'] }}
                                        </span>
                                        <div class="text-end">
                                            <p class="type-figure">{{ $progress === null ? '—' : $progress.'%' }}</p>
                                            <p class="text-xs tabular-nums text-secondary-medium dark:text-ink-muted">
                                                {{ $progress === null ? __('projects.no_weighted_milestones') : __('projects.milestones_reached', ['reached' => $project->reached_milestones_count, 'total' => $project->milestones_count]) }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </x-ui.panel>
            @endif

            @if ($projects->hasPages())
                <x-ui.panel class="px-4 py-3">
                    <flux:pagination :paginator="$projects" class="text-secondary-medium! dark:text-ink-muted!" scroll-to="#project-directory-heading" />
                </x-ui.panel>
            @endif
        </div>
    @endif

    <x-ui.modal
        wire:model="showProjectModal"
        size="xl"
        :title="$editingProjectId === null ? __('projects.add_project') : __('projects.edit_project')"
        :description="__('projects.define_delivery_ownership_lifecycle_schedule_and_beneficiary')"
        submit="saveProject"
        close="closeProjectModal"
        required-note
    >
        {{-- One Alpine scope for the live figures: the duration under Details and the breakdown beside Beneficiaries. --}}
        <div
            class="contents"
            x-data="{
                forms: @js($durationForms),
                months: @js($monthNames),
                breakdownTemplate: @js(e(__('projects.broken_down_of_headline', ['breakdown' => '__BREAKDOWN__', 'headline' => '__HEADLINE__']))),
                get durationDays() {
                    const start = Date.parse($wire.startDate);
                    const end = Date.parse($wire.endDate);

                    if (Number.isNaN(start) || Number.isNaN(end)) {
                        return 0;
                    }

                    return Math.max(0, Math.floor((end - start) / 86400000) + 1);
                },
                get durationLabel() {
                    const days = this.durationDays;

                    if (days < 1) {
                        return '';
                    }

                    const form = days <= 3 ? this.forms[days] : (days <= 10 ? this.forms[3] : this.forms[11]);

                    return form.replace('__N__', days.toLocaleString());
                },
                get durationRange() {
                    if (this.durationDays < 1) {
                        return '';
                    }

                    const [startYear, startMonth] = $wire.startDate.split('-').map(Number);
                    const [endYear, endMonth] = $wire.endDate.split('-').map(Number);
                    const start = this.months[startMonth - 1];
                    const end = this.months[endMonth - 1];

                    if (startYear !== endYear) {
                        return `${start} ${startYear} – ${end} ${endYear}`;
                    }

                    return startMonth === endMonth ? `${start} ${startYear}` : `${start} – ${end} ${startYear}`;
                },
                get breakdownHint() {
                    const breakdown = [$wire.targetMen, $wire.targetWomen, $wire.targetBoys, $wire.targetGirls]
                        .reduce((total, value) => total + (Number(value) || 0), 0);
                    const headline = Number($wire.targetBeneficiaries) || 0;

                    if (breakdown === 0 && headline === 0) {
                        return '';
                    }

                    return this.breakdownTemplate
                        .replace('__BREAKDOWN__', `<b class='form-section-figure'>${breakdown.toLocaleString()}</b>`)
                        .replace('__HEADLINE__', headline.toLocaleString());
                },
            }"
        >
            <x-ui.form-section
                :title="__('projects.section_details')"
                :hint="__('projects.section_details_hint')"
                :fields="['name', 'projectStatus', 'startDate', 'endDate', 'description']"
            >
                <div class="grid grid-cols-2 gap-x-4 gap-y-3 @4xl/modal:grid-cols-3">
                    <x-ui.form-input wire:model="name" :label="__('projects.project_name')" maxlength="255" required autofocus class="col-span-2" />

                    <x-ui.form-select
                        wire:model="projectStatus"
                        :label="__('common.status')"
                        required
                        :disabled="$this->statusLocked"
                        :locked-reason="$this->statusLocked ? ($editingProjectId === null ? __('projects.status_locked_new') : __('projects.status_locked_until_acknowledged')) : null"
                        class="col-span-2 @4xl/modal:col-span-1"
                    >
                        @foreach (ProjectStatus::cases() as $statusOption)
                            <option value="{{ $statusOption->value }}">{{ $statusOption->label() }}</option>
                        @endforeach
                    </x-ui.form-select>

                    <x-ui.form-input wire:model="startDate" :label="__('common.start_date')" type="date" />
                    <x-ui.form-input wire:model="endDate" :label="__('projects.end_date')" type="date" />

                    <div class="col-span-2 min-w-0 @4xl/modal:col-span-1">
                        <span class="form-label">{{ __('projects.duration') }}</span>
                        <p class="flex min-h-11 flex-wrap items-center gap-x-2 tabular-nums">
                            <span class="text-lg font-bold text-secondary-dark dark:text-ink" x-text="durationLabel || '—'">—</span>
                            <span class="text-xs font-medium text-secondary-medium dark:text-ink-muted" x-text="durationRange"></span>
                        </p>
                    </div>

                    <x-ui.form-textarea wire:model="description" :label="__('common.description')" rows="2" class="col-span-2 @4xl/modal:col-span-3" />
                </div>
            </x-ui.form-section>

            <x-ui.form-section :title="__('projects.section_placement')" :fields="['cityId', 'programId', 'userId']">
                <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-3">
                    <x-ui.form-select wire:model="cityId" :label="__('common.city')" :placeholder="__('projects.select_a_city')" required>
                        @foreach ($this->cityOptions as $cityOption)
                            <option value="{{ $cityOption->id }}">{{ $cityOption->name }} · {{ $cityOption->secondary_name }}</option>
                        @endforeach
                    </x-ui.form-select>

                    <x-ui.form-select wire:model.live="programId" :label="__('common.program')" :placeholder="__('projects.select_a_program')" required>
                        @foreach ($this->programOptions as $programOption)
                            <option value="{{ $programOption->id }}">{{ $programOption->name }}</option>
                        @endforeach
                    </x-ui.form-select>

                    <x-ui.form-select wire:model="userId" :label="__('projects.owner')" :placeholder="__('projects.select_an_owner')" required>
                        @foreach ($this->ownerOptions as $userOption)
                            <option value="{{ $userOption->id }}">{{ $userOption->name }} · {{ $userOption->secondary_name }}</option>
                        @endforeach
                    </x-ui.form-select>
                </div>
            </x-ui.form-section>

            <x-ui.form-section
                :title="__('projects.section_beneficiaries')"
                :fields="['targetBeneficiaries', 'targetMen', 'targetWomen', 'targetBoys', 'targetGirls']"
            >
                <x-slot:hint>
                    <span x-show="breakdownHint !== ''" x-html="breakdownHint" style="display: none"></span>
                </x-slot:hint>

                <div class="grid grid-cols-2 gap-3 @2xl/modal:grid-cols-5">
                    <x-ui.form-input wire:model="targetBeneficiaries" :label="__('projects.headline_total')" type="number" min="0" step="1" class="col-span-2 @2xl/modal:col-span-1" />
                    <x-ui.form-input wire:model="targetMen" :label="__('projects.men')" type="number" min="0" step="1" />
                    <x-ui.form-input wire:model="targetWomen" :label="__('projects.women')" type="number" min="0" step="1" />
                    <x-ui.form-input wire:model="targetBoys" :label="__('projects.boys')" type="number" min="0" step="1" />
                    <x-ui.form-input wire:model="targetGirls" :label="__('projects.girls')" type="number" min="0" step="1" />
                </div>
            </x-ui.form-section>
        </div>

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-project-button">
                {{ $editingProjectId === null ? __('projects.create_project') : __('common.save_changes') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    {{-- Archiving is reversible, so the dialog explains where the work goes, not what is lost. --}}
    <x-ui.confirm-modal
        wire:model="showArchiveModal"
        close="cancelArchive"
        tone="neutral"
        :title="__('projects.archive_this_project')"
        :message="$this->archivingProject !== null ? __('projects.archiving_keeps_everything', ['name' => $this->archivingProject['project']->name]) : null"
        confirm="archiveProject"
        :confirm-label="__('projects.archive_project')"
        confirm-test="confirm-archive-project"
    >
        @if ($this->archivingProject !== null && $this->archivingProject['carries'] !== [])
            <div class="rounded-lg border border-board-line bg-board-head p-3">
                <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('projects.kept_with_the_project') }}</p>
                <ul class="mt-1.5 flex flex-col gap-0.5">
                    @foreach ($this->archivingProject['carries'] as $carriedLabel => $carriedCount)
                        <li class="text-sm tabular-nums text-secondary-dark dark:text-ink" wire:key="carried-{{ $loop->index }}">{{ $carriedCount }} {{ $carriedLabel }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </x-ui.confirm-modal>
</section>
