<?php

declare(strict_types=1);

namespace App\Actions\MonthlyReports;

use App\Enums\IndicatorCollector;
use App\Enums\MonthlyReportStatus;
use App\Models\MonthlyReport;
use App\Support\MonthlyReportGuard;
use App\Support\StoredFiles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Delete an evidence file of a report (D-42), with the same sides as
 * SaveMonthlyReportFile: the preparer on a draft, the validator on a `meal`
 * line while submitted. The file loads through the report (BOLA); it leaves
 * the disk after commit.
 */
final class DeleteMonthlyReportFile
{
    public function handle(MonthlyReport $report, MonthlyReportStatus $expectedStatus, int $fileId): void
    {
        DB::transaction(function () use ($report, $expectedStatus, $fileId): void {
            $byValidator = $expectedStatus === MonthlyReportStatus::Submitted;
            $locked = MonthlyReportGuard::lock($report, $expectedStatus, $byValidator ? 'fillMealLines' : 'update');
            $file = $locked->files()->with('line:id,entered_by')->whereKey($fileId)->firstOrFail();

            if ($byValidator !== ($file->line?->entered_by === IndicatorCollector::Meal)) {
                throw new AuthorizationException;
            }

            $disk = $file->disk;
            $path = $file->path;
            $file->delete();

            DB::afterCommit(fn () => StoredFiles::forget($disk, $path, 'monthly report file', ['monthly_report_file_id' => $fileId]));
        });
    }
}
