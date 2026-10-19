<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\FileType;
use App\Enums\MediaCollection;
use App\Models\DonorReport;
use App\Models\Media;
use App\Notifications\ActivityRecorder;
use App\Rules\SecureUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The delivered files of a donor report (`media`, collection
 * `report_submission`, stored encrypted under `media/donor-reports/{id}`),
 * shared by the submit, add-file and remove-file actions.
 */
final class DonorReportFiles
{
    /**
     * Every upload passes the same checks as any other upload (5 MB, allowed
     * types, SecureUpload) before anything is written.
     *
     * @param  list<UploadedFile>  $uploads
     *
     * @throws ValidationException
     */
    public static function validateUploads(array $uploads, string $field): void
    {
        $allowedTypes = implode(',', FileType::values());

        Validator::make([$field => $uploads], [
            $field => ['array'],
            $field.'.*' => ['bail', 'file', 'max:5120', 'mimes:'.$allowedTypes, 'extensions:'.$allowedTypes, new SecureUpload],
        ])->validate();
    }

    /**
     * Re-load the report under a row lock, refusing one that has gone out
     * meanwhile. Call inside a transaction.
     *
     * @throws ValidationException already_submitted
     */
    public static function lockUnsubmitted(DonorReport $report): DonorReport
    {
        $locked = DonorReport::query()->whereKey($report->id)->lockForUpdate()->firstOrFail();

        if ($locked->isSubmitted()) {
            throw ValidationException::withMessages(['report' => __('donor_reports.already_submitted')]);
        }

        return $locked;
    }

    /**
     * Store one delivered file on the report and log it (no notification).
     */
    public static function store(DonorReport $report, UploadedFile $upload): Media
    {
        $file = new Media(['mediable_type' => $report->getMorphClass(), 'mediable_id' => $report->id, 'disk' => 'local']);

        StoredFiles::save(
            $file,
            $upload,
            'media/donor-reports/'.$report->id,
            '',
            ['collection' => MediaCollection::ReportSubmission->value],
            ['uploaded_at' => now()],
            'donor report file',
        );

        app(ActivityRecorder::class)->record($report, 'donor_report.file_added', properties: ['file' => $file->original_name], notify: false);

        return $file;
    }

    /**
     * Remove files written by a step that then failed.
     *
     * @param  list<Media>  $files
     */
    public static function forget(array $files): void
    {
        foreach ($files as $file) {
            StoredFiles::forget($file->disk, $file->path, 'donor report file', ['media_id' => $file->id]);
        }
    }
}
