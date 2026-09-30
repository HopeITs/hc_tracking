<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Models\Report;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Delete a draft report and the evidence uploaded with it.
 *
 * Only a draft: a reviewed or approved figure is part of the record. The
 * report_files rows cascade in the database, but their files on disk do not,
 * so those are removed here after the rows are gone.
 */
class DeleteReport
{
    /**
     * @param  Report  $report  loaded with its files (id, report_id, disk, path, original_name)
     * @return bool false when the report is not a draft and nothing changed
     */
    public function handle(Report $report): bool
    {
        if (! $report->isEditable()) {
            return false;
        }

        Gate::authorize('delete', $report);

        $stored = $report->files->filter(fn ($file): bool => $file->original_name !== null);

        DB::transaction(fn () => $report->delete());

        foreach ($stored as $file) {
            rescue(fn () => Storage::disk($file->disk)->delete($file->path), report: true);
        }

        return true;
    }
}
