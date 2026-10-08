<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Enums\ArStatus;
use App\Models\DonorProposal;
use App\Models\TransfersTracking;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

/**
 * The money on proposals/show: the agreements, their table rows and
 * tranches, and each agreement's and each currency's position.
 *
 * Used by proposals/show only. The page provides proposalId, and
 * canViewAgreements() / canViewTransfers() come from ChecksFinanceVisibility.
 *
 * @property int $proposalId
 * @property-read Collection<int, DonorProposal> $agreements  #[Computed] agreements()
 * @property-read array<int, array{budget: float, received: float, pending: float, unscheduled: float, overdue: float, overdueCount: int, failedCount: int, foreignCount: int, missingNetCount: int, nextDue: CarbonImmutable|null}> $agreementMoney  #[Computed] agreementMoney()
 */
trait ReadsProposalFunding
{
    /**
     * The funding agreements on this proposal, with every tranche behind them.
     *
     * Read through donorProposals(), never through donors()->pivot: the pivot
     * built by belongsToMany carries no casts, so its currency arrives as a raw
     * string while this relation's arrives as a Currency case.
     *
     * Empty for a viewer without funding-agreements.view, and every money
     * reading below is derived from it, so none of them is ever computed.
     * A viewer without transfers.view gets the agreements with no tranches.
     *
     * @return Collection<int, DonorProposal>
     */
    #[Computed]
    public function agreements(): Collection
    {
        if (! $this->canViewAgreements()) {
            return new Collection;
        }

        $canViewTransfers = $this->canViewTransfers();

        return DonorProposal::query()
            ->select(['id', 'slug', 'donor_id', 'budget', 'currency', 'external_project_number', 'proposal_id'])
            ->where('proposal_id', $this->proposalId)
            ->with([
                'donor:id,slug,name',
                'transfers' => fn ($query) => $query->when(! $canViewTransfers, fn ($query) => $query->whereRaw('1 = 0')),
                'transfers.account:id,account_name',
            ])
            ->orderBy('id')
            ->get();
    }

    /**
     * The same agreements as table rows: each with its money read by the one
     * SQL definition every agreement table shares, and its donor reports
     * counted. Empty for a viewer without funding-agreements.view; without
     * transfers.view the money is not queried at all and the rows carry the
     * budget only.
     *
     * @return Collection<int, DonorProposal>
     */
    #[Computed]
    public function agreementRows(): Collection
    {
        if (! $this->canViewAgreements()) {
            return new Collection;
        }

        $today = CarbonImmutable::today()->toDateString();

        return DonorProposal::query()
            ->select('donor_proposal.*')
            ->where('donor_proposal.proposal_id', $this->proposalId)
            ->when($this->canViewTransfers(), fn (Builder $query) => $query->withMoney())
            ->with(['donor:id,slug,name'])
            ->withCount(['reports as overdue_reports_count' => fn (Builder $query) => $query
                ->whereNull('submitted_on')
                ->whereDate('due_on', '<', $today)])
            ->withMin(['reports as next_report_due' => fn (Builder $query) => $query
                ->whereNull('submitted_on')
                ->whereDate('due_on', '>=', $today)], 'due_on')
            ->orderBy('donor_proposal.id')
            ->get();
    }

    /**
     * Every tranche of every agreement on this proposal, in schedule order
     * with undated ones last, each knowing the agreement it belongs to. Empty
     * without transfers.view, because agreements() loads no tranche then.
     *
     * @return Collection<int, TransfersTracking>
     */
    #[Computed]
    public function fundingTranches(): Collection
    {
        return new Collection($this->agreements
            ->flatMap(fn (DonorProposal $agreement) => $agreement->transfers
                ->each(fn (TransfersTracking $transfer) => $transfer->setRelation('donorProposal', $agreement)))
            ->sortBy([
                fn (TransfersTracking $a, TransfersTracking $b): int => ($a->scheduled_date === null) <=> ($b->scheduled_date === null),
                fn (TransfersTracking $a, TransfersTracking $b): int => $a->scheduled_date <=> $b->scheduled_date,
                fn (TransfersTracking $a, TransfersTracking $b): int => $a->id <=> $b->id,
            ])
            ->values()
            ->all());
    }

    /**
     * One agreement's money, counted by the single rule every figure on this
     * page shares.
     *
     * Received counts net amounts that landed in the agreement's own currency;
     * pending counts payments sent in it. A tranche in another currency cannot
     * be set against the budget without a rate this system does not hold, so
     * it is left out of the sums and counted in `foreignCount` instead. A
     * tranche is overdue from the day after its scheduled date, never on it.
     *
     * @return array<int, array{budget: float, received: float, pending: float, unscheduled: float, overdue: float, overdueCount: int, failedCount: int, foreignCount: int, missingNetCount: int, nextDue: CarbonImmutable|null}>
     */
    #[Computed]
    public function agreementMoney(): array
    {
        $today = CarbonImmutable::today();
        $money = [];

        foreach ($this->agreements as $agreement) {
            $currency = $agreement->currency->value;
            $budget = (float) $agreement->budget;

            $receivedTransfers = $agreement->transfers->where('ar_status', ArStatus::Received);
            $received = (float) $receivedTransfers
                ->filter(fn (TransfersTracking $transfer): bool => $transfer->amount_received_currency?->value === $currency)
                ->sum('net_amount_received');

            $pendingAll = $agreement->transfers->where('ar_status', ArStatus::Pending);
            $pendingTransfers = $pendingAll
                ->filter(fn (TransfersTracking $transfer): bool => $transfer->amount_transfered_currency?->value === $currency);

            $overdueTransfers = $pendingTransfers
                ->filter(fn (TransfersTracking $transfer): bool => $this->transferIsOverdue($transfer));

            $pending = (float) $pendingTransfers->sum('payment_amount');

            $money[$agreement->id] = [
                'budget' => $budget,
                'received' => $received,
                'pending' => $pending,
                // Every tranche sent in this currency covers part of the budget,
                // whatever became of it: fees or a missing net amount do not make
                // money "unscheduled", and failed money is counted in failedCount.
                'unscheduled' => max(0.0, $budget - (float) $agreement->transfers
                    ->filter(fn (TransfersTracking $transfer): bool => $transfer->amount_transfered_currency?->value === $currency)
                    ->sum('payment_amount')),
                'overdue' => (float) $overdueTransfers->sum('payment_amount'),
                'overdueCount' => $overdueTransfers->count(),
                'failedCount' => $agreement->transfers->where('ar_status', ArStatus::Failed)->count(),
                'missingNetCount' => $receivedTransfers->filter(fn (TransfersTracking $transfer): bool => $transfer->isMissingNetAmount())->count(),
                'foreignCount' => $receivedTransfers->filter(fn (TransfersTracking $transfer): bool => $transfer->amount_received_currency?->value !== $currency)->count()
                    + $pendingAll->count() - $pendingTransfers->count(),
                'nextDue' => $pendingTransfers->whereNull('transfer_date')->whereNotNull('scheduled_date')->sortBy('scheduled_date')->first()?->scheduled_date,
            ];
        }

        return $money;
    }

    /**
     * An unsent tranche whose scheduled date has passed, in the vocabulary
     * every funding page shares: money the donor has already sent is in
     * transit, never overdue, and due today is not yet late.
     */
    protected function transferIsOverdue(TransfersTracking $transfer): bool
    {
        return $transfer->state() === 'late';
    }

    /**
     * Money position per currency.
     *
     * Grouped, never summed: USD, EUR and SYP have no conversion rate in this
     * system, so a single total would be a fabricated number. Received counts
     * the net amounts that landed in the agreement's own currency; a tranche
     * that arrived in another currency is left out rather than converted.
     *
     * @return array<string, array{committed: float, received: float, pending: float, unscheduled: float, percent: int, overdue: float, overdueCount: int, failedCount: int, nextDue: CarbonImmutable|null}>
     */
    #[Computed]
    public function moneyByCurrency(): array
    {
        $lines = [];

        foreach ($this->agreements as $agreement) {
            $currency = $agreement->currency->value;
            $money = $this->agreementMoney[$agreement->id];
            $line = $lines[$currency] ?? [
                'committed' => 0.0,
                'received' => 0.0,
                'pending' => 0.0,
                'unscheduled' => 0.0,
                'percent' => 0,
                'overdue' => 0.0,
                'overdueCount' => 0,
                'failedCount' => 0,
                'nextDue' => null,
            ];

            $line['committed'] += $money['budget'];
            $line['received'] += $money['received'];
            $line['pending'] += $money['pending'];
            $line['unscheduled'] += $money['unscheduled'];
            $line['overdue'] += $money['overdue'];
            $line['overdueCount'] += $money['overdueCount'];
            $line['failedCount'] += $money['failedCount'];

            if ($money['nextDue'] !== null && ($line['nextDue'] === null || $money['nextDue']->lt($line['nextDue']))) {
                $line['nextDue'] = $money['nextDue'];
            }

            $lines[$currency] = $line;
        }

        foreach ($lines as $currency => $line) {
            $lines[$currency]['percent'] = $line['committed'] > 0.0
                ? (int) floor($line['received'] / $line['committed'] * 100)
                : 0;
        }

        uasort($lines, fn (array $a, array $b): int => $b['committed'] <=> $a['committed']);

        return $lines;
    }
}
