<?php

use App\Actions\Projects\ChangeProjectStatus;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Livewire\Concerns\AddsQuickTask;
use App\Livewire\Concerns\BuildsProjectGantt;
use App\Livewire\Concerns\ChecksFinanceVisibility;
use App\Livewire\Concerns\EditsProjectGantt;
use App\Livewire\Concerns\Projects\ManagesProjectAssignment;
use App\Livewire\Concerns\Projects\ManagesProjectFiles;
use App\Livewire\Concerns\Projects\ReadsProjectDelivery;
use App\Livewire\Concerns\Projects\ReadsProjectFunding;
use App\Enums\AssignmentStatus;
use App\Models\Project;
use Flux\Flux;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('titles.project')] class extends Component {
    use AddsQuickTask;
    use BuildsProjectGantt;
    use ChecksFinanceVisibility;
    use EditsProjectGantt;
    use ManagesProjectAssignment;
    use ManagesProjectFiles;
    use ReadsProjectDelivery;
    use ReadsProjectFunding;
    use WithFileUploads;

    #[Locked]
    public int $projectId;

    #[Url(except: 'milestones')]
    public string $tab = 'milestones';

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
    }

    /**
     * Move the project to another status; the action enforces who may.
     */
    public function changeStatus(string $status): void
    {
        $target = ProjectStatus::tryFrom($status);

        if ($target === null) {
            return;
        }

        try {
            app(ChangeProjectStatus::class)->handle(Project::query()->findOrFail($this->projectId), $target);
        } catch (ValidationException $exception) {
            Flux::toast(variant: 'danger', text: $exception->validator->errors()->first());

            return;
        }

        unset($this->project);

        Flux::toast(variant: 'success', text: __('projects.status_changed', ['status' => $target->label()]));
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

<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink">
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
            @if ($this->centerSummary !== null)
                <span class="inline-flex items-center gap-1.5" data-test="project-center">
                    @if ($this->centerSummary['unavailable'])
                        <span>#{{ $this->centerSummary['id'] }} · {{ __('common.hr_unavailable') }}</span>
                    @else
                        <span>{{ $this->centerSummary['name'] }}</span>
                    @endif
                    @if ($this->centerSummary['inactive'])
                        <span class="inline-flex items-center gap-1 rounded-full bg-status-critical-soft px-2 text-[11px] font-bold text-status-critical">
                            <flux:icon name="no-symbol" variant="micro" class="size-3 shrink-0" aria-hidden="true" />
                            {{ __('projects.center_inactive') }}
                        </span>
                    @endif
                </span>
            @endif
            <a href="{{ route('programs.show', $this->project->program) }}" wire:navigate class="font-medium hover:underline">{{ $this->project->program->name }}</a>
            <span>{{ $this->project->program->strategy?->name ?? __('common.strategy_not_assigned') }}</span>
            <span>{{ __('common.updated_date', ['date' => $this->project->updated_at?->translatedFormat(__('formats.date'))]) }}</span>
        </x-slot:meta>

        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                @can('update', $this->project)
                    @php
                        $mayArchive = auth()->user()->can('delete', $this->project);
                        $currentStatus = $this->project->status;
                    @endphp
                    <flux:dropdown position="bottom" align="end">
                        <button type="button" class="inline-flex items-center gap-1 rounded-full focus-visible:outline-2 focus-visible:outline-primary" aria-label="{{ __('projects.change_status') }}" data-test="project-status-trigger">
                            <x-ui.enum-badge :case="$currentStatus" />
                            <flux:icon name="chevron-down" variant="micro" class="size-4 text-secondary-medium dark:text-ink-muted" />
                        </button>

                        <flux:menu>
                            @foreach (ProjectStatus::cases() as $statusOption)
                                @continue(! $mayArchive && $statusOption !== $currentStatus && ($statusOption === ProjectStatus::Archived || $currentStatus === ProjectStatus::Archived))
                                <flux:menu.item
                                    :icon="$statusOption === $currentStatus ? 'check' : null"
                                    wire:click="changeStatus('{{ $statusOption->value }}')"
                                    wire:key="project-status-option-{{ $statusOption->value }}"
                                    data-test="project-status-option-{{ $statusOption->value }}"
                                >{{ $statusOption->label() }}</flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                @else
                    <x-ui.enum-badge :case="$this->project->status" />
                @endcan
                <x-ui.employee-chip :employee="$this->project->user" class="rounded-lg border border-secondary-soft p-2.5 dark:border-line" />
                @if ($this->canStartConceptNote)
                    <x-ui.button tone="primary" size="sm" icon="document-plus" class="min-h-10 touch-manipulation" :href="route('proposals.index', ['for_project' => $this->project->id])" wire:navigate data-test="new-concept-note">{{ __('proposals.new_concept_note') }}</x-ui.button>
                @endif
            </div>
        </x-slot:actions>

        <x-slot:summary>
            <div class="flex flex-wrap items-center gap-4 rounded-xl border border-secondary-soft bg-board px-4 py-3 dark:border-line">
                <x-ui.progress-ring
                    size="lg"
                    :value="$this->milestoneProgressPercent"
                    :tone="$this->milestoneProgressPercent === null ? 'neutral' : ($this->milestoneProgressPercent >= 70 ? 'good' : ($this->milestoneProgressPercent >= 30 ? 'warn' : 'critical'))"
                    :label="__('projects.milestone_progress')"
                />
                <div class="min-w-0">
                    <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('projects.milestone_progress') }}</p>
                    <p class="mt-0.5 text-sm font-semibold text-secondary-dark dark:text-ink">
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

    <div class="grid gap-3 @2xl/detail:grid-cols-2 @5xl/detail:grid-cols-4">
        <x-ui.stat-tile :label="__('common.start_date')" :value="$this->project->start_date?->translatedFormat(__('formats.date')) ?? '—'" />
        <x-ui.stat-tile :label="__('projects.end_date')" :value="$this->project->end_date?->translatedFormat(__('formats.date')) ?? '—'" />
        <x-ui.stat-tile :label="__('projects.headline_target')" :value="$this->project->target_beneficiaries === null ? '—' : number_format($this->project->target_beneficiaries)" />
        <x-ui.stat-tile :label="__('projects.demographic_total')" :value="$this->project->targetDemographicTotal() === null ? '—' : number_format($this->project->targetDemographicTotal())" />
    </div>

    <div @class(['grid gap-4', '@5xl/detail:grid-cols-3' => $this->canViewMoney(), '@5xl/detail:grid-cols-2' => ! $this->canViewMoney()])>
        <x-ui.panel :title="__('common.description')">
            @if ($this->project->description === null)
                <p class="p-3.5 text-sm italic text-secondary-medium dark:text-ink-muted">{{ __('projects.no_description_recorded') }}</p>
            @else
                <p class="p-3.5 text-sm leading-6 text-secondary-medium dark:text-ink-muted">{{ $this->project->description }}</p>
            @endif
        </x-ui.panel>

        <x-ui.panel :title="__('projects.beneficiary_breakdown')" :subtitle="__('projects.reported_separately_from_the_headline_target')">
            <div class="flex flex-col gap-3 p-3.5">
                @php $demographicTotal = $this->project->targetDemographicTotal(); @endphp
                @foreach ([__('projects.men') => $this->project->target_men, __('projects.women') => $this->project->target_women, __('projects.boys') => $this->project->target_boys, __('projects.girls') => $this->project->target_girls] as $label => $value)
                    <div wire:key="project-demographic-{{ strtolower($label) }}">
                        <div class="flex items-baseline justify-between gap-2">
                            <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ $label }}</p>
                            <p class="text-sm font-semibold tabular-nums text-secondary-dark dark:text-ink">{{ $value === null ? '—' : number_format($value) }}</p>
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

        @if ($this->canViewMoney())
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
        @endif

        <x-ui.panel :title="__('projects.funding_sources')" :count="$this->fundingSources->count()">
            @if ($this->fundingSources->isEmpty())
                <x-ui.empty-state icon="building-library" :heading="__('projects.no_donor_approved_funding_yet')" :body="__('projects.funding_becomes_official_when_a_donor_approves_a_proposal')" />
            @else
                <ul class="flex flex-col divide-y divide-secondary-soft dark:divide-line" data-funding-sources>
                    @foreach ($this->fundingSources as $source)
                        <li class="flex items-start justify-between gap-3 p-3.5" wire:key="funding-source-{{ $source->id }}">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold text-secondary-dark dark:text-ink">{{ $source->donor->name }}</p>
                                <p class="truncate text-xs text-secondary-light">{{ $source->name }} · {{ $source->status->label() }}</p>
                            </div>
                            @if ($this->canViewAgreements() && $source->donorProposals->isNotEmpty())
                                @foreach ($source->donorProposals as $agreement)
                                    <span class="shrink-0 text-sm font-semibold tabular-nums" dir="ltr">{{ number_format((float) $agreement->budget, 2) }} {{ $agreement->currency->value }}</span>
                                @endforeach
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.panel>
    </div>

    <x-ui.relation-tabs :tabs="$this->tabs" :active="$tab" wire-target="tab" />

    @if ($tab === 'milestones')
        @include('pages.projects._show-milestones-tab')
    @elseif ($tab === 'tasks')
        @include('pages.projects._show-tasks-tab')
    @elseif ($tab === 'plan')
        @include('pages.projects._show-plan-tab')
    @elseif ($tab === 'risks')
        @include('pages.projects._show-risks-tab')
    @elseif ($tab === 'targets')
        @include('pages.projects._show-targets-tab')
    @elseif ($tab === 'files')
        @include('pages.projects._show-files-tab')
    @elseif ($tab === 'activity')
        @include('pages.projects._show-activity-tab')
    @else
        @include('pages.projects._show-proposals-tab')
    @endif

    @include('pages.projects._show-modals')
</section>
