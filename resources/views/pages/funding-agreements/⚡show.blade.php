<?php

use App\Actions\FundingAgreements\DeleteDonorReport;
use App\Actions\FundingAgreements\MarkDonorReportSubmitted;
use App\Actions\FundingAgreements\SaveDonorReport;
use App\Enums\ArStatus;
use App\Enums\DonorReportType;
use App\Enums\MediaCollection;
use App\Enums\MilestoneStatus;
use App\Livewire\Concerns\BuildsPortfolioGantt;
use App\Livewire\Concerns\ManagesAgreementForm;
use App\Livewire\Concerns\ManagesTransferForm;
use App\Livewire\Concerns\SwitchesFundingView;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\Media;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\TransfersTracking;
use App\Support\CsvExport;
use App\Support\Tranches;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('titles.funding_agreement')] class extends Component {
    use BuildsPortfolioGantt;
    use ManagesAgreementForm;
    use SwitchesFundingView;
    use ManagesTransferForm {
        saveTransfer as private saveTransferForAgreement;
    }

    #[Locked]
    public int $agreementId;

    /** How many days ahead a term end counts as close enough to warn about. */
    private const TERM_WARNING_DAYS = 60;

    /** How many days ahead a donor report due date is worth warning about. */
    private const REPORT_WARNING_DAYS = 30;

    /** How long since the last contact before a donor counts as gone quiet. */
    private const CONTACT_STALE_DAYS = 90;

    public bool $showReportModal = false;

    #[Locked]
    public ?int $editingReportId = null;

    public string $reportTitle = '';

    public string $reportType = 'narrative';

    public string $reportDueOn = '';

    public string $reportSubmittedOn = '';

    public bool $showReportDeleteModal = false;

    #[Locked]
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
            ->select(['id', 'donor_id', 'proposal_id', 'budget', 'currency', 'external_project_number', 'created_at', 'updated_at'])
            ->with([
                'donor:id,name,type,contact_person,email,phone,country,last_contacted_on,next_step,next_step_due_on',
                'proposal:id,name,internal_project_number,status,planned_date_begin,planned_date_end,actual_date_begin,actual_date_end',
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
            ->select(['id', 'donor_id', 'proposal_id', 'budget', 'currency', 'external_project_number'])
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
     * Every tranche of this agreement as a CSV file, in schedule order.
     */
    public function exportTranchesCsv(): StreamedResponse
    {
        Gate::authorize('funding-agreements.view');

        $agreement = $this->agreement;

        $rows = $this->transfers->map(fn (TransfersTracking $transfer): array => Tranches::csvRow(
            $transfer,
            $agreement->donor->name,
            $agreement->proposal->name,
            $agreement->external_project_number,
            $transfer->account?->account_name,
        ));

        return CsvExport::download('agreement-tranches-'.CarbonImmutable::today()->toDateString().'.csv', Tranches::csvHeadings(), $rows);
    }

    /**
     * Nobody has spoken to the donor in a while, or ever.
     */
    protected function donorContactIsStale(): bool
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

        $report = app(SaveDonorReport::class)->handle(
            $this->agreementId,
            $this->editingReportId === null ? null : $this->scopedReport($this->editingReportId),
            $validated,
        );

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

        app(MarkDonorReportSubmitted::class)->handle($this->scopedReport($reportId));

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
            app(DeleteDonorReport::class)->handle($this->scopedReport($this->deletingReportId));
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

    protected function paperworkUrl(Media $media): string
    {
        return $media->mediable_type === 'proposal'
            ? route('proposals.files.download', [Proposal::query()->findOrFail($media->mediable_id), $media])
            : route('projects.files.download', [Project::query()->findOrFail($media->mediable_id), $media]);
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
    $mutedText = 'text-secondary-medium dark:text-ink-muted';
    $inkText = 'text-secondary-dark dark:text-ink';
    $hairline = 'border-secondary-soft dark:border-line';
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
        'late' => 'bg-primary dark:bg-status-critical',
        'failed' => 'bg-primary dark:bg-status-critical',
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
    class="agreement-page @container/detail flex min-h-full w-full flex-1 flex-col bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink"
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
            <x-ui.project-number :number="$proposal->internal_project_number" kind="internal" label class="{{ $inkText }}" />
            <span aria-hidden="true">·</span>
            <x-ui.project-number :number="$agreement->external_project_number" kind="external" label class="{{ $inkText }}" />
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
                <x-ui.view-toggle
                    :label="__('finance_tables.view_label')"
                    wire-model="view"
                    :active="$this->view"
                    :options="[
                        ['key' => 'table', 'label' => __('finance_tables.view_table'), 'icon' => 'table-cells'],
                        ['key' => 'overview', 'label' => __('finance_tables.view_overview'), 'icon' => 'chart-bar'],
                    ]"
                />
                @can('funding-agreements.update')
                <x-ui.button size="sm" icon="pencil-square" class="min-h-10 touch-manipulation" wire:click="openAgreementEdit({{ $agreement->id }})" data-test="edit-agreement">{{ __('funding_agreements.edit_agreement') }}</x-ui.button>
                @endcan
                @can('transfers.create')
                <x-ui.button tone="primary" size="sm" icon="calendar-days" class="min-h-10 touch-manipulation" wire:click="openTransferCreate({{ $agreement->id }})" data-test="schedule-tranche">{{ __('funding_agreements.schedule_tranche') }}</x-ui.button>
                @endcan
            </div>
        </x-slot:actions>
    </x-ui.detail-header>

    @if ($this->view === 'overview')
        @include('pages.funding-agreements._show-overview')
    @else
        @php
            $totalLines = [[
                'currency' => $currency,
                'caption' => trans_choice('common.count_tranche', count($journeys), ['count' => count($journeys)]),
                'figures' => array_values(array_filter([
                    ['key' => 'budget', 'label' => __('finance_tables.budget'), 'value' => $money['budget']],
                    // Every tranche sent in this currency, whatever became of it: failed money was scheduled too.
                    ['key' => 'scheduled', 'label' => __('finance_tables.scheduled'), 'value' => $money['scheduled'] + $money['failed']],
                    ['key' => 'received', 'label' => __('finance_tables.received'), 'value' => $money['landed'], 'tone' => 'good', 'note' => $money['percentLabel'].'%'],
                    ['key' => 'lost', 'label' => __('funding_agreements.fees_and_exchange'), 'value' => $money['lost'], 'prefix' => $money['lost'] > 0 ? '−' : ''],
                    ['key' => 'in_transit', 'label' => __('finance_tables.in_transit'), 'value' => $money['transit'], 'tone' => $money['transit'] > 0 ? 'warn' : null],
                    ['key' => 'overdue', 'label' => __('finance_tables.overdue'), 'value' => $money['late'], 'tone' => $money['late'] > 0 ? 'critical' : null],
                    $money['failed'] > 0 ? ['key' => 'failed', 'label' => __('finance_tables.failed'), 'value' => $money['failed'], 'tone' => 'critical'] : null,
                    ['key' => 'unscheduled', 'label' => __('finance_tables.not_scheduled'), 'value' => $money['unscheduled'], 'tone' => 'muted'],
                    ['key' => 'outstanding', 'label' => __('finance_tables.outstanding'), 'value' => $money['stillToCome']],
                ])),
                'notes' => array_values(array_filter([
                    ...collect($money['foreignLandings'])->map(fn (float $foreignTotal, string $foreignCode): string => __('funding_agreements.also_landed_in', ['amount' => $amount($foreignTotal), 'currency' => $foreignCode]))->values()->all(),
                    $money['foreignCount'] > 0 ? trans_choice('funding_agreements.count_tranches_sent_in_another_currency', $money['foreignCount'], ['count' => $money['foreignCount']]) : null,
                ])),
            ]];
        @endphp

        <div class="pt-8">
            <x-funding.totals :lines="$totalLines" />
        </div>

        @include('pages.funding-agreements._show-attention')

        {{-- Every tranche, with every figure of its journey in its own column. --}}
        <section class="mt-10 min-w-0" aria-labelledby="tranches-heading" data-tranches>
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                <h2 id="tranches-heading" class="text-xl font-bold tracking-tight {{ $inkText }}">
                    {{ __('funding_agreements.tranches') }}
                    <span class="ms-1 text-base font-semibold tabular-nums {{ $mutedText }}">{{ count($journeys) }}</span>
                </h2>
                @if ($journeys !== [])
                    <div class="flex flex-wrap items-center gap-3">
                        <a href="{{ route('transfers.index', ['agreement' => $agreement->id]) }}" wire:navigate class="text-xs font-semibold underline-offset-4 hover:underline {{ $inkText }}" data-transfers-ledger-link>{{ __('funding_agreements.open_in_transfers_ledger') }}</a>
                        <x-ui.button size="sm" icon="arrow-down-tray" class="min-h-10 touch-manipulation" wire:click="exportTranchesCsv" wire:loading.attr="disabled" wire:target="exportTranchesCsv" data-test="export-csv">{{ __('finance_tables.export_csv') }}</x-ui.button>
                    </div>
                @endif
            </div>

            @if ($journeys === [])
                <div class="mt-6">
                    <x-ui.empty-state class="rtl:[&_svg]:-scale-x-100" icon="arrow-right-circle" :heading="__('funding_agreements.no_tranches_scheduled')" :body="__('funding_agreements.payments_scheduled_under_this_agreement_will_appear_here')" />
                </div>
            @else
                <x-funding.tranche-table class="mt-4" :transfers="$this->transfers" :caption="__('funding_agreements.tranches_of_name', ['name' => $donor->name.' · '.$proposal->name])" editable />
            @endif

            @if ($money['unscheduled'] > 0.0)
                <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1 py-4" data-unscheduled-line>
                    <p class="flex items-center gap-2 text-sm {{ $mutedText }}">
                        <span class="size-2 rounded-full border-2 border-dashed border-secondary-light" aria-hidden="true"></span>
                        {{ __('funding_agreements.not_scheduled_yet') }}
                        <span class="font-bold tabular-nums {{ $inkText }}">{{ $amount($money['unscheduled']) }} {{ $currency }}</span>
                    </p>
                    @can('transfers.create')
                    <button type="button" wire:click="openTransferCreate({{ $agreement->id }})" class="min-h-9 touch-manipulation text-xs font-semibold underline underline-offset-4 {{ $inkText }}">{{ __('funding_agreements.schedule_the_rest') }}</button>
                    @endcan
                </div>
            @endif
        </section>

        {{-- What the donor is owed back: every report, by when, and whether it went out. --}}
        <section class="mt-10 min-w-0 border-t pt-8 {{ $hairline }}" aria-labelledby="reports-heading" data-donor-reports>
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
                <x-funding.register class="mt-4" size="sm" :caption="__('funding_agreements.donor_reports')">
                    <x-slot:head>
                        <x-funding.register.th>{{ __('funding_agreements.report_title') }}</x-funding.register.th>
                        <x-funding.register.th>{{ __('common.type') }}</x-funding.register.th>
                        <x-funding.register.th>{{ __('funding_agreements.due') }}</x-funding.register.th>
                        <x-funding.register.th>{{ __('funding_agreements.submitted') }}</x-funding.register.th>
                        <x-funding.register.th>{{ __('common.state') }}</x-funding.register.th>
                        <th scope="col"><span class="sr-only">{{ __('common.actions') }}</span></th>
                    </x-slot:head>

                    @foreach ($this->reports as $report)
                        @php
                            $reportDays = (int) \Carbon\CarbonImmutable::today()->diffInDays($report->due_on, false);
                            $reportOverdue = $report->isOverdue();
                        @endphp
                        <tr wire:key="donor-report-{{ $report->id }}" data-donor-report="{{ $report->id }}">
                            <td data-label="{{ __('funding_agreements.report_title') }}" data-wide><span class="font-semibold">{{ $report->title }}</span></td>
                            <td data-label="{{ __('common.type') }}">{{ $report->type->label() }}</td>
                            <td class="date" data-label="{{ __('funding_agreements.due') }}">{{ $report->due_on->translatedFormat(__('formats.date')) }}</td>
                            <td class="date" data-label="{{ __('funding_agreements.submitted') }}">{{ $report->submitted_on?->translatedFormat(__('formats.date')) ?? '—' }}</td>
                            <td data-label="{{ __('common.state') }}" data-report-status>
                                <span @class([
                                    'text-xs font-semibold',
                                    'text-funding' => $report->isSubmitted(),
                                    'text-status-critical' => $reportOverdue,
                                    $mutedText => ! $report->isSubmitted() && ! $reportOverdue,
                                ])>
                                    @if ($report->isSubmitted())
                                        {{ __('funding_agreements.submitted') }}
                                    @elseif ($reportOverdue)
                                        {{ trans_choice('funding_agreements.count_days_overdue', -$reportDays, ['count' => -$reportDays]) }}
                                    @else
                                        {{ trans_choice('funding_agreements.due_in_days', $reportDays, ['count' => $reportDays]) }}
                                    @endif
                                </span>
                            </td>
                            <td>
                                @can('funding-agreements.update')
                                    <span class="flex items-center justify-end gap-1">
                                        @unless ($report->isSubmitted())
                                            <x-ui.button size="sm" icon="check" class="min-h-8 touch-manipulation" wire:click="markReportSubmitted({{ $report->id }})" data-test="submit-report-{{ $report->id }}">{{ __('funding_agreements.mark_submitted') }}</x-ui.button>
                                        @endunless
                                        <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-8!" wire:click="openReportEdit({{ $report->id }})" :aria-label="__('common.edit_name', ['name' => $report->title])" />
                                        <x-ui.button tone="ghost" size="sm" icon="trash" class="size-8!" wire:click="confirmReportDelete({{ $report->id }})" :aria-label="__('funding_agreements.delete_report_name', ['name' => $report->title])" data-test="delete-report-{{ $report->id }}" />
                                    </span>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </x-funding.register>
            @endif
        </section>

        <div class="mt-10 border-t pt-8 {{ $hairline }}">
            @include('pages.funding-agreements._show-connections', ['connectionsAsColumns' => true])
        </div>
    @endif

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
