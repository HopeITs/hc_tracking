<?php

declare(strict_types=1);

namespace App\Actions\MonthlyReports;

use App\Enums\MonthlyReportStatus;
use App\Models\MonthlyReport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use LogicException;

/**
 * Attach a typed evidence file to a report, a line or an activity entry
 * (D-42). Signature frozen at CP3; implemented in T17A slice 4, where the
 * return type narrows to App\Models\MonthlyReportFile.
 */
final class SaveMonthlyReportFile
{
    public function handle(MonthlyReport $report, MonthlyReportStatus $expectedStatus, int $evidenceTypeId, UploadedFile $upload, ?int $lineId = null, ?int $activityEntryId = null): Model
    {
        throw new LogicException('Implemented in T17A slice 4');
    }
}
