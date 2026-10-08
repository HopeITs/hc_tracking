<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\ExecutiveSummary;

use Livewire\Attributes\Computed;

/**
 * The year stated as pairs: the struck balance, the ledger of every
 * commitment against what answered it, and the breakdown of the open row.
 *
 * Used by executive-summary/index only. The page provides the #[Url]
 * $openRow and the #[Computed] figures / priorFigures (built by
 * ReadsSummaryCoverage::figuresFor()). balance() and ledger() both call
 * percentageOf() and shareOfAmount() from ReadsSummaryCoverage and toneFor()
 * and deltaBetween() from LoadsSummarySources; balance() also calls
 * leadingCurrency() and ledger() also calls bandsFor() from there.
 * breakdown() calls targetsByProgram(), moneyByAgreement(),
 * milestonesByProject() and reportsByIndicator() from LoadsSummarySources.
 *
 * @property string $openRow
 * @property-read array<string, mixed> $figures
 * @property-read array<string, mixed> $priorFigures
 */
trait ReadsSummaryLedger
{
    /**
     * The struck balance: the three pairs that state the year in one line.
     *
     * Each entry is a share of a real pair, never a bare total — money is the
     * leading currency's own share, because USD, EUR and SYP are not summable.
     *
     * @return array<int, array{key: string, label: string, covered: int|float, total: int|float, unit: string, share: int|null, delta: int|null, tone: string, currency: string|null, href: string}>
     */
    #[Computed]
    public function balance(): array
    {
        $now = $this->figures;
        $before = $this->priorFigures;
        $money = $this->leadingCurrency($now['money']);
        $priorMoney = $money === null ? null : ($before['money'][$money['code']] ?? null);

        return [
            [
                'key' => 'planned',
                'label' => __('executive_summary.programs_planned'),
                'covered' => $now['plans']['written'],
                'total' => $now['plans']['programs'],
                'unit' => __('executive_summary.programs'),
                'share' => $this->percentageOf($now['plans']['written'], $now['plans']['programs']),
                'delta' => $this->deltaBetween(
                    $this->percentageOf($now['plans']['written'], $now['plans']['programs']),
                    $this->percentageOf($before['plans']['written'], $before['plans']['programs']),
                ),
                'tone' => $this->toneFor($this->percentageOf($now['plans']['written'], $now['plans']['programs'])),
                'currency' => null,
                'href' => route('annual-plans.index'),
            ],
            [
                'key' => 'targets',
                'label' => __('executive_summary.targets_met'),
                'covered' => $now['targets']['achieved'],
                'total' => $now['targets']['committed'],
                'unit' => __('executive_summary.targets'),
                'share' => $this->percentageOf($now['targets']['achieved'], $now['targets']['committed']),
                'delta' => $this->deltaBetween(
                    $this->percentageOf($now['targets']['achieved'], $now['targets']['committed']),
                    $this->percentageOf($before['targets']['achieved'], $before['targets']['committed']),
                ),
                'tone' => $this->toneFor($this->percentageOf($now['targets']['achieved'], $now['targets']['committed'])),
                'currency' => null,
                'href' => route('targets.index'),
            ],
            [
                'key' => 'money',
                'label' => __('executive_summary.money_landed'),
                'covered' => $money['landed'] ?? 0.0,
                'total' => $money['scheduled'] ?? 0.0,
                'unit' => $money['code'] ?? '',
                'share' => $money === null ? null : $this->shareOfAmount($money['landed'], $money['scheduled']),
                'delta' => $money === null || $priorMoney === null ? null : $this->deltaBetween(
                    $this->shareOfAmount($money['landed'], $money['scheduled']),
                    $this->shareOfAmount($priorMoney['landed'], $priorMoney['scheduled']),
                ),
                'tone' => $this->toneFor($money === null ? null : $this->shareOfAmount($money['landed'], $money['scheduled'])),
                'currency' => $money['code'] ?? null,
                'href' => route('transfers.index'),
            ],
        ];
    }

    /**
     * The spread: every commitment the year made, against what answered it.
     *
     * Money contributes one row per currency present in the year, because the
     * three currencies are never summed or drawn on one scale.
     *
     * @return array<int, array{key: string, kind: string, committedLabel: string, committed: int|float, committedUnit: string, committedNote: string, deliveredLabel: string, delivered: int|float, deliveredUnit: string, deliveredNote: string, gap: int|float, gapLabel: string, share: int|null, bands: array<int, array{share: int, tone: string}>, tone: string, delta: int|null, currency: string|null, href: string, hrefLabel: string}>
     */
    #[Computed]
    public function ledger(): array
    {
        $now = $this->figures;
        $before = $this->priorFigures;
        $rows = [];

        $targets = $now['targets'];
        $targetShare = $this->percentageOf($targets['achieved'], $targets['committed']);
        $rows[] = [
            'key' => 'targets',
            'kind' => 'count',
            'committedLabel' => __('executive_summary.targets_committed'),
            'committed' => $targets['committed'],
            'committedUnit' => __('executive_summary.targets'),
            'committedNote' => __('executive_summary.across_programs_indicators', [
                'programs' => $targets['programs'],
                'indicators' => $targets['indicators'],
            ]),
            'deliveredLabel' => __('executive_summary.targets_reached'),
            'delivered' => $targets['achieved'],
            'deliveredUnit' => __('executive_summary.met'),
            'deliveredNote' => __('executive_summary.in_progress_not_started', [
                'progress' => $targets['inProgress'],
                'idle' => $targets['notStarted'],
            ]),
            'gap' => $targets['committed'] - $targets['achieved'],
            'gapLabel' => __('executive_summary.unmet'),
            'share' => $targetShare,
            'bands' => $this->bandsFor([
                [$targets['achieved'], 'good'],
                [$targets['inProgress'], 'warn'],
            ], $targets['committed']),
            'tone' => $this->toneFor($targetShare),
            'delta' => $this->deltaBetween($targetShare, $this->percentageOf($before['targets']['achieved'], $before['targets']['committed'])),
            'currency' => null,
            'href' => route('targets.index'),
            'hrefLabel' => __('executive_summary.open_targets'),
        ];

        foreach ($now['money'] as $code => $line) {
            $moneyShare = $this->shareOfAmount($line['landed'], $line['scheduled']);
            $priorLine = $before['money'][$code] ?? null;

            $rows[] = [
                'key' => 'money-'.$code,
                'kind' => 'money',
                'committedLabel' => __('executive_summary.scheduled_to_arrive'),
                'committed' => $line['scheduled'],
                'committedUnit' => $code,
                'committedNote' => trans_choice('executive_summary.count_tranches_scheduled', $line['scheduledTranches'], ['count' => $line['scheduledTranches']]),
                'deliveredLabel' => __('executive_summary.landed_in_account'),
                'delivered' => $line['landed'],
                'deliveredUnit' => $code,
                'deliveredNote' => trans_choice('executive_summary.count_tranches_received', $line['landedTranches'], ['count' => $line['landedTranches']]),
                'gap' => max(0.0, $line['scheduled'] - $line['landed']),
                'gapLabel' => __('executive_summary.outstanding'),
                'share' => $moneyShare,
                'bands' => $this->bandsFor([[$line['landed'], 'funding']], $line['scheduled']),
                'tone' => $this->toneFor($moneyShare),
                'delta' => $priorLine === null ? null : $this->deltaBetween($moneyShare, $this->shareOfAmount($priorLine['landed'], $priorLine['scheduled'])),
                'currency' => $code,
                'href' => route('transfers.index'),
                'hrefLabel' => __('executive_summary.open_transfers'),
            ];
        }

        $milestones = $now['milestones'];
        $milestoneShare = $this->percentageOf($milestones['reached'], $milestones['due']);
        $rows[] = [
            'key' => 'milestones',
            'kind' => 'count',
            'committedLabel' => __('executive_summary.milestones_due'),
            'committed' => $milestones['due'],
            'committedUnit' => __('executive_summary.milestones'),
            'committedNote' => __('executive_summary.dated_in_this_year'),
            'deliveredLabel' => __('executive_summary.milestones_reached'),
            'delivered' => $milestones['reached'],
            'deliveredUnit' => __('executive_summary.reached'),
            'deliveredNote' => __('executive_summary.ongoing_postponed', [
                'ongoing' => $milestones['ongoing'],
                'postponed' => $milestones['postponed'],
            ]),
            'gap' => $milestones['due'] - $milestones['reached'],
            'gapLabel' => __('executive_summary.not_reached'),
            'share' => $milestoneShare,
            'bands' => $this->bandsFor([
                [$milestones['reached'], 'good'],
                [$milestones['ongoing'], 'warn'],
            ], $milestones['due']),
            'tone' => $this->toneFor($milestoneShare),
            'delta' => $this->deltaBetween($milestoneShare, $this->percentageOf($before['milestones']['reached'], $before['milestones']['due'])),
            'currency' => null,
            'href' => route('milestones.index'),
            'hrefLabel' => __('executive_summary.open_milestones'),
        ];

        $reports = $now['reports'];
        $reportShare = $this->percentageOf($reports['approved'], $reports['owed']);
        $rows[] = [
            'key' => 'reports',
            'kind' => 'count',
            'committedLabel' => __('executive_summary.reports_owed'),
            'committed' => $reports['owed'],
            'committedUnit' => __('executive_summary.reports'),
            'committedNote' => __('executive_summary.planned_for_this_year'),
            'deliveredLabel' => __('executive_summary.reports_approved'),
            'delivered' => $reports['approved'],
            'deliveredUnit' => __('executive_summary.approved'),
            'deliveredNote' => __('executive_summary.in_review_still_draft', [
                'review' => $reports['reviewed'],
                'draft' => $reports['draft'],
            ]),
            'gap' => $reports['owed'] - $reports['approved'],
            'gapLabel' => __('executive_summary.not_accepted'),
            'share' => $reportShare,
            'bands' => $this->bandsFor([
                [$reports['approved'], 'good'],
                [$reports['reviewed'], 'warn'],
            ], $reports['owed']),
            'tone' => $this->toneFor($reportShare),
            'delta' => $this->deltaBetween($reportShare, $this->percentageOf($before['reports']['approved'], $before['reports']['owed'])),
            'currency' => null,
            'href' => route('reports.index'),
            'hrefLabel' => __('executive_summary.open_reports'),
        ];

        return $rows;
    }

    /**
     * The rows that produced the open row's gap, widest gap first.
     *
     * @return array<int, array{key: string, name: string, context: string, committed: int|float, delivered: int|float, share: int|null, tone: string, href: string|null}>
     */
    #[Computed]
    public function breakdown(): array
    {
        if ($this->openRow === '') {
            return [];
        }

        if ($this->openRow === 'targets') {
            return $this->targetsByProgram();
        }

        if (str_starts_with($this->openRow, 'money-')) {
            return $this->moneyByAgreement(substr($this->openRow, strlen('money-')));
        }

        if ($this->openRow === 'milestones') {
            return $this->milestonesByProject();
        }

        if ($this->openRow === 'reports') {
            return $this->reportsByIndicator();
        }

        return [];
    }
}
