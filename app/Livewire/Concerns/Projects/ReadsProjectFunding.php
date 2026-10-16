<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Projects;

use App\Actions\Proposals\LinkProposalProject;
use App\Actions\Proposals\UnlinkProposalProject;
use App\Enums\Currency;
use App\Enums\ProposalPhase;
use App\Models\DonorProposal;
use App\Models\Project;
use App\Models\Proposal;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
     * The project's funding history (T10): every proposal across the years,
     * oldest first, with its donor, status and planned and actual years. A
     * renewal names the number it renews. Names, dates and statuses only: no
     * money, so it is the same for every viewer.
     *
     * @return Collection<int, Proposal>
     */
    #[Computed]
    public function fundingHistory(): Collection
    {
        return $this->project->proposals()
            ->select(['proposals.id', 'proposals.slug', 'proposals.name', 'proposals.internal_project_number', 'proposals.status', 'proposals.donor_id', 'proposals.previous_proposal_id', 'proposals.planned_date_begin', 'proposals.planned_date_end', 'proposals.actual_date_begin', 'proposals.actual_date_end'])
            ->with(['donor:id,name', 'previousProposal:id,internal_project_number'])
            ->orderBy('proposals.planned_date_begin')
            ->orderBy('proposals.id')
            ->get();
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
        return $this->project->fundingSources($this->canViewAgreements());
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
     * Proposals this project could be linked to. Linking is a proposal edit,
     * so the list is offered only to proposals.update holders, and only while
     * the project itself is linkable (Project::linkableToProposals()).
     *
     * @return Collection<int, Proposal>
     */
    #[Computed]
    public function attachableProposals(): Collection
    {
        if (! (auth()->user()?->can('proposals.update') ?? false)
            || ! Project::query()->linkableToProposals()->whereKey($this->projectId)->exists()) {
            return new Collection;
        }

        return Proposal::query()
            ->select(['id', 'name', 'internal_project_number', 'status'])
            ->whereDoesntHave('projects', fn ($query) => $query->whereKey($this->projectId))
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * Whether New Concept Note is offered: the viewer may create proposals
     * and see projects, and this project may take a proposal
     * (Project::linkableToProposals()). The proposal form checks both again.
     */
    #[Computed]
    public function canStartConceptNote(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->can('create', Proposal::class)
            && $user->can('projects.view')
            && Project::query()->linkableToProposals()->whereKey($this->projectId)->exists();
    }

    /**
     * Link the chosen proposal to this project through LinkProposalProject,
     * which authorizes on the proposal. A refusal is shown under the picker.
     */
    public function attachProposal(): void
    {
        $validated = $this->validate([
            'proposalToAttach' => ['required', 'integer', Rule::exists(Proposal::class, 'id')],
        ], [
            'proposalToAttach.required' => __('projects.select_a_proposal_to_link'),
            'proposalToAttach.integer' => __('common.the_selected_proposal_is_no_longer_available'),
            'proposalToAttach.exists' => __('common.the_selected_proposal_is_no_longer_available'),
        ]);

        try {
            app(LinkProposalProject::class)->handle(Proposal::query()->findOrFail((int) $validated['proposalToAttach']), $this->projectId);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['proposalToAttach' => $exception->validator->errors()->all()]);
        }

        $this->proposalToAttach = '';
        unset($this->project, $this->attachedProposals, $this->attachableProposals);

        Flux::toast(variant: 'success', text: __('projects.proposal_linked_to_this_project'));
    }

    /**
     * Unlink a proposal from this project through UnlinkProposalProject. A
     * refusal (its last project, reported targets) is shown as a toast and
     * kept in the error bag under `project`.
     */
    public function detachProposal(int $proposalId): void
    {
        try {
            app(UnlinkProposalProject::class)->handle(Proposal::query()->findOrFail($proposalId), $this->projectId);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
            Flux::toast(variant: 'danger', text: $exception->validator->errors()->first());

            return;
        }

        unset($this->project, $this->attachedProposals, $this->attachableProposals);

        Flux::toast(variant: 'success', text: __('projects.proposal_unlinked_from_this_project'));
    }
}
