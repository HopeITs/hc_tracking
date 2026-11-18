<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Reports;

use App\Enums\ActivityStatus;
use App\Enums\MatrixCellState;
use App\Models\HrCity;
use App\Support\CsvExport;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The CSV export of the Monthly Matrix.
 *
 * Used by reports/monthly-matrix only. It reads the same `matrix` (or
 * `activityBoard`) as the screen, so the file holds every row the filters
 * select, with the breakdown rows under their indicator, and no money. A
 * header block first states every active filter in words and the window
 * (Y-05); the file is in the page's language only, with one indicator name
 * column in that language.
 */
trait ExportsMonthlyMatrix
{
    /**
     * Ten exports a minute per user: a refusal shows a toast and writes
     * nothing.
     */
    public function exportCsv(): ?StreamedResponse
    {
        Gate::authorize('reports.view');

        if (! RateLimiter::attempt('monthly-matrix-export:'.Auth::id(), 10, fn (): bool => true, 60)) {
            Flux::toast(variant: 'danger', text: __('reports.matrix.export_throttled'));

            return null;
        }

        if ($this->resolvedTab === 'activities') {
            return $this->exportActivities();
        }

        $matrix = $this->matrix;
        $months = $this->months;
        $monthFormat = __('formats.month_year');

        $headings = [
            __('reports.matrix.code'),
            __('reports.matrix.indicator'),
            __('reports.matrix.center'),
            __('reports.matrix.city'),
            __('reports.matrix.source'),
            __('reports.matrix.measure'),
            __('reports.matrix.breakdown'),
            __('reports.matrix.target'),
            ...array_map(fn (CarbonImmutable $month): string => $month->translatedFormat($monthFormat), $months),
            __('reports.matrix.total'),
            __('reports.matrix.achieved'),
        ];

        $rows = (function () use ($matrix, $months) {
            foreach ($matrix['rows'] as $row) {
                yield [
                    $row['code'],
                    $this->exportIndicatorName($row),
                    $row['is_group'] ? __('reports.matrix.centers_count', ['count' => $row['centers_count']]) : $row['center'],
                    $row['city'],
                    collect($row['sources'])
                        ->map(fn (array $source): string => trim(($source['proposal'] ?? '').' — '.($source['donor'] ?? ''), ' —'))
                        ->implode('; '),
                    $row['measure']->label(),
                    '',
                    $this->matrixNumber($row['target']),
                    ...array_map(fn (CarbonImmutable $month): string => $this->csvCell($row['cells'][$month->format('Y-m')]), $months),
                    $this->matrixNumber($row['total']),
                    $this->matrixNumber($row['achieved']),
                ];

                yield from $this->breakdownCsvRows($row, $months);
            }
        })();

        return CsvExport::download('monthly-matrix-'.CarbonImmutable::today()->toDateString().'.csv', $headings, $rows, $this->exportHeaderBlock());
    }

    /**
     * The rows above the heading row: one [label, value in words] row per
     * active filter, the window as "from – to", then an empty row.
     *
     * @return list<list<string>>
     */
    protected function exportHeaderBlock(): array
    {
        $filter = $this->extractionFilter;
        $isArabic = app()->getLocale() === 'ar';
        $lines = [];

        if ($filter->proposalId !== null) {
            $lines[] = [__('reports.matrix.proposal'), $this->exportOptionLabel($this->proposalOptions, (string) $filter->proposalId)];
        }

        if ($filter->donorId !== null) {
            $lines[] = [__('reports.matrix.donor'), $this->exportOptionLabel($this->donorOptions, (string) $filter->donorId)];
        }

        if ($filter->cityId !== null) {
            $lines[] = [__('reports.matrix.city'), $this->exportOptionLabel($this->cityOptions, (string) $filter->cityId)];
        }

        if ($filter->programId !== null) {
            $lines[] = [__('reports.matrix.program'), $this->exportOptionLabel($this->programOptions, (string) $filter->programId)];
        }

        if ($filter->centerId !== null || $filter->cityWide || $filter->centerUnset) {
            $lines[] = [__('reports.matrix.center'), $this->exportOptionLabel($this->centerOptions, $this->center)];
        }

        if ($filter->indicatorId !== null) {
            $indicator = collect($this->indicatorOptions)->firstWhere('id', $filter->indicatorId);
            $name = $isArabic ? ($indicator->name_ar ?? null) : ($indicator->name_en ?? $indicator->name_ar ?? null);
            $lines[] = [__('reports.matrix.indicator'), $indicator === null ? (string) $filter->indicatorId : trim($indicator->code.' · '.$name, ' ·')];
        }

        if ($filter->pillarId !== null) {
            $pillar = collect($this->pillarOptions)->firstWhere('id', $filter->pillarId);
            $lines[] = [__('reports.matrix.pillar'), $pillar === null ? (string) $filter->pillarId : (string) ($isArabic ? $pillar->name_ar : ($pillar->name_en ?? $pillar->name_ar))];
        }

        if ($filter->mine) {
            $lines[] = [__('reports.matrix.my_projects'), __('reports.matrix.export.mine_on')];
        }

        $months = $this->months;
        $first = $months[0] ?? $filter->from;
        $last = $months === [] ? $filter->to : $months[array_key_last($months)];
        $monthFormat = __('formats.month_year');
        $lines[] = [__('reports.matrix.window'), __('reports.matrix.export.window_value', [
            'from' => $first?->translatedFormat($monthFormat) ?? '',
            'to' => $last?->translatedFormat($monthFormat) ?? '',
        ])];
        $lines[] = [''];

        return $lines;
    }

    /**
     * The words of a picked option: an option is either an `{value, label}`
     * array (centers) or a model with `id` and `name`; the id itself when the
     * option is no longer listed.
     *
     * @param  iterable<int, array{value: string, label: string}|\stdClass|HrCity>  $options
     */
    protected function exportOptionLabel(iterable $options, string $value): string
    {
        foreach ($options as $option) {
            if (is_array($option)) {
                if ((string) $option['value'] === $value) {
                    return (string) $option['label'];
                }

                continue;
            }

            if ((string) $option->id === $value) {
                return (string) $option->name;
            }
        }

        return $value;
    }

    /**
     * The indicator's name in the page's language (approved Y-05 Q4): the
     * file carries one name column, never both.
     *
     * @param  array<string, mixed>  $row
     */
    protected function exportIndicatorName(array $row): string
    {
        return (string) (app()->getLocale() === 'ar'
            ? ($row['name_ar'] ?? $row['name_en'])
            : ($row['name_en'] ?? $row['name_ar']));
    }

    /**
     * One month as text: the validated figure, the pending one marked as
     * such, or the state's own label.
     *
     * @param  array{state: MatrixCellState, value: ?float}  $cell
     */
    protected function csvCell(array $cell): string
    {
        return match ($cell['state']) {
            MatrixCellState::Approved => $cell['value'] === null ? $cell['state']->label() : $this->matrixNumber($cell['value']),
            MatrixCellState::Pending => $cell['value'] === null ? $cell['state']->label() : $this->matrixNumber($cell['value']).' ('.$cell['state']->label().')',
            MatrixCellState::NotSubmitted, MatrixCellState::Upcoming, MatrixCellState::OutsidePeriod, MatrixCellState::NotDue => $cell['state']->label(),
        };
    }

    /**
     * The breakdown rows under an indicator: male, female and total, each
     * followed by its disability row when the pwd switch is on.
     *
     * @param  array<string, mixed>  $row
     * @param  list<CarbonImmutable>  $months
     * @return list<list<string>>
     */
    protected function breakdownCsvRows(array $row, array $months): array
    {
        if ($row['breakdown'] === null) {
            return [];
        }

        $lines = [];

        foreach (['male', 'female', 'total'] as $category) {
            $figures = [[__('reports.matrix.sex.'.$category), $row['breakdown'][$category]]];

            if ($this->pwd) {
                $figures[] = [__('reports.matrix.sex.'.$category).' — '.__('reports.matrix.of_whom_pwd'), $row['breakdown'][$category]['pwd']];
            }

            foreach ($figures as [$label, $series]) {
                $lines[] = [
                    $row['code'], $this->exportIndicatorName($row),
                    $row['is_group'] ? __('reports.matrix.centers_count', ['count' => $row['centers_count']]) : $row['center'],
                    $row['city'], '', $row['measure']->label(), $label, '',
                    ...array_map(fn (CarbonImmutable $month): string => $this->matrixNumber($series['cells'][$month->format('Y-m')] ?? null), $months),
                    $this->matrixNumber($series['total']),
                    '',
                ];
            }
        }

        return $lines;
    }

    /**
     * The Activities board as a CSV: one row per activity and center, each
     * month's validated status, carried status or planned weeks.
     */
    protected function exportActivities(): StreamedResponse
    {
        $board = $this->activityBoard;
        $monthFormat = __('formats.month_year');

        $headings = [
            __('reports.matrix.output'),
            __('reports.matrix.activity'),
            __('reports.matrix.center'),
            __('reports.matrix.activity_kind'),
            ...array_map(fn (CarbonImmutable $month): string => $month->translatedFormat($monthFormat), $board['months']),
        ];

        $rows = (function () use ($board) {
            foreach ($board['outputs'] as $group) {
                foreach ($group['activities'] as $row) {
                    yield [
                        $group['statement'],
                        $row['statement'],
                        $row['center'],
                        $row['kind']->label(),
                        ...array_map(fn (array $cell): string => $this->activityCsvCell($cell), array_values($row['cells'])),
                    ];
                }
            }
        })();

        return CsvExport::download('monthly-matrix-activities-'.CarbonImmutable::today()->toDateString().'.csv', $headings, $rows, $this->exportHeaderBlock());
    }

    /**
     * @param  array{kind: string, status: ?ActivityStatus, weeks: ?list<int>}  $cell
     */
    protected function activityCsvCell(array $cell): string
    {
        return match ($cell['kind']) {
            'status' => $cell['status']?->label() ?? '',
            'carried' => ($cell['status']?->label() ?? '').' ('.__('reports.matrix.carried').')',
            'planned' => $cell['weeks'] === null ? __('reports.matrix.planned') : __('reports.matrix.planned_weeks', ['weeks' => implode(', ', $cell['weeks'])]),
            default => '',
        };
    }
}
