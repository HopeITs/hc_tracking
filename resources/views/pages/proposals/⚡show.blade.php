<?php

use App\Livewire\Concerns\BuildsProposalPlanGantt;
use App\Livewire\Concerns\ChecksFinanceVisibility;
use App\Livewire\Concerns\ManagesAgreementForm;
use App\Livewire\Concerns\ManagesTransferForm;
use App\Livewire\Concerns\PreviewsFiles;
use App\Livewire\Concerns\Proposals\BuildsProposalHead;
use App\Livewire\Concerns\Proposals\ChartsProposalChronology;
use App\Livewire\Concerns\Proposals\DecidesProposalChange;
use App\Livewire\Concerns\Proposals\EditsProposalCard;
use App\Livewire\Concerns\Proposals\FillsProposalCenters;
use App\Livewire\Concerns\Proposals\FoldsProposalSections;
use App\Livewire\Concerns\Proposals\ManagesFinalReportAcceptance;
use App\Livewire\Concerns\Proposals\ManagesNegotiationNotes;
use App\Livewire\Concerns\Proposals\ManagesProposalApproval;
use App\Livewire\Concerns\Proposals\ManagesProposalFiles;
use App\Livewire\Concerns\Proposals\ManagesProposalExtension;
use App\Livewire\Concerns\Proposals\ManagesProposalForm;
use App\Livewire\Concerns\Proposals\ManagesProposalHold;
use App\Livewire\Concerns\Proposals\ManagesProposalPlan;
use App\Livewire\Concerns\Proposals\ManagesProposalRecord;
use App\Livewire\Concerns\Proposals\ManagesProposalReviewers;
use App\Livewire\Concerns\Proposals\ManagesProposalStatus;
use App\Livewire\Concerns\Proposals\ReadsProposalAttention;
use App\Livewire\Concerns\Proposals\ReadsProposalDelivery;
use App\Livewire\Concerns\Proposals\ReadsProposalFunding;
use App\Livewire\Concerns\Proposals\ReadsProposalHealth;
use App\Livewire\Concerns\Proposals\ReadsProposalVitals;
use App\Livewire\Concerns\Proposals\RequestsProposalChange;
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
    use BuildsProposalHead;
    use BuildsProposalPlanGantt;
    use ChartsProposalChronology;
    use ChecksFinanceVisibility;
    use DecidesProposalChange;
    use EditsProposalCard;
    use FillsProposalCenters;
    use FoldsProposalSections;
    use ManagesAgreementForm;
    use ManagesFinalReportAcceptance;
    use ManagesNegotiationNotes;
    use ManagesProposalApproval;
    use ManagesProposalExtension;
    use ManagesProposalFiles;
    use ManagesProposalForm;
    use ManagesProposalHold;
    use ManagesProposalPlan;
    use ManagesProposalRecord;
    use ManagesProposalReviewers;
    use ManagesProposalStatus;
    use ManagesTransferForm;
    use PreviewsFiles;
    use ReadsProposalAttention;
    use ReadsProposalDelivery;
    use ReadsProposalFunding;
    use ReadsProposalHealth;
    use ReadsProposalVitals;
    use RequestsProposalChange;
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
     * The tabs a payroll proposal offers (W-32): it has no projects, logframe,
     * targets, plan, deadlines or donor reports, so those tabs are absent.
     */
    private const PAYROLL_TABS = ['status', 'funding', 'documents'];

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
     * The activity log under the tabs is closed until asked for, so no tab
     * opens onto a long list.
     */
    public bool $showActivity = false;

    /**
     * Embedded on the project's Funding tab: only the lifecycle, the
     * attention list and the vitals in a grid. Picking a tab (from a warning,
     * a vital or "Show all details") opens the full proposal in place.
     */
    public bool $compact = false;

    /**
     * Deep link from the "Record agreement" notification action: opens the
     * agreement form once for a proposal that may take one, then clears itself.
     */
    #[Url(as: 'record_agreement', except: false)]
    public bool $recordAgreementLink = false;

    /**
     * Deep link from the "Missing a center" list: opens the Add centers
     * editor once for a proposal that may take it, then clears itself.
     */
    #[Url(as: 'add-centers', except: false)]
    public bool $addCentersLink = false;

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

        if ($this->addCentersLink && $this->canFillCenters) {
            $this->tab = 'projects';
            $this->openCenterFill();
        }

        $this->addCentersLink = false;

        $this->openExtensionFromLink();

        $this->openChangeRequestFromLink();
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

    /**
     * A tab picked in the compact view needs the tab panel, so the full
     * proposal opens in place. Runs on browser updates only, not in mount().
     */
    public function updatingTab(mixed $value = null): void
    {
        $this->compact = false;

        // On its own page a tab is a folded section now: picking one opens it.
        if (! $this->embedded && is_string($value)) {
            $key = array_search($value === 'tasks' ? 'plan' : $value, self::SECTION_TABS, true);

            if ($key !== false) {
                $this->openSection($key);
            }
        }
    }

    public function updatedTab(): void
    {
        if ($this->tab === 'tasks') {
            $this->tab = 'plan';
        }

        if ($this->tab === 'logframe' && ! $this->embedded) {
            // The framework lives in the proposal card now: an old ?ptab=logframe link scrolls to it.
            $this->tab = 'status';
            $this->dispatch('pp-flash', target: 'lf');
        }

        $offered = $this->proposal->isPayroll() ? self::PAYROLL_TABS : self::TABS;

        if (! in_array($this->tab, $offered, true) || ($this->tab === 'funding' && ! $this->canViewAgreements())) {
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
        if ($this->proposal->isPayroll()) {
            return [
                ['key' => 'status', 'label' => __('proposals.status_history'), 'count' => $this->statusNotes->count()],
                ...($this->canViewAgreements() ? [['key' => 'funding', 'label' => __('common.funding'), 'count' => $this->agreements->count()]] : []),
                ['key' => 'documents', 'label' => __('proposals.documents'), 'count' => $this->proposalFiles->count() + $this->projectFiles->count()],
            ];
        }

        return [
            ['key' => 'status', 'label' => __('proposals.status_history'), 'count' => $this->statusNotes->count()],
            ['key' => 'deadlines', 'label' => __('proposals.deadlines'), 'count' => count($this->deadlines)],
            ...($this->canViewAgreements() ? [['key' => 'funding', 'label' => __('common.funding'), 'count' => $this->agreements->count()]] : []),
            ['key' => 'projects', 'label' => __('common.projects'), 'count' => $this->projects->count()],
            // On its own page the framework sits in the proposal card; only the embedded view keeps the tab.
            ...($this->embedded ? [['key' => 'logframe', 'label' => __('proposals.tab_logframe'), 'count' => null]] : []),
            ['key' => 'targets', 'label' => __('proposals.tab_targets'), 'count' => $this->targets->count()],
            ['key' => 'plan', 'label' => __('proposals.tab_plan'), 'count' => $this->planTaskRows->count()],
            ['key' => 'reports', 'label' => __('proposals.donor_reports'), 'count' => $this->reports->count()],
            ['key' => 'documents', 'label' => __('proposals.documents'), 'count' => $this->proposalFiles->count() + $this->projectFiles->count()],
        ];
    }

    #[Computed]
    public function proposal(): Proposal
    {
        return Proposal::query()
            ->select(['id', 'name', 'internal_project_number', 'planned_date_begin', 'planned_date_end', 'actual_date_begin', 'actual_date_end', 'extended_date_end', 'extension_reason', 'status', 'kind', 'on_hold_since', 'status_remind_on', 'notes', 'concept_note', 'updated_at', 'created_at', 'donor_id', 'final_report_accepted_on', 'final_report_accepted_by', 'requested_budget', 'requested_currency'])
            // The donor's name heads the concept-note card (W-24); a name is not money.
            ->with('donor:id,name')
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
            ->select(['id', 'proposal_id', 'status', 'context', 'date', 'kind'])
            ->with('media')
            ->where('proposal_id', $this->proposalId)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The Plan tab's read-only Gantt of this proposal's tasks and activities.
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
            $this->projects, $this->locationRows, $this->linkableProjects, $this->tasks, $this->targets, $this->reports,
            $this->planTaskRows, $this->planGroups, $this->planGanttPayload, $this->planWindow,
            $this->projectFiles, $this->conceptNoteFiles, $this->documents, $this->deadlines, $this->warnings, $this->lifecycle, $this->chronology,
            $this->arrivals, $this->vitals, $this->term, $this->tabs, $this->proposalFiles, $this->reviewStages, $this->needAssessments, $this->approvalSubject, $this->approvalPreview, $this->approvalLocations,
            $this->canRecordFinalReportAcceptance, $this->finalReportAcceptance,
            $this->canFillCenters, $this->centerFillRows, $this->centerFillCentersByCity,
            $this->sections, $this->headActions, $this->attentionItems, $this->hiddenAttentionKeys, $this->cardDates, $this->donorChoices,
            $this->pendingChangeRequestId, $this->changeRequestHistory, $this->changeRequestHistoryLines, $this->decidingChangeRequest, $this->decidingChangeLines, $this->changeReviewSlot,
        );

        $this->attentionFrameworkMemo = null;
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

<section @class(['dossier @container/detail flex w-full flex-col text-secondary-dark dark:text-ink', 'pp-page' => ! $embedded]) @if ($embedded) data-embedded-proposal @endif x-on:pp-flash.window="const el = document.getElementById($event.detail.target); if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); el.classList.remove('pp-flash'); void el.offsetWidth; el.classList.add('pp-flash') }">
    @if ($embedded)
        <header @class(['flex flex-col gap-3 @3xl/detail:flex-row @3xl/detail:items-start @3xl/detail:justify-between', '@3xl/detail:justify-end' => $compact]) data-embedded-proposal-head>
            <div @class(['min-w-0', 'sr-only' => $compact])>
                <h4 class="text-xl font-bold leading-tight text-secondary-dark dark:text-ink" dir="auto">
                    {{ $this->proposal->name }}
                    @if (app(\App\Notifications\UnreadSubjects::class)->has(auth()->user(), 'proposal', $this->proposal->id))
                        <span class="inline-block size-2.5 rounded-full bg-primary align-middle dark:bg-ink" data-unread-dot title="{{ __('notifications.has_unread') }}"><span class="sr-only">{{ __('notifications.has_unread') }}</span></span>
                    @endif
                </h4>
                <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs {{ $mutedText }}">
                    @if ($this->proposal->isPayroll())
                        <x-ui.enum-badge :case="$this->proposal->kind" :aria-label="__('proposals.payroll_kind_badge')" data-test="payroll-kind-chip" />
                    @endif
                    <x-ui.project-number :number="$this->proposal->internal_project_number" kind="internal" label />
                    <span class="tabular-nums">{{ __('proposals.planned_range', ['begin' => $this->proposal->planned_date_begin?->translatedFormat(__('formats.date')), 'end' => $this->proposal->planned_date_end?->translatedFormat(__('formats.date'))]) }}</span>
                </p>
            </div>
            @include('pages.proposals._show-actions')
        </header>
    @else
        @include('pages.proposals._show-head')
    @endif

    @if ($embedded)
        @include('pages.proposals._show-lifecycle')
    @endif

    @if ($compact)
        {{-- Compact (project Funding tab): what needs attention beside the four readings, then the way in. --}}
        <div @class(['mt-6 grid gap-x-10 gap-y-6 @3xl/detail:items-start [&>*]:mt-0!', '@3xl/detail:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)]' => $this->warnings !== []]) data-compact-proposal>
            @include('pages.proposals._show-warnings')
            <div class="[&>[data-bench]]:mt-0! [&>[data-bench]]:@3xl/detail:grid-cols-2! [&_[data-vital]_span.text-4xl]:text-3xl! [&_[data-vital]_span.text-4xl]:@xl/detail:text-4xl! [&_[data-vital]_span.text-4xl]:@5xl/detail:text-4xl!">
                @include('pages.proposals._show-vitals')
            </div>
        </div>

        <div class="mt-6 flex flex-wrap items-center gap-2 border-t border-dashed border-secondary-soft pt-4 dark:border-line">
            <button type="button" class="dk-btn dk-sm dk-primary" wire:click="$set('tab', 'status')" data-show-all-details>{{ __('proposals.show_all_details') }}</button>
            <a class="dk-btn dk-sm" href="{{ route('proposals.show', $this->proposal) }}" wire:navigate>{{ __('proposals.open_proposal_page') }}<x-desk.icon name="arrow" class="dk-flip" /></a>
        </div>
    @else
        @if ($this->pendingChangeRequestId !== null)
            {{-- Y-09: a change request is waiting; nothing changes until the PR Manager decides. --}}
            <div class="mt-7 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-status-warn bg-status-warn-soft px-4 py-3 text-sm" role="status" data-test="change-pending-notice">
                <p class="flex min-w-0 flex-1 items-start gap-2 font-semibold text-status-warn">
                    <flux:icon.clock variant="micro" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <span>{{ __('proposals.change.pending_notice') }}</span>
                </p>
                <x-ui.button size="sm" icon="document-magnifying-glass" class="min-h-10 touch-manipulation" wire:click="openChangeRequestModal" data-test="change-pending-open">{{ __('proposals.change.open_button') }}</x-ui.button>
            </div>
        @endif

        @if ($embedded)
            @include('pages.proposals._show-renewal-chain')

            @include('pages.proposals._show-warnings')

            @include('pages.proposals._show-vitals')
        @else
            @include('pages.proposals._show-attention')

            @include('pages.proposals._show-card')
        @endif

        @if ($embedded)
            <div class="mt-8 flex justify-end">
                <button type="button" class="dk-btn dk-sm" wire:click="$set('compact', true)" data-hide-details>{{ __('proposals.hide_details') }}</button>
            </div>
        @endif

        @if ($embedded)
        <x-ui.relation-tabs :tabs="$this->tabs" :active="$tab" wire-target="tab" @class(['flex-nowrap! overflow-x-auto [scrollbar-width:none] *:shrink-0 @3xl/detail:flex-wrap!', 'mt-8' => ! $embedded, 'mt-2' => $embedded]) />

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
        @else
            @include('pages.proposals._show-more')
        @endif
    @endif

    @include('pages.proposals._show-modals')
</section>
