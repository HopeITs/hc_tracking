<?php

use App\Actions\Projects\DeleteProjectFile;
use App\Actions\Projects\SaveProjectFile;
use App\Enums\FileType;
use App\Enums\IndicatorMeasure;
use App\Enums\MediaCollection;
use App\Enums\MilestoneStatus;
use App\Enums\ProposalPhase;
use App\Enums\ResultType;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Livewire\Concerns\BuildsProjectGantt;
use App\Livewire\Concerns\EditsProjectGantt;
use App\Models\DonorProposal;
use App\Models\HrEmployee;
use App\Models\Indicator;
use App\Models\Media;
use App\Models\Milestone;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Result;
use App\Models\Risk;
use App\Models\Task;
use App\Rules\SecureUpload;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('titles.project')] class extends Component {
    use BuildsProjectGantt;
    use EditsProjectGantt;
    use WithFileUploads;

    #[Locked]
    public int $projectId;

    #[Url(except: 'milestones')]
    public string $tab = 'milestones';

    public string $proposalToAttach = '';

    public string $fileCategoryFilter = 'all';

    public bool $showFileModal = false;

    public bool $confirmingFileDelete = false;

    #[Locked]
    public ?int $editingFileId = null;

    #[Locked]
    public ?int $deletingFileId = null;

    public $newFile = null;

    public string $fileCategory = '';

    public string $fileDisplayName = '';

    public bool $showAddTaskModal = false;

    public string $newTaskTitle = '';

    public string $newTaskStatus = 'todo';

    public string $newTaskPriority = 'medium';

    public string $newTaskDueDate = '';

    /** @var array<int, string> */
    public array $newTaskAssignedTo = [];

    public function mount(Project $project): void
    {
        $this->projectId = $project->id;
    }

    #[Computed]
    public function project(): Project
    {
        return Project::query()
            ->select([
                'id',
                'name',
                'description',
                'status',
                'start_date',
                'end_date',
                'schedule_weekend',
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
                'media' => fn ($query) => $query
                    ->select(['id', 'mediable_type', 'mediable_id', 'collection', 'original_name', 'type', 'size', 'uploaded_at', 'uploaded_by'])
                    ->with('uploader:id,hr_user_id')
                    ->orderByDesc('uploaded_at')
                    ->orderByDesc('id'),
            ])
            ->withCount([
                'risks',
                'milestones',
                'planIndicators',
                'proposals',
                'media',
                'milestones as reached_milestones_count' => fn ($query) => $query->where('status', MilestoneStatus::Reached->value),
            ])
            ->withSum(['milestones as milestones_reached_weight' => fn ($query) => $query->where('status', MilestoneStatus::Reached->value)], 'weight_percentage')
            ->withSum(['milestones as milestones_planned_weight' => fn ($query) => $query->where('status', '!=', MilestoneStatus::Cancelled->value)], 'weight_percentage')
            ->findOrFail($this->projectId);
    }

    /**
     * Weighted milestone completion, the same formula used on the project
     * directory and program detail pages: reached weight over every weight
     * that still counts (cancelled milestones are excluded from both sides).
     */
    #[Computed]
    public function milestoneProgressPercent(): ?float
    {
        $planned = (float) ($this->project->milestones_planned_weight ?? 0);

        if ($planned <= 0) {
            return null;
        }

        $reached = (float) ($this->project->milestones_reached_weight ?? 0);

        return round(min(100, ($reached / $planned) * 100), 1);
    }

    /**
     * Every milestone in the project, flattened and ordered by sequence, for
     * the delivery timeline: the same reading as the program detail page's
     * project timeline, one row per milestone instead of per project.
     *
     * @return Collection<int, Milestone>
     */
    #[Computed]
    public function milestonesFlat(): Collection
    {
        return Milestone::query()
            ->select(['id', 'project_id', 'name', 'planned_start_date', 'planned_end_date', 'actual_start_date', 'actual_end_date', 'status', 'sequence', 'weight_percentage', 'responsible_user_id'])
            ->where('project_id', $this->projectId)
            ->with('responsibleUser:id,first_name_en,last_name_en,first_name_ar,last_name_ar')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();
    }

    /**
     * The shared time axis for the milestone timeline: the project's own
     * schedule, widened to fit every milestone's planned and actual date plus
     * today, so nothing renders off-window.
     *
     * @return array{start: \Carbon\CarbonImmutable, end: \Carbon\CarbonImmutable}
     */
    #[Computed]
    public function milestoneWindow(): array
    {
        $dates = collect([$this->project->start_date, $this->project->end_date])->filter();

        foreach ($this->milestonesFlat as $milestone) {
            $dates->push($milestone->planned_start_date);
            $dates->push($milestone->planned_end_date);
            $dates->push($milestone->actual_start_date);
            $dates->push($milestone->actual_end_date);
        }

        $dates = $dates->filter()->push(\Carbon\CarbonImmutable::today());

        return ['start' => $dates->min(), 'end' => $dates->max()];
    }

    /**
     * Tasks raised straight against this project or against one of its
     * milestones — a task has no project column, so a milestone-sourced task
     * is reached through the milestone ids belonging to this project.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function tasks(): Collection
    {
        $milestoneIds = Milestone::query()->where('project_id', $this->projectId)->pluck('id');

        return Task::query()
            ->select(['id', 'source_type', 'source_id', 'title', 'status', 'priority', 'due_date', 'progress_percentage'])
            ->with(['taskAssignees.employee:id,first_name_en,last_name_en,first_name_ar,last_name_ar', 'source'])
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('source_type', 'project')->where('source_id', $this->projectId))
                ->orWhere(fn (Builder $query) => $query->where('source_type', 'milestone')->whereIn('source_id', $milestoneIds)))
            ->orderByRaw('due_date is null, due_date')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, HrEmployee> */
    #[Computed]
    public function userOptions(): Collection
    {
        return HrEmployee::pickerOptions();
    }

    public function openAddTaskModal(): void
    {
        Gate::authorize('create', Task::class);

        $this->resetValidation();
        $this->newTaskTitle = '';
        $this->newTaskStatus = TaskStatus::Todo->value;
        $this->newTaskPriority = TaskPriority::Medium->value;
        $this->newTaskDueDate = '';
        $this->newTaskAssignedTo = [];
        $this->showAddTaskModal = true;
    }

    public function closeAddTaskModal(): void
    {
        $this->showAddTaskModal = false;
    }

    /**
     * Raise a task straight against this project — the source is always this
     * project, never a milestone: a task tied to one milestone's delivery is
     * added from the milestone's own page instead.
     */
    public function addTask(): void
    {
        Gate::authorize('create', Task::class);

        $this->newTaskTitle = trim($this->newTaskTitle);

        $validated = $this->validate([
            'newTaskTitle' => ['required', 'string', 'max:255'],
            'newTaskStatus' => ['required', new Enum(TaskStatus::class)],
            'newTaskPriority' => ['required', new Enum(TaskPriority::class)],
            'newTaskDueDate' => ['nullable', 'date'],
            'newTaskAssignedTo' => ['required', 'array', 'min:1'],
            'newTaskAssignedTo.*' => ['integer', Rule::exists(HrEmployee::class, 'id')],
        ], [
            'newTaskTitle.required' => __('tasks.enter_a_task_title'),
            'newTaskAssignedTo.required' => __('tasks.assign_this_task_to_someone'),
            'newTaskAssignedTo.min' => __('tasks.assign_this_task_to_someone'),
            'newTaskAssignedTo.*.exists' => __('tasks.the_selected_assignee_is_no_longer_available'),
        ]);

        $projectId = $this->projectId;
        $statusCase = TaskStatus::from($validated['newTaskStatus']);
        $employeeIds = collect($validated['newTaskAssignedTo'])->map(fn ($id): int => (int) $id)->unique()->values();

        DB::transaction(function () use ($projectId, $validated, $statusCase, $employeeIds): void {
            $task = Task::query()->create([
                'source_type' => 'project',
                'source_id' => $projectId,
                'title' => $validated['newTaskTitle'],
                'status' => $statusCase,
                'priority' => $validated['newTaskPriority'],
                'due_date' => $validated['newTaskDueDate'] === '' ? null : $validated['newTaskDueDate'],
                'progress_percentage' => $statusCase === TaskStatus::Done ? 100 : 0,
                'completed_date' => $statusCase === TaskStatus::Done ? CarbonImmutable::today() : null,
                'created_by' => (int) auth()->id(),
            ]);

            foreach ($employeeIds as $employeeId) {
                $task->taskAssignees()->create(['employee_id' => $employeeId]);
            }
        });

        $this->showAddTaskModal = false;
        unset($this->tasks);

        Flux::toast(variant: 'success', text: __('tasks.task_created'));
    }

    /**
     * File category counts for the files tab's segmented filter, including
     * an "all" bucket first and only the categories actually present after
     * it — an empty category never earns a filter chip.
     *
     * @return array<int, array{value: string, label: string, count: int}>
     */
    #[Computed]
    public function fileCategoryCounts(): array
    {
        $files = $this->project->media;

        $counts = [[
            'value' => 'all',
            'label' => __('projects.all_files'),
            'count' => $files->count(),
        ]];

        foreach (MediaCollection::cases() as $collection) {
            $count = $files->filter(fn (Media $media): bool => $media->collection === $collection)->count();

            if ($count > 0) {
                $counts[] = ['value' => $collection->value, 'label' => $collection->label(), 'count' => $count];
            }
        }

        return $counts;
    }

    /**
     * This project's files narrowed to the selected category filter, still
     * newest first.
     *
     * @return Collection<int, Media>
     */
    #[Computed]
    public function filteredProjectFiles(): Collection
    {
        if ($this->fileCategoryFilter === 'all') {
            return $this->project->media;
        }

        return $this->project->media
            ->filter(fn (Media $media): bool => $media->collection->value === $this->fileCategoryFilter)
            ->values();
    }

    /**
     * The media row behind a file-tab action, scoped to this project.
     *
     * Every method below takes a media id from the client — a clicked
     * download/edit/delete button — so it must be re-scoped to this project
     * rather than trusting a bare findOrFail(). Without this guard a user
     * could act on another project's file by tampering with the id (IDOR).
     */
    private function projectFile(int $mediaId): Media
    {
        return Media::query()
            ->where('mediable_type', 'project')
            ->where('mediable_id', $this->projectId)
            ->findOrFail($mediaId);
    }

    public function openCreateFileModal(): void
    {
        Gate::authorize('update', Project::query()->findOrFail($this->projectId));

        $this->resetValidation();
        $this->editingFileId = null;
        $this->newFile = null;
        $this->fileCategory = '';
        $this->fileDisplayName = '';
        $this->showFileModal = true;
    }

    public function openEditFileModal(int $mediaId): void
    {
        Gate::authorize('update', Project::query()->findOrFail($this->projectId));

        $media = $this->projectFile($mediaId);

        $this->resetValidation();
        $this->editingFileId = $media->id;
        $this->newFile = null;
        $this->fileCategory = $media->collection->value;
        $this->fileDisplayName = $media->original_name;
        $this->showFileModal = true;
    }

    /**
     * Create a new project file or update an existing one's metadata.
     *
     * Create validates and stores the upload itself; edit changes only the
     * category and display name — swapping the underlying document is
     * delete + re-upload, not an in-place replace.
     */
    public function saveFile(): void
    {
        Gate::authorize('update', Project::query()->findOrFail($this->projectId));

        if ($this->editingFileId === null) {
            $allowedTypes = implode(',', FileType::values());

            $validated = $this->validate([
                'newFile' => ['required', 'bail', 'file', 'max:5120', 'mimes:'.$allowedTypes, 'extensions:'.$allowedTypes, new SecureUpload],
                'fileCategory' => ['required', Rule::enum(MediaCollection::class)],
                'fileDisplayName' => ['nullable', 'string', 'max:255'],
            ], [
                'newFile.required' => __('projects.select_a_file_to_upload'),
                'fileCategory.required' => __('projects.select_a_file_category'),
            ]);

            app(SaveProjectFile::class)->handle(Project::query()->findOrFail($this->projectId), null, $this->newFile, $validated);

            $toast = __('projects.file_uploaded');
        } else {
            $media = $this->projectFile($this->editingFileId);

            $validated = $this->validate([
                'fileCategory' => ['required', Rule::enum(MediaCollection::class)],
                'fileDisplayName' => ['required', 'string', 'max:255'],
            ], [
                'fileCategory.required' => __('projects.select_a_file_category'),
            ]);

            app(SaveProjectFile::class)->handle(Project::query()->findOrFail($this->projectId), $media, null, $validated);

            $toast = __('projects.file_updated');
        }

        $this->closeFileModal();
        unset($this->project, $this->fileCategoryCounts, $this->filteredProjectFiles);

        Flux::toast(variant: 'success', text: $toast);
    }

    public function confirmDeleteFile(int $mediaId): void
    {
        Gate::authorize('update', Project::query()->findOrFail($this->projectId));

        $media = $this->projectFile($mediaId);

        $this->deletingFileId = $media->id;
        $this->confirmingFileDelete = true;
    }

    /**
     * Delete a file's row and its underlying disk file.
     *
     * A storage I/O failure is logged, not surfaced — the record is removed
     * regardless so it doesn't linger stuck in the UI over a storage hiccup.
     */
    public function deleteFile(): void
    {
        Gate::authorize('update', Project::query()->findOrFail($this->projectId));

        $media = $this->projectFile($this->deletingFileId);

        app(DeleteProjectFile::class)->handle(Project::query()->findOrFail($this->projectId), $media);

        $this->confirmingFileDelete = false;
        $this->deletingFileId = null;
        unset($this->project, $this->fileCategoryCounts, $this->filteredProjectFiles);

        Flux::toast(variant: 'success', text: __('projects.file_deleted'));
    }

    public function closeFileModal(): void
    {
        $this->showFileModal = false;
        $this->editingFileId = null;
        $this->newFile = null;
        $this->fileCategory = '';
        $this->fileDisplayName = '';
        $this->resetValidation();
    }

    /**
     * Committed vs. received money for this project, grouped by currency,
     * across every donor proposal signed against every linked proposal.
     *
     * @return \Illuminate\Support\Collection<string, array{currency: \App\Enums\Currency, committed: float, received: float}>
     */
    #[Computed]
    public function fundingByCurrency(): \Illuminate\Support\Collection
    {
        return DonorProposal::query()
            ->select(['id', 'proposal_id', 'currency', 'budget'])
            ->whereHas('proposal.projects', fn ($query) => $query->whereKey($this->projectId))
            ->with(['transfers:id,donor_proposal_id,net_amount_received'])
            ->get()
            ->groupBy(fn (DonorProposal $donorProposal) => $donorProposal->currency->value)
            ->map(fn (Collection $group) => [
                'currency' => $group->first()->currency,
                'committed' => (float) $group->sum('budget'),
                'received' => (float) $group->sum(fn (DonorProposal $donorProposal) => $donorProposal->transfers->sum('net_amount_received')),
            ]);
    }

    /** @return Collection<int, Risk> */
    #[Computed]
    public function risks(): Collection
    {
        return Risk::query()
            ->select(['id', 'name', 'impact', 'solution', 'happened', 'project_id'])
            ->where('project_id', $this->projectId)
            ->orderByDesc('happened')
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, PlanIndicator> */
    #[Computed]
    public function targets(): Collection
    {
        return PlanIndicator::query()
            ->select(['id', 'source_type', 'source_id', 'indicator_id', 'target', 'project_id', 'period'])
            ->where('project_id', $this->projectId)
            ->with(['indicator:id,name,result_id,measure,unit', 'indicator.result:id,statement,type'])
            ->withCount('reports')
            ->orderBy('id')
            ->get();
    }

    /**
     * Targets grouped by the result they measure and, within that, by the
     * indicator committed against — the indicator -> result and
     * indicator -> source/period connections shown on the targets tab.
     * Groups sort in logframe order (impact, then outcome, then output).
     *
     * @return \Illuminate\Support\Collection<int, array{result: Result, indicators: \Illuminate\Support\Collection<int, array{indicator: Indicator, targets: Collection<int, PlanIndicator>}>}>
     */
    #[Computed]
    public function targetsByResult(): \Illuminate\Support\Collection
    {
        $resultOrder = [ResultType::Impact->value => 0, ResultType::Outcome->value => 1, ResultType::Output->value => 2];

        return $this->targets
            ->groupBy(fn (PlanIndicator $target) => $target->indicator->result_id)
            ->map(fn (Collection $groupTargets) => [
                'result' => $groupTargets->first()->indicator->result,
                'indicators' => $groupTargets
                    ->groupBy('indicator_id')
                    ->map(fn (Collection $indicatorTargets) => [
                        'indicator' => $indicatorTargets->first()->indicator,
                        'targets' => $indicatorTargets,
                    ])
                    ->values(),
            ])
            ->sortBy(fn (array $group) => $resultOrder[$group['result']->type->value] ?? 99)
            ->values();
    }

    /**
     * How many distinct indicators are named by this project's targets, for
     * the overview strip above the target lineage.
     */
    #[Computed]
    public function targetedIndicatorCount(): int
    {
        return $this->targets->pluck('indicator_id')->unique()->count();
    }

    /**
     * A committed target's value, in the indicator's own unit: a percentage
     * indicator reads "42.00%", an indicator with a recorded unit reads
     * "42.00 households", and one with neither reads as a bare number.
     */
    protected function formatTargetValue(PlanIndicator $target): string
    {
        $value = number_format((float) $target->target, 2);

        if ($target->indicator->measure === IndicatorMeasure::Percentage) {
            return __('projects.target_value_percentage', ['value' => $value]);
        }

        if ($target->indicator->unit !== null) {
            return __('projects.target_value_with_unit', ['value' => $value, 'unit' => $target->indicator->unit]);
        }

        return $value;
    }

    /**
     * Every proposal funding this project, with the donor agreements behind
     * each one eager loaded so the connections view (donors, committed and
     * received amounts) costs no extra queries per card.
     *
     * @return Collection<int, Proposal>
     */
    #[Computed]
    public function attachedProposals(): Collection
    {
        return $this->project->proposals()
            ->select(['proposals.id', 'proposals.name', 'proposals.status', 'proposals.planned_date_begin', 'proposals.planned_date_end'])
            ->with(['donorProposals' => fn ($query) => $query
                ->select(['id', 'proposal_id', 'donor_id', 'currency', 'budget'])
                ->with([
                    'donor:id,name',
                    'transfers:id,donor_proposal_id,net_amount_received',
                ]),
            ])
            ->orderBy('proposals.name')
            ->orderBy('proposals.id')
            ->get();
    }

    /**
     * Committed vs. received funding for one linked proposal, grouped by
     * currency — the same read as fundingByCurrency() but scoped to a single
     * proposal's already eager loaded agreements.
     *
     * @return \Illuminate\Support\Collection<string, array{currency: \App\Enums\Currency, committed: float, received: float}>
     */
    protected function proposalFunding(Proposal $proposal): \Illuminate\Support\Collection
    {
        return $proposal->donorProposals
            ->groupBy(fn (DonorProposal $donorProposal) => $donorProposal->currency->value)
            ->map(fn (Collection $group) => [
                'currency' => $group->first()->currency,
                'committed' => (float) $group->sum('budget'),
                'received' => (float) $group->sum(fn (DonorProposal $donorProposal) => $donorProposal->transfers->sum('net_amount_received')),
            ]);
    }

    /**
     * Portfolio-level read across every proposal funding this project: how
     * many distinct donors are behind it, how many signed agreements exist,
     * and how many proposals are currently in implementation or monitoring —
     * the headline numbers a fundraising manager scans first.
     *
     * @return array{donors: int, agreements: int, underway: int}
     */
    #[Computed]
    public function proposalsSummary(): array
    {
        $agreements = $this->attachedProposals->flatMap->donorProposals;

        return [
            'donors' => $agreements->pluck('donor_id')->unique()->count(),
            'agreements' => $agreements->count(),
            'underway' => $this->attachedProposals
                ->filter(fn (Proposal $proposal) => in_array($proposal->phase, [ProposalPhase::Implementation, ProposalPhase::Monitoring], true))
                ->count(),
        ];
    }

    /** @return Collection<int, Proposal> */
    #[Computed]
    public function attachableProposals(): Collection
    {
        return Proposal::query()
            ->select(['id', 'name', 'status'])
            ->whereDoesntHave('projects', fn ($query) => $query->whereKey($this->projectId))
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function attachProposal(): void
    {
        Gate::authorize('update', Project::query()->findOrFail($this->projectId));

        $validated = $this->validate([
            'proposalToAttach' => ['required', 'integer', Rule::exists(Proposal::class, 'id')],
        ], [
            'proposalToAttach.required' => __('projects.select_a_proposal_to_link'),
            'proposalToAttach.exists' => __('common.the_selected_proposal_is_no_longer_available'),
        ]);

        $this->project->proposals()->syncWithoutDetaching([(int) $validated['proposalToAttach']]);

        $this->proposalToAttach = '';
        unset($this->project, $this->attachedProposals, $this->attachableProposals);

        Flux::toast(variant: 'success', text: __('projects.proposal_linked_to_this_project'));
    }

    public function detachProposal(int $proposalId): void
    {
        Gate::authorize('update', Project::query()->findOrFail($this->projectId));

        $this->project->proposals()->detach($proposalId);

        unset($this->project, $this->attachedProposals, $this->attachableProposals);

        Flux::toast(variant: 'success', text: __('projects.proposal_unlinked_from_this_project'));
    }

    /**
     * The Plan tab's Gantt payload: fundraising, delivery and donor
     * obligations for this project as one WBS row list.
     *
     * @return array{rows: list<array<string, mixed>>, from: string, to: string, today: string}
     */
    #[Computed]
    public function ganttPayload(): array
    {
        return $this->projectGanttPayload($this->project, $this->ganttBaselineId);
    }

    /** @return array<int, array{key: string, label: string, count: int|null}> */
    #[Computed]
    public function tabs(): array
    {
        return [
            ['key' => 'milestones', 'label' => __('common.milestones'), 'count' => $this->project->milestones_count],
            ['key' => 'tasks', 'label' => __('common.tasks'), 'count' => $this->tasks->count()],
            ['key' => 'plan', 'label' => __('gantt.plan'), 'count' => null],
            ['key' => 'risks', 'label' => __('common.risks'), 'count' => $this->project->risks_count],
            ['key' => 'targets', 'label' => __('common.targets'), 'count' => $this->project->plan_indicators_count],
            ['key' => 'proposals', 'label' => __('common.proposals'), 'count' => $this->project->proposals_count],
            ['key' => 'files', 'label' => __('projects.files'), 'count' => $this->project->media_count],
            ['key' => 'activity', 'label' => __('activity.title'), 'count' => null],
        ];
    }
};
?>

<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white">
    <x-ui.detail-header
        :breadcrumbs="[
            ['label' => __('common.projects'), 'url' => route('projects.index')],
            ['label' => $this->project->name],
        ]"
        :title="$this->project->name"
        :unread="app(\App\Notifications\UnreadSubjects::class)->has(auth()->user(), 'project', $this->project->id)"
        :subtitle="$this->project->city?->name ?? __('common.unknown_city')"
    >
        <x-slot:meta>
            <a href="{{ route('programs.show', $this->project->program) }}" wire:navigate class="font-medium hover:underline">{{ $this->project->program->name }}</a>
            <span>{{ $this->project->program->strategy?->name ?? __('common.strategy_not_assigned') }}</span>
            <span>{{ __('common.updated_date', ['date' => $this->project->updated_at?->translatedFormat(__('formats.date'))]) }}</span>
        </x-slot:meta>

        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.enum-badge :case="$this->project->status" />
                <x-ui.employee-chip :employee="$this->project->user" class="rounded-lg border border-secondary-soft p-2.5 dark:border-secondary-medium" />
            </div>
        </x-slot:actions>

        <x-slot:summary>
            <div class="flex flex-wrap items-center gap-4 rounded-xl border border-secondary-soft bg-board px-4 py-3 dark:border-secondary-medium">
                <x-ui.progress-ring
                    size="lg"
                    :value="$this->milestoneProgressPercent"
                    :tone="$this->milestoneProgressPercent === null ? 'neutral' : ($this->milestoneProgressPercent >= 70 ? 'good' : ($this->milestoneProgressPercent >= 30 ? 'warn' : 'critical'))"
                    :label="__('projects.milestone_progress')"
                />
                <div class="min-w-0">
                    <p class="type-label text-secondary-medium dark:text-secondary-soft">{{ __('projects.milestone_progress') }}</p>
                    <p class="mt-0.5 text-sm font-semibold text-secondary-dark dark:text-white">
                        {{ $this->milestoneProgressPercent === null ? __('projects.no_weighted_milestones') : __('projects.milestones_reached', ['reached' => $this->project->reached_milestones_count, 'total' => $this->project->milestones_count]) }}
                    </p>
                    <div class="mt-1.5">
                        <x-ui.schedule-status
                            :planned="$this->project->end_date"
                            :done="! $this->project->status->isOpen()"
                        />
                    </div>
                </div>
            </div>
        </x-slot:summary>
    </x-ui.detail-header>

    <div class="grid gap-3 @2xl/detail:grid-cols-2 @5xl/detail:grid-cols-4">
        <x-ui.stat-tile :label="__('common.start_date')" :value="$this->project->start_date?->translatedFormat(__('formats.date')) ?? '—'" />
        <x-ui.stat-tile :label="__('projects.end_date')" :value="$this->project->end_date?->translatedFormat(__('formats.date')) ?? '—'" />
        <x-ui.stat-tile :label="__('projects.headline_target')" :value="$this->project->target_beneficiaries === null ? '—' : number_format($this->project->target_beneficiaries)" />
        <x-ui.stat-tile :label="__('projects.demographic_total')" :value="$this->project->targetDemographicTotal() === null ? '—' : number_format($this->project->targetDemographicTotal())" />
    </div>

    <div class="grid gap-4 @5xl/detail:grid-cols-3">
        <x-ui.panel :title="__('common.description')">
            @if ($this->project->description === null)
                <p class="p-3.5 text-sm italic text-secondary-medium dark:text-secondary-soft">{{ __('projects.no_description_recorded') }}</p>
            @else
                <p class="p-3.5 text-sm leading-6 text-secondary-medium dark:text-secondary-soft">{{ $this->project->description }}</p>
            @endif
        </x-ui.panel>

        <x-ui.panel :title="__('projects.beneficiary_breakdown')" :subtitle="__('projects.reported_separately_from_the_headline_target')">
            <div class="flex flex-col gap-3 p-3.5">
                @php $demographicTotal = $this->project->targetDemographicTotal(); @endphp
                @foreach ([__('projects.men') => $this->project->target_men, __('projects.women') => $this->project->target_women, __('projects.boys') => $this->project->target_boys, __('projects.girls') => $this->project->target_girls] as $label => $value)
                    <div wire:key="project-demographic-{{ strtolower($label) }}">
                        <div class="flex items-baseline justify-between gap-2">
                            <p class="type-meta text-secondary-medium dark:text-secondary-soft">{{ $label }}</p>
                            <p class="text-sm font-semibold tabular-nums text-secondary-dark dark:text-white">{{ $value === null ? '—' : number_format($value) }}</p>
                        </div>
                        <x-ui.progress-bar
                            class="mt-1"
                            :value="$value"
                            :max="$demographicTotal ?: 1"
                            label-hidden
                            :label="$label"
                        />
                    </div>
                @endforeach
            </div>
        </x-ui.panel>

        <x-ui.panel :title="__('projects.funding_envelope')">
            @if ($this->fundingByCurrency->isEmpty())
                <x-ui.empty-state icon="banknotes" :heading="__('projects.not_funded_yet')" :body="__('projects.link_a_proposal_to_record_which_funding_covers_this_project')" />
            @else
                <div class="flex flex-col gap-4 p-3.5">
                    @foreach ($this->fundingByCurrency as $row)
                        <x-ui.capacity-bar :committed="$row['committed']" :used="$row['received']" :currency="$row['currency']" wire:key="funding-{{ $row['currency']->value }}" />
                    @endforeach
                </div>
            @endif
        </x-ui.panel>
    </div>

    <x-ui.relation-tabs :tabs="$this->tabs" :active="$tab" wire-target="tab" />

    @if ($tab === 'milestones')
        <x-ui.panel :title="__('common.milestones')" :count="$this->project->milestones_count" :subtitle="__('projects.milestones_planned_vs_actual_by_sequence')">
            @if ($this->milestonesFlat->isEmpty())
                <x-ui.empty-state icon="flag" :heading="__('common.no_milestones_yet')" :body="__('projects.milestones_planned_for_this_project_will_appear_here')" />
            @else
                <x-ui.milestone-timeline :milestones="$this->milestonesFlat" :window="$this->milestoneWindow" />
            @endif
        </x-ui.panel>
    @elseif ($tab === 'tasks')
        <x-ui.panel :title="__('common.tasks')" :count="$this->tasks->count()" :subtitle="__('projects.tasks_raised_against_this_project_or_its_milestones')">
            <x-slot:toolbar>
                @can('create', App\Models\Task::class)<x-ui.button tone="primary" size="sm" icon="plus" wire:click="openAddTaskModal" data-test="add-project-task-button">{{ __('tasks.add_task') }}</x-ui.button>@endcan
            </x-slot:toolbar>

            @if ($this->tasks->isEmpty())
                <x-ui.empty-state icon="clipboard-document-list" :heading="__('common.no_tasks_yet')" :body="__('projects.tasks_raised_against_this_project_will_appear_here')">
                    <x-slot:action>
                        @can('create', App\Models\Task::class)<x-ui.button size="sm" tone="primary" icon="plus" wire:click="openAddTaskModal">{{ __('tasks.add_task') }}</x-ui.button>@endcan
                    </x-slot:action>
                </x-ui.empty-state>
            @else
                @php
                    $taskDone = $this->tasks->where('status', TaskStatus::Done)->count();
                    $taskTotal = $this->tasks->count();
                    $taskOverdue = $this->tasks->filter(fn (Task $task): bool => $task->status !== TaskStatus::Done && $task->due_date !== null && $task->due_date->lt(today()))->count();
                    $taskUnassigned = $this->tasks->filter(fn (Task $task): bool => $task->taskAssignees->isEmpty())->count();
                    $taskInkText = 'text-secondary-dark dark:text-white';
                    $taskMutedText = 'text-secondary-medium dark:text-secondary-soft';
                @endphp

                <div class="grid gap-3 border-b border-secondary-soft p-3.5 @lg/detail:grid-cols-3 dark:border-secondary-medium">
                    <x-ui.stat-tile :label="__('projects.stat_tasks_done')" :value="$taskDone" :denominator="$taskTotal" />
                    <x-ui.stat-tile :label="__('tasks.overdue')" :value="$taskOverdue" :tone="$taskOverdue > 0 ? 'warn' : null" />
                    <x-ui.stat-tile :label="__('common.unassigned')" :value="$taskUnassigned" />
                </div>

                <div class="hidden grid-cols-[1.25rem_minmax(0,1fr)_11rem_6rem_7.5rem_8rem_7rem] gap-x-4 px-3.5 pt-4 @3xl/detail:grid" aria-hidden="true">
                    <span></span>
                    @foreach ([__('tasks.task'), __('tasks.assignee'), __('tasks.priority'), __('tasks.due'), __('tasks.progress'), __('common.status')] as $column)
                        <span @class(['type-label', $taskMutedText, 'text-end' => $loop->last])>{{ $column }}</span>
                    @endforeach
                </div>

                <ul class="divide-y divide-secondary-soft px-3.5 pb-3.5 @6xl/detail:px-5 @6xl/detail:pb-5 dark:divide-secondary-medium">
                    @foreach ($this->tasks as $task)
                        @include('pages.projects._task-row', ['task' => $task, 'inkText' => $taskInkText, 'mutedText' => $taskMutedText])
                    @endforeach
                </ul>
            @endif
        </x-ui.panel>
    @elseif ($tab === 'plan')
        <x-ui.panel :title="__('gantt.plan')" :subtitle="__('gantt.plan_subtitle')">
            @if ($this->ganttPayload['rows'] === [])
                <x-ui.empty-state icon="chart-bar" :heading="__('gantt.nothing_to_plan')" :body="__('gantt.nothing_to_plan_body')" />
            @else
                <x-ui.gantt :payload="$this->ganttPayload" :scope="'project-'.$this->projectId" :editable="auth()->user()->can('update', $this->project)" wire:ignore wire:key="project-gantt-{{ $this->projectId }}" />
            @endif
        </x-ui.panel>
    @elseif ($tab === 'risks')
        <x-ui.panel :title="__('projects.risk_register')" :count="$this->risks->count()" :subtitle="__('projects.risks_that_have_materialised_are_listed_first')">
            <x-slot:toolbar>
                <a href="{{ route('risks.index', ['project' => $this->projectId]) }}" wire:navigate class="type-meta font-semibold text-secondary-medium hover:text-primary hover:underline dark:text-secondary-soft dark:hover:text-primary">
                    {{ __('projects.manage_risks') }}
                </a>
            </x-slot:toolbar>

            @if ($this->risks->isEmpty())
                <x-ui.empty-state icon="exclamation-triangle" :heading="__('common.no_risks_logged')" :body="__('projects.risks_logged_against_this_project_will_appear_here')">
                    <x-slot:action>
                        <x-ui.button size="sm" tone="primary" icon="plus" href="{{ route('risks.index', ['project' => $this->projectId]) }}" wire:navigate>{{ __('risks.log_risk') }}</x-ui.button>
                    </x-slot:action>
                </x-ui.empty-state>
            @else
                @php
                    [$materialisedRisks, $watchingRisks] = $this->risks->partition(fn ($risk) => $risk->happened);
                    $riskLanes = array_values(array_filter([
                        [
                            'key' => 'materialised',
                            'label' => __('common.materialised'),
                            'icon' => 'exclamation-triangle',
                            'risks' => $materialisedRisks,
                            'laneClasses' => 'border-primary bg-white dark:bg-secondary-dark',
                            'headerClasses' => 'bg-primary text-white',
                        ],
                        [
                            'key' => 'watching',
                            'label' => __('common.watching'),
                            'icon' => 'eye',
                            'risks' => $watchingRisks,
                            'laneClasses' => 'border-secondary-soft bg-white dark:border-secondary-medium dark:bg-secondary-dark',
                            'headerClasses' => 'bg-board-head text-secondary-medium dark:bg-secondary-medium/30 dark:text-secondary-soft',
                        ],
                    ], fn ($lane) => $lane['risks']->isNotEmpty()));
                @endphp
                <div class="p-4">
                    <div @class(['grid gap-4', '@lg/detail:grid-cols-2' => count($riskLanes) === 2])>
                        @foreach ($riskLanes as $lane)
                            <div class="overflow-hidden rounded-xl border {{ $lane['laneClasses'] }}" wire:key="project-risk-lane-{{ $lane['key'] }}">
                                <div class="flex items-center gap-1.5 px-3 py-2 {{ $lane['headerClasses'] }}">
                                    <flux:icon :name="$lane['icon']" variant="micro" class="size-4" />
                                    <p class="type-label">{{ $lane['label'] }}</p>
                                    <span class="ms-auto text-xs font-semibold tabular-nums">{{ $lane['risks']->count() }}</span>
                                </div>

                                <div class="flex flex-col divide-y divide-secondary-soft dark:divide-secondary-medium">
                                    @foreach ($lane['risks'] as $risk)
                                        <article class="flex flex-col gap-2.5 p-3.5" wire:key="project-risk-{{ $risk->id }}">
                                            <p class="text-sm font-semibold text-secondary-dark dark:text-white">{{ $risk->name }}</p>
                                            <p class="type-body text-secondary-medium dark:text-secondary-soft">{{ $risk->impact }}</p>
                                            <div class="flex items-start gap-1.5 border-t border-secondary-soft pt-2.5 dark:border-secondary-medium">
                                                @if ($risk->solution !== null)
                                                    <flux:icon name="check-circle" variant="micro" class="mt-0.5 size-4 shrink-0 text-secondary-medium dark:text-secondary-soft" />
                                                    <p class="type-body text-secondary-dark dark:text-white">{{ $risk->solution }}</p>
                                                @else
                                                    <flux:icon name="exclamation-circle" variant="micro" class="mt-0.5 size-4 shrink-0 text-primary" />
                                                    <p class="type-body italic text-primary">{{ __('risks.no_mitigation_recorded_yet') }}</p>
                                                @endif
                                            </div>
                                        </article>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </x-ui.panel>
    @elseif ($tab === 'targets')
        <x-ui.panel :title="__('projects.targets_naming_this_project')" :count="$this->targets->count()" :subtitle="__('projects.targets_linked_by_indicator_result_source_and_period')">
            @if ($this->targets->isEmpty())
                <x-ui.empty-state icon="chart-bar" :heading="__('common.no_targets_yet')" :body="__('projects.indicator_targets_that_name_this_project_will_appear_here')" />
            @else
                @php
                    $viaAnnualPlan = $this->targets->where('source_type', 'annual_plan')->count();
                    $viaProposal = $this->targets->count() - $viaAnnualPlan;
                    $reportsFiled = $this->targets->sum('reports_count');
                @endphp
                <div class="grid gap-3 border-b border-secondary-soft p-3.5 @lg/detail:grid-cols-4 dark:border-secondary-medium">
                    <x-ui.stat-tile :label="__('projects.stat_indicators_covered')" :value="$this->targetedIndicatorCount" icon="chart-bar" />
                    <x-ui.stat-tile :label="__('projects.stat_committed_via_annual_plans')" :value="$viaAnnualPlan" icon="calendar-days" />
                    <x-ui.stat-tile :label="__('projects.stat_committed_via_proposals')" :value="$viaProposal" icon="document-text" />
                    <x-ui.stat-tile :label="__('projects.stat_reports_filed')" :value="$reportsFiled" icon="document-check" :tone="$reportsFiled > 0 ? 'good' : 'warn'" />
                </div>

                <div class="p-3.5 @3xl/detail:p-5">
                    <x-ui.target-lineage :groups="$this->targetsByResult" :format="fn (PlanIndicator $target): string => $this->formatTargetValue($target)" />
                </div>
            @endif
        </x-ui.panel>
    @elseif ($tab === 'files')
        <x-ui.panel :title="__('projects.project_files')" :count="$this->project->media_count" :subtitle="__('projects.project_files_subtitle')">
            <x-slot:toolbar>
                @can('update', $this->project)<x-ui.button tone="primary" size="sm" icon="plus" wire:click="openCreateFileModal" data-test="add-project-file-button">{{ __('projects.add_file') }}</x-ui.button>@endcan
            </x-slot:toolbar>

            @if ($this->project->media->isEmpty())
                <x-ui.empty-state icon="paper-clip" :heading="__('common.no_files_recorded')" :body="__('projects.file_records_for_this_project_will_appear_here')">
                    <x-slot:action>
                        @can('update', $this->project)<x-ui.button size="sm" tone="primary" icon="plus" wire:click="openCreateFileModal">{{ __('projects.add_file') }}</x-ui.button>@endcan
                    </x-slot:action>
                </x-ui.empty-state>
            @else
                @if (count($this->fileCategoryCounts) > 2)
                    <div class="flex flex-wrap items-center gap-2 border-b border-secondary-soft p-3.5 dark:border-secondary-medium">
                        @foreach ($this->fileCategoryCounts as $category)
                            @php $isActive = $fileCategoryFilter === $category['value']; @endphp
                            <button
                                type="button"
                                wire:click="$set('fileCategoryFilter', '{{ $category['value'] }}')"
                                wire:key="project-file-filter-{{ $category['value'] }}"
                                aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                                @class([
                                    'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 type-meta font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-primary',
                                    'border-secondary-dark bg-secondary-dark text-white dark:border-white dark:bg-white dark:text-secondary-dark' => $isActive,
                                    'border-secondary-soft text-secondary-medium hover:border-secondary-light hover:text-secondary-dark dark:border-secondary-medium dark:text-secondary-soft dark:hover:text-white' => ! $isActive,
                                ])
                            >
                                {{ $category['label'] }}
                                <span @class([
                                    'inline-flex min-w-5 items-center justify-center rounded-full px-1.5 py-px text-[10px] tabular-nums',
                                    'bg-white/20' => $isActive,
                                    'bg-secondary-soft dark:bg-secondary-medium' => ! $isActive,
                                ])>{{ $category['count'] }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif

                <div class="grid gap-3 p-3.5 @lg/detail:grid-cols-2 @4xl/detail:grid-cols-3 @6xl/detail:p-5">
                    @forelse ($this->filteredProjectFiles as $media)
                        <x-ui.file-card :media="$media" :project="$this->project" :editable="auth()->user()->can('update', $this->project)" wire:key="project-file-{{ $media->id }}" />
                    @empty
                        <div class="col-span-full">
                            <x-ui.empty-state icon="funnel" :heading="__('projects.no_files_in_this_category')" />
                        </div>
                    @endforelse
                </div>
            @endif
        </x-ui.panel>
    @elseif ($tab === 'activity')
        <livewire:activity.timeline :subjects="['project' => [$this->project->id]]" :key="'project-activity-'.$this->project->id" />
    @else
        <x-ui.panel :title="__('projects.linked_proposals')" :count="$this->attachedProposals->count()" :subtitle="__('projects.a_project_may_be_funded_by_several_proposals_and_a_proposal')">
            <x-slot:toolbar>
                @can('update', $this->project)
                <div class="flex w-full flex-col items-stretch gap-2 sm:w-auto sm:flex-row sm:items-end">
                    <x-ui.select :label="__('projects.link_a_proposal')" wire:model="proposalToAttach" class="min-w-56">
                        <flux:select.option value="">{{ __('common.select_a_proposal') }}</flux:select.option>
                        @foreach ($this->attachableProposals as $proposalOption)
                            <flux:select.option :value="$proposalOption->id" :wire:key="'attachable-'.$proposalOption->id">{{ $proposalOption->name }}</flux:select.option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.button tone="primary" size="sm" icon="link" class="min-h-10" wire:click="attachProposal">{{ __('projects.link') }}</x-ui.button>
                </div>
                @endcan
            </x-slot:toolbar>

            @if ($this->attachedProposals->isEmpty())
                <x-ui.empty-state icon="document-text" :heading="__('projects.no_proposals_linked')" :body="__('projects.link_a_proposal_to_record_which_funding_covers_this_project')" />
            @else
                @php $summary = $this->proposalsSummary; @endphp
                <div class="grid gap-3 border-b border-secondary-soft p-3.5 @lg/detail:grid-cols-3 dark:border-secondary-medium">
                    <x-ui.stat-tile :label="__('projects.stat_donors_funding')" :value="$summary['donors']" icon="building-library" />
                    <x-ui.stat-tile :label="__('projects.stat_funding_agreements')" :value="$summary['agreements']" icon="document-check" />
                    <x-ui.stat-tile :label="__('projects.stat_proposals_underway')" :value="$summary['underway']" icon="signal" :tone="$summary['underway'] > 0 ? 'good' : null" />
                </div>

                <div class="grid gap-4 p-3.5 @3xl/detail:grid-cols-2 @3xl/detail:p-5">
                    @foreach ($this->attachedProposals as $proposal)
                        <article
                            class="group flex flex-col gap-3.5 rounded-xl border border-secondary-soft bg-white p-4 transition-all hover:-translate-y-0.5 hover:shadow-[0_2px_4px_rgba(16,24,40,0.06),0_4px_8px_rgba(16,24,40,0.08)] dark:border-secondary-medium dark:bg-secondary-dark dark:hover:shadow-none"
                            wire:key="project-proposal-{{ $proposal->id }}"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <a href="{{ route('proposals.show', $proposal) }}" wire:navigate class="truncate text-base font-bold text-secondary-dark hover:underline dark:text-white">{{ $proposal->name }}</a>
                                    <p class="mt-1 flex items-center gap-1.5 text-xs text-secondary-medium dark:text-secondary-soft">
                                        <flux:icon name="calendar-days" variant="micro" class="size-3.5 shrink-0" />
                                        <span class="tabular-nums">{{ $proposal->planned_date_begin?->translatedFormat(__('formats.date')) }} – {{ $proposal->planned_date_end?->translatedFormat(__('formats.date')) }}</span>
                                    </p>
                                </div>
                                @can('update', $this->project)
                                    <x-ui.button
                                        tone="ghost"
                                        size="sm"
                                        icon="x-mark"
                                        class="size-8! shrink-0 opacity-0 transition-opacity focus-visible:opacity-100 group-hover:opacity-100"
                                        wire:click="detachProposal({{ $proposal->id }})"
                                        :aria-label="__('projects.unlink_name', ['name' => $proposal->name])"
                                    />
                                @endcan
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                <x-ui.enum-badge :case="$proposal->status" />
                                <span class="rounded-md border border-secondary-light px-2 py-0.5 text-[11px] font-semibold text-secondary-medium dark:text-secondary-soft">
                                    {{ $proposal->phase->label() }}
                                </span>
                            </div>

                            @if ($proposal->donorProposals->isEmpty())
                                <p class="type-meta border-t border-secondary-soft pt-3 italic text-secondary-medium dark:border-secondary-medium dark:text-secondary-soft">
                                    {{ __('projects.no_funding_agreement_recorded_yet') }}
                                </p>
                            @else
                                <div class="flex flex-col gap-3 border-t border-secondary-soft pt-3 dark:border-secondary-medium">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        @foreach ($proposal->donorProposals->unique('donor_id') as $agreement)
                                            <span class="inline-flex items-center gap-1 rounded-full border border-secondary-light bg-board px-2 py-0.5 text-[11px] font-semibold text-secondary-dark dark:border-secondary-medium dark:text-white" wire:key="proposal-{{ $proposal->id }}-donor-{{ $agreement->donor_id }}">
                                                <flux:icon name="building-library" variant="micro" class="size-3 shrink-0 text-secondary-medium dark:text-secondary-soft" />
                                                {{ $agreement->donor->name }}
                                            </span>
                                        @endforeach
                                    </div>

                                    <div class="flex flex-col gap-2.5">
                                        @foreach ($this->proposalFunding($proposal) as $row)
                                            <x-ui.capacity-bar :committed="$row['committed']" :used="$row['received']" :currency="$row['currency']" wire:key="proposal-{{ $proposal->id }}-funding-{{ $row['currency']->value }}" />
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </x-ui.panel>
    @endif

    <x-ui.modal
        wire:model="showFileModal"
        size="md"
        :title="$editingFileId === null ? __('projects.add_file') : __('projects.edit_file')"
        submit="saveFile"
        close="closeFileModal"
        required-note
    >
        @if ($showFileModal)
            <div class="grid gap-y-4">
                @if ($editingFileId === null)
                    <x-ui.form-file wire:model="newFile" :label="__('projects.choose_a_file')" required />
                @endif

                <div class="grid grid-cols-2 gap-x-4 gap-y-4">
                    <x-ui.form-select wire:model="fileCategory" :label="__('projects.file_category')" :placeholder="__('common.select')" required>
                        @foreach (\App\Enums\MediaCollection::cases() as $collectionOption)
                            <flux:select.option :value="$collectionOption->value">{{ $collectionOption->label() }}</flux:select.option>
                        @endforeach
                    </x-ui.form-select>

                    <x-ui.form-input wire:model="fileDisplayName" :label="__('projects.file_display_name')" :required="$editingFileId !== null" />
                </div>
            </div>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-project-file-button">{{ __('common.save_changes') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    <x-ui.confirm-modal
        wire:model="confirmingFileDelete"
        :title="__('projects.delete_this_file')"
        :message="$deletingFileId !== null && ($deletingMedia = $this->project->media->firstWhere('id', $deletingFileId)) !== null ? __('projects.deleting_the_file_also_removes_it_from_storage', ['name' => $deletingMedia->original_name]) : null"
        confirm="deleteFile"
        :confirm-label="__('projects.delete_this_file')"
        confirm-test="confirm-delete-project-file"
    />

    <x-tasks.add-task-modal test-prefix="new-project-task" :user-options="$this->userOptions" />
</section>
