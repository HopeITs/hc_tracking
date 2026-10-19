<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Reports;

use App\Enums\ActivityStatus;
use App\Enums\MatrixCellState;
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
 * select, with the breakdown rows under their indicator, and no money.
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
        $monthFormat = __('formats.month_year');

        $headings = [
            __('reports.matrix.code'),
            __('reports.matrix.name_en'),
            __('reports.matrix.name_ar'),
            __('reports.matrix.center'),
            __('reports.matrix.city'),
            __('reports.matrix.source'),
            __('reports.matrix.measure'),
            __('reports.matrix.breakdown'),
            __('reports.matrix.target'),
            ...array_map(fn (CarbonImmutable $month): string => $month->translatedFormat($monthFormat), $matrix['months']),
            __('reports.matrix.total'),
            __('reports.matrix.achieved'),
        ];

        $rows = (function () use ($matrix) {
            foreach ($matrix['rows'] as $row) {
                yield [
                    $row['code'],
                    $row['name_en'],
                    $row['name_ar'],
                    $row['is_group'] ? __('reports.matrix.centers_count', ['count' => $row['centers_count']]) : $row['center'],
                    $row['city'],
                    collect($row['sources'])
                        ->map(fn (array $source): string => trim(($source['proposal'] ?? '').' — '.($source['donor'] ?? ''), ' —'))
                        ->implode('; '),
                    $row['measure']->label(),
                    '',
                    $this->matrixNumber($row['target']),
                    ...array_map(fn (array $cell): string => $this->csvCell($cell), array_values($row['cells'])),
                    $this->matrixNumber($row['total']),
                    $this->matrixNumber($row['achieved']),
                ];

                yield from $this->breakdownCsvRows($row);
            }
        })();

        return CsvExport::download('monthly-matrix-'.CarbonImmutable::today()->toDateString().'.csv', $headings, $rows);
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
            MatrixCellState::NotSubmitted, MatrixCellState::Upcoming, MatrixCellState::NotDue => $cell['state']->label(),
        };
    }

    /**
     * The breakdown rows under an indicator: male, female and total, each
     * followed by its disability row when the pwd switch is on.
     *
     * @param  array<string, mixed>  $row
     * @return list<list<string>>
     */
    protected function breakdownCsvRows(array $row): array
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
                    $row['code'], $row['name_en'], $row['name_ar'],
                    $row['is_group'] ? __('reports.matrix.centers_count', ['count' => $row['centers_count']]) : $row['center'],
                    $row['city'], '', $row['measure']->label(), $label, '',
                    ...array_map(fn (?float $figure): string => $this->matrixNumber($figure), array_values($series['cells'])),
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

        return CsvExport::download('monthly-matrix-activities-'.CarbonImmutable::today()->toDateString().'.csv', $headings, $rows);
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
