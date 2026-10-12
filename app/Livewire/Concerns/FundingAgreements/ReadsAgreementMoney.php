<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\FundingAgreements;

use App\Models\DonorProposal;
use App\Models\TransfersTracking;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

/**
 * The money on funding-agreements/show: the promise split into its parts,
 * each tranche's journey, the dial's arcs and the grant term, plus the
 * shared tranche-state vocabulary.
 *
 * Used by funding-agreements/show only. The page provides the #[Computed]
 * agreement() and transfers(); ReadsAgreementAttention reads money, journeys
 * and term from here.
 *
 * @property-read DonorProposal $agreement  #[Computed] agreement()
 * @property-read Collection<int, TransfersTracking> $transfers  #[Computed] transfers()
 * @property-read array{budget: float, landed: float, lost: float, gained: float, unrecorded: float, unrecordedCount: int, receivedGross: float, upcoming: float, transit: float, late: float, failed: float, scheduled: float, unscheduled: float, overScheduled: float, stillToCome: float, percent: int, percentLabel: string, foreignCount: int, foreignLandings: array<string, float>, nextDue: CarbonImmutable|null, lateSince: CarbonImmutable|null} $money  #[Computed] money()
 * @property-read list<array{transfer: TransfersTracking, number: int, state: string, foreign: bool, stops: list<array{key: string, label: string, detail: string|null, state: string}>, timing: string|null, timingAlarm: bool, left: float|null}> $journeys  #[Computed] journeys()
 */
trait ReadsAgreementMoney
{
    /**
     * Where a tranche stands, in the vocabulary every funding page shares.
     *
     * Money the donor has sent is in transit until it lands; a tranche is
     * late only while still unsent, from the day after its scheduled date.
     *
     * @return 'received'|'failed'|'transit'|'late'|'upcoming'
     */
    protected function trancheState(TransfersTracking $transfer): string
    {
        return $transfer->state();
    }

    protected function trancheStateLabel(string $state): string
    {
        return match ($state) {
            'received' => __('funding_agreements.tranche_state_received'),
            'failed' => __('funding_agreements.tranche_state_failed'),
            'late' => __('funding_agreements.tranche_state_late'),
            'transit' => __('funding_agreements.tranche_state_transit'),
            default => __('funding_agreements.tranche_state_upcoming'),
        };
    }

    /**
     * Whether a tranche was sent in the agreement's own currency and can
     * therefore be set against its budget.
     */
    private function isInAgreementCurrency(TransfersTracking $transfer): bool
    {
        return $transfer->amount_transfered_currency === $this->agreement->currency;
    }

    /**
     * The promise, split so its parts add back up to the budget.
     *
     * Every part is counted in the agreement's own currency. A received
     * tranche splits into what landed and what fees and exchange took on the
     * way; pending money counts only once, as upcoming, in transit or late. A
     * tranche sent in another currency has no rate to the budget in this
     * system, so it is counted apart rather than converted. Pending net
     * amounts are never counted as landed, even when they were filled in
     * ahead of time, and a received tranche with no net recorded counts as
     * nothing landed: its payment sits apart as unrecorded until someone
     * fills the net in. It is never read as the payment amount.
     *
     * @return array{budget: float, landed: float, lost: float, gained: float, unrecorded: float, unrecordedCount: int, receivedGross: float, upcoming: float, transit: float, late: float, failed: float, scheduled: float, unscheduled: float, overScheduled: float, stillToCome: float, percent: int, percentLabel: string, foreignCount: int, foreignLandings: array<string, float>, nextDue: CarbonImmutable|null, lateSince: CarbonImmutable|null}
     */
    #[Computed]
    public function money(): array
    {
        $budget = (float) $this->agreement->budget;
        $parts = ['landed' => 0.0, 'lost' => 0.0, 'gained' => 0.0, 'unrecorded' => 0.0, 'receivedGross' => 0.0, 'upcoming' => 0.0, 'transit' => 0.0, 'late' => 0.0, 'failed' => 0.0];
        $unrecordedCount = 0;
        $foreignCount = 0;
        $foreignLandings = [];
        $nextDue = null;
        $lateSince = null;

        foreach ($this->transfers as $transfer) {
            if (! $this->isInAgreementCurrency($transfer)) {
                $foreignCount++;

                continue;
            }

            $payment = (float) $transfer->payment_amount;
            $state = $this->trancheState($transfer);

            if ($state === 'received') {
                $parts['receivedGross'] += $payment;

                if ($transfer->isMissingNetAmount()) {
                    $parts['unrecorded'] += $payment;
                    $unrecordedCount++;
                } elseif ($transfer->amount_received_currency === $this->agreement->currency) {
                    $net = (float) $transfer->net_amount_received;
                    $parts['landed'] += $net;
                    $parts['lost'] += max(0.0, $payment - $net);
                    $parts['gained'] += max(0.0, $net - $payment);
                } else {
                    $code = $transfer->amount_received_currency->value;
                    $foreignLandings[$code] = ($foreignLandings[$code] ?? 0.0) + (float) $transfer->net_amount_received;
                }

                continue;
            }

            $parts[$state] += $payment;

            if ($state === 'late' && ($lateSince === null || $transfer->scheduled_date->lt($lateSince))) {
                $lateSince = $transfer->scheduled_date;
            }

            if (in_array($state, ['upcoming', 'transit'], true) && $transfer->scheduled_date !== null && ($nextDue === null || $transfer->scheduled_date->lt($nextDue))) {
                $nextDue = $transfer->scheduled_date;
            }
        }

        // Failed money is still owed and has its own reading, so it counts as
        // neither scheduled nor unscheduled.
        $scheduled = $parts['receivedGross'] + $parts['upcoming'] + $parts['transit'] + $parts['late'];

        return [
            'budget' => $budget,
            ...$parts,
            'scheduled' => $scheduled,
            'unscheduled' => max(0.0, $budget - $scheduled - $parts['failed']),
            'overScheduled' => max(0.0, $scheduled + $parts['failed'] - $budget),
            'stillToCome' => max(0.0, $budget - $parts['receivedGross']),
            'percent' => $budget > 0.0 ? (int) floor($parts['landed'] / $budget * 100) : 0,
            'percentLabel' => DonorProposal::percentLabel($parts['landed'], $budget),
            'unrecordedCount' => $unrecordedCount,
            'foreignCount' => $foreignCount,
            'foreignLandings' => $foreignLandings,
            'nextDue' => $nextDue,
            'lateSince' => $lateSince,
        ];
    }

    /**
     * Each tranche's journey from schedule to account, with its money traced
     * and the promise left after it.
     *
     * @return list<array{transfer: TransfersTracking, number: int, state: string, foreign: bool, stops: list<array{key: string, label: string, detail: string|null, state: string}>, timing: string|null, timingAlarm: bool, left: float|null}>
     */
    #[Computed]
    public function journeys(): array
    {
        $today = CarbonImmutable::today();
        $budget = $this->money['budget'];
        $running = 0.0;
        $journeys = [];

        foreach ($this->transfers->values() as $index => $transfer) {
            $state = $this->trancheState($transfer);
            $foreign = ! $this->isInAgreementCurrency($transfer);
            $isReceived = $state === 'received';
            $wasSent = $isReceived || $transfer->transfer_date !== null;

            if (! $foreign && $state !== 'failed') {
                $running += (float) $transfer->payment_amount;
            }

            $sentState = match (true) {
                $wasSent => 'done',
                $state === 'late' => 'late',
                default => 'ahead',
            };

            $receivedState = match (true) {
                $isReceived => 'done',
                $state === 'failed' => 'failed',
                default => 'ahead',
            };

            $stops = [
                [
                    'key' => 'scheduled',
                    'label' => __('funding_agreements.stop_scheduled'),
                    'detail' => $transfer->scheduled_date?->translatedFormat(__('formats.date_short')),
                    'state' => $transfer->scheduled_date !== null ? 'done' : 'ahead',
                ],
                [
                    'key' => 'sent',
                    'label' => __('funding_agreements.sent'),
                    'detail' => $transfer->transfer_date?->translatedFormat(__('formats.date_short')),
                    'state' => $sentState,
                ],
                [
                    'key' => 'received',
                    'label' => __('funding_agreements.received'),
                    'detail' => $transfer->receive_date?->translatedFormat(__('formats.date_short')),
                    'state' => $receivedState,
                ],
                [
                    'key' => 'account',
                    'label' => __('funding_agreements.stop_in_account'),
                    'detail' => $transfer->account?->account_name,
                    'state' => $isReceived && $transfer->account !== null ? 'done' : 'ahead',
                ],
            ];

            $timing = null;
            $timingAlarm = false;

            if ($isReceived && $transfer->receive_date !== null && $transfer->scheduled_date !== null) {
                $drift = (int) $transfer->scheduled_date->diffInDays($transfer->receive_date, false);
                $timing = match (true) {
                    $drift > 0 => trans_choice('funding_agreements.arrived_days_late', $drift, ['count' => $drift]),
                    $drift < 0 => trans_choice('funding_agreements.arrived_days_early', -$drift, ['count' => -$drift]),
                    default => __('funding_agreements.arrived_on_schedule'),
                };
            } elseif ($state === 'late') {
                $days = (int) $transfer->scheduled_date->diffInDays($today);
                $timing = trans_choice('funding_agreements.count_days_late', $days, ['count' => $days]);
                $timingAlarm = true;
            } elseif ($state === 'transit' && $transfer->scheduled_date !== null && $transfer->scheduled_date->lt($today)) {
                // Sent, so not late, but still not here after its date.
                $days = (int) $transfer->scheduled_date->diffInDays($today);
                $timing = trans_choice('funding_agreements.in_transit_days_past_its_date', $days, ['count' => $days]);
            } elseif (in_array($state, ['upcoming', 'transit'], true) && $transfer->scheduled_date !== null) {
                $days = (int) $today->diffInDays($transfer->scheduled_date);
                $timing = trans_choice('funding_agreements.due_in_days', $days, ['count' => $days]);
            } elseif ($state === 'failed') {
                $timing = __('funding_agreements.transfer_failed');
                $timingAlarm = true;
            }

            $journeys[] = [
                'transfer' => $transfer,
                'number' => $index + 1,
                'state' => $state,
                'foreign' => $foreign,
                'stops' => $stops,
                'timing' => $timing,
                'timingAlarm' => $timingAlarm,
                'left' => $foreign ? null : $budget - $running,
            ];
        }

        return $journeys;
    }

    /**
     * The dial: one arc per tranche in schedule order, sized by its payment
     * against the promise, with the fee a received tranche lost drawn as a
     * paler tail. Lengths are on a 0–100 circumference.
     *
     * @return list<array{id: int, state: string, start: float, length: float, net: float, label: string}>
     */
    #[Computed]
    public function arcs(): array
    {
        $money = $this->money;
        $whole = max($money['budget'], $money['scheduled'] + $money['failed'], 0.01);
        $countable = array_values(array_filter(
            $this->journeys,
            fn (array $journey): bool => ! $journey['foreign'] && (float) $journey['transfer']->payment_amount > 0.0,
        ));
        $gap = count($countable) > 1 || $money['unscheduled'] > 0.0 ? 0.6 : 0.0;
        $cursor = 0.0;
        $arcs = [];

        foreach ($countable as $journey) {
            $transfer = $journey['transfer'];
            $payment = (float) $transfer->payment_amount;
            $share = $payment / $whole * 100;
            $length = max(0.4, $share - $gap);
            $net = $length;

            if ($journey['state'] === 'received' && $transfer->amount_received_currency === $this->agreement->currency && $transfer->net_amount_received !== null) {
                $net = $length * min(1.0, (float) $transfer->net_amount_received / $payment);
            }

            $arcs[] = [
                'id' => $transfer->id,
                // Received with no net recorded is drawn apart so it never reads as landed.
                'state' => $transfer->isMissingNetAmount() ? 'unrecorded' : $journey['state'],
                'start' => round($cursor, 3),
                'length' => round($length, 3),
                'net' => round($net, 3),
                'label' => __('funding_agreements.tranche_summary', [
                    'amount' => number_format($payment, 2),
                    'currency' => $transfer->amount_transfered_currency->value,
                    'date' => $transfer->scheduled_date?->translatedFormat(__('formats.date')) ?? __('common.unscheduled'),
                    'state' => $transfer->isMissingNetAmount() ? __('funding_agreements.received_net_not_recorded') : $this->trancheStateLabel($journey['state']),
                ]),
            ];

            $cursor += $share;
        }

        return $arcs;
    }

    /**
     * The grant term this agreement pays into, and where today falls in it.
     *
     * @return array{begin: CarbonImmutable|null, end: CarbonImmutable|null, day: int, days: int, percent: float, phase: string}
     */
    #[Computed]
    public function term(): array
    {
        $proposal = $this->agreement->proposal;
        $begin = $proposal->actual_date_begin ?? $proposal->planned_date_begin;
        $end = $proposal->actual_date_end ?? $proposal->planned_date_end;
        $today = CarbonImmutable::today();

        if ($begin === null || $end === null || $end->lt($begin)) {
            return ['begin' => $begin, 'end' => $end, 'day' => 0, 'days' => 0, 'percent' => 0.0, 'phase' => 'unknown'];
        }

        $days = (int) $begin->diffInDays($end) + 1;
        $day = (int) $begin->diffInDays($today, false) + 1;

        return [
            'begin' => $begin,
            'end' => $end,
            'day' => max(0, min($days, $day)),
            'days' => $days,
            'percent' => round(max(0, min($days, $day)) / $days * 100, 2),
            'phase' => match (true) {
                $today->lt($begin) => 'ahead',
                $today->gt($end) => 'ended',
                default => 'running',
            },
        ];
    }
}
