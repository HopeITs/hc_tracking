<?php

use App\Actions\Locations\CloseLocation;
use App\Actions\Targets\ScopeIndicatorToLocation;
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
use App\Models\HrCenter;
use App\Models\HrEmployee;
use App\Models\Program;
use App\Models\Project;
use App\Models\ProposalLocation;
use App\Support\Locations\LocationFromSlug;
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

    /**
     * The location row this page shows (Y-16, D-93). One entry on the
     * Projects screen is one row of one proposal.
     */
    #[Locked]
    public int $locationId;

    #[Url(except: 'tasks')]
    public string $tab = 'tasks';

    /**
     * The page's open section: overview, work, funding or files. Switched in
     * the browser (no round trip); resolved in mount() when the link names none.
     */
    #[Url(as: 'view', except: '')]
    public string $section = '';

    /** The owner assignment dialog: the owner acknowledges or rejects. */
    #[Url(as: 'respond', except: '')]
    public string $respond = '';

    /**
     * The URL slug is resolved here rather than by implicit binding, because
     * an old project slug must redirect to the row that took it over (D-94)
     * instead of 404ing.
     */
    public function mount(string $project): mixed
    {
        $resolver = app(LocationFromSlug::class);
        $row = $resolver->row($project);

        if ($row === null) {
            $successor = $resolver->successorOfProjectSlug($project);

            abort_if($successor === null, 404);

            return $this->redirectRoute('projects.show', [$successor, ...request()->query()], navigate: false);
        }

        $this->locationId = $row->id;

        $assignment = $row->currentOwnerAssignment();

        if ($assignment !== null && Auth::user() !== null && $assignment->status === AssignmentStatus::Pending && $assignment->isFor(Auth::user())) {
            $assignment->markSeen();

            if ($this->respond === 'reject') {
                $this->openAssignmentModal('reject');
            }
        }

        $this->respond = '';

        $this->section = $this->resolveSection($row);

        return null;
    }

    /**
     * The section a link opens on. An old `?tab=` link keeps its meaning
     * (files and activity, proposals, or a working tab); a link into a
     * proposal opens Funding; otherwise a running project opens on its work
     * and every other one on its overview.
     */
    private function resolveSection(ProposalLocation $row): string
    {
        if (in_array($this->section, ['overview', 'work', 'funding', 'files'], true)) {
            return $this->section;
        }

        return match (true) {
            in_array($this->tab, ['files', 'activity'], true) => 'files',
            $this->tab === 'proposals', $this->storyProposal !== '', request()->query('ptab') !== null => 'funding',
            $this->tab !== 'tasks' => 'work',
            $row->status === ProjectStatus::Active => 'work',
            default => 'overview',
        };
    }

    /** The Close Project confirmation: a flux:modal only opens from a boolean bound with wire:model. */
    public bool $showCloseModal = false;

    public function confirmClose(): void
    {
        Gate::authorize('update', $this->location);

        $this->showCloseModal = true;
    }

    /**
     * Close the active place (active → closed); the action enforces who may
     * and that it is still active (Y-17: the row is the writer).
     */
    public function closeProject(): void
    {
        try {
            app(CloseLocation::class)->handle($this->location);
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
        unset($this->location, $this->story, $this->attachedProposals, $this->fundingHistory, $this->planSummary, $this->canStartConceptNote, $this->embeddedProposalId);
    }

    /**
     * Scope a program-framework indicator to this project (T13); the action
     * checks the program and the committed targets again under lock.
     */
    public function scopeIndicator(int $indicatorId): void
    {
        Gate::authorize('indicators.update');

        app(ScopeIndicatorToLocation::class)->handle($this->location, $indicatorId);

        unset($this->projectIndicators);

        Flux::toast(variant: 'success', text: __('projects.indicator_scoped'));
    }

    /**
     * The location row this page reads, with everything it shows off it: the
     * status, the period, the calendar, the five beneficiary figures, the
     * description, its sectors, its files and its responsible's appointment
     * (all Y-11), and its place through `label()`.
     */
    #[Computed]
    public function location(): ProposalLocation
    {
        $hiddenCollections = $this->hiddenCollections();

        return ProposalLocation::query()
            ->with([
                // A row is of one proposal (D-93), so its funding is that one.
                'proposal:id,name,slug,status,donor_id',
                'city',
                'program:id,strategy_id',
                'program.strategy:id,name',
                'centers',
                'responsible:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
                'project:id,slug',
                'sectors' => fn ($query) => $query->select(['sectors.id', 'sectors.name_ar', 'sectors.name_en', 'sectors.is_active'])->ordered(),
                'ownerAssignment',
                'media' => fn ($query) => $query
                    ->select(['id', 'mediable_type', 'mediable_id', 'collection', 'original_name', 'type', 'size', 'uploaded_at', 'uploaded_by'])
                    ->whereNotIn('collection', $hiddenCollections)
                    ->with('uploader:id,hr_user_id,employee_id')
                    ->orderByDesc('uploaded_at')
                    ->orderByDesc('id'),
            ])
            ->withCount([
                'targets',
                'media' => fn ($query) => $query->whereNotIn('collection', $hiddenCollections),
            ])
            ->findOrFail($this->locationId);
    }

    /**
     * Raise a task straight against this project — the source is always this
     * project.
     *
     * @return array{type: string, id: int}
     */
    protected function quickTaskSource(): array
    {
        return ['type' => 'proposal_location', 'id' => $this->locationId];
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
        // A row may hold several centres (D-90); the header summarises the
        // first, and `label()` names them all. A field-work row has none.
        $centerId = $this->location->centerIds()[0] ?? null;

        if ($centerId === null) {
            return null;
        }

        try {
            $center = HrCenter::remembered($centerId);
        } catch (QueryException) {
            return ['id' => $centerId, 'name' => null, 'inactive' => false, 'unavailable' => true];
        }

        return ['id' => $centerId, 'name' => $center?->name, 'inactive' => $center !== null && ! $center->is_active, 'unavailable' => $center === null];
    }

    /**
     * The delivery chapter's tab strip. Proposals, files and activity sit in
     * the Story's side column, so an old `?tab=files` link shows tasks here.
     *
     * @return array<int, array{key: string, label: string, count: int|null}>
     */
    #[Computed]
    public function tabs(): array
    {
        $tabs = [
            ['key' => 'tasks', 'label' => __('common.tasks'), 'count' => $this->tasks->count()],
            ['key' => 'targets', 'label' => __('common.targets'), 'count' => $this->location->targets_count],
        ];

        if ($this->canViewReportTemplate) {
            array_splice($tabs, 5, 0, [['key' => 'template', 'label' => __('report_templates.tab.title'), 'count' => (int) $this->reportTemplate?->getAttribute('pending_lines_count') ?: null]]);
        }

        return $tabs;
    }

    #[Computed]
    public function deliveryTab(): string
    {
        return in_array($this->tab, ['tasks', 'targets', ...($this->canViewReportTemplate ? ['template'] : [])], true) ? $this->tab : 'tasks';
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
        icon="lock-closed"
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
        icon="archive-box"
        wire:model="showArchiveModal"
        close="cancelArchive"
        tone="neutral"
        :title="__('projects.archive_this_project')"
        :message="$this->archivingProject !== null ? __('projects.archiving_keeps_everything', ['name' => $this->archivingProject['project']->headline()]) : null"
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
