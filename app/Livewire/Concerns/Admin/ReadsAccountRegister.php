<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Admin;

use App\Enums\ArStatus;
use App\Models\Account;
use App\Models\TransfersTracking;
use App\Support\AccountMoney;
use App\Support\AgreementMoney;
use App\Support\Like;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * What the accounts register reads from the database: the filtered account
 * query, the Overview view's cards and figures, the table's joined money query
 * with its sort orders, the visible page, the totals and the portfolio
 * figures.
 *
 * Used by admin/accounts only. The page provides the filter properties. It
 * reads FiltersFinanceRegister (selectedYear(), yearOnly(),
 * applyRegisterSort(), registerPerPage()), which in turn calls
 * registerSorts() and registerDefaultSort() from here. exportCsv() is in
 * ExportsAccountRegister.
 *
 * @property string $search
 * @property string $state
 * @property string $currencyFilter
 * @property string $countryFilter
 * @property-read bool $showsFullAccountNumbers  #[Computed] showsFullAccountNumbers()
 * @property-read \Illuminate\Pagination\LengthAwarePaginator<int, Account> $accounts  #[Computed] accounts()
 */
trait ReadsAccountRegister
{
    /**
     * The countries accounts are held in, for the country picker.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function countryOptions(): array
    {
        return Account::query()->distinct()->orderBy('country')->pluck('country')->all();
    }

    /**
     * Only people who may change accounts see full account numbers and can
     * find an account by its number; everyone else sees the last four
     * characters, so a search cannot confirm a guessed number either.
     */
    #[Computed]
    public function showsFullAccountNumbers(): bool
    {
        return auth()->user()->can('accounts.update');
    }

    /**
     * Accounts matching every active filter, with no ordering, so the table,
     * its totals, the Overview cards and the export all build on it. Flags and
     * the year are conditions on the account's tranches rather than a join, so
     * the Overview view needs nothing extra.
     *
     * @return Builder<Account>
     */
    protected function filteredQuery(): Builder
    {
        $search = trim($this->search);

        return Account::query()
            ->when(
                $search !== '',
                fn (Builder $query) => $query->where(fn (Builder $query) => $query
                    ->where('accounts.account_name', 'like', Like::contains($search))
                    ->orWhere('accounts.bank_name', 'like', Like::contains($search))
                    ->orWhere('accounts.account_number_last4', Account::lastFour($search))
                    ->when($this->showsFullAccountNumbers, fn (Builder $query) => $query->orWhereIn('accounts.id', Account::idsMatchingAccountNumber($search)))),
            )
            ->when($this->state === 'active', fn (Builder $query) => $query->where('accounts.is_active', true))
            ->when($this->state === 'closed', fn (Builder $query) => $query->where('accounts.is_active', false))
            ->when($this->currencyFilter !== '', fn (Builder $query) => $query->where('accounts.currency', $this->currencyFilter))
            ->when($this->countryFilter !== '', fn (Builder $query) => $query->where('accounts.country', $this->countryFilter))
            ->when($this->flag === 'has_transfers', fn (Builder $query) => $query->has('transfers'))
            ->when($this->flag === 'no_transfers', fn (Builder $query) => $query->doesntHave('transfers'))
            ->when($this->flag === 'in_transit', fn (Builder $query) => $query->whereHas('transfers', fn (Builder $query) => $query->inState('transit')))
            ->when($this->selectedYear() !== null, fn (Builder $query) => $this->applyYear($query));
    }

    /**
     * Keep only accounts with money in the chosen year: a tranche due in it
     * or, while the lifetime figures are shown, one that landed in it.
     *
     * @param  Builder<Account>  $query
     * @return Builder<Account>
     */
    protected function applyYear(Builder $query): Builder
    {
        $bounds = AgreementMoney::yearBounds($this->selectedYear());
        $yearOnly = $this->yearOnly();

        return $query->whereHas('transfers', fn (Builder $query) => $query->where(fn (Builder $query) => $query
            ->whereBetween('scheduled_date', $bounds)
            ->when(! $yearOnly, fn (Builder $query) => $query->orWhere(fn (Builder $query) => $query
                ->where('ar_status', ArStatus::Received)
                ->whereBetween('receive_date', $bounds)))));
    }

    /**
     * The Overview view's cards, open accounts first.
     *
     * @return LengthAwarePaginator<int, Account>
     */
    #[Computed]
    public function accounts(): LengthAwarePaginator
    {
        return $this->filteredQuery()
            ->select(['id', 'account_name', 'bank_name', 'currency', 'account_number', 'country', 'opened_at', 'closed_at', 'is_active', 'updated_at'])
            ->withCount('transfers')
            ->withMax('transfers as last_transfer_at', 'transfer_date')
            ->orderByDesc('is_active')
            ->orderBy('account_name')
            ->orderBy('id')
            ->paginate(15);
    }

    /**
     * The filtered accounts joined to their money, which is one grouped join
     * rather than a loaded relation: that is what lets a money column be
     * sorted, totalled and exported.
     *
     * @return Builder<Account>
     */
    protected function registerQuery(): Builder
    {
        return $this->filteredQuery()
            ->leftJoinSub(AccountMoney::perAccount($this->selectedYear(), $this->yearOnly()), 'money', 'money.account_id', '=', 'accounts.id')
            ->select(['accounts.id', 'accounts.account_name', 'accounts.bank_name', 'accounts.currency', 'accounts.account_number', 'accounts.country', 'accounts.opened_at', 'accounts.closed_at', 'accounts.is_active'])
            ->selectRaw(AccountMoney::select());
    }

    /**
     * What each column heading sorts by. Amounts sort inside their currency.
     *
     * @return array<string, array{dir: 'asc'|'desc', order: list<string>}>
     */
    protected function registerSorts(): array
    {
        $byName = 'accounts.account_name asc';
        $lastById = 'accounts.id asc';
        $text = fn (string $column): array => ['dir' => 'asc', 'order' => ["accounts.{$column} {dir}", $byName, $lastById]];
        $date = fn (string $expression): array => ['dir' => 'desc', 'order' => ["{$expression} is null", "{$expression} {dir}", $byName, $lastById]];
        $money = fn (string $column): array => ['dir' => 'desc', 'order' => ['accounts.currency asc', "coalesce(money.{$column}, 0) {dir}", $byName, $lastById]];

        return [
            'account' => ['dir' => 'asc', 'order' => ['accounts.account_name {dir}', $lastById]],
            'bank' => $text('bank_name'),
            'country' => $text('country'),
            'currency' => $text('currency'),
            // Open accounts first.
            'state' => ['dir' => 'desc', 'order' => ['accounts.is_active {dir}', $byName, $lastById]],
            'opened' => $date('accounts.opened_at'),
            'closed' => $date('accounts.closed_at'),
            'transfers' => ['dir' => 'desc', 'order' => ['coalesce(money.a_transfers, 0) {dir}', $byName, $lastById]],
            'received' => $money('a_received'),
            'received_in_year' => $money('a_received_in_year'),
            'in_transit' => $money('a_in_transit'),
            'upcoming' => $money('a_upcoming'),
            'overdue' => $money('a_overdue'),
            'last_receipt' => $date('money.a_last_receipt'),
        ];
    }

    protected function registerDefaultSort(): string
    {
        return 'account';
    }

    /**
     * The table's visible page.
     *
     * @return LengthAwarePaginator<int, Account>
     */
    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        return $this->applyRegisterSort($this->registerQuery())->paginate($this->registerPerPage());
    }

    /**
     * The table's totals, one line per account currency, for every account
     * the filters match.
     *
     * @return Collection<int, array{currency: string, accounts: int, open_accounts: int, transfers: int, received: float, in_transit: float, upcoming: float, overdue: float, received_in_year: float}>
     */
    #[Computed]
    public function totals(): Collection
    {
        return $this->filteredQuery()
            ->leftJoinSub(AccountMoney::perAccount($this->selectedYear(), $this->yearOnly()), 'money', 'money.account_id', '=', 'accounts.id')
            ->toBase()
            ->selectRaw(AccountMoney::totalsSelect())
            ->groupBy('accounts.currency')
            ->orderBy('accounts.currency')
            ->get()
            ->map(fn (object $row): array => [
                'currency' => (string) $row->currency,
                'accounts' => (int) $row->accounts,
                'open_accounts' => (int) $row->open_accounts,
                'transfers' => (int) $row->transfers,
                'received' => (float) $row->received,
                'in_transit' => (float) $row->in_transit,
                'upcoming' => (float) $row->upcoming,
                'overdue' => (float) $row->overdue,
                'received_in_year' => (float) $row->received_in_year,
            ]);
    }

    /**
     * Whether any account exists at all, so an empty page can tell "nothing
     * opened yet" from "nothing matches".
     */
    #[Computed]
    public function hasAnyAccount(): bool
    {
        return Account::query()->exists();
    }

    /**
     * Net funds actually received into each visible account, split by
     * currency (currencies are never combined — see x-ui.money). Keyed by
     * account id, each value a currency-code => total map sorted largest
     * first. A second query against the page's own ids, same pattern
     * cities/⚡index.blade.php uses for its cross-connection project stats.
     *
     * @return Collection<array-key, Collection<array-key, float>>
     */
    #[Computed]
    public function accountMoneyStats(): Collection
    {
        $accountIds = $this->accounts->pluck('id');

        if ($accountIds->isEmpty()) {
            return collect();
        }

        return TransfersTracking::query()
            ->whereIn('account_id', $accountIds)
            ->where('ar_status', ArStatus::Received)
            ->whereNotNull('net_amount_received')
            ->select(['account_id', 'net_amount_received', 'amount_received_currency'])
            ->get()
            ->groupBy('account_id')
            ->map(fn (Collection $transfers): Collection => $transfers
                ->groupBy(fn (TransfersTracking $transfer): string => $transfer->amount_received_currency->value)
                ->map(fn (Collection $group): float => (float) $group->sum(fn (TransfersTracking $transfer): float => (float) $transfer->net_amount_received))
                ->sortDesc());
    }

    /**
     * Portfolio-wide figures for the summary strip — independent of the
     * current search, filter or page, matching cities/⚡index.blade.php.
     *
     * @return array{total: int, active: int, closed: int, transfers: int}
     */
    #[Computed]
    public function summary(): array
    {
        return [
            'total' => Account::count(),
            'active' => Account::query()->active()->count(),
            'closed' => Account::where('is_active', false)->count(),
            'transfers' => TransfersTracking::count(),
        ];
    }

    /**
     * Net funds received across every account, by currency — never combined
     * into one figure since USD/EUR/SYP are not convertible in this system.
     *
     * @return Collection<string, float>
     */
    #[Computed]
    public function fundsByCurrency(): Collection
    {
        return TransfersTracking::query()
            ->where('ar_status', ArStatus::Received)
            ->whereNotNull('net_amount_received')
            ->select(['net_amount_received', 'amount_received_currency'])
            ->get()
            ->groupBy(fn (TransfersTracking $transfer): string => $transfer->amount_received_currency->value)
            ->map(fn (Collection $group): float => (float) $group->sum(fn (TransfersTracking $transfer): float => (float) $transfer->net_amount_received))
            ->sortDesc();
    }
}
