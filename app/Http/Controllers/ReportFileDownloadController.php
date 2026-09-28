<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\ReportFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
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

        return Storage::disk($reportFile->disk)->download($reportFile->path, $reportFile->displayName());
    }
}
