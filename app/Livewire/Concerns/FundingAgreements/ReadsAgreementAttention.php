<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\FundingAgreements;

use App\Enums\ArStatus;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\TransfersTracking;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

/**
 * What needs chasing on funding-agreements/show: late, failed and stranded
 * tranches, reports coming due, a donor gone quiet, the term running out.
 *
 * Used by funding-agreements/show only. The page provides the #[Computed]
 * agreement() and transfers(); money, journeys and term come from
 * ReadsAgreementMoney, reports from ReadsAgreementConnections.
 *
 * @property-read DonorProposal $agreement  #[Computed] agreement()
 * @property-read Collection<int, TransfersTracking> $transfers  #[Computed] transfers()
 * @property-read array{budget: float, landed: float, lost: float, gained: float, unrecorded: float, unrecordedCount: int, receivedGross: float, upcoming: float, transit: float, late: float, failed: float, scheduled: float, unscheduled: float, overScheduled: float, stillToCome: float, percent: int, percentLabel: string, foreignCount: int, foreignLandings: array<string, float>, nextDue: CarbonImmutable|null, lateSince: CarbonImmutable|null} $money  #[Computed] money()
 * @property-read list<array{transfer: TransfersTracking, number: int, state: string, foreign: bool, stops: list<array{key: string, label: string, detail: string|null, state: string}>, timing: string|null, timingAlarm: bool, left: float|null}> $journeys  #[Computed] journeys()
 * @property-read array{begin: CarbonImmutable|null, end: CarbonImmutable|null, day: int, days: int, percent: float, phase: string} $term  #[Computed] term()
 * @property-read Collection<int, DonorReport> $reports  #[Computed] reports()
 */
trait ReadsAgreementAttention
{
    /** How many days ahead a term end counts as close enough to warn about. */
    private const TERM_WARNING_DAYS = 60;

    /** How many days ahead a donor report due date is worth warning about. */
    private const REPORT_WARNING_DAYS = 30;

    /** How long since the last contact before a donor counts as gone quiet. */
    private const CONTACT_STALE_DAYS = 90;

    /**
     * What needs chasing on this agreement, most urgent first.
     *
     * @return list<array{key: string, text: string, critical: bool}>
     */
    #[Computed]
    public function attention(): array
    {
        $money = $this->money;
        $currency = $this->agreement->currency->value;
        $donor = $this->agreement->donor;
        $items = [];

        foreach ($this->journeys as $journey) {
            $transfer = $journey['transfer'];

            if ($journey['state'] === 'late') {
                $items[] = [
                    'key' => 'late-'.$transfer->id,
                    'text' => __('funding_agreements.attention_late', [
                        'amount' => number_format((float) $transfer->payment_amount, 2),
                        'currency' => $transfer->amount_transfered_currency->value,
                        'date' => $transfer->scheduled_date->translatedFormat(__('formats.date')),
                        'late' => $journey['timing'],
                    ]),
                    'critical' => true,
                ];
            }

            if ($journey['state'] === 'failed') {
                $items[] = [
                    'key' => 'failed-'.$transfer->id,
                    'text' => __('funding_agreements.attention_failed', [
                        'amount' => number_format((float) $transfer->payment_amount, 2),
                        'currency' => $transfer->amount_transfered_currency->value,
                    ]),
                    'critical' => true,
                ];
            }

            if ($journey['state'] === 'transit' && $transfer->scheduled_date !== null && $transfer->scheduled_date->lt(CarbonImmutable::today())) {
                $items[] = [
                    'key' => 'transit-'.$transfer->id,
                    'text' => __('funding_agreements.attention_in_transit', [
                        'amount' => number_format((float) $transfer->payment_amount, 2),
                        'currency' => $transfer->amount_transfered_currency->value,
                        'date' => $transfer->transfer_date->translatedFormat(__('formats.date')),
                        'late' => $journey['timing'],
                    ]),
                    'critical' => false,
                ];
            }
        }

        if (! $this->agreement->proposal->status->isApprovedByDonor()) {
            $items[] = [
                'key' => 'proposal-status',
                'text' => __('funding_agreements.proposal_not_approved_warning', ['status' => $this->agreement->proposal->status->label()]),
                'critical' => false,
            ];
        }

        if ($money['unrecordedCount'] > 0) {
            $items[] = [
                'key' => 'net-missing',
                'text' => trans_choice('funding_agreements.attention_net_missing', $money['unrecordedCount'], [
                    'count' => $money['unrecordedCount'],
                    'amount' => number_format($money['unrecorded'], 2),
                    'currency' => $currency,
                ]),
                'critical' => false,
            ];
        }

        foreach ($this->reports as $report) {
            if ($report->isSubmitted()) {
                continue;
            }

            $daysToGo = (int) CarbonImmutable::today()->diffInDays($report->due_on, false);

            if ($daysToGo < 0) {
                $items[] = [
                    'key' => 'report-'.$report->id,
                    'text' => trans_choice('funding_agreements.attention_report_overdue', -$daysToGo, [
                        'title' => $report->title,
                        'date' => $report->due_on->translatedFormat(__('formats.date')),
                        'count' => -$daysToGo,
                    ]),
                    'critical' => true,
                ];
            } elseif ($daysToGo <= self::REPORT_WARNING_DAYS) {
                $items[] = [
                    'key' => 'report-'.$report->id,
                    'text' => trans_choice('funding_agreements.attention_report_due', $daysToGo, [
                        'title' => $report->title,
                        'date' => $report->due_on->translatedFormat(__('formats.date')),
                        'count' => $daysToGo,
                    ]),
                    'critical' => false,
                ];
            }
        }

        if ($this->donorContactIsStale()) {
            $items[] = [
                'key' => 'contact',
                'text' => $donor->last_contacted_on === null
                    ? __('funding_agreements.attention_never_contacted', ['donor' => $donor->name])
                    : trans_choice('funding_agreements.attention_contact_stale', (int) $donor->last_contacted_on->diffInDays(CarbonImmutable::today()), [
                        'donor' => $donor->name,
                        'count' => (int) $donor->last_contacted_on->diffInDays(CarbonImmutable::today()),
                    ]),
                'critical' => false,
            ];
        }

        if ($donor->isFollowUpOverdue()) {
            $items[] = [
                'key' => 'follow-up',
                'text' => __('funding_agreements.attention_follow_up', [
                    'donor' => $donor->name,
                    'date' => $donor->next_step_due_on->translatedFormat(__('formats.date')),
                ]),
                'critical' => true,
            ];
        }

        $unassigned = $this->transfers
            ->filter(fn (TransfersTracking $transfer): bool => $transfer->account_id === null && $transfer->ar_status !== ArStatus::Failed)
            ->count();

        if ($unassigned > 0) {
            $items[] = [
                'key' => 'no-account',
                'text' => trans_choice('funding_agreements.attention_no_account', $unassigned, ['count' => $unassigned]),
                'critical' => false,
            ];
        }

        if ($money['unscheduled'] > 0.0) {
            $items[] = [
                'key' => 'unscheduled',
                'text' => __('funding_agreements.attention_unscheduled', ['amount' => number_format($money['unscheduled'], 2), 'currency' => $currency]),
                'critical' => false,
            ];
        }

        if ($money['overScheduled'] > 0.0) {
            $items[] = [
                'key' => 'over-scheduled',
                'text' => __('funding_agreements.attention_over_scheduled', ['amount' => number_format($money['overScheduled'], 2), 'currency' => $currency]),
                'critical' => false,
            ];
        }

        $term = $this->term;

        if ($money['stillToCome'] > 0.0 && $term['end'] !== null && $term['phase'] !== 'unknown') {
            $daysLeft = (int) CarbonImmutable::today()->diffInDays($term['end'], false);

            if ($daysLeft < 0) {
                $items[] = [
                    'key' => 'term',
                    'text' => __('funding_agreements.attention_term_ended', ['date' => $term['end']->translatedFormat(__('formats.date')), 'amount' => number_format($money['stillToCome'], 2), 'currency' => $currency]),
                    'critical' => true,
                ];
            } elseif ($daysLeft <= self::TERM_WARNING_DAYS) {
                $items[] = [
                    'key' => 'term',
                    'text' => __('funding_agreements.attention_term_ending', ['date' => $term['end']->translatedFormat(__('formats.date')), 'amount' => number_format($money['stillToCome'], 2), 'currency' => $currency]),
                    'critical' => false,
                ];
            }
        }

        usort($items, fn (array $a, array $b): int => $b['critical'] <=> $a['critical']);

        return $items;
    }

    /**
     * Nobody has spoken to the donor in a while, or ever.
     */
    protected function donorContactIsStale(): bool
    {
        $lastContact = $this->agreement->donor->last_contacted_on;

        return $lastContact === null || $lastContact->lt(CarbonImmutable::today()->subDays(self::CONTACT_STALE_DAYS));
    }
}
