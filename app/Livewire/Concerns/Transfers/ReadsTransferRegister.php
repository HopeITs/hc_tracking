<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Transfers;

use App\Models\TransfersTracking;
use App\Support\Like;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;

/**
 * What the transfers register reads from the database: the stage and filter
 * SQL, the register and ladder queries, their sort orders, the grouping, and
 * the two visible pages (rows() for the table, transfers() for the Overview
 * ladder).
 *
 * Used by transfers/index only. The page provides the filter properties. It
 * reads FiltersFinanceRegister (applyRegisterSort(), registerPerPage()). The
 * ledger, totals and counts built on top of filteredQuery() live in
 * ReadsTransferLedger; exportCsv() is in ExportsTransferRegister.
 *
 * @property string $search
 * @property string $donor
 * @property string $account
 * @property string $currency
 * @property string $stage
 * @property string $dateBasis
 * @property string $from
 * @property string $to
 * @property string $min
 * @property string $max
 * @property string $group
 * @property string $sort
 * @property string $agreement
 * @property string $flag
 * @property-read LengthAwarePaginator<int, TransfersTracking> $rows  #[Computed] rows()
 * @property-read LengthAwarePaginator<int, TransfersTracking> $transfers  #[Computed] transfers()
 */
trait ReadsTransferRegister
{
    /**
     * A tranche whose journey has finished and whose two ends can honestly be
     * compared. One definition, on the model, for every page.
     */
    protected function settledSql(): string
    {
        return TransfersTracking::settledSql();
    }

    /**
     * Stage conditions as SQL, keyed by stage. Each one is the model's own
     * definition, so a filter, a count and a total read a tranche the same way
     * its row does.
     *
     * @return array<string, string>
     */
    protected function stageSql(): array
    {
        return [
            'overdue' => TransfersTracking::stateSql('late'),
            'transit' => TransfersTracking::stateSql('transit'),
            'upcoming' => TransfersTracking::stateSql('upcoming'),
            'received' => TransfersTracking::stateSql('received'),
            'failed' => TransfersTracking::stateSql('failed'),
            'short' => TransfersTracking::landedShortSql(),
            'unrecorded' => TransfersTracking::missingNetSql(),
        ];
    }

    /**
     * Tranches matching every active filter, with no ordering and no eager
     * loads, so the table, its totals, the Overview ledger and the export all
     * build on it.
     *
     * The Overview ledger and the status counts pass $withStage false so they
     * keep describing the whole filtered set while a stage narrows the rows.
     *
     * @return Builder<TransfersTracking>
     */
    protected function filteredQuery(bool $withStage = true): Builder
    {
        $search = trim($this->search);
        $stages = $this->stageSql();

        return TransfersTracking::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->whereHas('donorProposal', function (Builder $query) use ($search): void {
                    $query
                        ->where('external_project_number', 'like', Like::contains($search))
                        ->orWhereHas('donor', fn (Builder $query) => $query->where('name', 'like', Like::contains($search)))
                        ->orWhereHas('proposal', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                            ->where('name', 'like', Like::contains($search))
                            ->orWhere('internal_project_number', 'like', Like::contains($search))));
                });
            })
            ->when($this->donor !== '', fn (Builder $query) => $query->whereHas('donorProposal', fn (Builder $query) => $query->where('donor_id', (int) $this->donor)))
            ->when($this->account !== '', fn (Builder $query) => $query->where('transfers_tracking.account_id', (int) $this->account))
            ->when((int) $this->agreement > 0, fn (Builder $query) => $query->where('transfers_tracking.donor_proposal_id', (int) $this->agreement))
            ->when($this->currency !== '', fn (Builder $query) => $query->where('transfers_tracking.amount_transfered_currency', $this->currency))
            ->when($this->from !== '' || $this->to !== '', fn (Builder $query) => $query->datedWithin($this->dateBasis, $this->from ?: null, $this->to ?: null))
            ->when($this->min !== '', fn (Builder $query) => $query->where('transfers_tracking.payment_amount', '>=', (float) $this->min))
            ->when($this->max !== '', fn (Builder $query) => $query->where('transfers_tracking.payment_amount', '<=', (float) $this->max))
            ->when($this->flag === 'converted', fn (Builder $query) => $query->converted())
            ->when($this->flag === 'no_account', fn (Builder $query) => $query->whereNull('transfers_tracking.account_id'))
            ->when(
                $withStage && array_key_exists($this->stage, $stages),
                fn (Builder $query) => $query->whereRaw($stages[$this->stage]),
            );
    }

    /**
     * The filtered tranches joined to the records their rows name, so the
     * table can be sorted and grouped by donor, proposal and account.
     *
     * @return Builder<TransfersTracking>
     */
    protected function registerQuery(): Builder
    {
        return $this->filteredQuery()
            ->join('donor_proposal', 'donor_proposal.id', '=', 'transfers_tracking.donor_proposal_id')
            ->join('donors', 'donors.id', '=', 'donor_proposal.donor_id')
            ->join('proposals', 'proposals.id', '=', 'donor_proposal.proposal_id')
            ->leftJoin('accounts', 'accounts.id', '=', 'transfers_tracking.account_id');
    }

    /**
     * What each column heading sorts by. Dates always put a missing date last;
     * amounts sort inside their currency, so figures in different currencies
     * are never ranked against each other.
     *
     * @return array<string, array{dir: 'asc'|'desc', order: list<string>}>
     */
    protected function registerSorts(): array
    {
        $table = 'transfers_tracking';
        $stages = $this->stageSql();
        $settled = $this->settledSql();
        $lastById = "{$table}.id desc";

        $byDate = fn (string $column): array => ["{$table}.{$column} is null", "{$table}.{$column} {dir}", $lastById];
        $byAmount = fn (string $currency, string $amount): array => ["{$table}.{$amount} is null", "{$table}.{$currency} asc", "{$table}.{$amount} {dir}", $lastById];

        return [
            'scheduled' => ['dir' => 'desc', 'order' => $byDate('scheduled_date')],
            'sent' => ['dir' => 'desc', 'order' => $byDate('transfer_date')],
            'received' => ['dir' => 'desc', 'order' => $byDate('receive_date')],
            'donor' => ['dir' => 'asc', 'order' => ['donors.name {dir}', 'proposals.name {dir}', $lastById]],
            'account' => ['dir' => 'asc', 'order' => ['accounts.account_name is null', 'accounts.account_name {dir}', $lastById]],
            'status' => ['dir' => 'asc', 'order' => [
                "case when {$stages['overdue']} then 0 when {$stages['failed']} then 1 when {$stages['transit']} then 2 when {$stages['upcoming']} then 3 else 4 end {dir}",
                "{$table}.scheduled_date is null",
                "{$table}.scheduled_date asc",
                $lastById,
            ]],
            'amount' => ['dir' => 'desc', 'order' => $byAmount('amount_transfered_currency', 'payment_amount')],
            'fees' => ['dir' => 'desc', 'order' => $byAmount('fees_currency', 'fees')],
            'rate' => ['dir' => 'desc', 'order' => ["{$table}.exchange_rate {dir}", $lastById]],
            'gross' => ['dir' => 'desc', 'order' => $byAmount('amount_received_currency', 'amount_received')],
            'net' => ['dir' => 'desc', 'order' => $byAmount('amount_received_currency', 'net_amount_received')],
            // Largest loss first. A tranche with no comparable ends sorts last
            // rather than passing for one that lost nothing.
            'difference' => ['dir' => 'asc', 'order' => [
                "case when {$settled} then 0 else 1 end",
                "{$table}.amount_transfered_currency asc",
                "({$table}.net_amount_received - {$table}.payment_amount) {dir}",
                $lastById,
            ]],
            // Overdue first and oldest-overdue first inside it, then outright
            // failures, then money that landed short, then money still moving.
            'attention' => ['dir' => 'asc', 'order' => [
                "case when {$stages['overdue']} then 0 when {$stages['failed']} then 1 when {$stages['short']} then 2 when {$stages['unrecorded']} then 3 when {$stages['transit']} then 4 else 5 end {dir}",
                "{$table}.scheduled_date is null",
                "{$table}.scheduled_date asc",
                $lastById,
            ]],
        ];
    }

    protected function registerDefaultSort(): string
    {
        return 'scheduled';
    }

    /**
     * The date column the date range and the month grouping read.
     */
    protected function dateColumn(): string
    {
        return 'transfers_tracking.'.TransfersTracking::DATE_COLUMNS[$this->dateBasis];
    }

    /**
     * The SQL expression whose value a group shares, or null when the table is
     * not grouped.
     */
    protected function groupKeySql(): ?string
    {
        return match ($this->group) {
            'agreement' => 'transfers_tracking.donor_proposal_id',
            'donor' => 'donor_proposal.donor_id',
            'account' => 'coalesce(transfers_tracking.account_id, 0)',
            'month' => "coalesce(date_format({$this->dateColumn()}, '%Y-%m'), '')",
            default => null,
        };
    }

    /**
     * Keep each group's rows together, ahead of the chosen sort.
     *
     * @param  Builder<TransfersTracking>  $query
     * @return Builder<TransfersTracking>
     */
    protected function groupOrder(Builder $query): Builder
    {
        return match ($this->group) {
            'agreement' => $query->orderBy('donors.name')->orderBy('proposals.name')->orderBy('transfers_tracking.donor_proposal_id'),
            'donor' => $query->orderBy('donors.name')->orderBy('donor_proposal.donor_id'),
            'account' => $query->orderByRaw('accounts.account_name is null')->orderBy('accounts.account_name')->orderBy('transfers_tracking.account_id'),
            'month' => $query->orderByRaw("{$this->dateColumn()} is null")->orderByRaw("date_format({$this->dateColumn()}, '%Y-%m') desc"),
            default => $query,
        };
    }

    /**
     * The group a row belongs to: the same value groupKeySql() gives it.
     */
    protected function groupKeyOf(TransfersTracking $transfer): string
    {
        return match ($this->group) {
            'agreement' => (string) $transfer->donor_proposal_id,
            'donor' => (string) $transfer->donorProposal->donor_id,
            'account' => (string) ($transfer->account_id ?? 0),
            'month' => $transfer->{TransfersTracking::DATE_COLUMNS[$this->dateBasis]}?->format('Y-m') ?? '',
            default => '',
        };
    }

    /**
     * What a group's header row calls it.
     */
    protected function groupLabelOf(TransfersTracking $transfer): string
    {
        $agreement = $transfer->donorProposal;

        return match ($this->group) {
            'agreement' => collect([$agreement->donor->name, $agreement->proposal->name, $agreement->external_project_number])->filter()->implode(' · '),
            'donor' => $agreement->donor->name,
            'account' => $transfer->account?->account_name ?? __('transfers.no_account'),
            'month' => $transfer->{TransfersTracking::DATE_COLUMNS[$this->dateBasis]}?->translatedFormat(__('formats.month_year')) ?? __('finance_tables.no_date'),
            default => '',
        };
    }

    /**
     * The table's visible page.
     *
     * @return LengthAwarePaginator<int, TransfersTracking>
     */
    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        $query = $this->registerQuery()
            ->select('transfers_tracking.*')
            ->with([
                'donorProposal:id,slug,donor_id,proposal_id,external_project_number',
                'donorProposal.donor:id,slug,name',
                'donorProposal.proposal:id,slug,name',
                // Nullable: a tranche may be scheduled before an account exists.
                'account:id,account_name',
            ]);

        return $this->applyRegisterSort($this->groupOrder($query))->paginate($this->registerPerPage());
    }

    /**
     * The Overview ladder's order.
     *
     * @param  Builder<TransfersTracking>  $query
     * @return Builder<TransfersTracking>
     */
    protected function applySort(Builder $query): Builder
    {
        $settled = $this->settledSql();
        $stages = $this->stageSql();

        $sorted = match ($this->sort) {
            // Proportional loss, so a small tranche bled dry outranks a large
            // one that lost a rounding error. Tranches with no comparable end
            // sort last rather than masquerading as losing nothing.
            'shortfall' => $query
                ->orderByRaw("case when {$settled} and payment_amount > 0 then (payment_amount - net_amount_received) / payment_amount else -1 end desc")
                ->orderByDesc('payment_amount'),
            'amount' => $query->orderByDesc('payment_amount'),
            'date' => $query->orderByRaw('scheduled_date is null, scheduled_date desc'),
            // Overdue first and oldest-overdue first inside it, then outright
            // failures, then money that landed short, then money still moving.
            default => $query
                ->orderByRaw("case
                    when {$stages['overdue']} then 0
                    when {$stages['failed']} then 1
                    when {$stages['short']} then 2
                    when {$stages['unrecorded']} then 3
                    when {$stages['transit']} then 4
                    else 5 end")
                ->orderByRaw('scheduled_date is null, scheduled_date asc'),
        };

        return $sorted->orderByDesc('id');
    }

    /**
     * The Overview ladder's visible page.
     *
     * @return LengthAwarePaginator<int, TransfersTracking>
     */
    #[Computed]
    public function transfers(): LengthAwarePaginator
    {
        $query = $this->filteredQuery()
            ->select([
                'id', 'donor_proposal_id', 'account_id', 'payment_amount', 'amount_transfered_currency',
                'scheduled_date', 'transfer_date', 'fees', 'fees_currency', 'amount_received',
                'exchange_rate', 'net_amount_received', 'amount_received_currency', 'receive_date', 'ar_status',
            ])
            ->with([
                'donorProposal:id,donor_id,proposal_id,external_project_number',
                'donorProposal.donor:id,name',
                'donorProposal.proposal:id,name,internal_project_number',
                // Nullable: a tranche may be scheduled before an account exists.
                'account:id,account_name,is_active',
            ]);

        return $this->applySort($query)->paginate(20);
    }
}
