<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\FundingAgreements;

use App\Enums\ArStatus;
use App\Models\DonorProposal;
use App\Models\TransfersTracking;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * The Overview figures of the funding agreements page: the per-currency
 * collection ledger, the cash-flow calendar and the helpers they and the
 * agreement lanes share (quarter keys, month offsets, tranche state, percent
 * labels and one agreement's money).
 *
 * Used by funding-agreements/index only. It reads filteredQuery() from
 * ReadsAgreementRegister.
 *
 * @property-read Collection<int, array{currency: string, agreements: int, committed: float, scheduled: float, sent: float, received: float, receivedGross: float, receivedCount: int, lost: float, gained: float, netMissing: float, netMissingCount: int, landedElsewhere: float, unscheduled: float|int, overscheduled: float|int, overdue: float, overdueCount: int, upcoming: float, upcomingCount: int, nextUpcoming: ?CarbonImmutable, inTransit: float, inTransitCount: int, failed: float, failedCount: int, otherCurrencyTranches: int}> $ledger  #[Computed] ledger()
 * @property-read array{start: CarbonImmutable, count: int, today: float, months: list<array{key: string, quarterKey: string, label: string, year: ?string, current: bool, date: CarbonImmutable, quarter: bool}>, quarters: list<array{key: string, label: string, index: int, current: bool}>, inflow: array<string, array{max: float, quarterMax: float, months: array<string, array{received: float, pending: float, late: float, failed: float, total: float}>, quarters: array<string, array{received: float, pending: float, late: float, failed: float, total: float}>}>} $calendar  #[Computed] calendar()
 */
trait ChartsAgreementCalendar
{
    /**
     * The collection ledger, one line per currency, across every agreement the
     * filters match (not only the visible page, and ignoring the stage filter).
     *
     * Received is net money that landed in the agreement's own currency, the
     * same reading the agreement page gives. A received tranche with no net
     * amount recorded counts as nothing landed; it is reported beside the line
     * so someone fills it in. Committed splits into received (with what fees
     * and exchange took, and what is still unrecorded), in transit, upcoming,
     * overdue, failed and not scheduled. Only tranches in the agreement's own
     * currency count; any others are counted aside so nothing is silently
     * dropped.
     *
     * @return Collection<int, array{currency: string, agreements: int, committed: float, scheduled: float, sent: float, received: float, receivedGross: float, receivedCount: int, lost: float, gained: float, netMissing: float, netMissingCount: int, landedElsewhere: float, unscheduled: float|int, overscheduled: float|int, overdue: float, overdueCount: int, upcoming: float, upcomingCount: int, nextUpcoming: ?CarbonImmutable, inTransit: float, inTransitCount: int, failed: float, failedCount: int, otherCurrencyTranches: int}>
     */
    #[Computed]
    public function ledger(): Collection
    {
        $today = CarbonImmutable::today()->toDateString();
        $ids = $this->filteredQuery(withStage: false)->pluck('id');

        $committed = DonorProposal::query()
            ->toBase()
            ->whereIn('id', $ids)
            ->selectRaw('currency, sum(budget) as total, count(*) as agreements')
            ->groupBy('currency')
            ->get()
            ->keyBy('currency');

        $pending = ArStatus::Pending->value;
        $received = ArStatus::Received->value;
        $failed = ArStatus::Failed->value;

        $landedHere = 'ar_status = ? and net_amount_received is not null and amount_received_currency = donor_proposal.currency';

        $money = TransfersTracking::query()
            ->toBase()
            ->join('donor_proposal', 'donor_proposal.id', '=', 'transfers_tracking.donor_proposal_id')
            ->whereIn('donor_proposal.id', $ids)
            ->whereColumn('transfers_tracking.amount_transfered_currency', 'donor_proposal.currency')
            ->selectRaw(
                "donor_proposal.currency as currency,
                sum(payment_amount) as scheduled,
                sum(case when ar_status = ? then payment_amount else 0 end) as received_gross,
                sum(case when ar_status = ? then 1 else 0 end) as received_count,
                sum(case when {$landedHere} then net_amount_received else 0 end) as net,
                sum(case when {$landedHere} and payment_amount > net_amount_received then payment_amount - net_amount_received else 0 end) as lost,
                sum(case when {$landedHere} and net_amount_received > payment_amount then net_amount_received - payment_amount else 0 end) as gained,
                sum(case when ar_status = ? and net_amount_received is null then payment_amount else 0 end) as net_missing,
                sum(case when ar_status = ? and net_amount_received is null then 1 else 0 end) as net_missing_count,
                sum(case when ar_status = ? and net_amount_received is not null and amount_received_currency <> donor_proposal.currency then payment_amount else 0 end) as landed_elsewhere,
                sum(case when ar_status = ? and transfer_date is null and scheduled_date < ? then payment_amount else 0 end) as overdue,
                sum(case when ar_status = ? and transfer_date is null and scheduled_date < ? then 1 else 0 end) as overdue_count,
                sum(case when ar_status = ? and transfer_date is null and (scheduled_date >= ? or scheduled_date is null) then payment_amount else 0 end) as upcoming,
                sum(case when ar_status = ? and transfer_date is null and (scheduled_date >= ? or scheduled_date is null) then 1 else 0 end) as upcoming_count,
                min(case when ar_status = ? and transfer_date is null and scheduled_date >= ? then scheduled_date end) as next_upcoming,
                sum(case when ar_status = ? and transfer_date is not null then payment_amount else 0 end) as in_transit,
                sum(case when ar_status = ? and transfer_date is not null then 1 else 0 end) as in_transit_count,
                sum(case when ar_status = ? then payment_amount else 0 end) as failed,
                sum(case when ar_status = ? then 1 else 0 end) as failed_count,
                sum(case when ar_status = ? and transfer_date is not null then payment_amount else 0 end) as failed_sent",
                [
                    $received,
                    $received,
                    $received,
                    $received,
                    $received,
                    $received,
                    $received,
                    $received,
                    $pending, $today,
                    $pending, $today,
                    $pending, $today,
                    $pending, $today,
                    $pending, $today,
                    $pending,
                    $pending,
                    $failed,
                    $failed,
                    $failed,
                ],
            )
            ->groupBy('donor_proposal.currency')
            ->get()
            ->keyBy('currency');

        $otherCurrency = TransfersTracking::query()
            ->toBase()
            ->join('donor_proposal', 'donor_proposal.id', '=', 'transfers_tracking.donor_proposal_id')
            ->whereIn('donor_proposal.id', $ids)
            ->whereColumn('transfers_tracking.amount_transfered_currency', '<>', 'donor_proposal.currency')
            ->selectRaw('donor_proposal.currency as currency, count(*) as tranches')
            ->groupBy('donor_proposal.currency')
            ->pluck('tranches', 'currency');

        return $committed->keys()
            ->sort()
            ->values()
            ->map(function (string $currency) use ($committed, $money, $otherCurrency): array {
                $row = $money[$currency] ?? null;
                $number = fn (string $column): float => (float) ($row->{$column} ?? 0);

                $committedTotal = (float) $committed[$currency]->total;
                $receivedGross = $number('received_gross');
                $inTransit = $number('in_transit');

                return [
                    'currency' => $currency,
                    'agreements' => (int) $committed[$currency]->agreements,
                    'committed' => $committedTotal,
                    'scheduled' => $number('scheduled'),
                    'sent' => $receivedGross + $inTransit + $number('failed_sent'),
                    'received' => $number('net'),
                    'receivedGross' => $receivedGross,
                    'receivedCount' => (int) $number('received_count'),
                    'lost' => $number('lost'),
                    'gained' => $number('gained'),
                    'netMissing' => $number('net_missing'),
                    'netMissingCount' => (int) $number('net_missing_count'),
                    'landedElsewhere' => $number('landed_elsewhere'),
                    'unscheduled' => max(0, $committedTotal - $number('scheduled')),
                    'overscheduled' => max(0, $number('scheduled') - $committedTotal),
                    'overdue' => $number('overdue'),
                    'overdueCount' => (int) $number('overdue_count'),
                    'upcoming' => $number('upcoming'),
                    'upcomingCount' => (int) $number('upcoming_count'),
                    'nextUpcoming' => filled($row->next_upcoming ?? null) ? CarbonImmutable::parse($row->next_upcoming) : null,
                    'inTransit' => $inTransit,
                    'inTransitCount' => (int) $number('in_transit_count'),
                    'failed' => $number('failed'),
                    'failedCount' => (int) $number('failed_count'),
                    'otherCurrencyTranches' => (int) ($otherCurrency[$currency] ?? 0),
                ];
            });
    }

    /**
     * The cash-flow calendar: one month grid shared by every lane, and what is
     * expected to land in each month, per currency.
     *
     * Built from every tranche of every matching agreement (not only the
     * visible page), so the month and quarter totals are the true pipeline.
     * The grid always shows a little of the past and a stretch of the future
     * around today, and runs in whole quarters so the quarter zoom never
     * shows a clipped quarter at either end.
     *
     * @return array{start: CarbonImmutable, count: int, today: float, months: list<array{key: string, quarterKey: string, label: string, year: ?string, current: bool, date: CarbonImmutable, quarter: bool}>, quarters: list<array{key: string, label: string, index: int, current: bool}>, inflow: array<string, array{max: float, quarterMax: float, months: array<string, array{received: float, pending: float, late: float, failed: float, total: float}>, quarters: array<string, array{received: float, pending: float, late: float, failed: float, total: float}>}>}
     */
    #[Computed]
    public function calendar(): array
    {
        $today = CarbonImmutable::today();

        $tranches = TransfersTracking::query()
            ->toBase()
            ->whereIn('donor_proposal_id', $this->filteredQuery()->select('donor_proposal.id')->toBase())
            ->whereNotNull('scheduled_date')
            ->get(['payment_amount', 'amount_transfered_currency', 'scheduled_date', 'transfer_date', 'ar_status']);

        $dates = $tranches->map(fn (object $tranche): CarbonImmutable => CarbonImmutable::parse($tranche->scheduled_date));
        $start = $dates->push($today->subMonths(2))->min()->startOfQuarter();
        $end = $dates->push($today->addMonths(5))->max()->startOfQuarter()->addMonths(2);

        $months = [];
        $quarters = [];
        for ($month = $start; $month->lessThanOrEqualTo($end); $month = $month->addMonth()) {
            if ($month->month % 3 === 1) {
                $quarters[] = [
                    'key' => $this->quarterKey($month),
                    'label' => __('funding_agreements.quarter_label', ['number' => $month->quarter]),
                    'year' => $month->quarter === 1 || $quarters === [] ? $month->format('Y') : null,
                    'index' => count($months),
                    'current' => $month->year === $today->year && $month->quarter === $today->quarter,
                ];
            }

            $months[] = [
                'key' => $month->format('Y-m'),
                'quarterKey' => $this->quarterKey($month),
                'label' => $month->translatedFormat(__('formats.month_short')),
                'year' => $month->month === 1 || $months === [] ? $month->format('Y') : null,
                'current' => $month->isSameMonth($today),
                'date' => $month,
                'quarter' => $month->month % 3 === 1,
            ];
        }

        $inflow = $tranches
            ->groupBy('amount_transfered_currency')
            ->sortKeys()
            ->map(function (Collection $group) use ($today): array {
                $byMonth = $group
                    ->groupBy(fn (object $tranche): string => CarbonImmutable::parse($tranche->scheduled_date)->format('Y-m'))
                    ->map(function (Collection $monthTranches) use ($today): array {
                        $sum = fn (callable $filter): float => (float) $monthTranches->filter($filter)->sum('payment_amount');

                        $received = $sum(fn (object $tranche): bool => $tranche->ar_status === ArStatus::Received->value);
                        $failed = $sum(fn (object $tranche): bool => $tranche->ar_status === ArStatus::Failed->value);
                        $late = $sum(fn (object $tranche): bool => $tranche->ar_status === ArStatus::Pending->value
                            && $tranche->transfer_date === null
                            && CarbonImmutable::parse($tranche->scheduled_date)->lessThan($today));
                        $total = (float) $monthTranches->sum('payment_amount');

                        return [
                            'received' => $received,
                            'late' => $late,
                            'failed' => $failed,
                            'pending' => max(0.0, $total - $received - $late - $failed),
                            'total' => $total,
                        ];
                    });

                $byQuarter = $byMonth
                    ->groupBy(fn (array $money, string $month): string => $this->quarterKey(CarbonImmutable::createFromFormat('!Y-m', $month)), preserveKeys: true)
                    ->map(fn (Collection $quarterMonths): array => [
                        'received' => (float) $quarterMonths->sum('received'),
                        'pending' => (float) $quarterMonths->sum('pending'),
                        'late' => (float) $quarterMonths->sum('late'),
                        'failed' => (float) $quarterMonths->sum('failed'),
                        'total' => (float) $quarterMonths->sum('total'),
                    ]);

                return [
                    'max' => (float) $byMonth->max('total'),
                    'quarterMax' => (float) $byQuarter->max('total'),
                    'months' => $byMonth->all(),
                    'quarters' => $byQuarter->all(),
                ];
            })
            ->all();

        return [
            'start' => $start,
            'count' => count($months),
            'today' => $this->monthOffset($start, $today),
            'months' => $months,
            'quarters' => $quarters,
            'inflow' => $inflow,
        ];
    }

    /**
     * The key a month's quarter is filed under, e.g. 2026-Q3.
     */
    protected function quarterKey(CarbonInterface $date): string
    {
        return $date->year.'-Q'.$date->quarter;
    }

    /**
     * How many months a date sits after the calendar's first month, with the
     * day as a fraction, so a lane can place it at `offset × month width`.
     */
    protected function monthOffset(CarbonInterface $start, CarbonInterface $date): float
    {
        $wholeMonths = ($date->year - $start->year) * 12 + ($date->month - $start->month);

        return round($wholeMonths + ($date->day - 1) / $date->daysInMonth, 4);
    }

    /**
     * Where one tranche stands, in the shared vocabulary of every funding page.
     *
     * @return 'received'|'failed'|'transit'|'late'|'upcoming'
     */
    protected function trancheState(TransfersTracking $transfer): string
    {
        return $transfer->state();
    }

    /**
     * A whole percentage of $of, floored so nothing is claimed early, but
     * never shown as 0 while there is something to count.
     */
    protected function percentLabel(float $value, float $of): string
    {
        return DonorProposal::percentLabel($value, $of);
    }

    /**
     * Money on one agreement, split the way its lane draws it.
     *
     * Only tranches in the agreement's own currency count, as on the agreement
     * page. Received is net money that landed in that currency; a received
     * tranche with no net recorded counts as nothing landed and is reported
     * beside it, as is anything that landed in another currency.
     *
     * @return array{received: float, pending: float, unscheduled: float, share: int, percentLabel: string, missingNet: int, otherCurrencies: array<string, float>, overdueSince: ?CarbonImmutable, overdueAmount: float, failedAmount: float, nextDue: ?CarbonImmutable, accounts: array<int, string>}
     */
    protected function agreementMoney(DonorProposal $agreement): array
    {
        $currency = $agreement->currency;
        $budget = (float) $agreement->budget;
        $sameCurrency = $agreement->transfers->filter(fn (TransfersTracking $transfer): bool => $transfer->amount_transfered_currency === $currency);
        $received = $sameCurrency->filter(fn (TransfersTracking $transfer): bool => $transfer->state() === 'received');
        $recorded = $received->reject(fn (TransfersTracking $transfer): bool => $transfer->isMissingNetAmount());
        $late = $agreement->transfers->filter(fn (TransfersTracking $transfer): bool => $transfer->state() === 'late');

        $receivedHere = (float) $recorded
            ->filter(fn (TransfersTracking $transfer): bool => $transfer->amount_received_currency === $currency)
            ->sum(fn (TransfersTracking $transfer): float => (float) $transfer->net_amount_received);
        $pendingHere = (float) $sameCurrency
            ->filter(fn (TransfersTracking $transfer): bool => in_array($transfer->state(), ['transit', 'late', 'upcoming'], true))
            ->sum(fn (TransfersTracking $transfer): float => (float) $transfer->payment_amount);

        return [
            'received' => $receivedHere,
            'pending' => $pendingHere,
            'unscheduled' => max(0, $budget - (float) $sameCurrency->sum(fn (TransfersTracking $transfer): float => (float) $transfer->payment_amount)),
            'share' => $budget > 0 ? (int) floor(min(1, $receivedHere / $budget) * 100) : 0,
            'percentLabel' => $this->percentLabel($receivedHere, $budget),
            'missingNet' => $received->filter(fn (TransfersTracking $transfer): bool => $transfer->isMissingNetAmount())->count(),
            'otherCurrencies' => $recorded
                ->reject(fn (TransfersTracking $transfer): bool => $transfer->amount_received_currency === $currency)
                ->groupBy(fn (TransfersTracking $transfer): string => $transfer->amount_received_currency->value)
                ->map(fn (Collection $group): float => (float) $group->sum(fn (TransfersTracking $transfer): float => (float) $transfer->net_amount_received))
                ->all(),
            'overdueSince' => $late->min('scheduled_date'),
            'overdueAmount' => (float) $late->sum(fn (TransfersTracking $transfer): float => (float) $transfer->payment_amount),
            'failedAmount' => (float) $agreement->transfers
                ->filter(fn (TransfersTracking $transfer): bool => $transfer->state() === 'failed')
                ->sum(fn (TransfersTracking $transfer): float => (float) $transfer->payment_amount),
            'nextDue' => $agreement->transfers
                ->filter(fn (TransfersTracking $transfer): bool => $transfer->state() === 'upcoming' && $transfer->scheduled_date !== null)
                ->min('scheduled_date'),
            'accounts' => $agreement->transfers->pluck('account.account_name')->filter()->unique()->values()->all(),
        ];
    }
}
