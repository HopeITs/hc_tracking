<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\UnreadableStoredFile;
use App\Models\DonorReport;
use App\Support\StoredFiles;
use App\Support\UploadName;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DonorReportFileDownloadController extends Controller
{
    /**
     * Stream a delivered donor report file: the responsible person or a
     * funding-agreements.view holder (DonorReportPolicy::downloadFile). The
     * file loads through the report in the URL (BOLA).
     */
    public function __invoke(DonorReport $donorReport, int $media): StreamedResponse
    {
        Gate::authorize('downloadFile', $donorReport);

        $file = $donorReport->media()->whereKey($media)->firstOrFail();

        abort_unless(Storage::disk($file->disk)->exists($file->path), 404);

        try {
            return StoredFiles::stream($file->disk, $file->path, $file->encryption_key_id, UploadName::clean($file->original_name), $file->type, [
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (UnreadableStoredFile $exception) {
            throw $exception->forRow('media', $file->id);
        }
    }
}
