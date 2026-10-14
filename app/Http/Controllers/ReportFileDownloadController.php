<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\UnreadableStoredFile;
use App\Models\Report;
use App\Models\ReportFile;
use App\Support\StoredFiles;
use App\Support\UploadName;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportFileDownloadController extends Controller
{
    /**
     * Stream a report's supporting file to the browser.
     *
     * The file must belong to the report in the URL — the IDOR guard, same as
     * ProjectFileDownloadController. Reference-only rows with nothing on disk
     * 404 rather than 500.
     */
    public function __invoke(Report $report, ReportFile $reportFile): StreamedResponse
    {
        Gate::authorize('view', $report);

        abort_unless($reportFile->report_id === $report->id, 404);
        abort_unless($reportFile->isStored(), 404);

        try {
            return StoredFiles::stream($reportFile->disk, $reportFile->path, $reportFile->encryption_key_id, UploadName::clean($reportFile->displayName()), $reportFile->type, [
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (UnreadableStoredFile $exception) {
            throw $exception->forRow('report_files', $reportFile->id);
        }
    }
}
