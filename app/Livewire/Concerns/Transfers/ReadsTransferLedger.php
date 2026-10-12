<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Transfers;

use App\Models\TransfersTracking;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * The figures of the transfers register: the per-currency ledger lines behind
 * the Overview ledger and the table totals, the stage counts for the status
 * pills, each group's subtotals, and whether any tranche exists.
 *
 * Used by transfers/index only. Split from ReadsTransferRegister to stay under
 * the size limit; it calls settledSql(), stageSql(), filteredQuery() and
 * groupKeySql() from that trait.
 *
 * @phpstan-type LedgerLine array{currency: string, tranches: int, expected: float, sent: float, settledSent: float, landed: float, shrink: float, fees: float, exchangeGap: float, overage: float, inFlight: float, overdue: float, overdueCount: int, transit: float, transitCount: int, upcoming: float, upcomingCount: int, failed: float, failedCount: int, shortCount: int, unrecordedCount: int, unrecordedAmount: float, convertedCount: int, convertedAmount: float, gross: float, recordedFees: float, feesElsewhereCount: int}
 */
trait ReadsTransferLedger
{
    /**
     * One line per currency money was sent in: what left the donor, what
     * actually landed, and what the journey took out of it.
     *
     * Every figure is a grouped aggregate so the line costs one query however
     * many tranches exist. Currencies are grouped, never added: a USD line and
     * a EUR line are two separate stories on the same page.
     *
     * Only settled tranches feed the shortfall. Money still travelling has not
     * lost anything yet, and saying otherwise would invent leakage.
     *
     * The shortfall is the sum of each tranche's own loss, never the
     * difference between two totals. A tranche that arrived over — a
     * favourable rate, a donor rounding up — is real, but it did not put the
     * money back into the tranche that lost it, so it is reported beside the
     * shortfall and never allowed to cancel it. Netting the two is how a page
     * ends up announcing "landed in full" over money that never arrived.
     *
     * @return Collection<int, LedgerLine>
     */
    protected function ledgerLines(bool $withStage): Collection
    {
        $settled = $this->settledSql();
        $stages = $this->stageSql();

        // Per tranche: what it lost, and how much of that loss the recorded
        // fee explains. Written as CASE rather than LEAST so the expression
        // does not depend on one database's scalar function set.
        $rowShortfall = "(case when {$settled} and net_amount_received < payment_amount then payment_amount - net_amount_received else 0 end)";
        $rowFees = "(case
            when not ({$settled}) then 0
            when net_amount_received >= payment_amount then 0
            when fees_currency <> amount_transfered_currency then 0
            when fees < payment_amount - net_amount_received then fees
            else payment_amount - net_amount_received end)";

        return $this->filteredQuery($withStage)
            ->selectRaw("
                amount_transfered_currency as currency,
                count(*) as tranches,
                coalesce(sum(payment_amount), 0) as expected,
                coalesce(sum(case when {$settled} then payment_amount else 0 end), 0) as settled_sent,
                coalesce(sum(case when {$settled} then net_amount_received else 0 end), 0) as landed,
                coalesce(sum({$rowShortfall}), 0) as shortfall,
                coalesce(sum({$rowFees}), 0) as fees,
                coalesce(sum(case when {$settled} and net_amount_received > payment_amount then net_amount_received - payment_amount else 0 end), 0) as overage,
                coalesce(sum(case when {$stages['overdue']} then payment_amount else 0 end), 0) as overdue,
                sum(case when {$stages['overdue']} then 1 else 0 end) as overdue_count,
                coalesce(sum(case when {$stages['transit']} then payment_amount else 0 end), 0) as transit,
                sum(case when {$stages['transit']} then 1 else 0 end) as transit_count,
                coalesce(sum(case when {$stages['upcoming']} then payment_amount else 0 end), 0) as upcoming,
                sum(case when {$stages['upcoming']} then 1 else 0 end) as upcoming_count,
                coalesce(sum(case when {$stages['failed']} then payment_amount else 0 end), 0) as failed,
                sum(case when {$stages['failed']} then 1 else 0 end) as failed_count,
                sum(case when {$stages['short']} then 1 else 0 end) as short_count,
                sum(case when {$stages['unrecorded']} then 1 else 0 end) as unrecorded_count,
                coalesce(sum(case when {$stages['unrecorded']} then payment_amount else 0 end), 0) as unrecorded_amount,
                sum(case when ar_status = 'received' and amount_received_currency <> amount_transfered_currency then 1 else 0 end) as converted_count,
                coalesce(sum(case when ar_status = 'received' and amount_received_currency <> amount_transfered_currency then payment_amount else 0 end), 0) as converted_amount,
                coalesce(sum(case when transfer_date is not null then payment_amount else 0 end), 0) as actually_sent,
                coalesce(sum(case when ar_status = 'received' and amount_received_currency = amount_transfered_currency then coalesce(amount_received, 0) else 0 end), 0) as gross,
                coalesce(sum(case when fees_currency = amount_transfered_currency then fees else 0 end), 0) as recorded_fees,
                sum(case when fees > 0 and fees_currency <> amount_transfered_currency then 1 else 0 end) as fees_elsewhere_count
            ")
            ->groupBy('amount_transfered_currency')
            ->orderByRaw('sum(payment_amount) desc')
            ->get()
            ->map(function (TransfersTracking $row): array {
                $settledSent = (float) $row->getAttribute('settled_sent');
                $landed = (float) $row->getAttribute('landed');

                // Already summed per tranche in SQL, so a tranche that arrived
                // over cannot hide one that arrived short. Fees are named
                // first because they are the part someone recorded; whatever
                // is left is the exchange and the unexplained, and it is not
                // padded to look tidy.
                $shrink = (float) $row->getAttribute('shortfall');
                $feesTaken = (float) $row->getAttribute('fees');

                return [
                    'currency' => (string) $row->getAttribute('currency'),
                    'tranches' => (int) $row->getAttribute('tranches'),
                    // Everything this currency's tranches add up to, sent or not.
                    // Never labelled "sent": most of it may not have left yet.
                    'expected' => (float) $row->getAttribute('expected'),
                    // Only what actually left the donor, i.e. has a transfer date.
                    'sent' => (float) $row->getAttribute('actually_sent'),
                    'settledSent' => $settledSent,
                    'landed' => $landed,
                    'shrink' => $shrink,
                    'fees' => $feesTaken,
                    'exchangeGap' => max(0.0, $shrink - $feesTaken),
                    'overage' => (float) $row->getAttribute('overage'),
                    // Everything whose journey has not finished: still to be sent,
                    // moving, failed, converted, or received without a net amount.
                    'inFlight' => max(0.0, (float) $row->getAttribute('expected') - $settledSent),
                    'overdue' => (float) $row->getAttribute('overdue'),
                    'overdueCount' => (int) $row->getAttribute('overdue_count'),
                    'transit' => (float) $row->getAttribute('transit'),
                    'transitCount' => (int) $row->getAttribute('transit_count'),
                    'upcoming' => (float) $row->getAttribute('upcoming'),
                    'upcomingCount' => (int) $row->getAttribute('upcoming_count'),
                    'failed' => (float) $row->getAttribute('failed'),
                    'failedCount' => (int) $row->getAttribute('failed_count'),
                    'shortCount' => (int) $row->getAttribute('short_count'),
                    'unrecordedCount' => (int) $row->getAttribute('unrecorded_count'),
                    'unrecordedAmount' => (float) $row->getAttribute('unrecorded_amount'),
                    'convertedCount' => (int) $row->getAttribute('converted_count'),
                    'convertedAmount' => (float) $row->getAttribute('converted_amount'),
                    // The table's own columns: what the bank credited before its
                    // charges, and every fee recorded in the currency sent.
                    'gross' => (float) $row->getAttribute('gross'),
                    'recordedFees' => (float) $row->getAttribute('recorded_fees'),
                    'feesElsewhereCount' => (int) $row->getAttribute('fees_elsewhere_count'),
                ];
            });
    }

    /**
     * The Overview ledger. It deliberately ignores the stage filter, so its
     * figures keep describing the whole filtered set while a stage narrows
     * only the ladder beneath it.
     *
     * @return Collection<int, LedgerLine>
     */
    #[Computed]
    public function ledger(): Collection
    {
        return $this->ledgerLines(withStage: false);
    }

    /**
     * The table's totals. They respect every filter, status included, so the
     * totals line and the footer always add up to the rows shown.
     *
     * @return Collection<int, LedgerLine>
     */
    #[Computed]
    public function totals(): Collection
    {
        return $this->ledgerLines(withStage: true);
    }

    /**
     * How many tranches sit at each stage under every other filter, for the
     * status pills.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function stageCounts(): array
    {
        $stages = $this->stageSql();

        $counts = $this->filteredQuery(withStage: false)
            ->toBase()
            ->selectRaw(
                'count(*) as n_all, '.collect($stages)
                    ->map(fn (string $condition, string $stage): string => "coalesce(sum(case when {$condition} then 1 else 0 end), 0) as n_{$stage}")
                    ->implode(', ')
            )
            ->first();

        return collect(['all', ...array_keys($stages)])
            ->mapWithKeys(fn (string $stage): array => [$stage => (int) ($counts->{'n_'.$stage} ?? 0)])
            ->all();
    }

    /**
     * Each group's subtotals per currency, over every filtered tranche and not
     * only the visible page, so a group split across two pages shows its true
     * subtotal on both. Keyed by the group's key.
     *
     * @return Collection<array-key, Collection<int, \stdClass>>
     */
    #[Computed]
    public function groupTotals(): Collection
    {
        $key = $this->groupKeySql();

        if ($key === null) {
            return new Collection;
        }

        $settled = $this->settledSql();
        $table = 'transfers_tracking';

        return $this->filteredQuery()
            ->join('donor_proposal', 'donor_proposal.id', '=', 'transfers_tracking.donor_proposal_id')
            ->toBase()
            ->selectRaw("
                {$key} as group_key,
                {$table}.amount_transfered_currency as currency,
                count(*) as tranches,
                coalesce(sum({$table}.payment_amount), 0) as sent,
                coalesce(sum(case when {$table}.fees_currency = {$table}.amount_transfered_currency then {$table}.fees else 0 end), 0) as fees,
                coalesce(sum(case when {$table}.ar_status = 'received' and {$table}.amount_received_currency = {$table}.amount_transfered_currency then coalesce({$table}.amount_received, 0) else 0 end), 0) as gross,
                coalesce(sum(case when {$settled} then {$table}.net_amount_received else 0 end), 0) as net,
                coalesce(sum(case when {$settled} and {$table}.net_amount_received < {$table}.payment_amount then {$table}.payment_amount - {$table}.net_amount_received else 0 end), 0) as shortfall,
                coalesce(sum(case when {$settled} and {$table}.net_amount_received > {$table}.payment_amount then {$table}.net_amount_received - {$table}.payment_amount else 0 end), 0) as overage
            ")
            ->groupByRaw("{$key}, {$table}.amount_transfered_currency")
            ->orderBy('currency')
            ->get()
            ->groupBy(fn (object $row): string => (string) $row->group_key);
    }

    /**
     * Whether any tranche exists at all, so an empty page can tell the
     * difference between "nothing recorded yet" and "nothing matches".
     */
    #[Computed]
    public function hasAnyTransfer(): bool
    {
        return TransfersTracking::query()->exists();
    }
}
