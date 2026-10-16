<?php

declare(strict_types=1);

namespace App\Actions\MonthlyReports;

use App\Enums\DonorAttribution;
use App\Enums\MonthlyReportStatus;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportLine;
use App\Support\MonthlyReportGuard;
use App\Support\MonthlyReportLineBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Bring a draft up to date with its template (contracts §12.4, D-41): adds
 * the lines and shares confirmed since it was built and removes none. A line
 * that gained a share gets its fixed denominator again (§13.1 item 1:
 * `split` = Σ shares, `shared` = the highest).
 */
final class RefreshMonthlyReportLines
{
    public function __construct(private MonthlyReportLineBuilder $builder) {}

    /**
     * @return array{lines: int, shares: int} rows created
     */
    public function handle(MonthlyReport $report, MonthlyReportStatus $expectedStatus): array
    {
        return DB::transaction(function () use ($report, $expectedStatus): array {
            $locked = MonthlyReportGuard::lock($report, $expectedStatus, 'update');
            $built = $this->builder->build($locked);

            $locked->project->loadMissing('program:id,donor_attribution');
            $attribution = MonthlyReportLineBuilder::attributionOf($locked->project);

            $locked->lines()->with('shares:id,monthly_report_line_id,fixed_denominator')->get()
                ->each(function (MonthlyReportLine $line) use ($attribution): void {
                    $denominators = $line->shares->pluck('fixed_denominator')->filter(fn (?int $value): bool => $value !== null);
                    $expected = $denominators->isEmpty() ? null : (int) ($attribution === DonorAttribution::Split ? $denominators->sum() : $denominators->max());

                    if ($line->fixed_denominator !== $expected) {
                        $line->forceFill(['fixed_denominator' => $expected])->save();
                    }
                });

            return $built;
        });
    }
}
