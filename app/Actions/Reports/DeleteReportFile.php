<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Models\Report;
use App\Models\ReportFile;
use App\Support\StoredFiles;
use Illuminate\Support\Facades\Gate;

/**
 * Delete a report file's row and, for a real upload, its file on disk.
 */
class DeleteReportFile
{
    public function handle(Report $report, ReportFile $file): void
    {
        Gate::authorize('update', $report);

        if ($file->original_name !== null) {
            StoredFiles::forget($file->disk, $file->path, 'report file', ['report_file_id' => $file->id]);
        }

        $file->delete();
    }
}
