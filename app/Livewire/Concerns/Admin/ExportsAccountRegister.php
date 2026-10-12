<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Admin;

use App\Models\Account;
use App\Support\CsvExport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\LazyCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The CSV export of the accounts register.
 *
 * Used by admin/accounts only. It reads registerQuery() and
 * showsFullAccountNumbers from ReadsAccountRegister, and applyRegisterSort()
 * and selectedYear() from FiltersFinanceRegister.
 *
 * @property-read bool $showsFullAccountNumbers  #[Computed] showsFullAccountNumbers() in ReadsAccountRegister
 */
trait ExportsAccountRegister
{
    /**
     * Every account the filters match, in the table's order, as a CSV file.
     * The account number follows the same rule as the screen: whole for the
     * people who may change accounts, masked for everyone else.
     */
    public function exportCsv(): StreamedResponse
    {
        Gate::authorize('accounts.view');

        $year = $this->selectedYear();
        $showsFullNumbers = $this->showsFullAccountNumbers;
        /** @var LazyCollection<int, Account&object{money_transfers: int, money_received: string, money_in_transit: string, money_upcoming: string, money_overdue: string, money_last_receipt: ?string, money_received_in_year: string}> $accounts */
        $accounts = $this->applyRegisterSort($this->registerQuery())->cursor();

        $headings = [
            __('accounts.account_name'),
            __('accounts.bank_name'),
            __('donors.country'),
            __('common.currency'),
            __('accounts.account_number'),
            __('common.state'),
            __('accounts.opened_on'),
            __('finance_tables.closed_on'),
            __('common.transfers'),
            __('finance_tables.received'),
            __('finance_tables.in_transit'),
            __('finance_tables.upcoming'),
            __('finance_tables.overdue'),
            __('finance_tables.last_receipt'),
            ...($year === null ? [] : [__('finance_tables.received_in_year', ['year' => $year])]),
        ];

        $rows = (function () use ($accounts, $year, $showsFullNumbers) {
            foreach ($accounts as $account) {
                yield [
                    $account->account_name,
                    $account->bank_name,
                    $account->country,
                    $account->currency->value,
                    $showsFullNumbers ? $account->account_number : $account->maskedAccountNumber(),
                    $account->is_active ? __('accounts.open') : __('accounts.closed'),
                    $account->opened_at,
                    $account->closed_at,
                    (int) $account->money_transfers,
                    CsvExport::amount($account->money_received),
                    CsvExport::amount($account->money_in_transit),
                    CsvExport::amount($account->money_upcoming),
                    CsvExport::amount($account->money_overdue),
                    $account->money_last_receipt,
                    ...($year === null ? [] : [CsvExport::amount($account->money_received_in_year)]),
                ];
            }
        })();

        return CsvExport::download('accounts-'.CarbonImmutable::today()->toDateString().'.csv', $headings, $rows);
    }
}
