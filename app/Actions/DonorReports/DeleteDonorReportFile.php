<?php

declare(strict_types=1);

namespace App\Actions\DonorReports;

use App\Models\DonorReport;
use App\Notifications\ActivityRecorder;
use App\Support\DonorReportFiles;
use App\Support\StoredFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Remove a delivered file from a donor report that has not gone out
 * (contracts §14.3). The file loads through the report (BOLA) and leaves the
 * disk after commit.
 */
final class DeleteDonorReportFile
{
    /**
     * @throws ValidationException already_submitted
     */
    public function handle(DonorReport $report, int $mediaId): void
    {
        Gate::authorize('deleteFile', $report);

        DB::transaction(function () use ($report, $mediaId): void {
            $locked = DonorReportFiles::lockUnsubmitted($report);
            $file = $locked->media()->whereKey($mediaId)->firstOrFail();
            [$disk, $path, $name] = [$file->disk, $file->path, $file->original_name];

            $file->delete();
            app(ActivityRecorder::class)->record($locked, 'donor_report.file_removed', properties: ['file' => $name], notify: false);

            DB::afterCommit(fn () => StoredFiles::forget($disk, $path, 'donor report file', ['media_id' => $mediaId]));
        });
    }
}
