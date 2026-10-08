<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\AnnualPlans;

use App\Enums\Locale;
use App\Models\AnnualPlan;
use App\Models\HrEmployee;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

/**
 * The annual-plan matrix's filters and its fiscal-year columns: which years
 * are columns, which year the cockpit reads, and which managers the filter
 * can offer. BuildsPlanMatrix reads the columns this trait resolves.
 *
 * Used by annual-plans/index only. The page uses WithPagination (resetPage)
 * and provides the four #[Url] filter properties.
 *
 * @property array<int, int> $years
 * @property string $manager
 * @property string $programSearch
 * @property string $focusYear
 */
trait FiltersPlanMatrix
{
    /**
     * The widest grid the matrix will render.
     *
     * Columns are fiscal years, so an unbounded range would grow the row width
     * without limit as history accumulates. Ten keeps a full row readable on a
     * desktop screen; a wider span is clamped and the toolbar says so.
     */
    private const MAX_YEAR_COLUMNS = 10;

    public function updatedManager(): void
    {
        $this->resetPage();
    }

    /**
     * Add a fiscal year to the visible columns, or take it away again.
     *
     * Kept sorted so the grid's column order never depends on the order a
     * reader happened to tick the boxes in.
     */
    public function toggleYear(int $year): void
    {
        $years = in_array($year, $this->years, true)
            ? array_values(array_diff($this->years, [$year]))
            : [...$this->years, $year];

        sort($years);

        $this->years = $years;
        $this->resetPage();
    }

    public function clearYears(): void
    {
        $this->years = [];
        $this->resetPage();
    }

    public function updatedProgramSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Point the cockpit at one fiscal year without moving the grid.
     *
     * The year column and the cockpit read the same figure, so focusing a year
     * is a read, not a filter: the rest of the span stays on screen to compare
     * against.
     */
    public function focusOnYear(int $year): void
    {
        $this->focusYear = (string) $year;
    }

    public function clearFilters(): void
    {
        $this->years = [];
        $this->manager = '';
        $this->programSearch = '';
        $this->focusYear = '';
        $this->resetPage();
    }

    /**
     * Every fiscal year an annual plan has been written for, ascending.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function yearOptions(): array
    {
        return AnnualPlan::query()
            ->select('year')
            ->distinct()
            ->orderBy('year')
            ->pluck('year')
            ->map(fn ($year): int => (int) $year)
            ->all();
    }

    /**
     * The columns the grid would show if there were no cap.
     *
     * Split out so the toolbar can say whether the cap actually cut anything
     * without recomputing the rule that produced the columns.
     *
     * @return array<int, int>
     */
    private function candidateYearColumns(): array
    {
        if ($this->years === []) {
            $available = $this->yearOptions;

            return $available === []
                ? []
                : range($available[0], $available[count($available) - 1]);
        }

        $selected = array_values(array_unique(array_map('intval', $this->years)));
        sort($selected);

        return $selected;
    }

    /**
     * The fiscal years rendered as columns, gaps included.
     *
     * With no selection the grid spans every year from the earliest plan to the
     * latest, gaps included: a year nobody planned still earns a column,
     * because an empty column is the point of a coverage matrix.
     *
     * A selection shows exactly the years chosen and does not fill the holes
     * between them — a reader who asked for 2023 and 2027 is comparing two
     * years, not planning five.
     *
     * Either way the list is capped at MAX_YEAR_COLUMNS, keeping the most
     * recent years when it has to cut.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function yearColumns(): array
    {
        return array_slice($this->candidateYearColumns(), -self::MAX_YEAR_COLUMNS);
    }

    /**
     * How the visible columns should be described in the toolbar.
     *
     * A contiguous run reads as a span; anything else has to be listed, because
     * "2023–2027" would claim three columns that are not on screen.
     *
     * @return array{from: int, to: int, contiguous: bool, clamped: bool, list: string}|null
     */
    #[Computed]
    public function yearRange(): ?array
    {
        $columns = $this->yearColumns;

        if ($columns === []) {
            return null;
        }

        $from = $columns[0];
        $to = $columns[count($columns) - 1];
        $requested = count($this->candidateYearColumns());

        return [
            'from' => $from,
            'to' => $to,
            'contiguous' => ($to - $from + 1) === count($columns),
            'clamped' => $requested > self::MAX_YEAR_COLUMNS,
            'list' => implode(', ', $columns),
        ];
    }

    /**
     * The fiscal year the cockpit reads.
     *
     * A focus year outside the visible span is ignored rather than corrected,
     * so narrowing the range never leaves the cockpit reporting on a column
     * nobody can see. Without a usable choice it lands on this calendar year,
     * or on the most recent column when this year is not in the span.
     */
    #[Computed]
    public function activeYear(): ?int
    {
        $columns = $this->yearColumns;

        if ($columns === []) {
            return null;
        }

        if ($this->focusYear !== '' && in_array((int) $this->focusYear, $columns, true)) {
            return (int) $this->focusYear;
        }

        $thisYear = (int) now()->year;

        return in_array($thisYear, $columns, true) ? $thisYear : $columns[count($columns) - 1];
    }

    /**
     * The managers accountable for at least one annual plan.
     *
     * Users without a plan are left out, so the filter cannot be set to a value
     * that empties the grid.
     *
     * @return Collection<int, HrEmployee>
     */
    #[Computed]
    public function managerOptions(): Collection
    {
        // Employees live in the HR database, so "has a plan" is resolved to
        // ids first instead of a whereHas() across connections.
        return HrEmployee::query()
            ->select(HrEmployee::NameColumns)
            ->whereIn('id', AnnualPlan::query()->distinct()->pluck('manager_id'))
            ->orderBy(Locale::current() === Locale::Arabic ? 'first_name_ar' : 'first_name_en')
            ->orderBy(Locale::current() === Locale::Arabic ? 'last_name_ar' : 'last_name_en')
            ->orderBy('id')
            ->get();
    }
}
