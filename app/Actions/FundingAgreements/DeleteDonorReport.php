<?php

declare(strict_types=1);

namespace App\Actions\FundingAgreements;

use App\Models\DonorReport;
use App\Support\StoredFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Delete a donor report that has not gone out (DonorReportPolicy::delete);
 * its delivered files leave the disk after commit.
 */
class DeleteDonorReport
{
    public function handle(DonorReport $report): void
    {
        Gate::authorize('delete', $report);

        DB::transaction(function () use ($report): void {
            $files = $report->media()->get(['id', 'disk', 'path']);

            $report->media()->delete();
            $report->delete();

            DB::afterCommit(fn () => $files->each(fn ($file) => StoredFiles::forget($file->disk, $file->path, 'donor report file', ['media_id' => $file->id])));
        });
    }
}
