<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Donors;

use App\Enums\ArStatus;
use App\Models\DonorProposal;
use App\Models\TransfersTracking;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

/**
 * The money on donors/show: the donor's agreements, their tranches and every
 * reading derived from them (per-currency position, money received in another
 * currency, overdue and next tranches), plus the shared tranche-state
 * vocabulary.
 *
 * Used by donors/show only. The page provides the locked $donorId; the
 * calendar() and projects() computeds on the page read agreements() from
 * here, and calendar() also calls transferState().
 *
 * @property-read int $donorId  #[Locked] public property of the page
 * @property-read Collection<int, DonorProposal> $agreements  #[Computed] agreements()
 * @property-read Collection<int, DonorProposal> $agreementRows  #[Computed] agreementRows()
 * @property-read Collection<int, TransfersTracking> $allTranches  #[Computed] allTranches()
 * @property-read array<string, array{committed: float, received: float, due: float, overdue: float, failed: float, unscheduled: float, missingNet: int}> $moneyByCurrency  #[Computed] moneyByCurrency()
 * @property-read array<string, float> $receivedElsewhere  #[Computed] receivedElsewhere()
 * @property-read array<int, array{agreement: DonorProposal, transfer: TransfersTracking, days: int}> $overdueTranches  #[Computed] overdueTranches()
 * @property-read array{agreement: DonorProposal, transfer: TransfersTracking}|null $nextTranche  #[Computed] nextTranche()
 */
trait ReadsDonorMoney
{
    /**
     * Every agreement this donor holds, with the proposal behind it, the
     * projects that proposal funds and every tranche paid against it.
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
        if (! auth()->user()?->can('funding-agreements.view')) {
            return new Collection;
        }

        $canViewTransfers = (bool) auth()->user()->can('transfers.view');

        return DonorProposal::query()
            ->select(['id', 'slug', 'donor_id', 'proposal_id', 'budget', 'currency', 'external_project_number'])
            ->where('donor_id', $this->donorId)
            ->with([
                'proposal:id,slug,name,internal_project_number,status',
                'proposal.projects:id,name,status',
                'transfers' => fn ($query) => $query
                    ->select(['id', 'donor_proposal_id', 'account_id', 'payment_amount', 'amount_transfered_currency', 'scheduled_date', 'transfer_date', 'fees', 'fees_currency', 'amount_received', 'exchange_rate', 'net_amount_received', 'amount_received_currency', 'receive_date', 'ar_status'])
                    ->when(! $canViewTransfers, fn ($query) => $query->whereRaw('1 = 0'))
                    ->orderByRaw('coalesce(receive_date, scheduled_date) is null')
                    ->orderByRaw('coalesce(receive_date, scheduled_date)')
                    ->orderBy('id'),
                'transfers.account:id,account_name',
                // The reports this donor is still owed under each agreement.
                'reports' => fn ($query) => $query
                    ->select(['id', 'donor_proposal_id', 'title', 'type', 'due_on', 'submitted_on'])
                    ->whereNull('submitted_on')
                    ->orderBy('due_on')
                    ->orderBy('id'),
            ])
            ->orderBy('id')
            ->get();
    }

    /**
     * Where one tranche stands, read from the vocabulary every funding page
     * shares (TransfersTracking::state()): money the donor has sent is in
     * transit however far past its date, and only unsent money is overdue.
     *
     * @return 'received'|'failed'|'transit'|'overdue'|'due'|'undated'
     */
    protected function transferState(TransfersTracking $transfer): string
    {
        return match ($transfer->state()) {
            'received' => 'received',
            'failed' => 'failed',
            'transit' => 'transit',
            'late' => 'overdue',
            default => $transfer->scheduled_date === null ? 'undated' : 'due',
        };
    }

    /**
     * The money position of one agreement in its own currency.
     *
     * Received counts only tranches with status "received" that landed in the
     * agreement's currency; a pending tranche already carries a net amount, so
     * that column alone is not proof of money in. A received tranche with no
     * net recorded counts as nothing landed and is counted in `missingNet`.
     * Money in transit is still due, never overdue. Failed money is its own
     * figure: it was scheduled, so it is not "unscheduled", and it did not
     * land. Only tranches sent in the agreement's own currency are set against
     * its budget; unscheduled is the part of the budget no tranche covers yet.
     *
     * @return array{committed: float, received: float, due: float, overdue: float, failed: float, unscheduled: float, missingNet: int}
     */
    protected function agreementMoney(DonorProposal $agreement): array
    {
        $committed = (float) $agreement->budget;
        $money = ['committed' => $committed, 'received' => 0.0, 'due' => 0.0, 'overdue' => 0.0, 'failed' => 0.0, 'unscheduled' => 0.0, 'missingNet' => 0];
        $covered = 0.0;

        foreach ($agreement->transfers as $transfer) {
            $state = $this->transferState($transfer);

            if ($transfer->amount_transfered_currency !== $agreement->currency) {
                continue;
            }

            $covered += (float) $transfer->payment_amount;

            if ($state === 'failed') {
                $money['failed'] += (float) $transfer->payment_amount;
            } elseif ($state === 'received') {
                if ($transfer->isMissingNetAmount()) {
                    $money['missingNet']++;
                } elseif ($transfer->amount_received_currency === $agreement->currency) {
                    $money['received'] += (float) $transfer->net_amount_received;
                }
            } elseif ($state === 'overdue') {
                $money['overdue'] += (float) $transfer->payment_amount;
            } else {
                $money['due'] += (float) $transfer->payment_amount;
            }
        }

        $money['unscheduled'] = max(0.0, $committed - $covered);

        return $money;
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
        if (! auth()->user()?->can('funding-agreements.view')) {
            return new Collection;
        }

        $today = CarbonImmutable::today()->toDateString();

        return DonorProposal::query()
            ->select('donor_proposal.*')
            ->where('donor_proposal.donor_id', $this->donorId)
            ->when(auth()->user()->can('transfers.view'), fn (Builder $query) => $query->withMoney())
            ->with(['proposal:id,slug,name,internal_project_number,status'])
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
     * Every tranche of every agreement, in schedule order with undated ones
     * last, each knowing the agreement it belongs to. Empty without
     * transfers.view, because agreements() loads no tranche then.
     *
     * @return Collection<int, TransfersTracking>
     */
    #[Computed]
    public function allTranches(): Collection
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
     * The money position per currency. Grouped, never summed across currencies.
     *
     * @return array<string, array{committed: float, received: float, due: float, overdue: float, failed: float, unscheduled: float, missingNet: int}>
     */
    #[Computed]
    public function moneyByCurrency(): array
    {
        $totals = [];

        foreach ($this->agreements as $agreement) {
            $currency = $agreement->currency->value;
            $totals[$currency] ??= ['committed' => 0.0, 'received' => 0.0, 'due' => 0.0, 'overdue' => 0.0, 'failed' => 0.0, 'unscheduled' => 0.0, 'missingNet' => 0];

            foreach ($this->agreementMoney($agreement) as $key => $value) {
                $totals[$currency][$key] += $value;
            }
        }

        return $totals;
    }

    /**
     * Money that landed in a currency other than its agreement's, per landing
     * currency. Shown beside the position rather than converted into it.
     *
     * @return array<string, float>
     */
    #[Computed]
    public function receivedElsewhere(): array
    {
        return $this->agreements
            ->flatMap(fn (DonorProposal $agreement) => $agreement->transfers
                ->filter(fn (TransfersTracking $transfer): bool => $transfer->ar_status === ArStatus::Received
                    && $transfer->amount_received_currency !== null
                    && $transfer->amount_received_currency !== $agreement->currency))
            ->groupBy(fn (TransfersTracking $transfer): string => $transfer->amount_received_currency->value)
            ->map(fn ($group): float => (float) $group->sum(fn (TransfersTracking $transfer): float => (float) $transfer->net_amount_received))
            ->all();
    }

    /**
     * Unsent tranches whose scheduled date has passed, oldest first.
     *
     * @return array<int, array{agreement: DonorProposal, transfer: TransfersTracking, days: int}>
     */
    #[Computed]
    public function overdueTranches(): array
    {
        $today = CarbonImmutable::today();

        return $this->agreements
            ->flatMap(fn (DonorProposal $agreement) => $agreement->transfers
                ->filter(fn (TransfersTracking $transfer): bool => $this->transferState($transfer) === 'overdue')
                ->map(fn (TransfersTracking $transfer): array => [
                    'agreement' => $agreement,
                    'transfer' => $transfer,
                    'days' => (int) $transfer->scheduled_date->diffInDays($today),
                ]))
            ->sortBy(fn (array $item) => $item['transfer']->scheduled_date)
            ->values()
            ->all();
    }

    /**
     * The next unsent tranche due today or later.
     *
     * @return array{agreement: DonorProposal, transfer: TransfersTracking}|null
     */
    #[Computed]
    public function nextTranche(): ?array
    {
        return $this->agreements
            ->flatMap(fn (DonorProposal $agreement) => $agreement->transfers
                ->filter(fn (TransfersTracking $transfer): bool => $this->transferState($transfer) === 'due')
                ->map(fn (TransfersTracking $transfer): array => ['agreement' => $agreement, 'transfer' => $transfer]))
            ->sortBy(fn (array $item) => $item['transfer']->scheduled_date)
            ->first();
    }
}
