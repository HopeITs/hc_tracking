<?php

declare(strict_types=1);

namespace App\Actions\Locations;

use App\Models\Media;
use App\Models\ProposalLocation;
use App\Support\StoredFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * Add a file to a location row, or change an existing file's category and
 * name. The twin of the former SaveProjectFile, moved onto the row (Y-17).
 *
 * The row owns the file outright: there is no project copy to keep in step
 * any more, and MediaOwnerObserver removes the row's media with the row.
 */
final class SaveLocationFile
{
    /**
     * @param  array<string, mixed>  $validated  keyed by the file form's property names
     */
    public function handle(ProposalLocation $row, ?Media $existing, ?UploadedFile $upload, array $validated): Media
    {
        Gate::authorize('update', $row);

        $file = $existing ?? new Media(['mediable_type' => 'proposal_location', 'mediable_id' => $row->id, 'disk' => 'local']);

        StoredFiles::save(
            $file,
            $upload,
            'media/locations/'.$row->id,
            (string) ($validated['fileDisplayName'] ?? ''),
            ['collection' => $validated['fileCategory']],
            ['uploaded_at' => now()],
            'location file',
        );

        return $file;
    }
}
