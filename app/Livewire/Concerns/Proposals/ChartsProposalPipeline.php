<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Enums\ArStatus;
use App\Enums\ProposalPhase;
use App\Enums\ProposalStatus;
use App\Models\DonorProposal;
use App\Models\Proposal;
use App\Models\TransfersTracking;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * The Overview view's figures: funding position per currency, the pipeline
 * rail, the donor to proposal to project flow, and one agreement's money
 * split. Every figure is computed over the proposals the filters match.
 *
 * Used by proposals/index only. The page uses ChecksFinanceVisibility;
 * filteredQuery() and filteredProposalIds() come from ReadsProposalRegister.
 *
 * @property-read Collection<int, int> $filteredProposalIds  #[Computed] filteredProposalIds()
 */
trait ChartsProposalPipeline
{
    /**
     * Money committed, received and still pending, per currency, across every
     * proposal the filters match (not only the visible page).
     *
     * Same definitions as the dashboard's funding position: received is net
     * money in its received currency, pending is the scheduled payment in its
     * transfer currency. Currencies are never added together.
     *
     * @return Collection<int, array{currency: string, committed: float, received: float, pending: float, pendingCount: int, unscheduled: float|int}>
     */
    #[Computed]
    public function fundingPosition(): Collection
    {
        if (! $this->canViewAgreements()) {
            return new Collection;
        }

        $ids = $this->filteredProposalIds;
        $canTransfers = $this->canViewTransfers();

        $committed = DonorProposal::query()
            ->whereIn('proposal_id', $ids)
            ->selectRaw('currency, sum(budget) as total')
            ->groupBy('currency')
            ->pluck('total', 'currency');

        $transfers = fn () => TransfersTracking::query()
            ->whereHas('donorProposal', fn (Builder $query) => $query->whereIn('proposal_id', $ids));

        $received = ! $canTransfers ? collect() : $transfers()
            ->where('ar_status', ArStatus::Received)
            ->selectRaw('amount_received_currency as currency, sum(net_amount_received) as total')
            ->groupBy('amount_received_currency')
            ->pluck('total', 'currency');

        $pending = ! $canTransfers ? collect() : $transfers()
            ->where('ar_status', ArStatus::Pending)
            ->selectRaw('amount_transfered_currency as currency, sum(payment_amount) as total, count(*) as transfers')
            ->groupBy('amount_transfered_currency')
            ->get()
            ->keyBy('currency');

        // What the tranches sent in each agreement's own currency already
        // cover of its budget, whatever became of them: fees, a missing net
        // amount or a failed transfer never turn money back into unscheduled.
        $scheduled = ! $canTransfers ? collect() : TransfersTracking::query()
            ->join('donor_proposal', 'donor_proposal.id', '=', 'transfers_tracking.donor_proposal_id')
            ->whereIn('donor_proposal.proposal_id', $ids)
            ->whereColumn('transfers_tracking.amount_transfered_currency', 'donor_proposal.currency')
            ->selectRaw('donor_proposal.currency as currency, sum(transfers_tracking.payment_amount) as total')
            ->groupBy('donor_proposal.currency')
            ->pluck('total', 'currency');

        return $committed->keys()
            ->concat($received->keys())
            ->concat($pending->keys())
            ->map(fn ($currency): string => (string) $currency)
            ->unique()
            ->sort()
            ->values()
            ->map(function (string $currency) use ($committed, $received, $pending, $scheduled): array {
                $committedTotal = (float) ($committed[$currency] ?? 0);
                $receivedTotal = (float) ($received[$currency] ?? 0);
                $pendingTotal = (float) ($pending[$currency]->total ?? 0);

                return [
                    'currency' => $currency,
                    'committed' => $committedTotal,
                    'received' => $receivedTotal,
                    'pending' => $pendingTotal,
                    'pendingCount' => (int) ($pending[$currency]->transfers ?? 0),
                    'unscheduled' => max(0, $committedTotal - (float) ($scheduled[$currency] ?? 0)),
                ];
            });
    }

    /**
     * The five phases with how many proposals sit in each and what they hold.
     *
     * Ignores the phase filter itself so every stop stays selectable.
     *
     * @return list<array{phase: ProposalPhase, count: int, rejected: int, money: array<string, float>}>
     */
    #[Computed]
    public function pipeline(): array
    {
        $rows = ! $this->canViewAgreements() ? collect() : $this->filteredQuery(withPhase: false)
            ->leftJoin('donor_proposal', 'donor_proposal.proposal_id', '=', 'proposals.id')
            ->selectRaw('proposals.status, donor_proposal.currency, count(distinct proposals.id) as proposals, sum(donor_proposal.budget) as budget')
            ->groupBy('proposals.status', 'donor_proposal.currency')
            ->get();

        $counts = $this->filteredQuery(withPhase: false)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return array_map(function (ProposalPhase $phase) use ($rows, $counts): array {
            $statuses = array_map(fn (ProposalStatus $status): string => $status->value, $phase->statuses());

            $money = $rows
                ->filter(fn ($row): bool => in_array($row->status instanceof ProposalStatus ? $row->status->value : $row->status, $statuses, true) && $row->currency !== null)
                ->groupBy(fn ($row): string => (string) $row->currency)
                ->map(fn (Collection $group): float => (float) $group->sum('budget'))
                ->sortKeys()
                ->all();

            return [
                'phase' => $phase,
                'count' => (int) collect($statuses)->sum(fn (string $status): int => (int) ($counts[$status] ?? 0)),
                'rejected' => $phase === ProposalPhase::Implementation ? (int) ($counts[ProposalStatus::Rejected->value] ?? 0) : 0,
                'money' => $money,
            ];
        }, ProposalPhase::cases());
    }

    /**
     * The donor → proposal → project graph behind the funding flow.
     *
     * One graph per currency, because ribbon widths are budgets and budgets in
     * different currencies cannot share a scale. Proposal → project links carry
     * no money (the system does not split a budget across projects), so they
     * are drawn as threads, not ribbons.
     *
     * @return array{currencies: array<int, array{currency: string, total: float, donors: array<int, array{key: string, id: int, name: string, total: float, agreements: int}>, proposals: array<int, array{key: string, id: int, name: string, total: float, status: ProposalStatus}>, projects: array<int, array{key: string, id: int, name: string}>, graph: array{order: array<int, string>, money: array<int, array{from: string, to: string, amount: float}>, links: array<int, array{from: string, to: string}>}}>, unfunded: array<int, array{id: int, name: string}>}
     */
    #[Computed]
    public function flow(): array
    {
        if (! $this->canViewAgreements()) {
            return ['currencies' => [], 'unfunded' => []];
        }

        $proposals = Proposal::query()
            ->whereIn('id', $this->filteredProposalIds)
            ->select(['id', 'name', 'status', 'planned_date_begin'])
            ->with([
                'donorProposals:id,proposal_id,donor_id,budget,currency',
                'donorProposals.donor:id,name',
                'projects' => fn ($query) => $query->select(['projects.id', 'projects.name'])->orderBy('projects.name'),
            ])
            ->orderByDesc('planned_date_begin')
            ->orderBy('id')
            ->get();

        $currencies = $proposals
            ->flatMap(fn (Proposal $proposal) => $proposal->donorProposals->map(fn (DonorProposal $agreement): string => $agreement->currency->value))
            ->unique()
            ->sort()
            ->values();

        $graphs = $currencies->map(function (string $currency) use ($proposals): array {
            $agreements = $proposals->flatMap(fn (Proposal $proposal) => $proposal->donorProposals
                ->filter(fn (DonorProposal $agreement): bool => $agreement->currency->value === $currency));

            $donors = $agreements
                ->groupBy('donor_id')
                ->map(fn (Collection $group): array => [
                    'key' => 'd'.$group->first()->donor_id,
                    'id' => (int) $group->first()->donor_id,
                    'name' => $group->first()->donor->name,
                    'total' => (float) $group->sum('budget'),
                    'agreements' => $group->count(),
                ])
                ->sortByDesc('total')
                ->values();

            $funded = $proposals
                ->map(fn (Proposal $proposal): array => [
                    'key' => 'p'.$proposal->id,
                    'id' => $proposal->id,
                    'name' => $proposal->name,
                    'status' => $proposal->status,
                    'total' => (float) $proposal->donorProposals->filter(fn (DonorProposal $agreement): bool => $agreement->currency->value === $currency)->sum('budget'),
                    'projects' => $proposal->projects,
                ])
                ->filter(fn (array $proposal): bool => $proposal['total'] > 0)
                // Order proposals by their largest donor so ribbons cross as little as possible.
                ->sortBy(function (array $proposal) use ($donors, $agreements): int {
                    $mainDonor = $agreements->where('proposal_id', $proposal['id'])->sortByDesc('budget')->first()?->donor_id;

                    return (int) $donors->search(fn (array $donor): bool => $donor['id'] === $mainDonor);
                })
                ->values();

            // Each project sits at the average row of the proposals it is linked to, so threads cross as little as possible.
            $projects = $funded
                ->flatMap(fn (array $proposal, int $row) => $proposal['projects']->map(fn ($project): array => ['project' => $project, 'row' => $row]))
                ->groupBy(fn (array $link): int => $link['project']->id)
                ->map(fn (Collection $links): array => [
                    'key' => 'j'.$links->first()['project']->id,
                    'id' => $links->first()['project']->id,
                    'name' => $links->first()['project']->name,
                    'row' => $links->avg('row'),
                ])
                ->sortBy([['row', 'asc'], ['name', 'asc']])
                ->map(fn (array $project): array => collect($project)->except('row')->all())
                ->values();

            $money = $agreements
                ->filter(fn (DonorProposal $agreement): bool => $funded->contains('id', $agreement->proposal_id))
                ->groupBy(fn (DonorProposal $agreement): string => $agreement->donor_id.'-'.$agreement->proposal_id)
                ->map(fn (Collection $group): array => [
                    'from' => 'd'.$group->first()->donor_id,
                    'to' => 'p'.$group->first()->proposal_id,
                    'amount' => (float) $group->sum('budget'),
                ])
                ->values();

            $links = $funded->flatMap(fn (array $proposal) => $proposal['projects']->map(fn ($project): array => [
                'from' => 'p'.$proposal['id'],
                'to' => 'j'.$project->id,
            ]))->values();

            return [
                'currency' => $currency,
                'total' => (float) $agreements->sum('budget'),
                'donors' => $donors->all(),
                'proposals' => $funded->map(fn (array $proposal): array => collect($proposal)->except('projects')->all())->all(),
                'projects' => $projects->all(),
                'graph' => [
                    'order' => $donors->pluck('key')->concat($funded->pluck('key'))->concat($projects->pluck('key'))->all(),
                    'money' => $money->all(),
                    'links' => $links->all(),
                ],
            ];
        });

        return [
            'currencies' => $graphs->all(),
            'unfunded' => $proposals
                ->filter(fn (Proposal $proposal): bool => $proposal->donorProposals->isEmpty())
                ->map(fn (Proposal $proposal): array => ['id' => $proposal->id, 'name' => $proposal->name])
                ->values()
                ->all(),
        ];
    }

    /**
     * Money on one agreement, split the way its bar draws it.
     *
     * Only transfers in the agreement's own currency fill its bar; money that
     * arrived in another currency is reported beside it, never mixed in.
     *
     * @return array{received: float, pending: float, unscheduled: float, share: int, otherCurrencies: array<string, float>, nextDue: ?CarbonImmutable}
     */
    protected function agreementMoney(DonorProposal $agreement): array
    {
        $currency = $agreement->currency->value;
        $received = $agreement->transfers->where('ar_status', ArStatus::Received);
        $pending = $agreement->transfers->where('ar_status', ArStatus::Pending);

        $receivedHere = (float) $received->filter(fn (TransfersTracking $transfer): bool => $transfer->amount_received_currency?->value === $currency)->sum('net_amount_received');
        $pendingHere = (float) $pending->filter(fn (TransfersTracking $transfer): bool => $transfer->amount_transfered_currency?->value === $currency)->sum('payment_amount');
        $budget = (float) $agreement->budget;

        return [
            'received' => $receivedHere,
            'pending' => $pendingHere,
            // Every tranche sent in this currency covers part of the budget,
            // whatever became of it; fees and failures are not "unscheduled".
            'unscheduled' => max(0, $budget - (float) $agreement->transfers
                ->filter(fn (TransfersTracking $transfer): bool => $transfer->amount_transfered_currency?->value === $currency)
                ->sum('payment_amount')),
            'share' => $budget > 0 ? (int) floor($receivedHere / $budget * 100) : 0,
            'otherCurrencies' => $received
                ->reject(fn (TransfersTracking $transfer): bool => $transfer->amount_received_currency?->value === $currency)
                ->groupBy(fn (TransfersTracking $transfer): string => (string) $transfer->amount_received_currency?->value)
                ->map(fn (Collection $group): float => (float) $group->sum('net_amount_received'))
                ->all(),
            // Only money still to be sent has a due date; a sent tranche is in transit.
            'nextDue' => $pending->whereNull('transfer_date')->whereNotNull('scheduled_date')->sortBy('scheduled_date')->first()?->scheduled_date,
        ];
    }
}
