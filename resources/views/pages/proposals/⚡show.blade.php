<?php

use App\Livewire\Concerns\BuildsProposalPlanGantt;
use App\Livewire\Concerns\ChecksFinanceVisibility;
use App\Livewire\Concerns\ManagesAgreementForm;
use App\Livewire\Concerns\ManagesTransferForm;
use App\Livewire\Concerns\PreviewsFiles;
use App\Livewire\Concerns\Proposals\ChartsProposalChronology;
use App\Livewire\Concerns\Proposals\ManagesProposalApproval;
use App\Livewire\Concerns\Proposals\ManagesProposalFiles;
use App\Livewire\Concerns\Proposals\ManagesProposalForm;
use App\Livewire\Concerns\Proposals\ManagesProposalPlan;
use App\Livewire\Concerns\Proposals\ManagesProposalRecord;
use App\Livewire\Concerns\Proposals\ManagesProposalReviewers;
use App\Livewire\Concerns\Proposals\ManagesProposalStatus;
use App\Livewire\Concerns\Proposals\ReadsProposalDelivery;
use App\Livewire\Concerns\Proposals\ReadsProposalFunding;
use App\Livewire\Concerns\Proposals\ReadsProposalHealth;
use App\Livewire\Concerns\Proposals\ReadsProposalVitals;
use App\Livewire\Concerns\SwitchesFundingView;
use App\Models\Activity;
use App\Models\DonorProposal;
use App\Models\Media;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\TransfersTracking;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts::desk'), Title('titles.proposal')] class extends Component
{
    use BuildsProposalPlanGantt;
    use ChartsProposalChronology;
    use ChecksFinanceVisibility;
    use ManagesAgreementForm;
    use ManagesProposalApproval;
    use ManagesProposalFiles;
    use ManagesProposalForm;
    use ManagesProposalPlan;
    use ManagesProposalRecord;
    use ManagesProposalReviewers;
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
     * Round-trips through the query string as ?ptab= (so it never collides
     * with the project Story's own ?tab= when embedded there); an unknown
     * value from a stale bookmark falls back to the first tab.
     */
    #[Url(as: 'ptab', except: 'status')]
    public string $tab = 'status';

    /**
     * Rendered inside the project Story's Proposal block (Tracking Desk
     * stage 4) rather than as its own page: no back link, breadcrumbs or
     * page title, the actions in a compact head.
     */
    #[Locked]
    public bool $embedded = false;

    /**
     * Deep link from the "Record agreement" notification action: opens the
     * agreement form once for a proposal that may take one, then clears itself.
     */
    #[Url(as: 'record_agreement', except: false)]
    public bool $recordAgreementLink = false;

    public function mount(Proposal $proposal): void
    {
        $this->proposalId = $proposal->id;

        $legacyTab = request()->query('tab');

        if (! $this->embedded && $this->tab === 'status' && is_string($legacyTab) && $legacyTab !== '') {
            $this->tab = $legacyTab;
        }

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

    /**
     * The renewal chain around this proposal (T10): the proposal it renews,
     * the ones that renew it, and whether its predecessor was removed (the
     * link clears when the predecessor is deleted; the creation activity still
     * says it renewed one). Names, numbers and statuses only.
     *
     * @return array{previous: ?Proposal, removed: bool, next: Collection<int, Proposal>}
     */
    #[Computed]
    public function renewalChain(): array
    {
        $previousId = Proposal::query()->whereKey($this->proposalId)->value('previous_proposal_id');
        $previous = $previousId === null
            ? null
            : Proposal::query()->select(['id', 'slug', 'name', 'internal_project_number', 'status'])->find($previousId);

        $removed = $previous === null && Activity::query()
            ->where('event', 'proposal.created')
            ->where('subject_type', 'proposal')
            ->where('subject_id', $this->proposalId)
            ->get(['id', 'properties'])
            ->contains(fn (Activity $activity): bool => isset($activity->properties['renewed_from']));

        return [
            'previous' => $previous,
            'removed' => $removed,
            'next' => Proposal::query()
                ->select(['id', 'slug', 'name', 'internal_project_number', 'status'])
                ->where('previous_proposal_id', $this->proposalId)
                ->orderBy('id')
                ->get(),
        ];
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

<section @class(['dossier @container/detail flex w-full flex-col text-secondary-dark dark:text-ink', 'min-h-full flex-1 bg-white dark:bg-surface-0' => ! $embedded]) @if ($embedded) data-embedded-proposal @endif>
    @if ($embedded)
        <header class="flex flex-col gap-3 @3xl/detail:flex-row @3xl/detail:items-start @3xl/detail:justify-between" data-embedded-proposal-head>
            <div class="min-w-0">
                <h4 class="text-xl font-bold leading-tight text-secondary-dark dark:text-ink" dir="auto">
                    {{ $this->proposal->name }}
                    @if (app(\App\Notifications\UnreadSubjects::class)->has(auth()->user(), 'proposal', $this->proposal->id))
                        <span class="inline-block size-2.5 rounded-full bg-primary align-middle dark:bg-ink" data-unread-dot title="{{ __('notifications.has_unread') }}"><span class="sr-only">{{ __('notifications.has_unread') }}</span></span>
                    @endif
                </h4>
                <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs {{ $mutedText }}">
                    <x-ui.project-number :number="$this->proposal->internal_project_number" kind="internal" label />
                    <span class="tabular-nums">{{ __('proposals.planned_range', ['begin' => $this->proposal->planned_date_begin?->translatedFormat(__('formats.date')), 'end' => $this->proposal->planned_date_end?->translatedFormat(__('formats.date'))]) }}</span>
                </p>
            </div>
            @include('pages.proposals._show-actions')
        </header>
    @else
        <a class="dk-back" href="{{ route('dashboard') }}" wire:navigate data-proposal-back><x-desk.icon name="back" class="dk-flip" />{{ __('desk.your_desk') }}</a>
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
                @include('pages.proposals._show-actions')
            </x-slot:actions>
        </x-ui.detail-header>
    @endif

    @include('pages.proposals._show-lifecycle')

    @include('pages.proposals._show-renewal-chain')

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
