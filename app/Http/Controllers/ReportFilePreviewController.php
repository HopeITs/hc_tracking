<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FileType;
use App\Models\Report;
use App\Models\ReportFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportFilePreviewController extends Controller
{
    /**
     * Stream a report file inline so the page can show it in its preview modal.
     *
     * Same report-scoped IDOR guard as the download route. Only PDFs and
     * images are served inline; the Content-Type comes from the validated
     * FileType rather than the stored client MIME, and nosniff stops the
     * browser from reinterpreting the body as anything else.
     */
    public function __invoke(Report $report, ReportFile $reportFile): StreamedResponse
    {
        Gate::authorize('view', $report);

        abort_unless($reportFile->report_id === $report->id, 404);
        abort_unless($reportFile->isPreviewable() && $reportFile->isStored(), 404);

        $contentType = match ($reportFile->type) {
            FileType::Pdf => 'application/pdf',
            FileType::Png => 'image/png',
            default => 'image/jpeg',
        };

        return Storage::disk($reportFile->disk)->response($reportFile->path, $reportFile->displayName(), [
            'Content-Type' => $contentType,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
        ], 'inline');
    }
}
