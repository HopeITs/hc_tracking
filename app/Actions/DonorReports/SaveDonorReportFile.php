<?php

declare(strict_types=1);

namespace App\Actions\DonorReports;

use App\Models\DonorReport;
use App\Models\Media;
use App\Models\User;
use App\Support\DonorReportFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Attach a delivered file to a donor report before it goes out (contracts
 * §14.3): the responsible person or a `funding-agreements.update` holder.
 * A submitted report takes no more files.
 */
final class SaveDonorReportFile
{
    /**
     * @throws ValidationException already_submitted, or an invalid upload
     */
    public function handle(DonorReport $report, UploadedFile $file, User $actor): Media
    {
        Gate::forUser($actor)->authorize('uploadFile', $report);

        DonorReportFiles::validateUploads([$file], 'submissionFiles');

        $stored = null;

        try {
            return DB::transaction(function () use ($report, $file, &$stored): Media {
                $stored = DonorReportFiles::store(DonorReportFiles::lockUnsubmitted($report), $file);

                return $stored;
            });
        } catch (Throwable $exception) {
            DonorReportFiles::forget($stored === null ? [] : [$stored]);

            throw $exception;
        }
    }
}
