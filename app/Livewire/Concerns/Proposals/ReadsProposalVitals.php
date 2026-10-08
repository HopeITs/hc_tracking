<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Enums\ProposalStatus;
use App\Enums\TaskStatus;
use App\Models\DonorProposal;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

/**
 * The four readings on the proposals/show bench: money landed, work done,
 * time spent and promises reported.
 *
 * Used by proposals/show only. It reads the page's #[Computed] proposal()
 * and the readings of ReadsProposalFunding, ReadsProposalDelivery and
 * ChartsProposalChronology; canViewAgreements() comes from
 * ChecksFinanceVisibility.
 *
 * @property-read Proposal $proposal  #[Computed] proposal()
 * @property-read array<string, array{committed: float, received: float, pending: float, unscheduled: float, percent: int, overdue: float, overdueCount: int, failedCount: int, nextDue: CarbonImmutable|null}> $moneyByCurrency  #[Computed] moneyByCurrency()
 * @property-read Collection<int, Project> $projects  #[Computed] projects()
 * @property-read Collection<int, Task> $tasks  #[Computed] tasks()
 * @property-read Collection<int, PlanIndicator> $targets  #[Computed] targets()
 * @property-read array{start: CarbonImmutable, end: CarbonImmutable, days: int, dayIn: int, percent: float, state: string, untilStart: int, overBy: int} $term  #[Computed] term()
 */
trait ReadsProposalVitals
{
    /**
     * The four readings on the summary line, in order.
     *
     * Each carries its figure, caption, the tab holding its evidence, bar
     * segments and whether it is the one asking for attention. The money
     * reading also names every currency after the one its figure leads with.
     *
     * @return array<int, array{key: string, tab: string, label: string, figure: string, suffix: string|null, caption: string, alarm: bool, others?: list<array{currency: string, text: string, alarm: bool}>, segments: array<int, array{width: float, class: string}>}>
     */
    #[Computed]
    public function vitals(): array
    {
        return [
            ...($this->canViewAgreements() ? [$this->moneyVital()] : []),
            $this->workVital(),
            $this->timeVital(),
            $this->promisesVital(),
        ];
    }

    /** @return array{key: string, tab: string, label: string, figure: string, suffix: string|null, caption: string, alarm: bool, segments: array<int, array{width: float, class: string}>} */
    private function moneyVital(): array
    {
        $lines = $this->moneyByCurrency;

        if ($lines === []) {
            return [
                'key' => 'money',
                'tab' => 'funding',
                'label' => __('proposals.money_landed'),
                'figure' => '—',
                'suffix' => null,
                'caption' => __('proposals.no_funding_agreements'),
                'alarm' => false,
                'segments' => [],
            ];
        }

        $currency = array_key_first($lines);
        $line = $lines[$currency];
        $base = max($line['committed'], $line['received'] + $line['pending'], 1.0);

        // The figure leads with the largest currency; every other one is named
        // beneath it rather than left for the funding tab to reveal.
        $others = [];

        foreach (array_slice($lines, 1, null, true) as $otherCurrency => $otherLine) {
            $others[] = [
                'currency' => $otherCurrency,
                'text' => __('proposals.other_currency_landed', [
                    'percent' => DonorProposal::percentLabel($otherLine['received'], $otherLine['committed']),
                    'received' => number_format($otherLine['received'], 0),
                    'committed' => number_format($otherLine['committed'], 0),
                    'currency' => $otherCurrency,
                ]),
                'alarm' => $otherLine['overdueCount'] > 0 || $otherLine['failedCount'] > 0,
            ];
        }

        return [
            'key' => 'money',
            'tab' => 'funding',
            'label' => __('proposals.money_landed'),
            'figure' => DonorProposal::percentLabel($line['received'], $line['committed']),
            'suffix' => '%',
            'caption' => __('proposals.received_of_committed', [
                'received' => number_format($line['received'], 0),
                'committed' => number_format($line['committed'], 0),
                'currency' => $currency,
            ]),
            'alarm' => $line['overdueCount'] > 0 || $line['failedCount'] > 0,
            'others' => $others,
            'segments' => [
                ['width' => round($line['received'] / $base * 100, 2), 'class' => 'bg-funding'],
                ['width' => round($line['pending'] / $base * 100, 2), 'class' => 'money-pending'],
            ],
        ];
    }

    /** @return array{key: string, tab: string, label: string, figure: string, suffix: string|null, caption: string, alarm: bool, segments: array<int, array{width: float, class: string}>} */
    private function workVital(): array
    {
        $total = $this->tasks->count();
        $done = $this->tasks->where('status', TaskStatus::Done)->count();
        $projectCount = $this->projects->count();

        return [
            'key' => 'work',
            'tab' => 'tasks',
            'label' => __('proposals.work_done'),
            'figure' => (string) $done,
            'suffix' => '/'.$total,
            'caption' => $total === 0
                ? trans_choice('common.count_project', $projectCount, ['count' => $projectCount])
                : trans_choice('proposals.tasks_across_count_projects', $projectCount, ['count' => $projectCount]),
            'alarm' => $total > 0 && $done < $total && $this->term['state'] === 'over',
            'segments' => [
                ['width' => $total > 0 ? round($done / $total * 100, 2) : 0.0, 'class' => 'bg-secondary-dark dark:bg-ink-muted'],
            ],
        ];
    }

    /** @return array{key: string, tab: string, label: string, figure: string, suffix: string|null, caption: string, alarm: bool, segments: array<int, array{width: float, class: string}>} */
    private function timeVital(): array
    {
        $term = $this->term;

        $caption = match ($term['state']) {
            'before' => trans_choice('proposals.starts_in_count_days', $term['untilStart'], ['count' => $term['untilStart']]),
            'over' => trans_choice('proposals.count_days_past_the_planned_end', $term['overBy'], ['count' => $term['overBy']]),
            default => __('proposals.days_into_the_grant_term'),
        };

        return [
            'key' => 'time',
            'tab' => 'deadlines',
            'label' => __('proposals.time_spent'),
            'figure' => (string) $term['dayIn'],
            'suffix' => '/'.$term['days'],
            'caption' => $caption,
            'alarm' => $term['state'] === 'over' && $this->proposal->status !== ProposalStatus::Completed,
            'segments' => [
                ['width' => $term['percent'], 'class' => 'bg-secondary-medium dark:bg-ink-muted'],
            ],
        ];
    }

    /** @return array{key: string, tab: string, label: string, figure: string, suffix: string|null, caption: string, alarm: bool, segments: array<int, array{width: float, class: string}>} */
    private function promisesVital(): array
    {
        $total = $this->targets->count();
        $reported = $this->targets->filter(fn (PlanIndicator $target): bool => $target->reports->isNotEmpty())->count();

        return [
            'key' => 'promises',
            'tab' => 'targets',
            'label' => __('proposals.promises_reported'),
            'figure' => (string) $reported,
            'suffix' => '/'.$total,
            'caption' => $total === 0
                ? __('proposals.no_targets_committed_here')
                : __('proposals.targets_carrying_at_least_one_report'),
            'alarm' => $total > 0 && $reported === 0 && $this->term['state'] !== 'before',
            'segments' => [
                ['width' => $total > 0 ? round($reported / $total * 100, 2) : 0.0, 'class' => 'bg-funding'],
            ],
        ];
    }
}
