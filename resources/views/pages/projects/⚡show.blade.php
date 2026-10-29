<?php

use App\Actions\Projects\CloseProject;
use App\Actions\Targets\ScopeIndicatorToProject;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Livewire\Concerns\AddsQuickTask;
use App\Livewire\Concerns\ChecksFinanceVisibility;
use App\Livewire\Concerns\Projects\ArchivesProjects;
use App\Livewire\Concerns\Projects\ManagesProjectAssignment;
use App\Livewire\Concerns\Projects\ManagesProjectFiles;
use App\Livewire\Concerns\Projects\ManagesProjectForm;
use App\Livewire\Concerns\Projects\ManagesReportTemplate;
use App\Livewire\Concerns\Projects\ReadsProjectDelivery;
use App\Livewire\Concerns\Projects\ReadsProjectFunding;
use App\Livewire\Concerns\Projects\ReadsProjectStory;
use App\Enums\AssignmentStatus;
use App\Models\HrEmployee;
use App\Models\Program;
use App\Models\Project;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts::desk'), Title('titles.project')] class extends Component {
    use AddsQuickTask;
    use ArchivesProjects;
    use ChecksFinanceVisibility;
    use ManagesProjectAssignment, ManagesProjectForm {
        ManagesProjectAssignment::refreshFromInbox insteadof ManagesProjectForm;
    }
    use ManagesProjectFiles;
    use ManagesReportTemplate;
    use ReadsProjectDelivery;
    use ReadsProjectFunding;
    use ReadsProjectStory;
    use WithFileUploads;

    #[Locked]
    public int $projectId;

    #[Url(except: 'milestones')]
    public string $tab = 'milestones';

    /**
     * The page's open section: overview, work, funding or files. Switched in
     * the browser (no round trip); resolved in mount() when the link names none.
     */
    #[Url(as: 'view', except: '')]
    public string $section = '';

    /** The owner assignment dialog: the owner acknowledges or rejects. */
    #[Url(as: 'respond', except: '')]
    public string $respond = '';

    public function mount(Project $project): void
    {
        $this->projectId = $project->id;

        $assignment = $project->currentAssignment('user_id');

        if ($assignment !== null && Auth::user() !== null && $assignment->status === AssignmentStatus::Pending && $assignment->isFor(Auth::user())) {
            $assignment->markSeen();

            if ($this->respond === 'reject') {
                $this->openAssignmentModal('reject');
            }
        }

        $this->respond = '';

        $this->section = $this->resolveSection($project);
    }

    /**
     * The section a link opens on. An old `?tab=` link keeps its meaning
     * (files and activity, proposals, or a working tab); a link into a
     * proposal opens Funding; otherwise a running project opens on its work
     * and every other one on its overview.
     */
    private function resolveSection(Project $project): string
    {
        if (in_array($this->section, ['overview', 'work', 'funding', 'files'], true)) {
            return $this->section;
        }

        return match (true) {
            in_array($this->tab, ['files', 'activity'], true) => 'files',
            $this->tab === 'proposals', $this->storyProposal !== '', request()->query('ptab') !== null => 'funding',
            $this->tab !== 'milestones' => 'work',
            $project->status === ProjectStatus::Active => 'work',
            default => 'overview',
        };
    }

    /** The Close Project confirmation: a flux:modal only opens from a boolean bound with wire:model. */
    public bool $showCloseModal = false;

    public function confirmClose(): void
    {
        Gate::authorize('update', Project::query()->findOrFail($this->projectId));

        $this->showCloseModal = true;
    }

    /**
     * Close the active project (active → closed); the action enforces who may
     * and that it is still active.
     */
    public function closeProject(): void
    {
        try {
            app(CloseProject::class)->handle(Project::query()->findOrFail($this->projectId));
        } catch (ValidationException $exception) {
            $this->showCloseModal = false;
            Flux::toast(variant: 'danger', text: $exception->validator->errors()->first());

            return;
        }

        $this->showCloseModal = false;
        $this->resetPage();

        Flux::toast(variant: 'success', text: __('projects.project_closed'));
    }

    /**
     * The project form's program picker (ManagesProjectForm reads it).
     *
     * @return Collection<int, Program>
     */
    #[Computed]
    public function programOptions(): Collection
    {
        return Program::options();
    }

    /**
     * The project form's owner picker (ManagesProjectForm reads it).
     *
     * @return Collection<int, HrEmployee>
     */
    #[Computed]
    public function userOptions(): Collection
    {
        return HrEmployee::pickerOptions();
    }

    /**
     * ArchivesProjects and ManagesProjectForm call this after a save, an
     * archive or a restore. The Story has no pager, so it re-reads the
     * project and its story instead.
     */
    protected function resetPage(): void
    {
        unset($this->project, $this->story, $this->attachedProposals, $this->fundingHistory, $this->planSummary, $this->canStartConceptNote, $this->embeddedProposalId);
    }

    /**
     * Scope a program-framework indicator to this project (T13); the action
     * checks the program and the committed targets again under lock.
     */
    public function scopeIndicator(int $indicatorId): void
    {
        Gate::authorize('indicators.update');

        app(ScopeIndicatorToProject::class)->handle(Project::query()->findOrFail($this->projectId), $indicatorId);

        unset($this->projectIndicators);

        Flux::toast(variant: 'success', text: __('projects.indicator_scoped'));
    }

    #[Computed]
    public function project(): Project
    {
        $hiddenCollections = $this->hiddenCollections();

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
                'center_id',
                'program_id',
                'user_id',
                'updated_at',
            ])
            ->with([
                'city:id,name_en,name_ar',
                'program:id,strategy_id',
                'program.strategy:id,name',
                'user:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
                'proposals:proposals.id,proposals.status',
                'sectors' => fn ($query) => $query->select(['sectors.id', 'sectors.name_ar', 'sectors.name_en', 'sectors.is_active'])->ordered(),
                'ownerAssignment',
                'media' => fn ($query) => $query
                    ->select(['id', 'mediable_type', 'mediable_id', 'collection', 'original_name', 'type', 'size', 'uploaded_at', 'uploaded_by'])
                    ->whereNotIn('collection', $hiddenCollections)
                    ->with('uploader:id,hr_user_id')
                    ->orderByDesc('uploaded_at')
                    ->orderByDesc('id'),
            ])
            ->withCount([
                'risks',
                'milestones',
                'planIndicators',
                'proposals',
                'media' => fn ($query) => $query->whereNotIn('collection', $hiddenCollections),
                'milestones as reached_milestones_count' => fn ($query) => $query->where('status', MilestoneStatus::Reached->value),
            ])
            ->withSum(['milestones as milestones_reached_weight' => fn ($query) => $query->where('status', MilestoneStatus::Reached->value)], 'weight_percentage')
            ->withSum(['milestones as milestones_planned_weight' => fn ($query) => $query->where('status', '!=', MilestoneStatus::Cancelled->value)], 'weight_percentage')
            ->findOrFail($this->projectId);
    }

    /**
     * Raise a task straight against this project — the source is always this
     * project, never a milestone: a task tied to one milestone's delivery is
     * added from the milestone's own page instead.
     *
     * @return array{type: string, id: int}
     */
    protected function quickTaskSource(): array
    {
        return ['type' => 'project', 'id' => $this->projectId];
    }

    protected function quickTaskCreated(): void
    {
        unset($this->tasks);
    }

    /**
     * The project's HR center for the header: its name, whether HR has
     * deactivated it, and whether HR could not be reached (the id is shown then).
     *
     * @return array{id: int, name: string|null, inactive: bool, unavailable: bool}|null
     */
    #[Computed]
    public function centerSummary(): ?array
    {
        $centerId = $this->project->center_id;

        if ($centerId === null) {
            return null;
        }

        try {
            $center = $this->project->center();
        } catch (QueryException) {
            return ['id' => $centerId, 'name' => null, 'inactive' => false, 'unavailable' => true];
        }

        return ['id' => $centerId, 'name' => $center?->name, 'inactive' => $center !== null && ! $center->is_active, 'unavailable' => $center === null];
    }

    /**
     * The delivery chapter's tab strip. Proposals, files and activity sit in
     * the Story's side column, so an old `?tab=files` link shows milestones here.
     *
     * @return array<int, array{key: string, label: string, count: int|null}>
     */
    #[Computed]
    public function tabs(): array
    {
        $tabs = [
            ['key' => 'milestones', 'label' => __('common.milestones'), 'count' => $this->project->milestones_count],
            ['key' => 'tasks', 'label' => __('common.tasks'), 'count' => $this->tasks->count()],
            ['key' => 'risks', 'label' => __('common.risks'), 'count' => $this->project->risks_count],
            ['key' => 'targets', 'label' => __('common.targets'), 'count' => $this->project->plan_indicators_count],
        ];

        if ($this->canViewReportTemplate) {
            array_splice($tabs, 5, 0, [['key' => 'template', 'label' => __('report_templates.tab.title'), 'count' => (int) $this->reportTemplate?->getAttribute('pending_lines_count') ?: null]]);
        }

        return $tabs;
    }

    /**
     * The next three milestones still to reach, for the Overview.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Milestone>
     */
    #[Computed]
    public function upcomingMilestones(): \Illuminate\Support\Collection
    {
        return $this->milestonesFlat
            ->reject(fn ($milestone): bool => in_array($milestone->status, [MilestoneStatus::Reached, MilestoneStatus::Cancelled], true))
            ->sortBy(fn ($milestone): string => $milestone->planned_end_date?->toDateString() ?? '9999-12-31')
            ->take(3)
            ->values();
    }

    #[Computed]
    public function deliveryTab(): string
    {
        return in_array($this->tab, ['milestones', 'tasks', 'risks', 'targets', ...($this->canViewReportTemplate ? ['template'] : [])], true) ? $this->tab : 'milestones';
    }
};
?>

<section class="dk-story" data-story>
    @include('pages.projects._story-header')
    @php
        $ownerAssignment = $this->ownerAssignment;
        $assignmentViewer = auth()->user();
        // Legacy owners (no responder) and people who put themselves in charge
        // were never asked, so there is nothing to show for them.
        $showAssignmentBanner = $ownerAssignment !== null
            && ($ownerAssignment->status !== AssignmentStatus::Acknowledged
                || ($ownerAssignment->responded_by !== null && $ownerAssignment->responded_by !== $ownerAssignment->assigned_by));
    @endphp

    @include('pages.projects._show-assignment-banner')

    <x-ui.modal
        wire:model="showAssignmentModal"
        size="md"
        :title="match ($assignmentMode) {
            'acknowledge' => __('projects.assignment_acknowledge_title'),
            default => __('projects.assignment_reject_title'),
        }"
        submit="submitAssignment"
        close="closeAssignmentModal"
    >
        @if ($showAssignmentModal)
            <x-ui.form-textarea
                wire:model="assignmentNote"
                :label="$assignmentMode === 'reject' ? __('projects.assignment_reason') : __('projects.assignment_note_optional')"
                :required="$assignmentMode === 'reject'"
                :help="$assignmentMode === 'reject' ? __('projects.assignment_reason_help') : null"
                rows="4"
                data-test="assignment-note"
            />
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="assignment-submit-button">{{ match ($assignmentMode) {
                'acknowledge' => __('projects.assignment_acknowledge'),
                default => __('projects.assignment_reject'),
            } }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    @include('pages.projects._story-chapters')

    @include('pages.projects._show-modals')

    @if ($this->canManageReportTemplate)
        @include('pages.projects._show-template-modals')
    @endif

    <x-ui.confirm-modal
        wire:model="showCloseModal"
        tone="neutral"
        :title="__('projects.close_project_title')"
        :message="__('projects.close_project_body')"
        confirm="closeProject"
        :confirm-label="__('projects.close_project')"
        confirm-test="confirm-close-project"
    />

    @php
        /* The project form's live duration hint (as on projects/index): plural forms with a count marker and month names in the reader's language. */
        $durationForms = [
            1 => trans_choice('projects.duration_days', 1, ['count' => '__N__']),
            2 => trans_choice('projects.duration_days', 2, ['count' => '__N__']),
            3 => trans_choice('projects.duration_days', 3, ['count' => '__N__']),
            11 => trans_choice('projects.duration_days', 11, ['count' => '__N__']),
        ];
        $monthNames = collect(range(1, 12))
            ->map(fn (int $month): string => \Carbon\CarbonImmutable::create(2000, $month, 1)->translatedFormat(__('formats.month_short')))
            ->all();
    @endphp
    @include('pages.projects._index-project-modal')

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
            <p class="dk-muted dk-small">{{ __('projects.kept_with_the_project') }}:
                @foreach ($this->archivingProject['carries'] as $carriedLabel => $carriedCount)
                    <span class="dk-num" wire:key="carried-{{ $loop->index }}">{{ $carriedCount }} {{ $carriedLabel }}</span>@if (! $loop->last) · @endif
                @endforeach
            </p>
        @endif
    </x-ui.confirm-modal>
</section>
