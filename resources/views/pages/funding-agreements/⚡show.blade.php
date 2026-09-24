<?php

use App\Enums\ArStatus;
use App\Enums\DonorReportType;
use App\Enums\MediaCollection;
use App\Enums\MilestoneStatus;
use App\Livewire\Concerns\BuildsPortfolioGantt;
use App\Livewire\Concerns\ManagesAgreementForm;
use App\Livewire\Concerns\ManagesTransferForm;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\Media;
use App\Models\Project;
use App\Models\TransfersTracking;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('titles.funding_agreement')] class extends Component {
    use BuildsPortfolioGantt;
    use ManagesAgreementForm;
    use ManagesTransferForm {
        saveTransfer as private saveTransferForAgreement;
    }

    public int $agreementId;

    /** How many days ahead a term end counts as close enough to warn about. */
    private const TERM_WARNING_DAYS = 60;

    /** How many days ahead a donor report due date is worth warning about. */
    private const REPORT_WARNING_DAYS = 30;

    /** How long since the last contact before a donor counts as gone quiet. */
    private const CONTACT_STALE_DAYS = 90;

    public bool $showReportModal = false;

    public ?int $editingReportId = null;

    public string $reportTitle = '';

    public string $reportType = 'narrative';

    public string $reportDueOn = '';

    public string $reportSubmittedOn = '';

    public bool $showReportDeleteModal = false;

    public ?int $deletingReportId = null;

    public function mount(DonorProposal $donorProposal): void
    {
        $this->agreementId = $donorProposal->id;
    }

    /**
     * The agreement on one Gantt: the proposal that won it, the delivery it
     * funds, and every tranche and donor report it owes.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function ganttPayload(): array
    {
        return $this->agreementGanttPayload($this->agreement);
    }

    #[Computed]
    public function agreement(): DonorProposal
    {
        return DonorProposal::query()
            ->select(['id', 'donor_id', 'proposal_id', 'budget', 'currency', 'project_num', 'created_at', 'updated_at'])
            ->with([
                'donor:id,name,type,contact_person,email,phone,country,last_contacted_on,next_step,next_step_due_on',
                'proposal:id,name,status,planned_date_begin,planned_date_end,actual_date_begin,actual_date_end',
            ])
            ->findOrFail($this->agreementId);
    }

    /** @return Collection<int, TransfersTracking> */
    #[Computed]
    public function transfers(): Collection
    {
        return TransfersTracking::query()
            ->select([
                'id', 'donor_proposal_id', 'account_id', 'payment_amount', 'amount_transfered_currency',
                'scheduled_date', 'transfer_date', 'fees', 'fees_currency', 'amount_received',
                'exchange_rate', 'net_amount_received', 'amount_received_currency', 'receive_date', 'ar_status',
            ])
            ->where('donor_proposal_id', $this->agreementId)
            // account_id is nullable: a tranche may be scheduled before an
            // account is assigned.
            ->with('account:id,account_name,is_active')
            ->orderByRaw('scheduled_date is null, scheduled_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Where a tranche stands, in the vocabulary every funding page shares.
     *
     * Money the donor has sent is in transit until it lands; a tranche is
     * late only while still unsent, from the day after its scheduled date.
     */
    public function trancheState(TransfersTracking $transfer): string
    {
        return $transfer->state();
    }

    public function trancheStateLabel(string $state): string
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
     * The projects the funded proposal pays for, with how far each has got.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projects(): Collection
    {
        return $this->agreement->proposal->projects()
            ->select(['projects.id', 'projects.name', 'projects.city_id', 'projects.program_id', 'projects.status', 'projects.start_date', 'projects.end_date'])
            ->with(['city:id,name_en,name_ar', 'program:id'])
            ->withCount([
                'milestones',
                'milestones as reached_milestones_count' => fn (Builder $query) => $query->where('status', MilestoneStatus::Reached),
            ])
            ->orderBy('projects.name')
            ->get();
    }

    /**
     * Other agreements that share this agreement's donor or its proposal.
     *
     * `landed` sums only net amounts that arrived in each agreement's own
     * currency, the same rule this page counts by.
     *
     * @return Collection<int, DonorProposal>
     */
    #[Computed]
    public function relatedAgreements(): Collection
    {
        return DonorProposal::query()
            ->select(['id', 'donor_id', 'proposal_id', 'budget', 'currency', 'project_num'])
            ->whereKeyNot($this->agreementId)
            ->where(fn (Builder $query) => $query
                ->where('donor_id', $this->agreement->donor_id)
                ->orWhere('proposal_id', $this->agreement->proposal_id))
            ->with(['donor:id,name', 'proposal:id,name'])
            ->withSum([
                'transfers as landed' => fn (Builder $query) => $query
                    ->where('ar_status', ArStatus::Received)
                    ->whereColumn('transfers_tracking.amount_transfered_currency', 'donor_proposal.currency')
                    ->whereColumn('transfers_tracking.amount_received_currency', 'donor_proposal.currency'),
            ], 'net_amount_received')
            ->orderByDesc('budget')
            ->get();
    }

    /**
     * The accounts this agreement's money moves through, with what landed in
     * each per currency.
     *
     * @return list<array{name: string, active: bool, tranches: int, landed: array<string, float>}>
     */
    #[Computed]
    public function accounts(): array
    {
        return $this->transfers
            ->filter(fn (TransfersTracking $transfer): bool => $transfer->account !== null)
            ->groupBy('account_id')
            ->map(fn (Collection $group): array => [
                'name' => $group->first()->account->account_name,
                'active' => (bool) $group->first()->account->is_active,
                'tranches' => $group->count(),
                'landed' => $group
                    ->where('ar_status', ArStatus::Received)
                    ->groupBy(fn (TransfersTracking $transfer): string => $transfer->amount_received_currency->value)
                    ->map(fn (Collection $byCurrency): float => (float) $byCurrency->sum(fn (TransfersTracking $transfer): float => (float) $transfer->net_amount_received))
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * The signed paperwork behind this money: agreements and MOUs filed on
     * the proposal or on the projects it funds.
     *
     * @return Collection<int, Media>
     */
    #[Computed]
    public function paperwork(): Collection
    {
        $projects = $this->projects->keyBy('id');
        $proposalId = $this->agreement->proposal_id;

        return Media::query()
            ->select(['id', 'mediable_type', 'mediable_id', 'collection', 'original_name', 'type', 'size', 'uploaded_at'])
            ->whereIn('collection', [MediaCollection::Agreement->value, MediaCollection::Mou->value])
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('mediable_type', 'proposal')->where('mediable_id', $proposalId))
                ->when($projects->isNotEmpty(), fn (Builder $query) => $query->orWhere(fn (Builder $query) => $query
                    ->where('mediable_type', 'project')
                    ->whereIn('mediable_id', $projects->keys()->all()))))
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id')
            ->get()
            ->each(fn (Media $media) => $media->setRelation('mediable', $media->mediable_type === 'project' ? $projects->get($media->mediable_id) : null));
    }

    /**
     * The reports owed to the donor under this agreement, soonest due first.
     *
     * @return Collection<int, DonorReport>
     */
    #[Computed]
    public function reports(): Collection
    {
        return DonorReport::query()
            ->select(['id', 'donor_proposal_id', 'title', 'type', 'due_on', 'submitted_on'])
            ->where('donor_proposal_id', $this->agreementId)
            ->orderBy('due_on')
            ->orderBy('id')
            ->get();
    }

    /**
     * Nobody has spoken to the donor in a while, or ever.
     */
    public function donorContactIsStale(): bool
    {
        $lastContact = $this->agreement->donor->last_contacted_on;

        return $lastContact === null || $lastContact->lt(CarbonImmutable::today()->subDays(self::CONTACT_STALE_DAYS));
    }

    public function openReportCreate(): void
    {
        Gate::authorize('funding-agreements.update');

        $this->resetReportForm();
        $this->showReportModal = true;
    }

    public function openReportEdit(int $reportId): void
    {
        Gate::authorize('funding-agreements.update');

        $report = $this->scopedReport($reportId);

        $this->resetValidation();
        $this->editingReportId = $report->id;
        $this->reportTitle = $report->title;
        $this->reportType = $report->type->value;
        $this->reportDueOn = $report->due_on->toDateString();
        $this->reportSubmittedOn = $report->submitted_on?->toDateString() ?? '';
        $this->showReportModal = true;
    }

    public function saveReport(): void
    {
        Gate::authorize('funding-agreements.update');

        $this->reportTitle = trim($this->reportTitle);

        $validated = $this->validate([
            'reportTitle' => ['required', 'string', 'max:255'],
            'reportType' => ['required', new Enum(DonorReportType::class)],
            'reportDueOn' => ['required', 'date'],
            'reportSubmittedOn' => ['nullable', 'date', 'before_or_equal:today'],
        ], [
            'reportTitle.required' => __('funding_agreements.name_the_report'),
            'reportDueOn.required' => __('funding_agreements.enter_the_date_the_report_is_due'),
            'reportSubmittedOn.before_or_equal' => __('funding_agreements.a_report_cannot_be_submitted_in_the_future'),
        ]);

        $report = $this->editingReportId === null ? new DonorReport : $this->scopedReport($this->editingReportId);

        $report->fill([
            'donor_proposal_id' => $this->agreementId,
            'title' => $validated['reportTitle'],
            'type' => $validated['reportType'],
            'due_on' => $validated['reportDueOn'],
            'submitted_on' => $validated['reportSubmittedOn'] === '' ? null : $validated['reportSubmittedOn'],
        ])->save();

        $isCreate = $report->wasRecentlyCreated;

        $this->closeReportModal();
        $this->forgetReadings();

        Flux::toast(
            variant: 'success',
            text: $isCreate ? __('funding_agreements.donor_report_added') : __('funding_agreements.donor_report_updated'),
        );
    }

    /**
     * Record that a report went out today.
     */
    public function markReportSubmitted(int $reportId): void
    {
        Gate::authorize('funding-agreements.update');

        $report = $this->scopedReport($reportId);

        if (! $report->isSubmitted()) {
            $report->update(['submitted_on' => CarbonImmutable::today()->toDateString()]);
        }

        $this->forgetReadings();

        Flux::toast(variant: 'success', text: __('funding_agreements.donor_report_marked_submitted'));
    }

    public function closeReportModal(): void
    {
        $this->showReportModal = false;
        $this->resetReportForm();
    }

    public function confirmReportDelete(int $reportId): void
    {
        Gate::authorize('funding-agreements.update');

        $this->deletingReportId = $this->scopedReport($reportId)->id;
        $this->showReportDeleteModal = true;
    }

    public function deleteReport(): void
    {
        Gate::authorize('funding-agreements.update');

        if ($this->deletingReportId !== null) {
            $this->scopedReport($this->deletingReportId)->delete();
        }

        $this->closeReportDeleteModal();
        $this->forgetReadings();

        Flux::toast(variant: 'success', text: __('funding_agreements.donor_report_deleted'));
    }

    public function closeReportDeleteModal(): void
    {
        $this->showReportDeleteModal = false;
        $this->deletingReportId = null;
    }

    /**
     * A report by id, only if it belongs to this agreement.
     */
    private function scopedReport(int $reportId): DonorReport
    {
        return DonorReport::query()->where('donor_proposal_id', $this->agreementId)->findOrFail($reportId);
    }

    private function resetReportForm(): void
    {
        $this->resetValidation();
        $this->editingReportId = null;
        $this->reportTitle = '';
        $this->reportType = DonorReportType::Narrative->value;
        $this->reportDueOn = '';
        $this->reportSubmittedOn = '';
    }

    public function paperworkUrl(Media $media): string
    {
        return $media->mediable_type === 'proposal'
            ? route('proposals.files.download', [$media->mediable_id, $media])
            : route('projects.files.download', [$media->mediable_id, $media]);
    }

    /**
     * Pinned to this agreement's proposal, so an edit cannot move the
     * agreement onto another grant.
     */
    protected function agreementProposalScope(): ?int
    {
        return $this->agreement->proposal_id;
    }

    /**
     * Tranches recorded or edited here belong to this agreement only.
     *
     * @return Builder<DonorProposal>
     */
    protected function scopedAgreements(): Builder
    {
        return DonorProposal::query()->whereKey($this->agreementId);
    }

    /**
     * Every tranche saved here belongs to this agreement, whatever agreement
     * id the payload carries.
     */
    public function saveTransfer(): void
    {
        $this->transferAgreementId = (string) $this->agreementId;

        $this->saveTransferForAgreement();
    }

    protected function agreementSaved(DonorProposal $agreement, bool $isCreate): void
    {
        $this->forgetReadings();
    }

    protected function transferSaved(TransfersTracking $transfer, bool $isCreate): void
    {
        $this->forgetReadings();
    }

    /**
     * This page shows one agreement, so once it is gone there is nothing left
     * to show: go back to the agreement directory.
     */
    protected function agreementDeleted(int $agreementId): void
    {
        $this->redirectRoute('funding-agreements.index', navigate: true);
    }

    protected function transferDeleted(int $transferId): void
    {
        $this->forgetReadings();
    }

    /**
     * Drop every memoised reading so the next render recomputes from the
     * database. Everything on this page derives from the agreement and its
     * tranches, so a write anywhere clears them all.
     */
    private function forgetReadings(): void
    {
        unset(
            $this->agreement, $this->transfers, $this->money, $this->journeys, $this->arcs,
            $this->term, $this->attention, $this->projects, $this->relatedAgreements, $this->accounts, $this->paperwork, $this->reports,
        );
    }
};
?>

@php
    $mutedText = 'text-secondary-medium dark:text-secondary-soft';
    $inkText = 'text-secondary-dark dark:text-white';
    $hairline = 'border-secondary-soft dark:border-secondary-medium';
    $amount = fn (float $value): string => number_format($value, 2);
    $agreement = $this->agreement;
    $donor = $agreement->donor;
    $proposal = $agreement->proposal;
    $currency = $agreement->currency->value;
    $money = $this->money;
    $term = $this->term;
    $journeys = $this->journeys;
    $coFunders = $this->relatedAgreements->where('proposal_id', $agreement->proposal_id)->values();
    $donorAgreements = $this->relatedAgreements->where('donor_id', $agreement->donor_id)->where('proposal_id', '!=', $agreement->proposal_id)->values();
    $arcStroke = [
        'received' => 'stroke-funding',
        'transit' => 'stroke-status-warn',
        'upcoming' => 'dial-upcoming',
        'late' => 'stroke-primary',
        'failed' => 'dial-failed',
        'unrecorded' => 'dial-unrecorded',
    ];
    $dotClass = [
        'received' => 'bg-funding',
        'transit' => 'bg-status-warn',
        'upcoming' => 'money-pending',
        'late' => 'bg-primary',
        'failed' => 'bg-primary',
    ];
    $tranchesForDial = collect($journeys)->mapWithKeys(fn (array $journey): array => [
        $journey['transfer']->id => [
            'amount' => $amount((float) $journey['transfer']->payment_amount).' '.$journey['transfer']->amount_transfered_currency->value,
            'label' => __('funding_agreements.tranche_number', ['number' => $journey['number'], 'total' => count($journeys)]),
            'state' => $this->trancheStateLabel($journey['state']),
            'alarm' => in_array($journey['state'], ['late', 'failed'], true),
        ],
    ])->all();
@endphp

<section
    class="agreement-page @container/detail flex min-h-full w-full flex-1 flex-col bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white"
    x-data="{ lit: null, tranches: @js($tranchesForDial) }"
>
    <x-ui.detail-header
        :breadcrumbs="[
            ['label' => __('common.funding_agreements'), 'url' => route('funding-agreements.index')],
            ['label' => $donor->name.' · '.$proposal->name],
        ]"
        :title="$donor->name.' · '.$proposal->name"
        :unread="app(\App\Notifications\UnreadSubjects::class)->has(auth()->user(), 'donor_proposal', $this->agreementId)"
        title-class="text-3xl! font-bold! leading-tight! tracking-tight! text-balance @3xl/detail:text-[2.5rem]!"
    >
        <x-slot:meta>
            <span class="font-semibold tabular-nums {{ $inkText }}">{{ $agreement->project_num ?? __('common.no_donor_reference') }}</span>
            <span aria-hidden="true">·</span>
            <span>{{ __('funding_agreements.signed_in_currency', ['currency' => $currency]) }}</span>
            @if ($agreement->updated_at !== null)
                <span aria-hidden="true">·</span>
                <span>{{ __('common.updated_date', ['date' => $agreement->updated_at->translatedFormat(__('formats.date'))]) }}</span>
            @endif
        </x-slot:meta>

        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2 @3xl/detail:shrink-0 @3xl/detail:flex-nowrap">
                <x-ui.enum-badge :case="$proposal->status" />
                @can('funding-agreements.update')
                <x-ui.button size="sm" icon="pencil-square" class="min-h-10 touch-manipulation" wire:click="openAgreementEdit({{ $agreement->id }})" data-test="edit-agreement">{{ __('funding_agreements.edit_agreement') }}</x-ui.button>
                @endcan
                @can('transfers.create')
                <x-ui.button tone="primary" size="sm" icon="calendar-days" class="min-h-10 touch-manipulation" wire:click="openTransferCreate({{ $agreement->id }})" data-test="schedule-tranche">{{ __('funding_agreements.schedule_tranche') }}</x-ui.button>
                @endcan
            </div>
        </x-slot:actions>
    </x-ui.detail-header>

    {{-- The promise: the dial on the start side, its readings on the end side. --}}
    <div class="grid gap-x-12 gap-y-10 pt-8 @4xl/detail:grid-cols-[minmax(0,22rem)_minmax(0,1fr)] @6xl/detail:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]" data-promise>
        <figure class="relative mx-auto aspect-square w-full max-w-[22rem] @6xl/detail:max-w-[26rem]" data-dial>
            <svg viewBox="0 0 200 200" class="size-full -rotate-90 overflow-visible" role="img" aria-labelledby="dial-title">
                <title id="dial-title">{{ __('funding_agreements.dial_label', ['percent' => $money['percentLabel'], 'budget' => $amount($money['budget']), 'currency' => $currency, 'owed' => $amount($money['stillToCome'])]) }}</title>
                <defs>
                    <pattern id="dial-stripes-warn" patternUnits="userSpaceOnUse" width="5" height="5" patternTransform="rotate(45)">
                        <rect width="5" height="5" class="fill-status-warn/25" />
                        <rect width="2" height="5" class="fill-status-warn" />
                    </pattern>
                    <pattern id="dial-stripes-critical" patternUnits="userSpaceOnUse" width="5" height="5" patternTransform="rotate(45)">
                        <rect width="5" height="5" class="fill-primary/20" />
                        <rect width="2" height="5" class="fill-primary" />
                    </pattern>
                    <pattern id="dial-stripes-funding" patternUnits="userSpaceOnUse" width="5" height="5" patternTransform="rotate(45)">
                        <rect width="5" height="5" class="fill-funding/15" />
                        <rect width="1.5" height="5" class="fill-funding" />
                    </pattern>
                </defs>

                {{-- The whole promise as a track; whatever no tranche covers stays visible as this ground. --}}
                <circle cx="100" cy="100" r="84" fill="none" stroke-width="16" class="stroke-secondary-soft/70 dark:stroke-secondary-medium/70" />
                <circle cx="100" cy="100" r="72" fill="none" stroke-width="0.75" stroke-dasharray="1 2.4" class="stroke-secondary-light" />

                @foreach ($this->arcs as $arc)
                    <a href="#tranche-{{ $arc['id'] }}" class="dial-arc outline-none" style="--dial-order: {{ $loop->index }}" x-on:mouseenter="lit = {{ $arc['id'] }}" x-on:mouseleave="lit = null" x-on:focus="lit = {{ $arc['id'] }}" x-on:blur="lit = null" x-bind:data-state="lit === null ? null : (lit === {{ $arc['id'] }} ? 'lit' : 'dim')" data-arc="{{ $arc['id'] }}" data-arc-state="{{ $arc['state'] }}" aria-label="{{ $arc['label'] }}">
                        <circle cx="100" cy="100" r="84" fill="none" stroke-width="16" pathLength="100"
                            class="dial-stroke {{ $arcStroke[$arc['state']] }}"
                            style="stroke-dasharray: {{ $arc['net'] }} 100; stroke-dashoffset: {{ -$arc['start'] }};" />
                        @if ($arc['net'] < $arc['length'])
                            {{-- What fees and exchange took from a received tranche: the same arc, paler. --}}
                            <circle cx="100" cy="100" r="84" fill="none" stroke-width="16" pathLength="100"
                                class="dial-stroke stroke-funding/35"
                                style="stroke-dasharray: {{ round($arc['length'] - $arc['net'], 3) }} 100; stroke-dashoffset: {{ -round($arc['start'] + $arc['net'], 3) }};" />
                        @endif
                    </a>
                @endforeach
            </svg>

            {{-- The answer in the centre; hovering an arc swaps it for that tranche. --}}
            <figcaption class="pointer-events-none absolute inset-[22%] flex flex-col items-center justify-center text-center">
                <div x-show="lit === null" class="flex flex-col items-center">
                    <span class="text-[3.5rem] leading-none font-extrabold tracking-tight tabular-nums text-funding @6xl/detail:text-[4.25rem]" data-test="dial-percent">{{ $money['percentLabel'] }}<span class="text-3xl font-bold">%</span></span>
                    <span class="type-label mt-2 {{ $mutedText }}">{{ __('funding_agreements.landed') }}</span>
                    <span class="mt-3 text-sm font-semibold tabular-nums {{ $inkText }}">{{ $amount($money['stillToCome']) }} <span class="text-xs font-medium {{ $mutedText }}">{{ $currency }}</span></span>
                    <span class="text-xs {{ $mutedText }}">{{ __('funding_agreements.still_to_come') }}</span>
                </div>
                <div x-show="lit !== null" x-cloak class="flex flex-col items-center">
                    <span class="type-label {{ $mutedText }}" x-text="lit !== null ? tranches[lit].label : ''"></span>
                    <span class="mt-2 text-xl leading-tight font-extrabold tracking-tight tabular-nums {{ $inkText }}" x-text="lit !== null ? tranches[lit].amount : ''"></span>
                    <span class="mt-1 text-sm font-semibold" x-bind:class="lit !== null && tranches[lit].alarm ? 'text-status-critical' : '{{ $mutedText }}'" x-text="lit !== null ? tranches[lit].state : ''"></span>
                </div>
            </figcaption>
        </figure>

        <div class="flex min-w-0 flex-col justify-center">
            {{-- Readings: the parts that add back up to the promise. --}}
            <dl class="grid grid-cols-2 gap-x-8 gap-y-6 @2xl/detail:grid-cols-3" data-readings>
                <div class="col-span-2 border-b pb-5 @2xl/detail:col-span-3 {{ $hairline }}">
                    <dt class="type-label {{ $mutedText }}">{{ __('common.committed') }}</dt>
                    <dd class="mt-1.5 flex flex-wrap items-baseline gap-x-2 text-4xl leading-none font-extrabold tracking-tight tabular-nums @5xl/detail:text-5xl {{ $inkText }}" data-test="reading-committed">
                        {{ $amount($money['budget']) }}
                        <span class="text-base font-bold tracking-normal {{ $mutedText }}">{{ $currency }}</span>
                    </dd>
                </div>

                @php
                    $readings = [
                        ['key' => 'landed', 'label' => __('funding_agreements.landed'), 'value' => $money['landed'], 'swatch' => 'bg-funding', 'figure' => 'text-funding', 'note' => __('funding_agreements.percent_of_committed', ['percent' => $money['percentLabel']])],
                        ['key' => 'lost', 'label' => __('funding_agreements.fees_and_exchange'), 'value' => $money['lost'], 'swatch' => 'bg-funding/35', 'figure' => $inkText, 'note' => $money['gained'] > 0.0 ? __('funding_agreements.exchange_gained_amount', ['amount' => $amount($money['gained'])]) : __('funding_agreements.taken_on_the_way')],
                        ['key' => 'unrecorded', 'label' => __('funding_agreements.received_net_not_recorded'), 'value' => $money['unrecorded'], 'swatch' => 'money-unrecorded', 'figure' => 'text-status-warn', 'note' => trans_choice('funding_agreements.count_tranches', $money['unrecordedCount'], ['count' => $money['unrecordedCount']])],
                        ['key' => 'on-its-way', 'label' => __('funding_agreements.on_its_way'), 'value' => $money['upcoming'] + $money['transit'], 'swatch' => 'money-pending', 'figure' => $inkText, 'note' => $money['nextDue'] !== null ? __('funding_agreements.next_due_on', ['date' => $money['nextDue']->translatedFormat(__('formats.date'))]) : __('funding_agreements.nothing_on_its_way')],
                        ['key' => 'late', 'label' => __('funding_agreements.late'), 'value' => $money['late'], 'swatch' => 'bg-primary', 'figure' => $money['late'] > 0 ? 'text-status-critical' : $inkText, 'note' => $money['lateSince'] !== null ? __('funding_agreements.due_since', ['date' => $money['lateSince']->translatedFormat(__('formats.date'))]) : __('funding_agreements.nothing_late')],
                        ['key' => 'failed', 'label' => __('funding_agreements.stage_failed'), 'value' => $money['failed'], 'swatch' => 'money-failed', 'figure' => 'text-status-critical', 'note' => __('funding_agreements.still_owed')],
                        ['key' => 'unscheduled', 'label' => __('funding_agreements.not_scheduled_yet'), 'value' => $money['unscheduled'], 'swatch' => 'bg-secondary-soft dark:bg-secondary-medium', 'figure' => $mutedText, 'note' => __('funding_agreements.no_date_agreed')],
                    ];
                    // Unrecorded and failed money only get a reading when there is some.
                    $readings = array_values(array_filter($readings, fn (array $reading): bool => ! in_array($reading['key'], ['unrecorded', 'failed'], true) || $reading['value'] > 0.0));
                @endphp

                @foreach ($readings as $reading)
                    <div class="logframe-reveal min-w-0" style="--logframe-order: {{ $loop->index }}" data-reading="{{ $reading['key'] }}">
                        <dt class="flex items-center gap-2">
                            <span class="size-2.5 shrink-0 rounded-full {{ $reading['swatch'] }}" aria-hidden="true"></span>
                            <span class="type-label truncate {{ $mutedText }}">{{ $reading['label'] }}</span>
                        </dt>
                        <dd class="mt-1.5 truncate text-2xl leading-none font-extrabold tracking-tight tabular-nums @5xl/detail:text-[1.75rem] {{ $reading['figure'] }}">{{ $amount($reading['value']) }}</dd>
                        <dd class="mt-1.5 truncate text-xs {{ $mutedText }}">{{ $reading['note'] }}</dd>
                    </div>
                @endforeach
            </dl>

            @if ($money['foreignLandings'] !== [] || $money['foreignCount'] > 0)
                <div class="mt-5 flex flex-col gap-1 text-xs {{ $mutedText }}" data-foreign>
                    @foreach ($money['foreignLandings'] as $foreignCode => $foreignTotal)
                        <p class="font-semibold tabular-nums text-funding">{{ __('funding_agreements.also_landed_in', ['amount' => $amount($foreignTotal), 'currency' => $foreignCode]) }}</p>
                    @endforeach
                    @if ($money['foreignCount'] > 0)
                        <p>{{ trans_choice('funding_agreements.count_tranches_sent_in_another_currency', $money['foreignCount'], ['count' => $money['foreignCount']]) }}</p>
                    @endif
                </div>
            @endif

            @if ($this->attention !== [])
                <section class="mt-7 border-t pt-5 {{ $hairline }}" aria-labelledby="attention-heading" data-attention>
                    <h2 id="attention-heading" class="type-label {{ $inkText }}">{{ trans_choice('funding_agreements.count_things_need_attention', count($this->attention), ['count' => count($this->attention)]) }}</h2>
                    <ul class="mt-3 flex flex-col gap-2">
                        @foreach ($this->attention as $item)
                            <li class="flex items-start gap-2.5 text-sm" wire:key="attention-{{ $item['key'] }}" data-attention-item="{{ $item['key'] }}">
                                <span @class(['mt-1.5 size-2 shrink-0 rounded-full', 'bg-primary' => $item['critical'], 'border-2 border-status-warn' => ! $item['critical']]) aria-hidden="true"></span>
                                <span @class(['min-w-0', 'font-semibold text-status-critical' => $item['critical'], $inkText => ! $item['critical']])>{{ $item['text'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    </div>

    {{-- The whole agreement in time: proposal, funded delivery, tranches and reports. --}}
    <section class="mt-14 border-t pt-8 {{ $hairline }}" aria-labelledby="agreement-timeline-heading" data-agreement-timeline x-data="{ open: true }">
        <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" class="flex w-full flex-wrap items-baseline justify-between gap-x-4 gap-y-1 text-start">
            <span>
                <span id="agreement-timeline-heading" class="block text-xl font-bold tracking-tight {{ $inkText }}">{{ __('gantt.timeline') }}</span>
                <span class="block text-sm {{ $mutedText }}">{{ __('gantt.timeline_subtitle') }}</span>
            </span>
            <flux:icon.chevron-down variant="micro" class="size-4 shrink-0 transition-transform {{ $mutedText }}" x-bind:class="open && 'rotate-180'" />
        </button>
        <div x-show="open" x-collapse class="mt-5 overflow-hidden rounded-xl border {{ $hairline }}">
            @if ($this->ganttPayload['rows'] === [])
                <x-ui.empty-state icon="chart-bar" :heading="__('gantt.nothing_to_plan')" :body="__('gantt.nothing_to_plan_body')" />
            @else
                <x-ui.gantt :payload="$this->ganttPayload" :scope="'agreement-'.$this->agreementId" height="h-[min(60vh,32rem)]" wire:ignore wire:key="agreement-gantt-{{ $this->agreementId }}" />
            @endif
        </div>
    </section>

    {{-- Below the promise: each tranche's journey beside the records this money is tied to. --}}
    <div class="mt-14 grid gap-x-12 gap-y-14 border-t pt-8 @5xl/detail:grid-cols-[minmax(0,1fr)_minmax(0,22rem)] {{ $hairline }}">
        <section class="min-w-0" aria-labelledby="tranches-heading" data-tranches>
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <h2 id="tranches-heading" class="text-xl font-bold tracking-tight {{ $inkText }}">
                    {{ __('funding_agreements.tranches') }}
                    <span class="ms-1 text-base font-semibold tabular-nums {{ $mutedText }}">{{ count($journeys) }}</span>
                </h2>
                <p class="flex flex-wrap items-baseline gap-x-3 text-xs {{ $mutedText }}">
                    <span>{{ __('funding_agreements.from_schedule_to_account') }}</span>
                    @if ($journeys !== [])
                        <a href="{{ route('transfers.index', ['agreement' => $agreement->id]) }}" wire:navigate class="font-semibold underline-offset-4 hover:underline {{ $inkText }}" data-transfers-ledger-link>{{ __('funding_agreements.open_in_transfers_ledger') }}</a>
                    @endif
                </p>
            </div>

            @if ($journeys === [])
                <div class="mt-6">
                    <x-ui.empty-state class="rtl:[&_svg]:-scale-x-100" icon="arrow-right-circle" :heading="__('funding_agreements.no_tranches_scheduled')" :body="__('funding_agreements.payments_scheduled_under_this_agreement_will_appear_here')" />
                </div>
            @else
                <ol class="mt-4 flex flex-col">
                    @foreach ($journeys as $journey)
                        @php
                            $transfer = $journey['transfer'];
                            $state = $journey['state'];
                            $isReceived = $state === 'received';
                            $feesShown = (float) $transfer->fees > 0.0;
                            $rate = rtrim(rtrim((string) $transfer->exchange_rate, '0'), '.');
                        @endphp
                        <li
                            id="tranche-{{ $transfer->id }}"
                            class="tranche-row -mx-3 scroll-mt-24 border-b px-3 py-5 last:border-b-0 {{ $hairline }}"
                            x-on:mouseenter="lit = {{ $transfer->id }}"
                            x-on:mouseleave="lit = null"
                            x-on:focusin="lit = {{ $transfer->id }}"
                            x-on:focusout="lit = null"
                            x-bind:data-state="lit === {{ $transfer->id }} ? 'lit' : null"
                            wire:key="agreement-transfer-{{ $transfer->id }}"
                            data-transfer="{{ $transfer->id }}"
                            data-tranche-state="{{ $state }}"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-2">
                                <div class="min-w-0">
                                    <p class="type-label flex items-center gap-2 {{ $mutedText }}">
                                        <span class="size-2 rounded-full {{ $dotClass[$state] }}" aria-hidden="true"></span>
                                        {{ __('funding_agreements.tranche_number', ['number' => $journey['number'], 'total' => count($journeys)]) }}
                                    </p>
                                    <p class="mt-1.5 text-2xl leading-none font-extrabold tracking-tight tabular-nums {{ $inkText }}">
                                        {{ $amount((float) $transfer->payment_amount) }}
                                        <span class="text-sm font-bold tracking-normal {{ $mutedText }}">{{ $transfer->amount_transfered_currency->value }}</span>
                                    </p>
                                    @if ($journey['timing'] !== null)
                                        <p @class(['mt-1.5 text-xs', 'font-bold text-status-critical' => $journey['timingAlarm'], $mutedText => ! $journey['timingAlarm']])>{{ $journey['timing'] }}</p>
                                    @endif
                                </div>

                                <div class="flex shrink-0 items-center gap-1">
                                    <x-ui.enum-badge :case="$transfer->ar_status" />
                                    @can('transfers.update')
                                    <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9! touch-manipulation" wire:click="openTransferEdit({{ $transfer->id }})" :aria-label="__('funding_agreements.edit_tranche_number', ['number' => $journey['number']])" data-test="edit-transfer-{{ $transfer->id }}" />
                                    @endcan
                                </div>
                            </div>

                            {{-- The journey: four stops from the promise of a date to money in an account. --}}
                            <ol class="mt-5 grid grid-cols-4" aria-label="{{ __('funding_agreements.journey_of_tranche', ['number' => $journey['number']]) }}">
                                @foreach ($journey['stops'] as $stop)
                                    <li class="relative flex min-w-0 flex-col gap-1 pe-2" data-stop="{{ $stop['key'] }}" data-stop-state="{{ $stop['state'] }}">
                                        <span class="relative flex h-3 items-center" aria-hidden="true">
                                            @unless ($loop->last)
                                                <span @class([
                                                    'absolute start-2.5 end-0 h-0.5',
                                                    'bg-funding' => $isReceived && $journey['stops'][$loop->index + 1]['state'] === 'done',
                                                    'bg-secondary-dark dark:bg-white' => ! $isReceived && $stop['state'] === 'done' && $journey['stops'][$loop->index + 1]['state'] === 'done',
                                                    'bg-primary' => in_array($journey['stops'][$loop->index + 1]['state'], ['late', 'failed'], true),
                                                    'bg-secondary-soft dark:bg-secondary-medium' => ! ($stop['state'] === 'done' && $journey['stops'][$loop->index + 1]['state'] === 'done') && ! in_array($journey['stops'][$loop->index + 1]['state'], ['late', 'failed'], true),
                                                ])></span>
                                            @endunless
                                            <span @class([
                                                'relative z-10 size-3 rounded-full',
                                                'bg-funding' => $stop['state'] === 'done' && $isReceived,
                                                'bg-secondary-dark dark:bg-white' => $stop['state'] === 'done' && ! $isReceived,
                                                'bg-primary ring-4 ring-primary/20' => in_array($stop['state'], ['late', 'failed'], true),
                                                'border-2 border-secondary-soft bg-white dark:border-secondary-medium dark:bg-secondary-dark' => $stop['state'] === 'ahead',
                                            ])></span>
                                        </span>
                                        <span @class([
                                            'mt-1 truncate text-[11px] leading-tight font-semibold',
                                            'text-status-critical' => in_array($stop['state'], ['late', 'failed'], true),
                                            $inkText => $stop['state'] === 'done',
                                            $mutedText => $stop['state'] === 'ahead',
                                        ])>{{ $stop['label'] }}</span>
                                        <span class="truncate text-[11px] leading-tight tabular-nums {{ $mutedText }}">
                                            @if ($stop['detail'] !== null)
                                                {{ $stop['detail'] }}
                                            @elseif ($stop['key'] === 'account')
                                                <span class="italic">{{ __('funding_agreements.no_account_assigned') }}</span>
                                            @elseif ($stop['state'] === 'late')
                                                {{ __('funding_agreements.not_yet') }}
                                            @elseif ($stop['state'] === 'failed')
                                                {{ __('funding_agreements.tranche_state_failed') }}
                                            @else
                                                —
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ol>

                            {{-- The money traced: what was sent, what the way cost, what landed, and what is left of the promise. --}}
                            <div class="mt-5 flex flex-wrap items-baseline justify-between gap-x-6 gap-y-2 text-xs tabular-nums {{ $mutedText }}">
                                <p class="flex flex-wrap items-baseline gap-x-2 gap-y-1" data-trace>
                                    <span>{{ __('funding_agreements.sent') }} <span class="font-semibold {{ $inkText }}">{{ $amount((float) $transfer->payment_amount) }}</span></span>
                                    @if ($feesShown)
                                        <span aria-hidden="true">−</span>
                                        <span>{{ __('funding_agreements.fees_amount', ['amount' => $amount((float) $transfer->fees), 'currency' => $transfer->fees_currency->value]) }}</span>
                                    @endif
                                    @if ($rate !== '1')
                                        <span aria-hidden="true">×</span>
                                        <span>{{ __('funding_agreements.rate_rate', ['rate' => $rate]) }}</span>
                                    @endif
                                    <span aria-hidden="true">=</span>
                                    @if ($isReceived && $transfer->net_amount_received !== null)
                                        <span class="font-bold text-funding">{{ __('funding_agreements.net_amount_landed', ['amount' => $amount((float) $transfer->net_amount_received), 'currency' => $transfer->amount_received_currency->value]) }}</span>
                                        @if ($transfer->amount_received !== null)
                                            <span data-gross>({{ __('funding_agreements.gross_amount', ['amount' => $amount((float) $transfer->amount_received)]) }})</span>
                                        @endif
                                    @elseif ($transfer->isMissingNetAmount())
                                        <span class="font-semibold text-status-warn" data-net-missing>{{ __('funding_agreements.net_not_recorded') }}</span>
                                    @else
                                        <span class="italic">{{ __('funding_agreements.nothing_landed_yet') }}</span>
                                    @endif
                                </p>
                                @if ($journey['left'] !== null)
                                    <p data-left>{{ __('funding_agreements.left_of_the_promise') }} <span class="font-semibold {{ $inkText }}">{{ $amount(max(0.0, $journey['left'])) }}</span></p>
                                @endif
                            </div>
                        </li>
                    @endforeach

                    @if ($money['unscheduled'] > 0.0)
                        <li class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1 py-5" data-unscheduled-line>
                            <p class="flex items-center gap-2 text-sm {{ $mutedText }}">
                                <span class="size-2 rounded-full border-2 border-dashed border-secondary-light" aria-hidden="true"></span>
                                {{ __('funding_agreements.not_scheduled_yet') }}
                                <span class="font-bold tabular-nums {{ $inkText }}">{{ $amount($money['unscheduled']) }} {{ $currency }}</span>
                            </p>
                            @can('transfers.create')
                            <button type="button" wire:click="openTransferCreate({{ $agreement->id }})" class="min-h-9 touch-manipulation text-xs font-semibold underline underline-offset-4 {{ $inkText }}">{{ __('funding_agreements.schedule_the_rest') }}</button>
                            @endcan
                        </li>
                    @endif
                </ol>
            @endif

            {{-- What the donor is owed back: every report, by when, and whether it went out. --}}
            <section class="mt-12 border-t pt-8 {{ $hairline }}" aria-labelledby="reports-heading" data-donor-reports>
                <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2">
                    <h2 id="reports-heading" class="text-xl font-bold tracking-tight {{ $inkText }}">
                        {{ __('funding_agreements.donor_reports') }}
                        <span class="ms-1 text-base font-semibold tabular-nums {{ $mutedText }}">{{ $this->reports->count() }}</span>
                    </h2>
                    @can('funding-agreements.update')
                    <x-ui.button size="sm" icon="plus" class="min-h-10 touch-manipulation" wire:click="openReportCreate" data-test="add-donor-report">{{ __('funding_agreements.add_report') }}</x-ui.button>
                    @endcan
                </div>

                @if ($this->reports->isEmpty())
                    <p class="mt-4 text-sm {{ $mutedText }}">{{ __('funding_agreements.no_donor_reports_yet') }}</p>
                @else
                    <ol class="mt-4 flex flex-col">
                        @foreach ($this->reports as $report)
                            @php
                                $reportDays = (int) \Carbon\CarbonImmutable::today()->diffInDays($report->due_on, false);
                                $reportOverdue = $report->isOverdue();
                            @endphp
                            <li class="flex flex-wrap items-start justify-between gap-x-6 gap-y-2 border-b py-4 last:border-b-0 {{ $hairline }}" wire:key="donor-report-{{ $report->id }}" data-donor-report="{{ $report->id }}">
                                <div class="flex min-w-0 items-start gap-3">
                                    <span @class([
                                        'mt-1.5 size-2.5 shrink-0 rounded-full',
                                        'bg-funding' => $report->isSubmitted(),
                                        'bg-primary' => $reportOverdue,
                                        'border-2 border-status-warn' => ! $report->isSubmitted() && ! $reportOverdue,
                                    ]) aria-hidden="true"></span>
                                    <div class="min-w-0">
                                        <p class="text-sm font-bold break-words {{ $inkText }}">{{ $report->title }}</p>
                                        <p class="mt-0.5 text-xs tabular-nums {{ $mutedText }}">
                                            {{ $report->type->label() }} · {{ __('funding_agreements.due_date', ['date' => $report->due_on->translatedFormat(__('formats.date'))]) }}
                                        </p>
                                        <p @class([
                                            'mt-1 text-xs tabular-nums',
                                            'text-funding' => $report->isSubmitted(),
                                            'font-bold text-status-critical' => $reportOverdue,
                                            $mutedText => ! $report->isSubmitted() && ! $reportOverdue,
                                        ]) data-report-status>
                                            @if ($report->isSubmitted())
                                                {{ __('funding_agreements.submitted_on', ['date' => $report->submitted_on->translatedFormat(__('formats.date'))]) }}
                                            @elseif ($reportOverdue)
                                                {{ trans_choice('funding_agreements.count_days_overdue', -$reportDays, ['count' => -$reportDays]) }}
                                            @else
                                                {{ trans_choice('funding_agreements.due_in_days', $reportDays, ['count' => $reportDays]) }}
                                            @endif
                                        </p>
                                    </div>
                                </div>
                                @can('funding-agreements.update')
                                <div class="flex shrink-0 items-center gap-1">
                                    @unless ($report->isSubmitted())
                                        <x-ui.button size="sm" icon="check" class="min-h-9 touch-manipulation" wire:click="markReportSubmitted({{ $report->id }})" data-test="submit-report-{{ $report->id }}">{{ __('funding_agreements.mark_submitted') }}</x-ui.button>
                                    @endunless
                                    <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9! touch-manipulation" wire:click="openReportEdit({{ $report->id }})" :aria-label="__('common.edit_name', ['name' => $report->title])" />
                                    <x-ui.button tone="ghost" size="sm" icon="trash" class="size-9! touch-manipulation" wire:click="confirmReportDelete({{ $report->id }})" :aria-label="__('funding_agreements.delete_report_name', ['name' => $report->title])" data-test="delete-report-{{ $report->id }}" />
                                </div>
                                @endcan
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        </section>

        {{-- The thread: every record this money is tied to, each one link away. --}}
        <aside class="min-w-0" aria-labelledby="connections-heading" data-connections>
            <h2 id="connections-heading" class="text-xl font-bold tracking-tight {{ $inkText }}">{{ __('funding_agreements.connected_records') }}</h2>

            <ol class="connection-thread mt-5 flex flex-col">
                {{-- Donor --}}
                <li class="relative ps-7 pb-8" data-connection="donor">
                    <span class="absolute start-0 top-1 size-3 rounded-full border-2 border-secondary-dark bg-white dark:border-white dark:bg-secondary-dark" aria-hidden="true"></span>
                    <p class="type-label {{ $mutedText }}">{{ __('funding_agreements.donor') }}</p>
                    <a href="{{ route('donors.show', $donor) }}" wire:navigate class="mt-1 inline-block text-base font-bold underline-offset-4 hover:underline {{ $inkText }}">{{ $donor->name }}</a>
                    @if ($donor->type !== null || filled($donor->country))
                        <p class="text-xs {{ $mutedText }}">{{ collect([$donor->type?->label(), $donor->country])->filter()->implode(' · ') }}</p>
                    @endif
                    @if (filled($donor->contact_person) || filled($donor->email) || filled($donor->phone))
                        <p class="mt-2 text-xs {{ $inkText }}">{{ $donor->contact_person }}</p>
                        <p class="flex flex-wrap gap-x-3 text-xs {{ $mutedText }}">
                            @if (filled($donor->email))
                                <a href="mailto:{{ $donor->email }}" class="underline-offset-4 hover:underline">{{ $donor->email }}</a>
                            @endif
                            @if (filled($donor->phone))
                                <a href="tel:{{ $donor->phone }}" class="tabular-nums underline-offset-4 hover:underline" dir="ltr">{{ $donor->phone }}</a>
                            @endif
                        </p>
                    @endif
                    <p @class(['mt-2 text-xs', 'font-semibold text-status-critical' => $donor->isFollowUpOverdue(), $mutedText => ! $donor->isFollowUpOverdue()])>
                        @if (filled($donor->next_step))
                            {{ $donor->next_step_due_on !== null
                                ? __('funding_agreements.next_step_due', ['step' => $donor->next_step, 'date' => $donor->next_step_due_on->translatedFormat(__('formats.date'))])
                                : $donor->next_step }}
                        @else
                            {{ __('funding_agreements.no_next_step_planned') }}
                        @endif
                    </p>
                    <p @class(['text-xs tabular-nums', 'font-semibold text-status-warn' => $this->donorContactIsStale(), $mutedText => ! $this->donorContactIsStale()]) data-last-contacted>
                        {{ $donor->last_contacted_on !== null ? __('donors.last_contacted', ['date' => $donor->last_contacted_on->translatedFormat(__('formats.date'))]) : __('donors.never_contacted') }}
                    </p>

                    @if ($donorAgreements->isNotEmpty())
                        <p class="type-label mt-4 {{ $mutedText }}">{{ __('funding_agreements.also_from_donor', ['donor' => $donor->name]) }}</p>
                        <ul class="mt-1.5 flex flex-col gap-1.5">
                            @foreach ($donorAgreements as $sibling)
                                <li class="flex items-baseline justify-between gap-3 text-xs" wire:key="donor-agreement-{{ $sibling->id }}">
                                    <a href="{{ route('funding-agreements.show', $sibling) }}" wire:navigate class="min-w-0 truncate underline-offset-4 hover:underline {{ $inkText }}">{{ $sibling->proposal->name }}</a>
                                    <span class="shrink-0 text-end tabular-nums {{ $mutedText }}">{{ __('funding_agreements.landed_of_budget', ['landed' => $amount((float) $sibling->landed), 'budget' => $amount((float) $sibling->budget), 'currency' => $sibling->currency->value]) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </li>

                {{-- Proposal --}}
                <li class="relative ps-7 pb-8" data-connection="proposal">
                    <span class="absolute start-0 top-1 size-3 rounded-full border-2 border-secondary-dark bg-white dark:border-white dark:bg-secondary-dark" aria-hidden="true"></span>
                    <p class="type-label {{ $mutedText }}">{{ __('common.proposal') }}</p>
                    <a href="{{ route('proposals.show', $proposal) }}" wire:navigate class="mt-1 inline-block text-base font-bold underline-offset-4 hover:underline {{ $inkText }}">{{ $proposal->name }}</a>
                    <p class="mt-1 flex flex-wrap items-center gap-2 text-xs {{ $mutedText }}">
                        <span>{{ $proposal->status->label() }}</span>
                        <span aria-hidden="true">·</span>
                        <span>{{ $proposal->phase->label() }}</span>
                    </p>

                    @if ($term['phase'] !== 'unknown')
                        <div class="mt-3" data-term>
                            <div class="flex items-baseline justify-between gap-3 text-[11px] tabular-nums {{ $mutedText }}">
                                <span>{{ $term['begin']->translatedFormat(__('formats.date_short')) }}</span>
                                <span class="font-semibold {{ $inkText }}">
                                    @if ($term['phase'] === 'ahead')
                                        {{ __('funding_agreements.term_starts', ['date' => $term['begin']->translatedFormat(__('formats.date'))]) }}
                                    @elseif ($term['phase'] === 'ended')
                                        {{ __('funding_agreements.term_ended') }}
                                    @else
                                        {{ __('funding_agreements.term_day', ['day' => $term['day'], 'days' => $term['days']]) }}
                                    @endif
                                </span>
                                <span>{{ $term['end']->translatedFormat(__('formats.date_short')) }}</span>
                            </div>
                            <div class="relative mt-1.5 h-1 rounded-full bg-secondary-soft dark:bg-secondary-medium" role="img" aria-label="{{ __('funding_agreements.term_day', ['day' => $term['day'], 'days' => $term['days']]) }}">
                                <span class="money-grow absolute inset-y-0 start-0 rounded-full bg-secondary-dark dark:bg-white" style="width: {{ $term['percent'] }}%"></span>
                            </div>
                        </div>
                    @endif

                    @if ($coFunders->isNotEmpty())
                        <p class="type-label mt-4 {{ $mutedText }}">{{ __('funding_agreements.co_funders') }}</p>
                        <ul class="mt-1.5 flex flex-col gap-1.5">
                            @foreach ($coFunders as $coFunder)
                                <li class="flex items-baseline justify-between gap-3 text-xs" wire:key="co-funder-{{ $coFunder->id }}">
                                    <a href="{{ route('funding-agreements.show', $coFunder) }}" wire:navigate class="min-w-0 truncate underline-offset-4 hover:underline {{ $inkText }}">{{ $coFunder->donor->name }}</a>
                                    <span class="shrink-0 text-end tabular-nums {{ $mutedText }}">{{ __('funding_agreements.landed_of_budget', ['landed' => $amount((float) $coFunder->landed), 'budget' => $amount((float) $coFunder->budget), 'currency' => $coFunder->currency->value]) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="mt-3 text-xs {{ $mutedText }}">{{ __('funding_agreements.sole_funder') }}</p>
                    @endif
                </li>

                {{-- Projects --}}
                <li class="relative ps-7 pb-8" data-connection="projects">
                    <span class="absolute start-0 top-1 size-3 rounded-full border-2 border-secondary-dark bg-white dark:border-white dark:bg-secondary-dark" aria-hidden="true"></span>
                    <p class="type-label {{ $mutedText }}">{{ __('funding_agreements.projects_funded') }} <span class="tabular-nums">{{ $this->projects->count() }}</span></p>
                    @forelse ($this->projects as $project)
                        <div class="mt-2.5 min-w-0" wire:key="project-{{ $project->id }}" data-project="{{ $project->id }}">
                            <div class="flex items-baseline justify-between gap-3">
                                <a href="{{ route('projects.show', $project) }}" wire:navigate class="min-w-0 truncate text-sm font-semibold underline-offset-4 hover:underline {{ $inkText }}">{{ $project->name }}</a>
                                <span class="shrink-0 text-[11px] {{ $mutedText }}">{{ $project->status->label() }}</span>
                            </div>
                            <p class="truncate text-xs {{ $mutedText }}">{{ collect([$project->program?->name, $project->city?->name ?? __('common.unknown_city')])->filter()->implode(' · ') }}</p>
                            @if ($project->milestones_count > 0)
                                <div class="mt-1.5 flex items-center gap-2">
                                    <span class="flex h-1 flex-1 overflow-hidden rounded-full bg-secondary-soft dark:bg-secondary-medium" aria-hidden="true">
                                        <span class="money-grow h-full rounded-full bg-secondary-dark dark:bg-white" style="width: {{ round($project->reached_milestones_count / $project->milestones_count * 100, 2) }}%"></span>
                                    </span>
                                    <span class="shrink-0 text-[11px] tabular-nums {{ $mutedText }}">{{ __('funding_agreements.milestones_reached', ['reached' => $project->reached_milestones_count, 'total' => $project->milestones_count]) }}</span>
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="mt-1.5 text-xs {{ $mutedText }}">{{ __('funding_agreements.no_projects_linked_yet') }}</p>
                    @endforelse
                </li>

                {{-- Accounts --}}
                <li class="relative ps-7 pb-8" data-connection="accounts">
                    <span class="absolute start-0 top-1 size-3 rounded-full border-2 border-secondary-dark bg-white dark:border-white dark:bg-secondary-dark" aria-hidden="true"></span>
                    <p class="type-label {{ $mutedText }}">{{ __('funding_agreements.accounts') }}</p>
                    @forelse ($this->accounts as $account)
                        <div class="mt-2 flex items-baseline justify-between gap-3 text-xs" wire:key="account-{{ $loop->index }}">
                            <span class="min-w-0 truncate font-semibold {{ $inkText }}">{{ $account['name'] }}@unless ($account['active']) <span class="font-normal {{ $mutedText }}">· {{ __('common.closed') }}</span>@endunless</span>
                            <span class="shrink-0 text-end tabular-nums {{ $mutedText }}">
                                @forelse ($account['landed'] as $landedCode => $landedTotal)
                                    <span class="block font-semibold text-funding">{{ $amount($landedTotal) }} {{ $landedCode }}</span>
                                @empty
                                    {{ trans_choice('funding_agreements.count_tranches', $account['tranches'], ['count' => $account['tranches']]) }}
                                @endforelse
                            </span>
                        </div>
                    @empty
                        <p class="mt-1.5 text-xs {{ $mutedText }}">{{ __('funding_agreements.no_account_assigned') }}</p>
                    @endforelse
                </li>

                {{-- Paperwork --}}
                <li class="relative ps-7" data-connection="paperwork">
                    <span class="absolute start-0 top-1 size-3 rounded-full border-2 border-secondary-dark bg-white dark:border-white dark:bg-secondary-dark" aria-hidden="true"></span>
                    <p class="type-label {{ $mutedText }}">{{ __('funding_agreements.paperwork') }}</p>
                    @forelse ($this->paperwork as $file)
                        <a href="{{ $this->paperworkUrl($file) }}" class="group mt-2 flex items-start gap-2 text-xs" wire:key="paper-{{ $file->id }}" aria-label="{{ __('common.download_name', ['name' => $file->original_name]) }}">
                            <flux:icon.document-text variant="micro" class="mt-0.5 size-4 shrink-0 {{ $mutedText }}" aria-hidden="true" />
                            <span class="min-w-0">
                                <span class="block truncate font-semibold underline-offset-4 group-hover:underline {{ $inkText }}">{{ $file->original_name }}</span>
                                <span class="block truncate {{ $mutedText }}">{{ $file->collection->label() }}@if ($file->mediable !== null) · {{ $file->mediable->name }}@endif</span>
                            </span>
                        </a>
                    @empty
                        <p class="mt-1.5 text-xs {{ $mutedText }}">{{ __('funding_agreements.no_paperwork_filed') }}</p>
                    @endforelse
                </li>
            </ol>
        </aside>
    </div>

    <section aria-labelledby="record-activity-heading" class="grid gap-3 mt-14" data-record-activity>
        <h2 id="record-activity-heading" class="text-base font-bold">{{ __('activity.title') }}</h2>
        <livewire:activity.timeline :subjects="['donor_proposal' => [$agreementId], 'transfer' => $this->transfers->pluck('id')->all(), 'donor_report' => $this->reports->pluck('id')->all()]" :key="'agreement-activity-'.$agreementId" />
    </section>

    <x-funding.agreement-modal
        :editing="$editingAgreementId !== null"
        :donor-options="$showAgreementModal ? $this->donorOptions : []"
        :warning="$showAgreementModal ? $this->agreementProposalWarning : null"
        :delete-blocker="$showAgreementModal ? $this->agreementDeleteBlocker : null"
        :locked-currency="$showAgreementModal && $this->agreementCurrencyLocked ? $agreementCurrency : null"
        :confirming-delete="$confirmingAgreementDelete"
        :locked-proposal-name="$proposal->name"
    />

    <x-ui.modal
        wire:model="showReportModal"
        size="md"
        :title="$editingReportId !== null ? __('funding_agreements.edit_report') : __('funding_agreements.add_report')"
        :description="__('funding_agreements.a_report_the_donor_expects_under_this_agreement')"
        submit="saveReport"
        close="closeReportModal"
        required-note
    >
        <div class="grid grid-cols-2 gap-x-4 gap-y-4">
            <x-ui.form-input wire:model="reportTitle" :label="__('funding_agreements.report_title')" maxlength="255" required autofocus class="col-span-2" />

            <x-ui.form-segmented
                wire:model="reportType"
                :label="__('common.type')"
                required
                class="col-span-2"
                :options="collect(DonorReportType::cases())->map(fn (DonorReportType $reportTypeCase): array => ['value' => $reportTypeCase->value, 'label' => $reportTypeCase->label()])->all()"
            />

            <x-ui.form-input wire:model="reportDueOn" :label="__('funding_agreements.due')" type="date" required />
            <x-ui.form-input wire:model="reportSubmittedOn" :label="__('funding_agreements.submitted')" type="date" />
        </div>

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-report-button">
                {{ $editingReportId !== null ? __('common.save_changes') : __('funding_agreements.add_report') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    <x-ui.confirm-modal
        wire:model="showReportDeleteModal"
        close="closeReportDeleteModal"
        :title="__('funding_agreements.delete_this_report')"
        :message="__('funding_agreements.the_report_is_removed_from_this_agreement')"
        confirm="deleteReport"
        :confirm-label="__('funding_agreements.delete_report')"
        confirm-test="confirm-delete-report"
    />

    <x-funding.transfer-modal
        :editing="$editingTransferId !== null"
        :agreement-options="$showTransferModal ? $this->agreementOptions : []"
        :account-options="$showTransferModal ? $this->accountOptions : []"
        :confirming-delete="$confirmingTransferDelete"
    />
</section>
