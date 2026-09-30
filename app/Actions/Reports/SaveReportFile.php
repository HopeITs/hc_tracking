<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Models\Report;
use App\Models\ReportFile;
use App\Support\StoredFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * Attach a supporting file to a report, or rename, redate and optionally replace one.
 */
class SaveReportFile
{
    /**
     * @param  array<string, mixed>  $validated  keyed by the file form's property names
     */
    public function handle(Report $report, ?ReportFile $existing, ?UploadedFile $upload, array $validated): ReportFile
    {
        Gate::authorize('update', $report);

        $file = $existing ?? new ReportFile(['report_id' => $report->id]);

        StoredFiles::save(
            $file,
            $upload,
            'report-files/'.$report->id,
            (string) ($validated['fileDisplayName'] ?? ''),
            ['date' => $validated['fileDate']],
            [],
            'report file',
        );

        return $file;
    }
}
