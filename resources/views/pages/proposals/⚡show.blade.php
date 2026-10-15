<?php

use App\Livewire\Concerns\BuildsProposalPlanGantt;
use App\Livewire\Concerns\ChecksFinanceVisibility;
use App\Livewire\Concerns\ManagesAgreementForm;
use App\Livewire\Concerns\ManagesTransferForm;
use App\Livewire\Concerns\PreviewsFiles;
use App\Livewire\Concerns\Proposals\ChartsProposalChronology;
use App\Livewire\Concerns\Proposals\ManagesProposalApproval;
use App\Livewire\Concerns\Proposals\ManagesProposalFiles;
use App\Livewire\Concerns\Proposals\ManagesProposalPlan;
use App\Livewire\Concerns\Proposals\ManagesProposalForm;
use App\Livewire\Concerns\Proposals\ManagesProposalRecord;
use App\Livewire\Concerns\Proposals\ManagesProposalReviewers;
use App\Livewire\Concerns\Proposals\ManagesProposalStatus;
use App\Livewire\Concerns\Proposals\ReadsProposalDelivery;
use App\Livewire\Concerns\Proposals\ReadsProposalFunding;
use App\Livewire\Concerns\Proposals\ReadsProposalHealth;
use App\Livewire\Concerns\Proposals\ReadsProposalVitals;
use App\Livewire\Concerns\SwitchesFundingView;
use App\Models\DonorProposal;
use App\Models\Media;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\TransfersTracking;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('titles.proposal')] class extends Component
{
    use BuildsProposalPlanGantt;
    use ChartsProposalChronology;
    use ChecksFinanceVisibility;
    use ManagesAgreementForm;
    use ManagesProposalFiles;
    use ManagesProposalPlan;
    use ManagesProposalForm;
    use ManagesProposalRecord;
    use ManagesProposalReviewers;
    use ManagesProposalApproval;
    use ManagesProposalStatus;
    use ManagesTransferForm;
    use PreviewsFiles;
    use ReadsProposalDelivery;
    use ReadsProposalFunding;
    use ReadsProposalHealth;
    use ReadsProposalVitals;
    use SwitchesFundingView;
    use WithFileUploads;

    #[Locked]
    public int $proposalId;

    /**
     * The tabs this page offers, in strip order. Logical Framework (T12),
     * Indicators & Targets (T13) and Plan (T31) each render their own
     * `_tab-*` partial, so those tasks never edit this file's lines. The
     * Plan tab took over the old Tasks tab; `?tab=tasks` lands on it.
     */
    private const TABS = ['status', 'deadlines', 'funding', 'projects', 'logframe', 'targets', 'plan', 'reports', 'documents'];

    /**
     * Which tab's content is shown beneath the fixed header.
     *
     * Round-trips through the query string, so an unknown value from a stale
     * bookmark falls back to the first tab rather than rendering nothing.
     */
    #[Url(except: 'status')]
    public string $tab = 'status';

    /**
     * Deep link from the "Record agreement" notification action: opens the
     * agreement form once for a proposal that may take one, then clears itself.
     */
    #[Url(as: 'record_agreement', except: false)]
    public bool $recordAgreementLink = false;

    public function mount(Proposal $proposal): void
    {
        $this->proposalId = $proposal->id;
        $this->updatedTab();

        if ($this->recordAgreementLink && $this->canRecordAgreement) {
            $this->openAgreementCreate();
        }

        $this->recordAgreementLink = false;
    }

    /**
     * Whether the Record Agreement button applies: the viewer may record
     * agreements, a donor approved this proposal and it has none yet. Asks
     * the database for existence only, never an amount.
     */
    #[Computed]
    public function canRecordAgreement(): bool
    {
        return auth()->user()?->can('funding-agreements.create') === true
            && $this->proposal->status->isApprovedByDonor()
            && ! DonorProposal::query()->where('proposal_id', $this->proposalId)->exists();
    }

    public function updatedTab(): void
    {
        if ($this->tab === 'tasks') {
            $this->tab = 'plan';
        }

        if (! in_array($this->tab, self::TABS, true) || ($this->tab === 'funding' && ! $this->canViewAgreements())) {
            $this->tab = 'status';
        }
    }

    /**
     * The tab strip, each tab counting the records it holds.
     *
     * Counts come from the same collections the tabs render, so a badge can
     * never disagree with the list beneath it.
     *
     * @return array<int, array{key: string, label: string, count: int|null}>
     */
    #[Computed]
    public function tabs(): array
    {
        return [
            ['key' => 'status', 'label' => __('proposals.status_history'), 'count' => $this->statusNotes->count()],
            ['key' => 'deadlines', 'label' => __('proposals.deadlines'), 'count' => count($this->deadlines)],
            ...($this->canViewAgreements() ? [['key' => 'funding', 'label' => __('common.funding'), 'count' => $this->agreements->count()]] : []),
            ['key' => 'projects', 'label' => __('common.projects'), 'count' => $this->projects->count()],
            ['key' => 'logframe', 'label' => __('proposals.tab_logframe'), 'count' => null],
            ['key' => 'targets', 'label' => __('proposals.tab_targets'), 'count' => $this->targets->count()],
            ['key' => 'plan', 'label' => __('proposals.tab_plan'), 'count' => count($this->planMilestones) + $this->planTaskRows->count()],
            ['key' => 'reports', 'label' => __('proposals.donor_reports'), 'count' => $this->reports->count()],
            ['key' => 'documents', 'label' => __('proposals.documents'), 'count' => $this->proposalFiles->count() + $this->projectFiles->count()],
        ];
    }

    #[Computed]
    public function proposal(): Proposal
    {
        return Proposal::query()
            ->select(['id', 'name', 'internal_project_number', 'planned_date_begin', 'planned_date_end', 'actual_date_begin', 'actual_date_end', 'status', 'notes', 'updated_at'])
            ->findOrFail($this->proposalId);
    }

    /** @return Collection<int, ProposalStatusNote> */
    #[Computed]
    public function statusNotes(): Collection
    {
        return ProposalStatusNote::query()
            ->select(['id', 'proposal_id', 'status', 'context', 'date'])
            ->where('proposal_id', $this->proposalId)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The Plan tab's read-only Gantt of this proposal's milestones, tasks
     * and activities.
     *
     * @return array{rows: list<array<string, mixed>>, links: list<array<string, mixed>>, from: string, to: string, today: string}
     */
    #[Computed]
    public function planGanttPayload(): array
    {
        return $this->proposalPlanGanttPayload($this->proposal);
    }

    /**
     * The documents the viewer steps through: the proposal's own files, then
     * its funded projects' grant paperwork.
     *
     * @return Collection<int, Media>
     */
    protected function previewableFiles(): Collection
    {
        return $this->documents;
    }

    /**
     * Opens nothing for an id that is not one of this tab's documents.
     */
    protected function previewableFileId(int $fileId): ?int
    {
        return $this->documents->firstWhere('id', $fileId)?->id;
    }

    protected function agreementProposalScope(): ?int
    {
        return $this->proposalId;
    }

    protected function proposalFormScope(): ?int
    {
        return $this->proposalId;
    }

    protected function proposalSaved(Proposal $proposal, bool $wasCreated): void
    {
        $this->forgetReadings();
    }

    protected function transferProposalScope(): ?int
    {
        return $this->proposalId;
    }

    protected function agreementSaved(DonorProposal $agreement, bool $isCreate): void
    {
        $this->forgetReadings();
    }

    protected function transferSaved(TransfersTracking $transfer, bool $isCreate): void
    {
        $this->forgetReadings();
    }

    protected function agreementDeleted(int $agreementId): void
    {
        $this->forgetReadings();
    }

    protected function transferDeleted(int $transferId): void
    {
        $this->forgetReadings();
    }

    /**
     * Drop every memoised reading so the next render recomputes from the
     * database. Everything on this page derives from these, so a write
     * anywhere clears them all rather than guessing which ones it touched.
     */
    private function forgetReadings(): void
    {
        unset(
            $this->proposal, $this->canRecordAgreement, $this->statusNotes, $this->agreements, $this->agreementRows, $this->fundingTranches, $this->donorReports, $this->agreementMoney, $this->moneyByCurrency,
            $this->projects, $this->linkableProjects, $this->tasks, $this->targets, $this->reports,
            $this->planMilestones, $this->planTaskRows, $this->planGroups, $this->planGanttPayload, $this->planWindow, $this->planProjectOptions,
            $this->projectFiles, $this->documents, $this->deadlines, $this->warnings, $this->lifecycle, $this->chronology,
            $this->arrivals, $this->vitals, $this->term, $this->tabs, $this->proposalFiles, $this->reviewStages, $this->needAssessments, $this->approvalSubject, $this->approvalPreview,
        );
    }
};
?>

@php
    $mutedText = 'text-secondary-medium dark:text-ink-muted';
    $inkText = 'text-secondary-dark dark:text-ink';
    $hairline = 'border-secondary-soft dark:border-line';
    $amount = fn (float $value): string => number_format($value, 2);
    $term = $this->term;
    $chronology = $this->chronology;
    $arrivals = $this->arrivals;
@endphp

<section class="dossier @container/detail flex min-h-full w-full flex-1 flex-col bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink">
    <x-ui.detail-header
        :breadcrumbs="[
            ['label' => __('common.proposals'), 'url' => route('proposals.index')],
            ['label' => $this->proposal->name],
        ]"
        :title="$this->proposal->name"
        :unread="app(\App\Notifications\UnreadSubjects::class)->has(auth()->user(), 'proposal', $this->proposal->id)"
        title-class="text-3xl! font-bold! leading-none! tracking-tight! @3xl/detail:text-[2.5rem]!"
    >
        <x-slot:meta>
            <x-ui.project-number :number="$this->proposal->internal_project_number" kind="internal" label />
            <span class="tabular-nums">{{ __('proposals.planned_range', ['begin' => $this->proposal->planned_date_begin?->translatedFormat(__('formats.date')), 'end' => $this->proposal->planned_date_end?->translatedFormat(__('formats.date'))]) }}</span>
            @if ($this->proposal->actual_date_begin !== null)
                <span class="tabular-nums">{{ __('proposals.actual_begin', ['begin' => $this->proposal->actual_date_begin->translatedFormat(__('formats.date'))]) }}</span>
            @endif
        </x-slot:meta>

        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2 @3xl/detail:shrink-0 @3xl/detail:flex-nowrap">
                <x-ui.enum-badge :case="$this->proposal->status" />
                <span class="rounded-md border border-secondary-light px-2 py-0.5 text-[11px] font-semibold text-secondary-medium dark:text-ink-muted">
                    {{ $this->proposal->phase->label() }}
                </span>
                @can('update', $this->proposal)
                <x-ui.button size="sm" icon="pencil-square" class="min-h-10 touch-manipulation" wire:click="openEditModal({{ $this->proposal->id }})" data-test="open-details-modal">{{ __('proposals.edit_details') }}</x-ui.button>
                @can('approve', $this->proposal)
                @if ($this->proposal->canBeApproved())
                <x-ui.button tone="primary" size="sm" icon="check-badge" class="min-h-10 touch-manipulation" wire:click="openApproveModal({{ $this->proposal->id }})" data-test="open-approve-modal">{{ __('proposals.approve_proposal') }}</x-ui.button>
                @endif
                @endcan
                @if ($this->proposal->transitionTargets() !== [])
                <x-ui.button tone="primary" size="sm" icon="arrow-path" class="min-h-10 touch-manipulation" wire:click="openStatusModal({{ $this->proposal->id }})" data-test="open-status-modal">{{ __('proposals.change_status') }}</x-ui.button>
                @endif
                @endcan
                @if ($this->canRecordAgreement)
                <x-ui.button size="sm" icon="banknotes" class="min-h-10 touch-manipulation" wire:click="openAgreementCreate" data-test="record-agreement-button">{{ __('funding_agreements.record_agreement') }}</x-ui.button>
                @endif
                @can('correctStatus', $this->proposal)
                @if ($this->proposal->status->isTerminal())
                <x-ui.button size="sm" icon="arrow-uturn-left" class="min-h-10 touch-manipulation" wire:click="openCorrectModal({{ $this->proposal->id }})" data-test="open-correct-status-modal">{{ __('proposals.correct_status') }}</x-ui.button>
                @endif
                @endcan
            </div>
        </x-slot:actions>
    </x-ui.detail-header>

    @include('pages.proposals._show-lifecycle')

    @include('pages.proposals._show-warnings')

    @include('pages.proposals._show-vitals')

    <x-ui.relation-tabs :tabs="$this->tabs" :active="$tab" wire-target="tab" class="mt-8 flex-nowrap! overflow-x-auto [scrollbar-width:none] *:shrink-0 @3xl/detail:flex-wrap!" />

    <div id="tab-panel" class="mt-6 min-w-0" wire:key="tab-{{ $tab }}" data-tab-panel="{{ $tab }}">
        @if ($tab === 'funding')
            @include('pages.proposals._show-funding-tab')

        @elseif ($tab === 'projects')
            @include('pages.proposals._show-projects-tab')
        @elseif ($tab === 'logframe')
            @include('pages.proposals._tab-logframe')
        @elseif ($tab === 'targets')
            @include('pages.proposals._tab-targets')
        @elseif ($tab === 'plan')
            @include('pages.proposals._tab-plan')
        @elseif ($tab === 'deadlines')
            @include('pages.proposals._show-deadlines-tab')
        @elseif ($tab === 'reports')
            @include('pages.proposals._show-reports-tab')
        @elseif ($tab === 'documents')
            @include('pages.proposals._show-documents-tab')
        @else
            @include('pages.proposals._show-status-tab')
        @endif
    </div>

    <section aria-labelledby="record-activity-heading" class="grid gap-3 mt-10" data-record-activity>
        <h2 id="record-activity-heading" class="text-base font-bold">{{ __('activity.title') }}</h2>
        <livewire:activity.timeline :subjects="['proposal' => [$this->proposal->id]]" :key="'proposal-activity-'.$this->proposal->id" />
    </section>

    @include('pages.proposals._show-modals')
</section>
