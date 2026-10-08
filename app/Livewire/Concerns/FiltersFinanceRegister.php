<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\TransfersTracking;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * Year, figures mode, column sort and page size for a Funding register.
 * Every value arrives from the URL, so each is normalised on the way in.
 *
 * The using page defines `public const FLAGS` (the flags its filter chips
 * offer) and a `public string $flag` that holds the chosen one.
 *
 * @property string $flag
 */
trait FiltersFinanceRegister
{
    use SwitchesFundingView;

    #[Url(except: '')]
    public string $year = '';

    /** Whole-agreement figures with the year beside them, or every figure for the year only. */
    #[Url(except: 'lifetime')]
    public string $figures = 'lifetime';

    #[Url(except: '')]
    public string $sortBy = '';

    #[Url(except: '')]
    public string $sortDir = '';

    /** The chosen page size; empty while the page's default size applies. */
    #[Url(except: '')]
    public string $perPage = '';

    /**
     * What each column heading sorts by: its default direction and the SQL
     * order terms, each with a {dir} placeholder where the direction goes.
     *
     * @return array<string, array{dir: 'asc'|'desc', order: list<string>}>
     */
    abstract protected function registerSorts(): array;

    abstract protected function registerDefaultSort(): string;

    public function mountFiltersFinanceRegister(): void
    {
        $this->normaliseRegister();
    }

    public function updatedFiltersFinanceRegister(string $property): void
    {
        if (in_array($property, ['year', 'figures', 'sortBy', 'sortDir', 'perPage'], true)) {
            $this->normaliseRegister();
            $this->resetRegisterPage();
        }
    }

    /**
     * Apply the filters dialog's draft. The dialog holds its choices on the
     * client and sends them with this call, so each one has already passed
     * through the page's updated() hooks; all that is left is the first page.
     */
    public function applyFilters(): void
    {
        $this->resetRegisterPage();
    }

    /**
     * Sort by a heading, or reverse it when it already leads.
     */
    public function sortRegister(string $key): void
    {
        $sorts = $this->registerSorts();

        if (! array_key_exists($key, $sorts)) {
            return;
        }

        $this->sortDir = $this->activeSort() === $key
            ? ($this->activeSortDir() === 'asc' ? 'desc' : 'asc')
            : $sorts[$key]['dir'];
        $this->sortBy = $key;
        $this->resetRegisterPage();
    }

    /**
     * Narrow to one flag, or clear it by choosing it again.
     */
    public function toggleFlag(string $flag): void
    {
        $this->flag = $this->flag === $flag || ! in_array($flag, self::FLAGS, true) ? '' : $flag;
        $this->resetRegisterPage();
    }

    /**
     * Every year a tranche is scheduled or received in, plus this year and
     * the chosen one, newest first.
     *
     * @return list<int>
     */
    #[Computed]
    public function yearOptions(): array
    {
        $years = array_values(TransfersTracking::query()->toBase()
            ->selectRaw('year(scheduled_date) as y')->whereNotNull('scheduled_date')
            ->union(TransfersTracking::query()->toBase()->selectRaw('year(receive_date) as y')->whereNotNull('receive_date'))
            ->pluck('y')
            ->map(fn ($year): int => (int) $year)
            ->push(CarbonImmutable::today()->year)
            ->when($this->selectedYear() !== null, fn ($years) => $years->push($this->selectedYear()))
            ->unique()
            ->sortDesc()
            ->all());

        return $years;
    }

    protected function activeSort(): string
    {
        return array_key_exists($this->sortBy, $this->registerSorts()) ? $this->sortBy : $this->registerDefaultSort();
    }

    protected function activeSortDir(): string
    {
        return in_array($this->sortDir, ['asc', 'desc'], true) ? $this->sortDir : $this->registerSorts()[$this->activeSort()]['dir'];
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function applyRegisterSort(Builder $query): Builder
    {
        $direction = $this->activeSortDir();

        foreach ($this->registerSorts()[$this->activeSort()]['order'] as $term) {
            $query->orderByRaw(str_replace('{dir}', $direction, $term));
        }

        return $query;
    }

    protected function selectedYear(): ?int
    {
        return preg_match('/^(19|20|21)\d{2}$/', $this->year) === 1 ? (int) $this->year : null;
    }

    protected function yearOnly(): bool
    {
        return $this->selectedYear() !== null && $this->figures === 'year';
    }

    /**
     * The page sizes the pager offers, the default first: five rows keep a
     * register readable without scrolling.
     *
     * @return list<string>
     */
    protected function registerPageSizes(): array
    {
        return ['5', '10', '25'];
    }

    protected function registerPerPage(): int
    {
        return (int) ($this->perPage !== '' ? $this->perPage : $this->registerPageSizes()[0]);
    }

    /**
     * Clear the year and the figures mode. The view, the sort and the page
     * size are choices about reading, not filters, so they stay.
     */
    protected function resetRegisterFilters(): void
    {
        $this->year = '';
        $this->figures = 'lifetime';
    }

    protected function normaliseRegister(): void
    {
        $this->normaliseFundingView();

        if ($this->selectedYear() === null) {
            $this->year = '';
        }

        if ($this->figures !== 'year' || $this->year === '') {
            $this->figures = 'lifetime';
        }

        if (! array_key_exists($this->sortBy, $this->registerSorts())) {
            $this->sortBy = '';
        }

        if (! in_array($this->sortDir, ['asc', 'desc'], true)) {
            $this->sortDir = '';
        }

        $sizes = $this->registerPageSizes();

        if (! in_array($this->perPage, $sizes, true) || $this->perPage === $sizes[0]) {
            $this->perPage = '';
        }
    }

    private function resetRegisterPage(): void
    {
        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }
}
