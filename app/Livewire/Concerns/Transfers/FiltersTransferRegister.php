<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Transfers;

use App\Enums\Currency;
use App\Models\Account;
use App\Models\Donor;
use App\Models\DonorProposal;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Attributes\Computed;

/**
 * The filters of the transfers register: normalising what the URL hands in,
 * the year-as-date-range shortcut, the pickers' options, the stage pills and
 * the two sort orders.
 *
 * Used by transfers/index only. The page provides the filter properties
 * (search, donor, account, currency, stage, flag, dateBasis, from, to, min,
 * max, group, sort, agreement) and the SORTS and STAGES constants. It reads
 * FiltersFinanceRegister (year, sortBy, sortDir, activeSort(),
 * resetRegisterFilters()), WithPagination (resetPage()) and
 * ManagesTransferForm (MAX_AMOUNT).
 *
 * @property string $search
 * @property string $donor
 * @property string $account
 * @property string $currency
 * @property string $stage
 * @property string $flag
 * @property string $dateBasis
 * @property string $from
 * @property string $to
 * @property string $min
 * @property string $max
 * @property string $group
 * @property string $sort
 * @property string $agreement
 * @property-read DonorProposal|null $agreementFilter  #[Computed] agreementFilter()
 */
trait FiltersTransferRegister
{
    protected function normaliseFilters(): void
    {
        if (! in_array($this->sort, self::SORTS, true)) {
            $this->sort = 'attention';
        }

        if (! in_array($this->stage, self::STAGES, true)) {
            $this->stage = '';
        }

        if (! in_array($this->flag, self::FLAGS, true)) {
            $this->flag = '';
        }

        if (! in_array($this->group, self::GROUPS, true)) {
            $this->group = '';
        }

        if (! in_array($this->dateBasis, self::DATE_BASES, true)) {
            $this->dateBasis = 'scheduled';
        }

        if (Currency::tryFrom($this->currency) === null) {
            $this->currency = '';
        }

        foreach (['donor', 'account', 'agreement'] as $idFilter) {
            if (! ctype_digit($this->{$idFilter})) {
                $this->{$idFilter} = '';
            }
        }

        $this->from = $this->cleanDate($this->from);
        $this->to = $this->cleanDate($this->to);
        $this->min = $this->cleanAmount($this->min);
        $this->max = $this->cleanAmount($this->max);
    }

    /**
     * On this page a year is a shortcut for a date range, not a filter of its
     * own: choosing one fills the range, and a range that is no longer that
     * whole year lets go of it.
     */
    protected function syncRangeWithYear(): void
    {
        $bounds = $this->yearBounds($this->year);

        if ($bounds === null) {
            $this->year = '';

            return;
        }

        if ($this->from === '' && $this->to === '') {
            [$this->from, $this->to] = $bounds;

            return;
        }

        if ([$this->from, $this->to] !== $bounds) {
            $this->year = '';
        }
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    protected function yearBounds(string $year): ?array
    {
        return preg_match('/^(19|20|21)\d{2}$/', $year) === 1 ? ["{$year}-01-01", "{$year}-12-31"] : null;
    }

    /**
     * A real calendar date in Y-m-d, or nothing.
     */
    protected function cleanDate(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
    }

    /**
     * An amount the payment column can hold, or nothing.
     */
    protected function cleanAmount(string $value): string
    {
        return is_numeric($value) && (float) $value >= 0 && (float) $value <= self::MAX_AMOUNT ? $value : '';
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'donor', 'account', 'currency', 'stage', 'flag', 'dateBasis', 'from', 'to', 'min', 'max', 'agreement']);
        $this->resetRegisterFilters();
        $this->resetPage();
    }

    /**
     * The agreement the page is narrowed to, for the filter chip.
     */
    #[Computed]
    public function agreementFilter(): ?DonorProposal
    {
        $agreementId = (int) $this->agreement;

        return $agreementId > 0
            ? DonorProposal::query()->with(['donor:id,name', 'proposal:id,name'])->find($agreementId, ['id', 'donor_id', 'proposal_id', 'external_project_number'])
            : null;
    }

    /**
     * The donors that hold an agreement, for the donor picker.
     *
     * @return EloquentCollection<int, Donor>
     */
    #[Computed]
    public function filterDonors(): EloquentCollection
    {
        return Donor::query()->has('donorProposals')->orderBy('name')->orderBy('id')->get(['id', 'name']);
    }

    /**
     * Every account, open or closed, for the account picker. The rows already
     * name the account each tranche lands in, so the list shows nothing new.
     *
     * @return EloquentCollection<int, Account>
     */
    #[Computed]
    public function filterAccounts(): EloquentCollection
    {
        return Account::query()->orderBy('account_name')->orderBy('id')->get(['id', 'account_name', 'is_active']);
    }

    /**
     * Narrow to one stage, or clear it by choosing it again.
     */
    public function toggleStage(string $stage): void
    {
        $this->stage = $this->stage === $stage || ! in_array($stage, self::STAGES, true) ? '' : $stage;
        $this->resetPage();
    }

    /**
     * The Overview ladder's order.
     */
    public function setSort(string $sort): void
    {
        $this->sort = in_array($sort, self::SORTS, true) ? $sort : 'attention';
        $this->resetPage();
    }

    /**
     * Rank the table by what needs chasing, or go back to its default order.
     */
    public function toggleAttentionOrder(): void
    {
        [$this->sortBy, $this->sortDir] = $this->activeSort() === 'attention' ? ['', ''] : ['attention', 'asc'];
        $this->resetPage();
    }
}
