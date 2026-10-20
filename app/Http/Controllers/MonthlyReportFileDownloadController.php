<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\UnreadableStoredFile;
use App\Models\MonthlyReport;
use App\Support\StoredFiles;
use App\Support\UploadName;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MonthlyReportFileDownloadController extends Controller
{
    /**
     * Stream a monthly report's evidence file (contracts §13.1 item 4, §16).
     *
     * The file loads through the report in the URL, so another report's
     * file is a 404 (BOLA). A row with nothing on disk is a 404 too.
     */
    public function __invoke(MonthlyReport $monthlyReport, int $monthlyReportFile): StreamedResponse
    {
        Gate::authorize('view', $monthlyReport);

        $file = $monthlyReport->files()->whereKey($monthlyReportFile)->firstOrFail();

        abort_unless(Storage::disk($file->disk)->exists($file->path), 404);

        try {
            return StoredFiles::stream($file->disk, $file->path, $file->encryption_key_id, UploadName::clean($file->original_name ?? basename($file->path)), $file->type, [
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (UnreadableStoredFile $exception) {
            throw $exception->forRow('monthly_report_files', $file->id);
        }
    }
}
