<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Donors;

use App\Enums\ArStatus;
use App\Enums\DonorType;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\TransfersTracking;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;

/**
 * The donors Overview view's figures: the pulse strip, the share-of-wallet
 * strip, the donor type rail, the transfers ahead and one donor's money, plus
 * the next transfer of each donor on the table's visible page.
 *
 * Used by donors/index only. The page provides the UNCLASSIFIED and
 * NAMED_SEGMENTS constants. It reads ChecksFinanceVisibility
 * (canViewAgreements(), canViewTransfers()) and ReadsDonorRegister
 * (filteredQuery(), filteredDonorIds(), rows()).
 *
 * @phpstan-type WalletSegment array{donorId: ?int, name: string, committed: float, received: float, share: float|int, count: int}
 * @phpstan-type WalletLine array{currency: string, committed: float, received: float, segments: array<int, WalletSegment>}
 *
 * @property-read Collection<int, int> $filteredDonorIds  #[Computed] filteredDonorIds() in ReadsDonorRegister
 * @property-read LengthAwarePaginator<int, Donor> $rows  #[Computed] rows() in ReadsDonorRegister
 * @property-read array<int, WalletLine> $wallet  #[Computed] wallet()
 * @property-read int $overviewRows  #[Computed] overviewRows()
 */
trait ChartsDonorPortfolio
{
    /**
     * The one-line reading of the portfolio: who funds, what, and what is due.
     *
     * @return array{donors: int, funding: int, proposals: int, projects: int, overdue: int, followUps: int}
     */
    #[Computed]
    public function pulse(): array
    {
        $ids = $this->filteredDonorIds;
        $agreements = DonorProposal::query()->whereIn('donor_id', $ids);

        $pendingTransfers = fn () => TransfersTracking::query()
            ->where('ar_status', ArStatus::Pending)
            ->whereHas('donorProposal', fn (Builder $query) => $query->whereIn('donor_id', $ids));

        return [
            'donors' => $ids->count(),
            'funding' => (clone $agreements)->distinct()->count('donor_id'),
            'proposals' => (clone $agreements)->distinct()->count('proposal_id'),
            'projects' => DB::table('project_proposal')
                ->whereIn('proposal_id', (clone $agreements)->select('proposal_id'))
                ->distinct()
                ->count('project_id'),
            // Sent money is in transit, not overdue, however late it is.
            'overdue' => $this->canViewTransfers() ? $pendingTransfers()->whereNull('transfer_date')->whereDate('scheduled_date', '<', today())->count() : 0,
            'followUps' => Donor::query()->whereIn('id', $ids)->whereDate('next_step_due_on', '<=', today())->count(),
        ];
    }

    /**
     * Each currency's committed money split by donor, largest first.
     *
     * Received counts only money that arrived in the agreement's own currency,
     * so a segment never mixes currencies.
     *
     * @return array<int, WalletLine>
     */
    #[Computed]
    public function wallet(): array
    {
        if (! $this->canViewAgreements()) {
            return [];
        }

        $ids = $this->filteredDonorIds;

        /** @var Collection<int, DonorProposal&object{total: string}> $committed */
        $committed = DonorProposal::query()
            ->whereIn('donor_id', $ids)
            ->selectRaw('donor_id, currency, sum(budget) as total')
            ->groupBy('donor_id', 'currency')
            ->get();

        $received = ! $this->canViewTransfers() ? collect() : TransfersTracking::query()
            ->join('donor_proposal', 'donor_proposal.id', '=', 'transfers_tracking.donor_proposal_id')
            ->whereIn('donor_proposal.donor_id', $ids)
            ->where('transfers_tracking.ar_status', ArStatus::Received)
            ->whereColumn('transfers_tracking.amount_received_currency', 'donor_proposal.currency')
            ->selectRaw('donor_proposal.donor_id as donor_id, donor_proposal.currency as currency, sum(transfers_tracking.net_amount_received) as total')
            ->groupBy('donor_proposal.donor_id', 'donor_proposal.currency')
            ->get()
            ->mapWithKeys(fn ($row): array => [$row->donor_id.'|'.$row->currency => (float) $row->total]);

        $names = Donor::query()->whereIn('id', $committed->pluck('donor_id')->unique())->pluck('name', 'id');

        return $committed
            ->groupBy(fn ($row): string => $row->currency->value)
            ->sortKeys()
            ->map(function (Collection $rows, string $currency) use ($received, $names): array {
                $total = (float) $rows->sum('total');

                /** @var Collection<int, array{donorId: ?int, name: string, committed: float, received: float, count: int}> $segments */
                $segments = $rows
                    ->map(fn ($row): array => [
                        'donorId' => (int) $row->donor_id,
                        'name' => (string) ($names[$row->donor_id] ?? ''),
                        'committed' => (float) $row->total,
                        'received' => (float) ($received[$row->donor_id.'|'.$currency] ?? 0),
                        'count' => 1,
                    ])
                    ->sortByDesc('committed')
                    ->values();

                $named = $segments->take(self::NAMED_SEGMENTS);
                $rest = $segments->slice(self::NAMED_SEGMENTS);

                if ($rest->isNotEmpty()) {
                    $named->push([
                        'donorId' => null,
                        'name' => trans_choice('donors.count_other_donors', $rest->count(), ['count' => $rest->count()]),
                        'committed' => (float) $rest->sum('committed'),
                        'received' => (float) $rest->sum('received'),
                        'count' => $rest->count(),
                    ]);
                }

                return [
                    'currency' => $currency,
                    'committed' => $total,
                    'received' => (float) $segments->sum('received'),
                    'segments' => $named
                        ->map(fn (array $segment): array => [...$segment, 'share' => $total > 0 ? round($segment['committed'] / $total * 100, 2) : 0])
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * How many donors of each type match the other filters; the rail stays fully selectable.
     *
     * @return list<array{value: string, label: string, count: int}>
     */
    #[Computed]
    public function typeRail(): array
    {
        $counts = $this->filteredQuery(withType: false)
            ->selectRaw("coalesce(type, '".self::UNCLASSIFIED."') as type_key, count(*) as total")
            ->groupBy('type_key')
            ->pluck('total', 'type_key');

        $stops = array_map(fn (DonorType $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
            'count' => (int) ($counts[$case->value] ?? 0),
        ], DonorType::cases());

        $stops[] = [
            'value' => self::UNCLASSIFIED,
            'label' => __('donors.not_classified'),
            'count' => (int) ($counts[self::UNCLASSIFIED] ?? 0),
        ];

        return $stops;
    }

    /**
     * How many rows the funders column shows, so the transfers column can match its height.
     *
     * Each currency block costs roughly two rows for its heading.
     */
    #[Computed]
    public function overviewRows(): int
    {
        $rows = collect($this->wallet)->sum(fn (array $line): int => count($line['segments']) + 2);

        return max(3, min(8, $rows));
    }

    /**
     * The pending transfers ahead, overdue ones first, for the transfers column.
     *
     * @return Collection<int, array{donorId: int, donor: string, proposal: string, agreementId: int, amount: float, currency: string, date: CarbonImmutable, overdue: bool}>
     */
    #[Computed]
    public function upcomingTransfers(): Collection
    {
        if (! $this->canViewTransfers()) {
            return new Collection;
        }

        return TransfersTracking::query()
            ->select(['id', 'donor_proposal_id', 'payment_amount', 'amount_transfered_currency', 'scheduled_date', 'transfer_date', 'ar_status'])
            ->where('ar_status', ArStatus::Pending)
            ->whereNull('transfer_date')
            ->whereNotNull('scheduled_date')
            ->whereHas('donorProposal', fn (Builder $query) => $query->whereIn('donor_id', $this->filteredDonorIds))
            ->with(['donorProposal:id,donor_id,proposal_id', 'donorProposal.donor:id,name', 'donorProposal.proposal:id,name'])
            ->orderBy('scheduled_date')
            ->limit($this->overviewRows)
            ->get()
            ->map(fn (TransfersTracking $transfer): array => [
                'donorId' => $transfer->donorProposal->donor_id,
                'donor' => $transfer->donorProposal->donor->name,
                'proposal' => (string) $transfer->donorProposal->proposal?->name,
                'agreementId' => $transfer->donor_proposal_id,
                'amount' => (float) $transfer->payment_amount,
                'currency' => $transfer->amount_transfered_currency->value,
                'date' => $transfer->scheduled_date,
                'overdue' => $transfer->state() === 'late',
            ]);
    }

    /**
     * One donor's money per currency, and the transfer rhythm behind it.
     *
     * @return array{lines: array<string, array{committed: float, received: float, pending: float, share: int}>, next: ?array{amount: float, currency: string, date: CarbonImmutable, overdue: bool}, overdue: int, lastReceived: ?CarbonImmutable, projects: int}
     */
    protected function donorMoney(Donor $donor): array
    {
        $projects = $donor->donorProposals->flatMap(fn (DonorProposal $agreement) => $agreement->proposal?->projects->pluck('id') ?? [])->unique()->count();

        if (! $this->canViewAgreements()) {
            return ['lines' => [], 'next' => null, 'overdue' => 0, 'lastReceived' => null, 'projects' => $projects];
        }

        $lines = [];
        $pendingTransfers = collect();
        $receivedDates = collect();

        foreach ($donor->donorProposals as $agreement) {
            $currency = $agreement->currency->value;
            $lines[$currency] ??= ['committed' => 0.0, 'received' => 0.0, 'pending' => 0.0, 'share' => 0];
            $lines[$currency]['committed'] += (float) $agreement->budget;

            foreach ($agreement->transfers as $transfer) {
                if ($transfer->ar_status === ArStatus::Received && $transfer->amount_received_currency?->value === $currency) {
                    $lines[$currency]['received'] += (float) $transfer->net_amount_received;
                }

                if ($transfer->ar_status === ArStatus::Pending) {
                    $pendingTransfers->push($transfer);

                    if ($transfer->amount_transfered_currency?->value === $currency) {
                        $lines[$currency]['pending'] += (float) $transfer->payment_amount;
                    }
                }

                if ($transfer->ar_status === ArStatus::Received && $transfer->receive_date !== null) {
                    $receivedDates->push($transfer->receive_date);
                }
            }
        }

        ksort($lines);

        foreach ($lines as $currency => $line) {
            $lines[$currency]['share'] = $line['committed'] > 0 ? (int) floor($line['received'] / $line['committed'] * 100) : 0;
        }

        $next = $pendingTransfers->whereNull('transfer_date')->whereNotNull('scheduled_date')->sortBy('scheduled_date')->first();

        return [
            'lines' => $lines,
            'next' => $next === null ? null : [
                'amount' => (float) $next->payment_amount,
                'currency' => (string) $next->amount_transfered_currency?->value,
                'date' => $next->scheduled_date,
                'overdue' => $next->state() === 'late',
            ],
            'overdue' => $pendingTransfers->filter(fn (TransfersTracking $transfer): bool => $transfer->state() === 'late')->count(),
            'lastReceived' => $receivedDates->sortDesc()->first(),
            'projects' => $projects,
        ];
    }

    /**
     * The earliest unsent tranche of each donor and agreement currency on the
     * visible page, keyed "donorId|currency": the date the table prints as the
     * next transfer, with the amount due on it. Money already sent is in
     * transit, so it is never a next transfer.
     *
     * @return Collection<string, \stdClass>
     */
    #[Computed]
    public function nextTransfers(): Collection
    {
        if (! $this->canViewAgreements() || ! $this->canViewTransfers()) {
            return new Collection;
        }

        $donorIds = $this->rows->getCollection()->pluck('id')->unique()->values();

        if ($donorIds->isEmpty()) {
            return new Collection;
        }

        return TransfersTracking::query()
            ->join('donor_proposal', 'donor_proposal.id', '=', 'transfers_tracking.donor_proposal_id')
            ->whereIn('donor_proposal.donor_id', $donorIds)
            ->where('transfers_tracking.ar_status', ArStatus::Pending)
            ->whereNull('transfers_tracking.transfer_date')
            ->whereNotNull('transfers_tracking.scheduled_date')
            ->orderBy('transfers_tracking.scheduled_date')
            ->orderBy('transfers_tracking.id')
            ->toBase()
            ->get([
                'donor_proposal.donor_id',
                'donor_proposal.currency as agreement_currency',
                'transfers_tracking.payment_amount',
                'transfers_tracking.amount_transfered_currency as sent_currency',
                'transfers_tracking.scheduled_date',
            ])
            ->unique(fn (object $row): string => $row->donor_id.'|'.$row->agreement_currency)
            ->keyBy(fn (object $row): string => $row->donor_id.'|'.$row->agreement_currency);
    }
}
