<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Media;
use App\Models\ReportFile;
use App\Models\TaskAttachment;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;

/**
 * Deletes uploaded files that no record points at any more, and temporary
 * uploads that were never saved.
 *
 * Deleting a task, report, indicator, proposal or annual plan removes its file
 * rows through database cascades or query-builder deletes, and neither fires
 * a model event, so the files themselves stay on disk. Sweeping for
 * unreferenced files catches every one of those paths in a single place.
 */
class PruneStoredFiles extends Command
{
    /**
     * @var string
     */
    protected $signature = 'files:prune {--dry-run : List what would be deleted without deleting anything}';

    /**
     * @var string
     */
    protected $description = 'Delete uploaded files no record points at, and abandoned temporary uploads';

    /**
     * Folders on the local disk that hold record-owned uploads.
     *
     * @var list<string>
     */
    private const UploadDirectories = ['media', 'report-files', 'task-attachments'];

    /**
     * How long a file must go untouched before it may be deleted: an upload
     * is written moments before its row, and Livewire keeps a temporary
     * upload usable for a day.
     */
    private const GraceHours = 24;

    public function handle(): int
    {
        $cutoff = now()->subHours(self::GraceHours)->getTimestamp();
        $isDryRun = (bool) $this->option('dry-run');

        $disk = Storage::disk('local');
        $orphans = $this->unreferencedUploads($disk, $cutoff);
        $abandoned = $this->abandonedTemporaryUploads($cutoff);

        if ($isDryRun) {
            foreach ([...$orphans, ...$abandoned] as $path) {
                $this->line($path);
            }

            $this->components->info(count($orphans).' unreferenced upload(s) and '.count($abandoned).' temporary upload(s) would be deleted.');

            return self::SUCCESS;
        }

        $disk->delete($orphans);
        FileUploadConfiguration::storage()->delete($abandoned);

        $this->components->info('Deleted '.count($orphans).' unreferenced upload(s) and '.count($abandoned).' temporary upload(s).');

        return self::SUCCESS;
    }

    /**
     * Files in the upload folders that no media, report file or task
     * attachment row on this disk points at.
     *
     * Global scopes are dropped: a console run has no signed-in user, and a
     * row hidden by a scope must still keep its file.
     *
     * @return list<string>
     */
    protected function unreferencedUploads(Filesystem $disk, int $cutoff): array
    {
        $referenced = collect([Media::class, ReportFile::class, TaskAttachment::class])
            ->flatMap(fn (string $model) => $model::query()
                ->withoutGlobalScopes()
                ->where('disk', 'local')
                ->toBase()
                ->pluck('path'))
            ->flip();

        return collect(self::UploadDirectories)
            ->flatMap(fn (string $directory) => $disk->allFiles($directory))
            ->reject(fn (string $path) => $referenced->has($path))
            ->filter(fn (string $path) => $disk->lastModified($path) < $cutoff)
            ->values()
            ->all();
    }

    /**
     * Livewire temporary uploads older than the grace period. Livewire only
     * clears these when someone uploads again, so a quiet site keeps them.
     *
     * @return list<string>
     */
    protected function abandonedTemporaryUploads(int $cutoff): array
    {
        $storage = FileUploadConfiguration::storage();

        return collect($storage->files(FileUploadConfiguration::path()))
            ->filter(fn (string $path) => $storage->lastModified($path) < $cutoff)
            ->values()
            ->all();
    }
}
