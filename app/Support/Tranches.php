<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\TransfersTracking;
use Carbon\CarbonImmutable;

/**
 * A list of tranches already in memory: labels, timing, totals and CSV rows,
 * by the same rules the transfers page applies in SQL.
 */
final class Tranches
{
    /**
     * Every state key written out in full (LocalizationTest reads keys literally).
     */
    public static function stateLabel(string $state, bool $missingNet = false): string
    {
        if ($missingNet) {
            return __('finance_tables.state_net_missing');
        }

        return match ($state) {
            'received' => __('finance_tables.state_received'),
            'failed' => __('finance_tables.state_failed'),
            'transit' => __('finance_tables.state_transit'),
            'late' => __('finance_tables.state_late'),
            default => __('finance_tables.state_upcoming'),
        };
    }

    /**
     * How late, how long in transit, or how long the journey took.
     */
    public static function timing(TransfersTracking $transfer): ?string
    {
        $today = CarbonImmutable::today();

        return match ($transfer->state()) {
            'late' => trans_choice('transfers.count_days_overdue', $days = (int) $transfer->scheduled_date->diffInDays($today), ['count' => $days]),
            // The journey ends at arrival (D-31a); until then it is still running.
            'transit' => trans_choice('transfers.in_transit_for_count_days', $days = max(0, (int) $transfer->transfer_date->diffInDays($transfer->arrival_date ?? $today)), ['count' => $days]),
            'received' => $transfer->transfer_date !== null && $transfer->receive_date !== null
                ? trans_choice('transfers.landed_in_count_days', $days = max(0, (int) $transfer->transfer_date->diffInDays($transfer->receive_date, false)), ['count' => $days])
                : null,
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function csvHeadings(): array
    {
        return [
            __('common.state'), __('funding_agreements.donor'), __('common.proposal'), __('finance_tables.reference'), __('transfers.account'),
            __('transfers.scheduled'), __('transfers.sent_on'), __('transfers.received_on'), __('common.currency'), __('finance_tables.amount_sent'),
            __('transfers.fees'), __('finance_tables.fees_currency'), __('transfers.exchange_rate'), __('finance_tables.gross_received'), __('common.net_received'),
            __('finance_tables.received_currency'), __('finance_tables.difference'), __('finance_tables.recorded_status'),
        ];
    }

    /**
     * @return list<mixed>
     */
    public static function csvRow(TransfersTracking $transfer, string $donor, string $proposal, ?string $reference, ?string $account): array
    {
        $received = $transfer->state() === 'received';
        $rate = rtrim(rtrim(number_format((float) $transfer->exchange_rate, 6, '.', ''), '0'), '.');

        return [
            self::stateLabel($transfer->state(), $transfer->isMissingNetAmount()),
            $donor, $proposal, $reference, $account,
            $transfer->scheduled_date, $transfer->transfer_date, $transfer->receive_date,
            $transfer->amount_transfered_currency->value,
            CsvExport::amount($transfer->payment_amount),
            CsvExport::amount($transfer->fees),
            $transfer->fees_currency->value,
            $rate === '' ? '0' : $rate,
            $received ? CsvExport::amount($transfer->amount_received) : '',
            $received ? CsvExport::amount($transfer->net_amount_received) : '',
            $received ? $transfer->amount_received_currency->value : '',
            CsvExport::amount($transfer->difference()),
            $transfer->ar_status->value,
        ];
    }
}
