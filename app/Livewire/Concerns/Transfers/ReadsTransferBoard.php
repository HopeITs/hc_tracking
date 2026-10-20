<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Transfers;

use App\Livewire\Concerns\Desk\PagesRegisterCalendar;
use App\Models\TransfersTracking;
use App\Support\Desk\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

/**
 * The transfers register's Board (one column per state a tranche can be in)
 * and Calendar (the day it arrived, else the day it is scheduled) views, over
 * the same filters as the table.
 */
trait ReadsTransferBoard
{
    use PagesRegisterCalendar;

    /** Cards per Board column (desk.js slices 40). */
    public const BOARD_COLUMN_LIMIT = 40;

    /**
     * TransfersTracking::state() → its label, in the order the columns stand.
     *
     * @return array<string, string>
     */
    protected function boardStates(): array
    {
        return [
            'late' => __('finance_tables.overdue'),
            'upcoming' => __('finance_tables.upcoming'),
            'transit' => __('finance_tables.in_transit'),
            'received' => __('finance_tables.received'),
            'failed' => __('finance_tables.failed'),
        ];
    }

    /**
     * @return list<array{key: string, label: string, count: int, items: Collection<int, TransfersTracking>}>
     */
    #[Computed]
    public function boardColumns(): array
    {
        $transfers = $this->boardQuery()
            ->orderByRaw('transfers_tracking.scheduled_date is null, transfers_tracking.scheduled_date')
            ->get();
        $columns = [];

        foreach ($this->boardStates() as $state => $label) {
            $inColumn = $transfers->filter(fn (TransfersTracking $transfer): bool => $transfer->state() === $state)->values();
            $columns[] = [
                'key' => $state,
                'label' => $label,
                'count' => $inColumn->count(),
                'items' => $inColumn->take(self::BOARD_COLUMN_LIMIT),
            ];
        }

        return $columns;
    }

    /**
     * @return list<array{date: string, label: string, href: null}>
     */
    #[Computed]
    public function calendarItems(): array
    {
        $start = $this->calendarMonthStart()->toDateString();
        $end = $this->calendarMonthStart()->endOfMonth()->toDateString();

        return array_values($this->boardQuery()
            ->whereRaw('coalesce(transfers_tracking.receive_date, transfers_tracking.scheduled_date) between ? and ?', [$start, $end])
            ->get()
            ->map(fn (TransfersTracking $transfer): array => [
                'date' => ($transfer->receive_date ?? $transfer->scheduled_date)->toDateString(),
                'label' => $transfer->donorProposal->donor->name.' · '.Money::format($transfer->payment_amount, $transfer->amount_transfered_currency),
                'href' => null,
            ])
            ->all());
    }

    /**
     * @return Builder<TransfersTracking>
     */
    protected function boardQuery(): Builder
    {
        return $this->filteredQuery()->with([
            'donorProposal:id,donor_id,proposal_id',
            'donorProposal.donor:id,name',
            'donorProposal.proposal:id,name',
        ]);
    }
}
