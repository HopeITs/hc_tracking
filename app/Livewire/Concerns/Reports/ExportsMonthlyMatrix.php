<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Reports;

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
 * Used by reports/monthly-matrix only. It reads the same `matrix` as the
 * screen, so the file holds every row the filters select and no money.
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
                    $row['center'],
                    $row['city'],
                    collect($row['sources'])
                        ->map(fn (array $source): string => trim(($source['proposal'] ?? '').' — '.($source['donor'] ?? ''), ' —'))
                        ->implode('; '),
                    $row['measure']->label(),
                    $this->matrixNumber($row['target']),
                    ...array_map(fn (array $cell): string => $this->csvCell($cell), array_values($row['cells'])),
                    $this->matrixNumber($row['total']),
                    $this->matrixNumber($row['achieved']),
                ];
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
}
