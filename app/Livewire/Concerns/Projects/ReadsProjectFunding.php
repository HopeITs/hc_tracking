<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Projects;

use App\Enums\Currency;
use App\Enums\ProposalPhase;
use App\Enums\ProposalStatus;
use App\Models\DonorProposal;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;

/**
 * The money and the linked proposals on projects/show: committed vs. received
 * funding, the proposals behind it, and linking / unlinking a proposal.
 *
 * Used by projects/show only. The page provides projectId and the #[Computed]
 * project(); canViewAgreements() comes from ChecksFinanceVisibility.
 *
 * @property int $locationId
 * @property-read ProposalLocation $location  #[Computed] location()
 * @property-read Collection<int, Proposal> $attachedProposals  #[Computed] attachedProposals()
 */
trait ReadsProjectFunding
{
    /**
     * Whether the viewer may see the money on this page: committed and
     * received figures need both the agreements (budgets) and the transfers
     * (amounts received).
     */
    protected function canViewMoney(): bool
    {
        return $this->canViewAgreements() && (auth()->user()?->can('transfers.view') ?? false);
    }

    /**
     * Committed vs. received money for this project, grouped by currency,
     * across every donor proposal signed against every linked proposal.
     * Empty unless the viewer holds both funding-agreements.view and
     * transfers.view.
     *
     * @return \Illuminate\Support\Collection<string, array{currency: Currency, committed: float, received: float}>
     */
    #[Computed]
    public function fundingByCurrency(): \Illuminate\Support\Collection
    {
        if (! $this->canViewMoney()) {
            return collect();
        }

        return DonorProposal::query()
            ->select(['id', 'proposal_id', 'currency', 'budget'])
            // A row is of one proposal (D-93), so its money is that one's.
            ->where('proposal_id', $this->location->proposal_id)
            ->with(['transfers:id,donor_proposal_id,net_amount_received'])
            ->get()
            ->groupBy(fn (DonorProposal $donorProposal) => $donorProposal->currency->value)
            ->toBase()
            ->map(fn (Collection $group) => [
                'currency' => $group->first()->currency,
                'committed' => (float) $group->sum('budget'),
                'received' => (float) $group->sum(fn (DonorProposal $donorProposal) => $donorProposal->transfers->sum('net_amount_received')),
            ]);
    }

    /**
     * Every proposal funding this project. Donor names always come along; the
     * agreements and transfers behind them (budgets, amounts received) are
     * eager loaded only for a viewer holding both funding-agreements.view and
     * transfers.view, so the connections view costs no extra queries per card.
     *
     * @return Collection<int, Proposal>
     */
    #[Computed]
    public function attachedProposals(): Collection
    {
        $canViewMoney = $this->canViewMoney();
        $viewer = Auth::user();
        $needsReviewerRows = $viewer?->employee_id !== null && ! $viewer->can('proposals.view');

        // A row is of exactly one proposal (D-93), so its funding is that one;
        // the shape stays a collection because the view lists it.
        return Proposal::query()
            ->whereKey($this->location->proposal_id)
            ->select(['proposals.id', 'proposals.name', 'proposals.internal_project_number', 'proposals.status', 'proposals.planned_date_begin', 'proposals.planned_date_end'])
            ->with('donorNames:id,name')
            ->when($needsReviewerRows, fn ($query) => $query->with(['reviewers' => fn ($query) => $query
                ->select(['id', 'proposal_id', 'employee_id'])
                ->where('employee_id', $viewer->employee_id),
            ]))
            ->when($canViewMoney, fn ($query) => $query->with(['donorProposals' => fn ($query) => $query
                ->select(['id', 'proposal_id', 'donor_id', 'currency', 'budget'])
                ->with('transfers:id,donor_proposal_id,net_amount_received'),
            ]))
            ->orderBy('proposals.name')
            ->orderBy('proposals.id')
            ->get();
    }

    /**
     * The place's funding history (T10): every proposal across the years,
     * oldest first, with its donor, status and planned and actual years. A
     * renewal names the number it renews. Names, dates and statuses only: no
     * money, so it is the same for every viewer.
     *
     * A place is of exactly one proposal (Y-16, D-93), but its history is
     * that proposal **and the renewal chain it sits in** — a grant renewed
     * for a third year is the same work at the same place, and the page is
     * where that is read. The chain is walked rather than joined: it is a
     * handful of rows, and `previous_proposal_id` is a single link each way.
     *
     * @return Collection<int, Proposal>
     */
    #[Computed]
    public function fundingHistory(): Collection
    {
        return Proposal::query()
            ->whereKey($this->renewalChainIds())
            ->select(['proposals.id', 'proposals.slug', 'proposals.name', 'proposals.internal_project_number', 'proposals.status', 'proposals.donor_id', 'proposals.previous_proposal_id', 'proposals.planned_date_begin', 'proposals.planned_date_end', 'proposals.actual_date_begin', 'proposals.actual_date_end'])
            ->with(['donor:id,name', 'previousProposal:id,internal_project_number'])
            ->orderBy('proposals.planned_date_begin')
            ->orderBy('proposals.id')
            ->get();
    }

    /**
     * Every proposal in the renewal chain the place's own proposal sits in:
     * the ones it renews, and the ones that renew it.
     *
     * Capped so a cycle in the data cannot spin: a chain of twenty renewals
     * is already beyond anything the organisation has filed.
     *
     * @return list<int>
     */
    private function renewalChainIds(): array
    {
        $ids = [];
        $cursor = (int) $this->location->proposal_id;

        while ($cursor > 0 && ! in_array($cursor, $ids, true) && count($ids) < 20) {
            $ids[] = $cursor;
            $cursor = (int) (Proposal::query()->whereKey($cursor)->toBase()->value('previous_proposal_id') ?? 0);
        }

        $frontier = $ids;

        while ($frontier !== [] && count($ids) < 20) {
            $next = array_values(array_diff(
                Proposal::query()->whereIn('previous_proposal_id', $frontier)->toBase()->pluck('id')->map(intval(...))->all(),
                $ids,
            ));

            $ids = array_merge($ids, $next);
            $frontier = $next;
        }

        return $ids;
    }

    /**
     * The proposals a donor has approved for this project, with their donors.
     * The agreement budgets are loaded only for a viewer holding
     * funding-agreements.view; everyone else gets names alone.
     *
     * @return Collection<int, Proposal>
     */
    #[Computed]
    public function fundingSources(): Collection
    {
        $statuses = array_values(array_filter(ProposalStatus::cases(), fn (ProposalStatus $status): bool => $status->isApprovedByDonor()));
        $columns = ['proposals.id', 'proposals.name', 'proposals.slug', 'proposals.status', 'proposals.donor_id'];

        return Proposal::query()
            ->whereKey($this->location->proposal_id)
            ->whereIn('proposals.status', $statuses)
            ->select($this->canViewAgreements() ? [...$columns, 'proposals.requested_budget', 'proposals.requested_currency'] : $columns)
            ->with('donor:id,name')
            ->get();
    }

    /**
     * Committed vs. received funding for one linked proposal, grouped by
     * currency — the same read as fundingByCurrency() but scoped to a single
     * proposal's already eager loaded agreements.
     *
     * @return \Illuminate\Support\Collection<string, array{currency: Currency, committed: float, received: float}>
     */
    protected function proposalFunding(Proposal $proposal): \Illuminate\Support\Collection
    {
        if (! $this->canViewMoney()) {
            return collect();
        }

        return $proposal->donorProposals
            ->groupBy(fn (DonorProposal $donorProposal) => $donorProposal->currency->value)
            ->toBase()
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
     * The donor and agreement counts are funding stats and stay zero unless
     * the viewer holds both funding-agreements.view and transfers.view.
     *
     * @return array{donors: int, agreements: int, underway: int}
     */
    #[Computed]
    public function proposalsSummary(): array
    {
        $agreements = $this->canViewMoney() ? $this->attachedProposals->flatMap->donorProposals : collect();

        return [
            'donors' => $agreements->pluck('donor_id')->unique()->count(),
            'agreements' => $agreements->count(),
            'underway' => $this->attachedProposals
                ->filter(fn (Proposal $proposal) => in_array($proposal->phase, [ProposalPhase::Implementation, ProposalPhase::Monitoring], true))
                ->count(),
        ];
    }

    /**
     * Whether New Concept Note is offered: the viewer may create proposals
     * and see the Projects screen. Y-17: the new proposal gets a row of its
     * own at this place (D-93), so there is no project to check for
     * linkability — a closed or archived place may still be proposed again.
     */
    #[Computed]
    public function canStartConceptNote(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->can('create', Proposal::class)
            && $user->can('projects.view');
    }
}
