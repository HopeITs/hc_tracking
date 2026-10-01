<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\TransfersTracking;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What passed through each bank account, as SQL.
 *
 * Every figure counts only money in the account's own currency: an amount in
 * another currency cannot be set beside it without a rate this system does not
 * hold, so it is counted apart (`a_other_currency`) and never converted.
 *
 * The accounts table joins perAccount() for its rows, its totals and its
 * export, so a figure that is sorted, summed or exported is always the figure
 * the row prints.
 */
final class AccountMoney
{
    /**
     * One row per account that has a tranche, with its money.
     *
     * With a year, `a_received_in_year` is what landed in that year and
     * `a_in_year` counts the tranches due or landed in it. With $yearOnly,
     * only tranches scheduled in that year are read at all.
     */
    public static function perAccount(?int $year = null, bool $yearOnly = false): Builder
    {
        $table = 'transfers_tracking';
        $sentHere = "{$table}.amount_transfered_currency = account.currency";
        $landedHere = '('.TransfersTracking::stateSql('received')." and {$table}.net_amount_received is not null and {$table}.amount_received_currency = account.currency)";
        $landedElsewhere = '('.TransfersTracking::stateSql('received')." and {$table}.net_amount_received is not null and {$table}.amount_received_currency <> account.currency)";

        $sum = fn (string $condition, string $column): string => "coalesce(sum(case when {$condition} then {$column} else 0 end), 0)";
        $count = fn (string $condition): string => "coalesce(sum(case when {$condition} then 1 else 0 end), 0)";
        $sent = fn (string $state): string => $sum("({$sentHere} and ".TransfersTracking::stateSql($state).')', "{$table}.payment_amount");

        $columns = [
            "{$table}.account_id",
            'count(*) as a_transfers',
            $sum($landedHere, "{$table}.net_amount_received").' as a_received',
            $sent('transit').' as a_in_transit',
            $sent('upcoming').' as a_upcoming',
            $sent('late').' as a_overdue',
            $count($landedElsewhere).' as a_other_currency',
            'max(case when '.TransfersTracking::stateSql('received')." then {$table}.receive_date end) as a_last_receipt",
        ];

        $bounds = AgreementMoney::yearBounds($year);

        if ($bounds === null) {
            $columns[] = '0 as a_received_in_year';
            $columns[] = '0 as a_in_year';
        } else {
            [$from, $to] = $bounds;
            $receivedInYear = "({$landedHere} and {$table}.receive_date between '{$from}' and '{$to}')";
            $inYear = "({$table}.scheduled_date between '{$from}' and '{$to}' or (".TransfersTracking::stateSql('received')." and {$table}.receive_date between '{$from}' and '{$to}'))";

            $columns[] = $sum($receivedInYear, "{$table}.net_amount_received").' as a_received_in_year';
            $columns[] = $count($inYear).' as a_in_year';
        }

        return DB::table($table)
            ->join('accounts as account', 'account.id', '=', "{$table}.account_id")
            ->when(
                $bounds !== null && $yearOnly,
                fn (Builder $query) => $query->whereRaw("{$table}.scheduled_date between '{$bounds[0]}' and '{$bounds[1]}'"),
            )
            ->selectRaw(implode(', ', $columns))
            ->groupBy("{$table}.account_id");
    }

    /**
     * The money columns of a query that joined perAccount() as `money`.
     */
    public static function select(): string
    {
        return implode(', ', [
            'coalesce(money.a_transfers, 0) as money_transfers',
            'coalesce(money.a_received, 0) as money_received',
            'coalesce(money.a_in_transit, 0) as money_in_transit',
            'coalesce(money.a_upcoming, 0) as money_upcoming',
            'coalesce(money.a_overdue, 0) as money_overdue',
            'coalesce(money.a_other_currency, 0) as money_other_currency',
            'money.a_last_receipt as money_last_receipt',
            'coalesce(money.a_received_in_year, 0) as money_received_in_year',
        ]);
    }

    /**
     * One totals line per account currency, for a query that joined
     * perAccount() as `money` and groups by accounts.currency.
     */
    public static function totalsSelect(): string
    {
        return implode(', ', [
            'accounts.currency as currency',
            'count(*) as accounts',
            'coalesce(sum(case when accounts.is_active = 1 then 1 else 0 end), 0) as open_accounts',
            'coalesce(sum(money.a_transfers), 0) as transfers',
            'coalesce(sum(money.a_received), 0) as received',
            'coalesce(sum(money.a_in_transit), 0) as in_transit',
            'coalesce(sum(money.a_upcoming), 0) as upcoming',
            'coalesce(sum(money.a_overdue), 0) as overdue',
            'coalesce(sum(money.a_received_in_year), 0) as received_in_year',
        ]);
    }
}
