<?php

declare(strict_types=1);

namespace App\Actions\MonthlyReports;

use App\Enums\FileType;
use App\Enums\IndicatorCollector;
use App\Enums\MonthlyReportStatus;
use App\Models\EvidenceType;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportFile;
use App\Rules\SecureUpload;
use App\Support\MonthlyReportGuard;
use App\Support\StoredFiles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Attach a typed evidence file (D-42, contracts §13, §16) to a report, one
 * of its lines or one of its activity entries. A draft takes files from the
 * preparer side (`update`) on its own lines and entries; a submitted report
 * takes them only from the validator (`fillMealLines`) on `meal` lines.
 * Either side on the other's line → 403. The line or entry loads through the
 * report (BOLA); the type must be active; the upload passes SecureUpload
 * (5 MB, allowed types) and is encrypted by StoredFiles.
 */
final class SaveMonthlyReportFile
{
    public function handle(MonthlyReport $report, MonthlyReportStatus $expectedStatus, int $evidenceTypeId, UploadedFile $upload, ?int $lineId = null, ?int $activityEntryId = null): MonthlyReportFile
    {
        $allowedTypes = implode(',', FileType::values());

        Validator::make(['upload' => $upload], [
            'upload' => ['required', 'bail', 'file', 'max:5120', 'mimes:'.$allowedTypes, 'extensions:'.$allowedTypes, new SecureUpload],
        ])->validate();

        return DB::transaction(function () use ($report, $expectedStatus, $evidenceTypeId, $upload, $lineId, $activityEntryId): MonthlyReportFile {
            $byValidator = $expectedStatus === MonthlyReportStatus::Submitted;
            $locked = MonthlyReportGuard::lock($report, $expectedStatus, $byValidator ? 'fillMealLines' : 'update');

            if ($lineId !== null && $activityEntryId !== null) {
                throw ValidationException::withMessages(['file' => __('monthly_reports.file_scope')]);
            }

            $line = $lineId === null ? null : $locked->lines()->whereKey($lineId)->firstOrFail();
            $entry = $activityEntryId === null ? null : $locked->activityEntries()->whereKey($activityEntryId)->firstOrFail();

            $onMealLine = $line !== null && $line->entered_by === IndicatorCollector::Meal;

            if ($byValidator !== $onMealLine) {
                throw new AuthorizationException;
            }

            if (! EvidenceType::query()->active()->whereKey($evidenceTypeId)->exists()) {
                throw ValidationException::withMessages(['evidence_type_id' => __('monthly_reports.evidence_type_inactive')]);
            }

            $file = new MonthlyReportFile;

            StoredFiles::save(
                $file,
                $upload,
                'monthly-report-files/'.$locked->id,
                '',
                [
                    'monthly_report_id' => $locked->id,
                    'monthly_report_line_id' => $line?->id,
                    'monthly_report_activity_entry_id' => $entry?->id,
                    'evidence_type_id' => $evidenceTypeId,
                ],
                [],
                'monthly report file',
            );

            return $file;
        });
    }
}
