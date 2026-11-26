<?php

declare(strict_types=1);

namespace App\Actions\Locations;

use App\Models\Media;
use App\Models\ProposalLocation;
use App\Support\StoredFiles;
use Illuminate\Support\Facades\Gate;

/**
 * Delete a location row's file row and its underlying disk file. The twin of
 * the former DeleteProjectFile, moved onto the row (Y-17).
 *
 * The file is loaded through the row (BOLA) by the caller, so a file id from
 * another place cannot be reached.
 */
final class DeleteLocationFile
{
    public function handle(ProposalLocation $row, Media $file): void
    {
        Gate::authorize('update', $row);

        StoredFiles::forget($file->disk, $file->path, 'location file', ['media_id' => $file->id]);

        $file->delete();
    }
}
