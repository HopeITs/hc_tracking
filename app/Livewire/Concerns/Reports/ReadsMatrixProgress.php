<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Reports;

use App\Enums\IndicatorMeasure;
use App\Enums\MatrixCellState;
use App\Models\MonthlyReport;
use App\Support\Reporting\ReportExtraction;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * The reading layer of the Monthly Matrix cards (2026-11-23 redesign): for
 * every row, how far it is against where it should be by now, which months
 * wait for validation and which were due and never sent; the four summary
 * figures; the quick filter they set; the search; and the cards the rows sit
 * in (by proposal or by center).
 *
 * Pace: a count is compared with its target spread evenly over its source
 * periods, up to the last validated month of the window; a percentage or an
 * average with its target itself. A row is behind below 95 % of that. The
 * figures are the matrix's own (validated only); nothing here re-reads the
 * database.
 *
 * @property-read array{months: list<CarbonImmutable>, rows: list<array<string, mixed>>, isDonorView: bool} $matrix
 * @property-read list<CarbonImmutable> $months
 * @property-read LengthAwarePaginator<int, array<string, mixed>> $pagedRows
 * @property-read string $resolvedView
 * @property-read string $resolvedGroup
 * @property-read array<string, string> $monthReports
 * @property-read array<string, array{kinds: array<string, string>, waiting: int, missing: int, expected: float|null, behind: bool, gap: float|null, reached: float|null, monthlyShare: float|null, lastWaiting: CarbonImmutable|null, lastMissing: CarbonImmutable|null, recent: list<array{month: CarbonImmutable, kind: string}>}> $rowProgress
 */
trait ReadsMatrixProgress
{
    /** The share of the expected figure under which a row is behind pace. */
    private const PACE_TOLERANCE = 0.95;

    /** @var list<string> */
    private const FOCUSES = ['pace', 'behind', 'waiting', 'missing'];

    /** A quick filter from the summary cards: pace, behind, waiting or missing. */
    #[Url(except: '')]
    public string $focus = '';

    /** The cards rows sit in: 'proposal' or 'center' (the by-indicator view always uses proposal). */
    #[Url(except: 'proposal')]
    public string $group = 'proposal';

    /** Words to find in a row's code, name, center, proposal or donor. */
    #[Url(except: '')]
    public string $search = '';

    /**
     * Presses a summary card; pressing it again clears it.
     */
    public function setFocus(string $focus): void
    {
        $this->focus = $this->focus === $focus || ! in_array($focus, self::FOCUSES, true) ? '' : $focus;
        $this->resetPage();
    }

    /**
     * Group by proposal or center (one row per center), or by indicator
     * (all centers added up, the old "grouped" view).
     */
    public function groupBy(string $group): void
    {
        [$this->view, $this->group] = match ($group) {
            'center' => ['rows', 'center'],
            'indicator' => ['grouped', 'proposal'],
            default => ['rows', 'proposal'],
        };
        $this->resetPage();
    }

    /**
     * Which grouping button is pressed.
     */
    #[Computed]
    public function resolvedGroup(): string
    {
        return match (true) {
            $this->resolvedView === 'grouped' => 'indicator',
            $this->group === 'center' => 'center',
            default => 'proposal',
        };
    }

    /**
     * The progress of every row, keyed by row key.
     *
     * @return array<string, array{kinds: array<string, string>, waiting: int, missing: int, expected: float|null, behind: bool, gap: float|null, reached: float|null, monthlyShare: float|null, lastWaiting: CarbonImmutable|null, lastMissing: CarbonImmutable|null, recent: list<array{month: CarbonImmutable, kind: string}>}>
     */
    #[Computed]
    public function rowProgress(): array
    {
        $progress = [];

        foreach ($this->matrix['rows'] as $row) {
            $progress[$row['key']] = $this->progressOf($row);
        }

        return $progress;
    }

    /**
     * The four summary figures, over every row the filters select.
     *
     * @return array{total: int, pace: int, behind: int, waiting: int, missing: int}
     */
    #[Computed]
    public function matrixSummary(): array
    {
        $progress = array_map(fn (array $row): array => $this->rowProgress[$row['key']], $this->searchedRows($this->matrix['rows']));
        $behind = count(array_filter($progress, fn (array $reading): bool => $reading['behind']));

        return [
            'total' => count($progress),
            'pace' => count($progress) - $behind,
            'behind' => $behind,
            'waiting' => array_sum(array_column($progress, 'waiting')),
            'missing' => array_sum(array_column($progress, 'missing')),
        ];
    }

    /**
     * This page's rows in their cards, in order of first appearance.
     *
     * @return list<array{key: string, title: string, sub: string|null, rows: list<array<string, mixed>>, behind: int, missing: int}>
     */
    #[Computed]
    public function matrixCards(): array
    {
        $cards = [];

        foreach ($this->pagedRows as $row) {
            [$key, $title, $sub] = $this->resolvedGroup === 'center' ? $this->centerCardOf($row) : $this->proposalCardOf($row);
            $progress = $this->rowProgress[$row['key']];

            $cards[$key] ??= ['key' => $key, 'title' => $title, 'sub' => $sub, 'rows' => [], 'behind' => 0, 'missing' => 0];
            $cards[$key]['rows'][] = $row;
            $cards[$key]['behind'] += $progress['behind'] ? 1 : 0;
            $cards[$key]['missing'] += $progress['missing'];
        }

        return array_values($cards);
    }

    /**
     * The monthly report of each place and month in the window, keyed
     * "placeKey|Y-m" → slug. One query whatever the number of rows.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function monthReports(): array
    {
        $months = $this->months;

        if ($months === []) {
            return [];
        }

        $reports = [];

        MonthlyReport::query()
            ->join('report_templates as rt', 'rt.id', '=', 'monthly_reports.report_template_id')
            ->whereNotNull('rt.city_id')
            ->whereNotNull('rt.program_id')
            ->whereBetween('monthly_reports.period', [$months[0]->toDateString(), $months[count($months) - 1]->toDateString()])
            ->orderBy('monthly_reports.id')
            ->toBase()
            ->get(['monthly_reports.slug', 'monthly_reports.period', 'rt.city_id', 'rt.program_id', 'rt.center_id', 'rt.city_wide'])
            ->each(function (object $report) use (&$reports): void {
                $key = ReportExtraction::placeKey((int) $report->city_id, (int) $report->program_id, $report->center_id === null ? null : (int) $report->center_id, (bool) $report->city_wide)
                    .'|'.substr((string) $report->period, 0, 7);
                $reports[$key] ??= (string) $report->slug;
            });

        return $reports;
    }

    /**
     * Where a month box leads: that month's report when the row is one place
     * and the report is known, else the monthly-reports list of that month.
     *
     * @param  array<string, mixed>  $row
     */
    protected function cellReportUrl(array $row, CarbonImmutable $month): ?string
    {
        $listUrl = $this->cellListUrl($row, $month);

        if ($listUrl === null || count($row['place_keys']) !== 1) {
            return $listUrl;
        }

        $slug = $this->monthReports[$row['place_keys'][0].'|'.$month->format('Y-m')] ?? null;

        return $slug === null || $slug === '' ? $listUrl : route('monthly-reports.show', $slug);
    }

    /**
     * The rows the search and the pressed summary card keep.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function focusedRows(array $rows): array
    {
        $rows = $this->searchedRows($rows);

        if (! in_array($this->focus, self::FOCUSES, true)) {
            return $rows;
        }

        return array_values(array_filter($rows, function (array $row): bool {
            $progress = $this->rowProgress[$row['key']];

            return match ($this->focus) {
                'pace' => ! $progress['behind'],
                'behind' => $progress['behind'],
                'waiting' => $progress['waiting'] > 0,
                default => $progress['missing'] > 0,
            };
        }));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function searchedRows(array $rows): array
    {
        $needle = mb_strtolower(trim($this->search));

        if ($needle === '') {
            return $rows;
        }

        return array_values(array_filter($rows, function (array $row) use ($needle): bool {
            $haystack = implode(' ', [
                $row['code'], $row['name_en'], $row['name_ar'], $row['center'] ?? '', $row['city'] ?? '',
                ...array_map(fn (array $source): string => ($source['proposal'] ?? '').' '.($source['donor'] ?? ''), $row['sources']),
            ]);

            return str_contains(mb_strtolower($haystack), $needle);
        }));
    }

    /**
     * One row's progress: each month's kind (done, waiting, missing, open,
     * upcoming, none), the counts, and the pace.
     *
     * @param  array<string, mixed>  $row
     * @return array{kinds: array<string, string>, waiting: int, missing: int, expected: float|null, behind: bool, gap: float|null, reached: float|null, monthlyShare: float|null, lastWaiting: CarbonImmutable|null, lastMissing: CarbonImmutable|null, recent: list<array{month: CarbonImmutable, kind: string}>}
     */
    protected function progressOf(array $row): array
    {
        $thisMonth = CarbonImmutable::today()->startOfMonth();
        $kinds = [];
        $lastDone = $lastWaiting = $lastMissing = null;
        $recent = [];

        foreach ($this->months as $month) {
            $state = $row['cells'][$month->format('Y-m')]['state'] ?? MatrixCellState::NotDue;
            $kind = match ($state) {
                MatrixCellState::Approved => 'done',
                MatrixCellState::Pending => 'waiting',
                MatrixCellState::NotSubmitted => $month->lessThan($thisMonth) ? 'missing' : 'open',
                MatrixCellState::Upcoming => 'upcoming',
                default => 'none',
            };
            $kinds[$month->format('Y-m')] = $kind;

            match ($kind) {
                'done' => $lastDone = $month,
                'waiting' => $lastWaiting = $month,
                'missing' => $lastMissing = $month,
                default => null,
            };

            if (in_array($kind, ['done', 'waiting', 'missing'], true)) {
                $recent[] = ['month' => $month, 'kind' => $kind];
            }
        }

        $target = $row['target'] === null ? null : (float) $row['target'];
        $total = $row['total'] === null ? null : (float) $row['total'];
        $isCount = $row['measure'] === IndicatorMeasure::Numeric;
        [$start, $end] = $this->spanOf($row);
        $spanMonths = (int) $start->diffInMonths($end) + 1;
        $expected = null;

        if ($target !== null && $target > 0.0 && $lastDone !== null) {
            $from = $start->greaterThan($this->months[0]) ? $start : $this->months[0];
            $elapsed = $lastDone->lessThan($from) ? 0 : (int) $from->diffInMonths($lastDone) + 1;
            $expected = $isCount ? $target * $elapsed / $spanMonths : $target;
        }

        $behind = $expected !== null && $expected > 0.0 && ($total ?? 0.0) < $expected * self::PACE_TOLERANCE;

        return [
            'kinds' => $kinds,
            'waiting' => count(array_keys($kinds, 'waiting', true)),
            'missing' => count(array_keys($kinds, 'missing', true)),
            'expected' => $expected,
            'behind' => $behind,
            'gap' => $expected === null ? null : max(0.0, $expected - ($total ?? 0.0)),
            'reached' => $target === null || $target <= 0.0 || $total === null ? null : $total / $target * 100,
            'monthlyShare' => $target === null || $target <= 0.0 ? null : ($isCount ? $target / $spanMonths : $target),
            'lastWaiting' => $lastWaiting,
            'lastMissing' => $lastMissing,
            'recent' => array_slice(array_reverse($recent), 0, 3),
        ];
    }

    /**
     * The months the row's sources run over (first start → last end), the
     * window where a source has no dates.
     *
     * @param  array<string, mixed>  $row
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function spanOf(array $row): array
    {
        $windows = $row['source_windows'] ?? [];
        $starts = array_filter(array_column($windows, 'start'));
        $ends = array_filter(array_column($windows, 'end'));
        $months = $this->months;
        $first = $months[0] ?? CarbonImmutable::today()->startOfMonth();
        $last = $months === [] ? $first : $months[count($months) - 1];

        $start = $starts === [] ? $first : CarbonImmutable::parse(min($starts))->startOfMonth();
        $end = $ends === [] ? $last : CarbonImmutable::parse(max($ends))->startOfMonth();

        return $end->lessThan($start) ? [$start, $start] : [$start, $end];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: string, 1: string, 2: string|null}
     */
    private function proposalCardOf(array $row): array
    {
        /** @var list<array{proposal: string|null, donor: string|null}> $sources */
        $sources = $row['sources'];
        $names = array_values(array_unique(array_map(fn (array $source): string => $source['proposal'] ?? __('reports.matrix.annual_plan'), $sources)));
        sort($names);
        $donors = array_values(array_unique(array_filter(array_column($sources, 'donor'))));

        return ['proposal:'.implode('|', $names), implode(' · ', $names), $donors === [] ? null : implode(' · ', $donors)];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: string, 1: string, 2: string|null}
     */
    private function centerCardOf(array $row): array
    {
        return ['center:'.($row['place_key'] ?? $row['center']), (string) $row['center'], $row['city']];
    }
}
