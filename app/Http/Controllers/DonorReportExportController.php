<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DonorReport;
use App\Support\CsvExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DonorReportExportController extends Controller
{
    /**
     * The saved snapshot as CSV (no live re-query): one line per indicator
     * and per center, then, only for funding-agreements.view holders, the
     * finance lines. The route checks DonorReportPolicy::view.
     */
    public function __invoke(Request $request, DonorReport $donorReport): StreamedResponse
    {
        abort_unless($donorReport->isComposed() && is_array($donorReport->snapshot), 404);

        $snapshot = $donorReport->snapshot;
        $centerNames = array_column(is_array($snapshot['centers'] ?? null) ? $snapshot['centers'] : [], 'name', 'project_id');
        $showFinance = $request->user()?->can('funding-agreements.view') === true && array_key_exists('finance', $snapshot);

        $headings = [
            __('donor_reports.export.section'), __('donor_reports.export.code'), __('donor_reports.indicator'), __('donor_reports.export.center'),
            __('donor_reports.target'), __('donor_reports.this_period'), __('donor_reports.export.period_numerator'), __('donor_reports.export.period_denominator'),
            __('donor_reports.to_date'), __('donor_reports.export.to_date_numerator'), __('donor_reports.export.to_date_denominator'), __('donor_reports.achievement'),
        ];

        $rows = function () use ($snapshot, $centerNames, $showFinance): iterable {
            foreach (['indicators', 'historical'] as $section) {
                foreach ($snapshot[$section] ?? [] as $row) {
                    yield $this->line(__('donor_reports.sections.'.$section), $row['code'], $row['name'], '', $row, $row['achievement']);

                    foreach ($row['centers'] as $center) {
                        yield $this->line(__('donor_reports.sections.'.$section), $row['code'], $row['name'], (string) ($centerNames[$center['project_id']] ?? $center['project_id']), $center, $center['achievement']);
                    }
                }
            }

            // Finance gets its own block with its own headings, after a blank line.
            if ($showFinance) {
                yield [];
                yield [__('donor_reports.sections.finance'), __('donor_reports.currency'), __('donor_reports.received'), __('donor_reports.fees'), __('donor_reports.net')];

                foreach ($snapshot['finance'] as $finance) {
                    $fees = implode(' + ', array_map(fn (array $fee): string => CsvExport::amount($fee['amount']).' '.$fee['currency'], $finance['fees']));

                    yield [__('donor_reports.sections.finance'), $finance['currency'], CsvExport::amount($finance['received']), $fees, CsvExport::amount($finance['net'])];
                }
            }
        };

        return CsvExport::download('donor-report-'.$donorReport->id.'-v'.$donorReport->version.'.csv', $headings, $rows());
    }

    /**
     * @param  array{target: float|int|null, period: array{value: float|int|null, numerator: float|int|null, denominator: float|int|null}, cumulative: array{value: float|int|null, numerator: float|int|null, denominator: float|int|null}}  $figures
     * @return list<string|float|int|null>
     */
    private function line(string $section, string $code, string $name, string $center, array $figures, float|int|null $achievement): array
    {
        return [
            $section, $code, $name, $center, $figures['target'],
            $figures['period']['value'], $figures['period']['numerator'], $figures['period']['denominator'],
            $figures['cumulative']['value'], $figures['cumulative']['numerator'], $figures['cumulative']['denominator'],
            $achievement === null ? null : round((float) $achievement, 1),
        ];
    }
}
