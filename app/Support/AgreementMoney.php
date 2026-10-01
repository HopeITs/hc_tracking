<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\DonorProposal;
use App\Models\TransfersTracking;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The one definition of an agreement's money, in SQL, so a figure reads the
 * same in a row, a sort, a total and an export.
 *
 * Only tranches sent in the agreement's own currency are set against its
 * budget; a tranche in another currency is counted apart, never converted.
 */
final class AgreementMoney
{
    /** The part of the budget no tranche covers yet. Reads the joined alias `money`. */
    public const UNSCHEDULED_SQL = '(case when donor_proposal.budget > coalesce(money.m_scheduled, 0) then donor_proposal.budget - coalesce(money.m_scheduled, 0) else 0 end)';

    /** The part of the budget not yet received, counted in payment amounts so fees are not owed again. */
    public const OUTSTANDING_SQL = '(case when donor_proposal.budget > coalesce(money.m_received_gross, 0) then donor_proposal.budget - coalesce(money.m_received_gross, 0) else 0 end)';

    /**
     * The first and last day of a year, or null without one.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function yearBounds(?int $year): ?array
    {
        return $year === null ? null : [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)];
    }

    /**
     * One row per agreement with every tranche figure. In year-only mode every
     * figure counts only the tranches scheduled in that year.
     */
    public static function perAgreement(?int $year = null, bool $yearOnly = false): Builder
    {
        $t = 'transfers_tracking';
        $own = "{$t}.amount_transfered_currency = agreement.currency";
        $state = fn (string $name): string => TransfersTracking::stateSql($name);
        $sum = fn (string $condition, ?string $column = null): string => "coalesce(sum(case when {$condition} then ".($column ?? "{$t}.payment_amount").' else 0 end), 0)';
        $count = fn (string $condition): string => "coalesce(sum(case when {$condition} then 1 else 0 end), 0)";
        $landed = "({$own} and ".TransfersTracking::settledSql().')';
        $bounds = self::yearBounds($year);

        $inYear = $bounds === null ? null : "between '{$bounds[0]}' and '{$bounds[1]}'";

        return DB::table($t)
            ->join('donor_proposal as agreement', 'agreement.id', '=', "{$t}.donor_proposal_id")
            ->when($yearOnly && $bounds !== null, fn (Builder $query) => $query->whereBetween("{$t}.scheduled_date", $bounds))
            ->groupBy("{$t}.donor_proposal_id")
            ->selectRaw(implode(', ', [
                "{$t}.donor_proposal_id as donor_proposal_id",
                'count(*) as m_tranches',
                $sum($own).' as m_scheduled',
                $sum($landed, "{$t}.net_amount_received").' as m_received',
                $sum("{$own} and ".$state('received')).' as m_received_gross',
                $sum("{$own} and ".$state('transit')).' as m_in_transit',
                $sum("{$own} and ".$state('late')).' as m_overdue',
                $count("{$own} and ".$state('late')).' as m_overdue_count',
                $sum("{$own} and ".$state('upcoming')).' as m_upcoming',
                $sum("{$own} and ".$state('failed')).' as m_failed',
                $count(TransfersTracking::missingNetSql()).' as m_net_missing',
                $count("not ({$own})").' as m_other_currency',
                'min(case when '.$state('late').' or '.$state('upcoming')." then {$t}.scheduled_date end) as m_next_due",
                'max(case when '.$state('received')." then {$t}.receive_date end) as m_last_received",
                ($inYear === null ? '0' : $sum("{$own} and {$t}.scheduled_date {$inYear}")).' as m_due_in_year',
                ($inYear === null ? '0' : $sum("{$landed} and {$t}.receive_date {$inYear}", "{$t}.net_amount_received")).' as m_received_in_year',
            ]));
    }

    /**
     * The money columns withMoney() adds to an agreement, zero when it has no tranche.
     */
    public static function agreementColumns(): string
    {
        $zeroed = ['tranches', 'scheduled', 'received', 'in_transit', 'overdue', 'overdue_count', 'upcoming', 'failed', 'net_missing', 'other_currency', 'due_in_year', 'received_in_year'];

        return implode(', ', [
            ...array_map(fn (string $name): string => "coalesce(money.m_{$name}, 0) as money_{$name}", $zeroed),
            'money.m_next_due as money_next_due',
            'money.m_last_received as money_last_received',
            self::UNSCHEDULED_SQL.' as money_unscheduled',
            self::OUTSTANDING_SQL.' as money_outstanding',
        ]);
    }

    /**
     * Totals per currency over agreements joined to `money`; group by donor_proposal.currency.
     */
    public static function totalsSelect(): string
    {
        $sums = ['tranches', 'scheduled', 'received', 'in_transit', 'overdue', 'failed', 'due_in_year', 'received_in_year'];

        return implode(', ', [
            'donor_proposal.currency as currency',
            'count(*) as agreements',
            'coalesce(sum(donor_proposal.budget), 0) as budget',
            ...array_map(fn (string $name): string => "coalesce(sum(money.m_{$name}), 0) as {$name}", $sums),
            'coalesce(sum('.self::UNSCHEDULED_SQL.'), 0) as unscheduled',
            'coalesce(sum('.self::OUTSTANDING_SQL.'), 0) as outstanding',
        ]);
    }

    /**
     * One totals row, typed.
     *
     * @return array{currency: string, agreements: int, tranches: int, budget: float, scheduled: float, received: float, in_transit: float, overdue: float, failed: float, unscheduled: float, outstanding: float, due_in_year: float, received_in_year: float}
     */
    public static function line(object|array $row): array
    {
        $row = (array) $row;
        $float = fn (string $key): float => (float) ($row[$key] ?? 0);

        return [
            'currency' => (string) $row['currency'],
            'agreements' => (int) ($row['agreements'] ?? 0),
            'tranches' => (int) ($row['tranches'] ?? 0),
            'budget' => $float('budget'),
            'scheduled' => $float('scheduled'),
            'received' => $float('received'),
            'in_transit' => $float('in_transit'),
            'overdue' => $float('overdue'),
            'failed' => $float('failed'),
            'unscheduled' => $float('unscheduled'),
            'outstanding' => $float('outstanding'),
            'due_in_year' => $float('due_in_year'),
            'received_in_year' => $float('received_in_year'),
        ];
    }

    /**
     * The same totals summed in PHP over agreements already loaded, for the
     * detail pages. An agreement loaded without withMoney() counts its budget
     * and, having no tranche figures, nothing else.
     *
     * @param  iterable<DonorProposal>  $agreements
     * @return list<array<string, mixed>>
     */
    public static function totals(iterable $agreements): array
    {
        $lines = [];

        foreach ($agreements as $agreement) {
            $values = $agreement->getAttributes();
            $currency = $agreement->currency->value;
            $lines[$currency] ??= ['currency' => $currency, 'agreements' => 0, 'tranches' => 0, 'budget' => 0.0, 'scheduled' => 0.0, 'received' => 0.0, 'in_transit' => 0.0, 'overdue' => 0.0, 'failed' => 0.0, 'unscheduled' => 0.0, 'outstanding' => 0.0, 'due_in_year' => 0.0, 'received_in_year' => 0.0];
            $lines[$currency]['agreements']++;
            $lines[$currency]['budget'] += (float) $agreement->budget;
            $lines[$currency]['tranches'] += (int) ($values['money_tranches'] ?? 0);

            foreach (['scheduled', 'received', 'in_transit', 'overdue', 'failed', 'unscheduled', 'outstanding', 'due_in_year', 'received_in_year'] as $key) {
                $lines[$currency][$key] += (float) ($values['money_'.$key] ?? 0);
            }
        }

        ksort($lines);

        return array_values($lines);
    }

    /**
     * The money columns of an agreement table, in order.
     *
     * @return list<array{key: string, label: string, numeric: bool}>
     */
    public static function columns(bool $withTransfers = true, ?int $year = null, bool $yearOnly = false, bool $withFailed = false, bool $withReports = true): array
    {
        $yearOnly = $yearOnly && $year !== null;
        $column = fn (string $key, string $label, bool $numeric = true): array => ['key' => $key, 'label' => $label, 'numeric' => $numeric];

        $columns = [
            $column('currency', __('common.currency'), false),
            $column('budget', $yearOnly ? __('finance_tables.budget_whole_agreement') : __('finance_tables.budget')),
        ];

        if ($withTransfers) {
            $columns[] = $column('scheduled', __('finance_tables.scheduled'));
            $columns[] = $column('received', __('finance_tables.received'));
            $columns[] = $column('percent', __('finance_tables.percent_received'));

            if ($year !== null && ! $yearOnly) {
                $columns[] = $column('due_in_year', __('finance_tables.due_in_year', ['year' => $year]));
                $columns[] = $column('received_in_year', __('finance_tables.received_in_year', ['year' => $year]));
            }

            $columns[] = $column('in_transit', __('finance_tables.in_transit'));
            $columns[] = $column('overdue', __('finance_tables.overdue'));

            if ($withFailed) {
                $columns[] = $column('failed', __('finance_tables.failed'));
            }

            if (! $yearOnly) {
                $columns[] = $column('unscheduled', __('finance_tables.not_scheduled'));
                $columns[] = $column('outstanding', __('finance_tables.outstanding'));
            }

            $columns[] = $column('next_due', __('finance_tables.next_due'), false);
            $columns[] = $column('tranches', __('finance_tables.tranches'));
        }

        if ($withReports) {
            $columns[] = $column('reports', __('funding_agreements.donor_reports'), false);
        }

        return $columns;
    }

    /**
     * One row per donor or proposal and agreement currency. Without the
     * transfers permission no tranche is read: those figures are zero.
     */
    public static function perOwner(string $owner, ?int $year = null, bool $yearOnly = false, bool $withTransfers = true): Builder
    {
        if (! in_array($owner, ['donor_id', 'proposal_id'], true)) {
            throw new InvalidArgumentException("Unknown agreement owner [{$owner}].");
        }

        $base = [
            "donor_proposal.{$owner} as owner_id",
            'donor_proposal.currency as currency',
            'count(*) as o_agreements',
            'coalesce(sum(donor_proposal.budget), 0) as o_committed',
        ];

        $query = DB::table('donor_proposal')->groupBy("donor_proposal.{$owner}", 'donor_proposal.currency');

        if (! $withTransfers) {
            return $query->selectRaw(implode(', ', [...$base, '0 as o_tranches', '0 as o_received', '0 as o_outstanding', '0 as o_overdue', '0 as o_overdue_count', 'null as o_next_due', 'null as o_last_received', '0 as o_due_in_year', '0 as o_received_in_year']));
        }

        return $query
            ->leftJoinSub(self::perAgreement($year, $yearOnly), 'money', 'money.donor_proposal_id', '=', 'donor_proposal.id')
            ->selectRaw(implode(', ', [
                ...$base,
                'coalesce(sum(money.m_tranches), 0) as o_tranches',
                'coalesce(sum(money.m_received), 0) as o_received',
                'coalesce(sum('.self::OUTSTANDING_SQL.'), 0) as o_outstanding',
                'coalesce(sum(money.m_overdue), 0) as o_overdue',
                'coalesce(sum(money.m_overdue_count), 0) as o_overdue_count',
                'min(money.m_next_due) as o_next_due',
                'max(money.m_last_received) as o_last_received',
                'coalesce(sum(money.m_due_in_year), 0) as o_due_in_year',
                'coalesce(sum(money.m_received_in_year), 0) as o_received_in_year',
            ]));
    }

    /**
     * The perOwner() columns, joined as `money`, under their row names.
     */
    public static function ownerSelect(): string
    {
        $columns = ['agreements', 'committed', 'tranches', 'received', 'outstanding', 'overdue', 'overdue_count', 'next_due', 'last_received', 'due_in_year', 'received_in_year'];

        return implode(', ', ['money.currency as money_currency', ...array_map(fn (string $name): string => "money.o_{$name} as money_{$name}", $columns)]);
    }

    /**
     * Totals per currency over owners joined to perOwner() as `money`.
     */
    public static function ownerTotalsSelect(string $ownerKey): string
    {
        if (preg_match('/^[a-z_]+\.[a-z_]+$/', $ownerKey) !== 1) {
            throw new InvalidArgumentException("Invalid owner key [{$ownerKey}].");
        }

        return implode(', ', [
            'money.currency as currency',
            "count(distinct {$ownerKey}) as owners",
            ...array_map(fn (string $name): string => "coalesce(sum(money.o_{$name}), 0) as {$name}", ['agreements', 'committed', 'received', 'outstanding', 'overdue', 'due_in_year', 'received_in_year']),
        ]);
    }
}
