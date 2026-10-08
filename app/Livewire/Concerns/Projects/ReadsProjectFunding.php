<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Projects;

use App\Enums\Currency;
use App\Enums\ProposalPhase;
use App\Models\DonorProposal;
use App\Models\Project;
use App\Models\Proposal;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;

/**
 * The money and the linked proposals on projects/show: committed vs. received
 * funding, the proposals behind it, and linking / unlinking a proposal.
 *
 * Used by projects/show only. The page provides projectId and the #[Computed]
 * project(); canViewAgreements() comes from ChecksFinanceVisibility.
 *
 * @property int $projectId
 * @property-read Project $project  #[Computed] project()
 * @property-read Collection<int, Proposal> $attachedProposals  #[Computed] attachedProposals()
 */
trait ReadsProjectFunding
{
    public string $proposalToAttach = '';

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

        return $this->project->proposals()
            ->select(['proposals.id', 'proposals.name', 'proposals.internal_project_number', 'proposals.status', 'proposals.planned_date_begin', 'proposals.planned_date_end'])
            ->with('donorNames:id,name')
            ->when($canViewMoney, fn ($query) => $query->with(['donorProposals' => fn ($query) => $query
                ->select(['id', 'proposal_id', 'donor_id', 'currency', 'budget'])
                ->with('transfers:id,donor_proposal_id,net_amount_received'),
            ]))
            ->orderBy('proposals.name')
            ->orderBy('proposals.id')
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

    /** @return Collection<int, Proposal> */
    #[Computed]
    public function attachableProposals(): Collection
    {
        return Proposal::query()
            ->select(['id', 'name', 'internal_project_number', 'status'])
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
}
