<?php

use App\Actions\Projects\CloseProject;
use App\Actions\Targets\ScopeIndicatorToProject;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Livewire\Concerns\AddsQuickTask;
use App\Livewire\Concerns\BuildsProjectGantt;
use App\Livewire\Concerns\ChecksFinanceVisibility;
use App\Livewire\Concerns\EditsProjectGantt;
use App\Livewire\Concerns\Projects\ManagesProjectAssignment;
use App\Livewire\Concerns\Projects\ManagesProjectFiles;
use App\Livewire\Concerns\Projects\ManagesReportTemplate;
use App\Livewire\Concerns\Projects\ReadsProjectDelivery;
use App\Livewire\Concerns\Projects\ReadsProjectFunding;
use App\Livewire\Concerns\Projects\ReadsProjectStory;
use App\Enums\AssignmentStatus;
use App\Models\Project;
use Flux\Flux;
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
    use BuildsProjectGantt;
    use ChecksFinanceVisibility;
    use EditsProjectGantt;
    use ManagesProjectAssignment;
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

    /** The owner assignment dialog: the owner acknowledges or rejects. */
    #[Url(as: 'respond', except: '')]
    public string $respond = '';

    /** The Plan tab's proposal filter (T31, D-05): '' = every linked proposal's plan, else one proposal id. */
    #[Url(as: 'plan_proposal', except: '')]
    public string $planProposal = '';

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
        unset($this->project);

        Flux::toast(variant: 'success', text: __('projects.project_closed'));
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
     * The Plan tab's Gantt payload: fundraising, delivery and donor
     * obligations for this project as one WBS row list.
     *
     * @return array{rows: list<array<string, mixed>>, from: string, to: string, today: string}
     */
    #[Computed]
    public function ganttPayload(): array
    {
        return $this->projectGanttPayload($this->project, $this->ganttBaselineId, $this->planProposal === '' ? null : (int) $this->planProposal);
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
            ['key' => 'plan', 'label' => __('gantt.plan'), 'count' => null],
            ['key' => 'risks', 'label' => __('common.risks'), 'count' => $this->project->risks_count],
            ['key' => 'targets', 'label' => __('common.targets'), 'count' => $this->project->plan_indicators_count],
        ];

        if ($this->canViewReportTemplate) {
            array_splice($tabs, 5, 0, [['key' => 'template', 'label' => __('report_templates.tab.title'), 'count' => (int) $this->reportTemplate?->getAttribute('pending_lines_count') ?: null]]);
        }

        return $tabs;
    }

    #[Computed]
    public function deliveryTab(): string
    {
        $deliveryTabs = ['milestones', 'tasks', 'plan', 'risks', 'targets'];

        if ($this->canViewReportTemplate) {
            $deliveryTabs[] = 'template';
        }

        return in_array($this->tab, $deliveryTabs, true) ? $this->tab : 'milestones';
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
</section>
