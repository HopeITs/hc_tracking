<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Enums\ArStatus;
use App\Enums\ProposalStatus;
use App\Models\DonorProposal;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

/**
 * Everything dated on proposals/show, placed on one scale: the grant term,
 * the chronology, the money's climb and the lifecycle rail.
 *
 * Used by proposals/show only. It reads the page's #[Computed] proposal()
 * and statusNotes(), and the readings of ReadsProposalFunding and
 * ReadsProposalDelivery.
 *
 * @property-read Proposal $proposal  #[Computed] proposal()
 * @property-read Collection<int, ProposalStatusNote> $statusNotes  #[Computed] statusNotes()
 * @property-read Collection<int, DonorProposal> $agreements  #[Computed] agreements()
 * @property-read array<string, array{committed: float, received: float, pending: float, unscheduled: float, percent: int, overdue: float, overdueCount: int, failedCount: int, nextDue: CarbonImmutable|null}> $moneyByCurrency  #[Computed] moneyByCurrency()
 * @property-read Collection<int, Project> $projects  #[Computed] projects()
 * @property-read array{start: CarbonImmutable, end: CarbonImmutable, days: int, dayIn: int, percent: float, state: string, untilStart: int, overBy: int} $term  #[Computed] term()
 * @property-read array{start: CarbonImmutable, end: CarbonImmutable, todayPercent: float|null, termStart: float, termWidth: float, projects: array<int, array{id: int, name: string, start: float, width: float}>, money: array<int, array{key: string, at: float, date: CarbonImmutable, amount: float, currency: string, state: string}>, moves: array<int, array{at: float, date: CarbonImmutable, status: ProposalStatus}>} $chronology  #[Computed] chronology()
 */
trait ChartsProposalChronology
{
    /**
     * Where the grant sits in its own term.
     *
     * The clock starts at the actual begin when one was recorded and the
     * planned begin otherwise, and stops at the actual end once the work has
     * ended. A one-day term still divides by one rather than by zero.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable, days: int, dayIn: int, percent: float, state: string, untilStart: int, overBy: int}
     */
    #[Computed]
    public function term(): array
    {
        $today = CarbonImmutable::today();
        $start = $this->proposal->actual_date_begin ?? $this->proposal->planned_date_begin ?? $today;
        $end = $this->proposal->actual_date_end ?? $this->proposal->planned_date_end ?? $start;

        if ($end->lt($start)) {
            $end = $start;
        }

        $days = max(1, (int) $start->diffInDays($end) + 1);
        $rawDayIn = (int) $start->diffInDays($today, false) + 1;
        $dayIn = max(0, min($days, $rawDayIn));

        return [
            'start' => $start,
            'end' => $end,
            'days' => $days,
            'dayIn' => $dayIn,
            'percent' => round($dayIn / $days * 100, 2),
            'state' => match (true) {
                $today->lt($start) => 'before',
                $today->gt($end) => 'over',
                default => 'running',
            },
            'untilStart' => max(0, (int) $today->diffInDays($start, false)),
            'overBy' => max(0, (int) $end->diffInDays($today, false)),
        ];
    }

    /**
     * Everything dated on this proposal, placed on one scale.
     *
     * The scale spans the widest of the grant term, the projects it funds, its
     * money movements, its status moves and today, so nothing dated falls off
     * the end. Positions come back as percentages ready to be written straight
     * into inline offsets — the view does no date arithmetic of its own.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable, todayPercent: float|null, termStart: float, termWidth: float, projects: array<int, array{id: int, name: string, start: float, width: float}>, money: array<int, array{key: string, at: float, date: CarbonImmutable, amount: float, currency: string, state: string}>, moves: array<int, array{at: float, date: CarbonImmutable, status: ProposalStatus}>}
     */
    #[Computed]
    public function chronology(): array
    {
        $today = CarbonImmutable::today();
        $term = $this->term;
        $dates = [$term['start'], $term['end'], $today];

        foreach ($this->projects as $project) {
            $dates[] = $project->start_date;
            $dates[] = $project->end_date;
        }

        foreach ($this->agreements as $agreement) {
            foreach ($agreement->transfers as $transfer) {
                $dates[] = $transfer->receive_date ?? $transfer->transfer_date ?? $transfer->scheduled_date;
            }
        }

        foreach ($this->statusNotes as $note) {
            $dates[] = $note->date;
        }

        $dates = collect($dates)->filter();
        $start = $dates->min();
        $end = $dates->max();
        $span = max(1, (int) $start->diffInDays($end));

        $at = fn (?CarbonImmutable $date): ?float => $date === null
            ? null
            : round(max(0, min($span, (int) $start->diffInDays($date))) / $span * 100, 3);

        $projects = [];

        foreach ($this->projects as $project) {
            $from = $at($project->start_date) ?? 0.0;
            $to = $at($project->end_date) ?? $from;

            $projects[] = [
                'id' => $project->id,
                'name' => $project->name,
                'start' => $from,
                'width' => max(2.0, $to - $from),
            ];
        }

        $money = [];

        foreach ($this->agreements as $agreement) {
            foreach ($agreement->transfers as $transfer) {
                $date = $transfer->receive_date ?? $transfer->transfer_date ?? $transfer->scheduled_date;

                if ($date === null) {
                    continue;
                }

                $isReceived = $transfer->ar_status === ArStatus::Received;

                $money[] = [
                    'key' => 'transfer-'.$transfer->id,
                    'at' => $at($date) ?? 0.0,
                    'date' => $date,
                    'amount' => (float) ($isReceived ? $transfer->net_amount_received : $transfer->payment_amount),
                    'currency' => ($isReceived ? $transfer->amount_received_currency : $transfer->amount_transfered_currency)?->value ?? '',
                    'state' => match (true) {
                        $isReceived => 'received',
                        $transfer->ar_status === ArStatus::Failed => 'failed',
                        $this->transferIsOverdue($transfer) => 'overdue',
                        default => 'pending',
                    },
                ];
            }
        }

        usort($money, fn (array $a, array $b): int => $a['at'] <=> $b['at']);
        // Gaps are sized for a phone-width scale, where one label spans about a quarter of it.
        $money = $this->laneLabels($money, 24.0);

        $moves = [];

        foreach ($this->statusNotes->sortBy('date') as $note) {
            if ($note->date === null) {
                continue;
            }

            $moves[] = ['at' => $at($note->date) ?? 0.0, 'date' => $note->date, 'status' => $note->status];
        }

        $moves = $this->laneLabels($moves, 18.0);

        $termStart = $at($term['start']) ?? 0.0;

        return [
            'start' => $start,
            'end' => $end,
            'todayPercent' => $today->betweenIncluded($start, $end) ? $at($today) : null,
            'termStart' => $termStart,
            'termWidth' => max(2.0, ($at($term['end']) ?? 100.0) - $termStart),
            'projects' => $projects,
            'money' => $money,
            'moves' => $moves,
        ];
    }

    /**
     * Give each label on a shared scale a lane and an anchor so none overlap
     * and none spill past either edge.
     *
     * Labels are placed left to right; a label starting within `$gap` percent
     * of the last one in a lane drops to the next lane. Labels near an edge
     * anchor to that edge instead of centring on their point.
     *
     * @template T of array{at: float}
     *
     * @param  list<T>  $labels
     * @return list<T>
     */
    private function laneLabels(array $labels, float $gap): array
    {
        $laneEnds = [];

        foreach ($labels as $index => $label) {
            $lane = 0;

            while (isset($laneEnds[$lane]) && $gap > $label['at'] - $laneEnds[$lane]) {
                $lane++;
            }

            $laneEnds[$lane] = $label['at'];
            $labels[$index]['lane'] = $lane;
            $labels[$index]['anchor'] = match (true) {
                $label['at'] < 6.0 => 'start',
                $label['at'] > 94.0 => 'end',
                default => 'center',
            };
        }

        return $labels;
    }

    /**
     * The money's climb towards what was committed, on the chronology's own
     * scale, and the payload the day scrubber reads.
     *
     * One climb per committed currency, never a single line: USD, EUR and SYP
     * have no conversion rate in this system, so one combined total would be
     * invented. The plot is as tall as whatever actually happened, so money
     * that overran its commitment is drawn rather than clipped away.
     *
     * The scrub offsets are day counts from the start of the scale and are
     * deliberately left unclamped — a project that began before the scale is
     * still running on its first day, and the readout has to say so.
     *
     * @return array{climbs: array<int, array{currency: string, committed: float, landed: float, ceiling: float, line: string, area: string}>, scrub: array<string, mixed>}
     */
    #[Computed]
    public function arrivals(): array
    {
        $chronology = $this->chronology;
        $start = $chronology['start'];
        $span = max(1, (int) $start->diffInDays($chronology['end']));
        $climbs = [];

        foreach ($this->moneyByCurrency as $currency => $line) {
            $base = max($line['committed'], $line['received'] + $line['pending'], 1.0);
            $running = 0.0;
            $points = [];

            foreach ($chronology['money'] as $event) {
                if ($event['currency'] !== $currency || $event['state'] !== 'received') {
                    continue;
                }

                $x = round($event['at'] * 10, 2);
                $points[] = $x.','.round(100 - $running / $base * 100, 2);
                $running += $event['amount'];
                $points[] = $x.','.round(100 - $running / $base * 100, 2);
            }

            $settled = round(100 - $running / $base * 100, 2);
            $polyline = $points === []
                ? '0,100 1000,100'
                : '0,100 '.implode(' ', $points).' 1000,'.$settled;

            $climbs[] = [
                'currency' => $currency,
                'committed' => $line['committed'],
                'landed' => $running,
                'ceiling' => round(100 - $line['committed'] / $base * 100, 2),
                'line' => $polyline,
                'area' => 'M'.str_replace(' ', ' L', $polyline).' L1000,100 Z',
            ];
        }

        $offset = fn (?CarbonImmutable $date): ?int => $date === null ? null : (int) $start->diffInDays($date, false);

        return [
            'climbs' => $climbs,
            'scrub' => [
                'days' => $span,
                'start' => $start->toDateString(),
                'today' => max(0, min($span, (int) $start->diffInDays(CarbonImmutable::today(), false))),
                // en-US rather than en-GB so a scrubbed date reads "Sep 21, 2026",
                // matching formats.date; Arabic keeps Latin digits so a scrubbed
                // figure matches every other tabular number on the page.
                'locale' => app()->getLocale() === 'ar' ? 'ar-u-nu-latn' : 'en-US',
                'money' => array_map(fn (array $climb): array => [
                    'currency' => $climb['currency'],
                    'committed' => round($climb['committed'], 2),
                    'transfers' => array_values(array_map(
                        fn (array $event): array => [
                            'at' => $offset($event['date']),
                            'amount' => round($event['amount'], 2),
                            'received' => $event['state'] === 'received',
                        ],
                        array_filter($chronology['money'], fn (array $event): bool => $event['currency'] === $climb['currency']),
                    )),
                ], $climbs),
                'projects' => $this->projects
                    ->map(fn ($project): array => ['from' => $offset($project->start_date), 'to' => $offset($project->end_date)])
                    ->values()
                    ->all(),
            ],
        ];
    }

    /**
     * The lifecycle rail: seven stops, each with the date it was reached.
     *
     * A rejection answers a submission, so the rail runs to Submitted and the
     * stop after it is redrawn as the rejection rather than as the next step.
     *
     * @return array<int, array{status: ProposalStatus, label: string, date: CarbonImmutable|null, state: string}>
     */
    #[Computed]
    public function lifecycle(): array
    {
        $isRejected = $this->proposal->status === ProposalStatus::Rejected;
        $stops = ProposalStatus::lifecycle();
        $reached = (int) array_search($isRejected ? ProposalStatus::Submitted : $this->proposal->status, $stops, true);
        $dates = $this->statusNotes->sortBy('date')->groupBy(fn (ProposalStatusNote $note): string => $note->status->value);

        $rail = [];

        foreach ($stops as $index => $stop) {
            $isRejectionPoint = $isRejected && $index === $reached + 1;

            $rail[] = [
                'status' => $isRejectionPoint ? ProposalStatus::Rejected : $stop,
                'label' => $isRejectionPoint ? ProposalStatus::Rejected->label() : $stop->label(),
                'date' => $isRejectionPoint
                    ? $dates->get(ProposalStatus::Rejected->value)?->first()?->date
                    : $dates->get($stop->value)?->first()?->date,
                'state' => match (true) {
                    $isRejectionPoint => 'rejected',
                    ! $isRejected && $index === $reached => 'current',
                    $index < $reached || ($isRejected && $index === $reached) => 'past',
                    default => 'ahead',
                },
            ];
        }

        return $rail;
    }
}
