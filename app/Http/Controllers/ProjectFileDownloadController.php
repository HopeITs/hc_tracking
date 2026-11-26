<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\UnreadableStoredFile;
use App\Models\Media;
use App\Models\ProposalLocation;
use App\Support\Locations\LocationFromSlug;
use App\Support\StoredFiles;
use App\Support\UploadName;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectFileDownloadController extends Controller
{
    /**
     * Stream a location row's file to the browser (Y-16, D-93).
     *
     * The media row must belong to the row in the URL — this is the IDOR
     * guard: without it, a signed-in user could download any row's file by
     * tampering with the media id.
     *
     * The slug is resolved rather than bound, like the page's, so a saved link
     * carrying a project slug still reaches the row that took it over (D-94).
     */
    public function __invoke(string $project, Media $media): StreamedResponse
    {
        $resolver = app(LocationFromSlug::class);
        $row = $resolver->row($project) ?? $resolver->successorOfProjectSlug($project);

        abort_if(! $row instanceof ProposalLocation, 404);

        Gate::authorize('view', $row);

        abort_unless(
            $media->mediable_type === 'proposal_location' && $media->mediable_id === $row->id,
            404
        );

        abort_if(
            $media->collection->isFundingPaper() && ! auth()->user()->can('funding-agreements.view'),
            403
        );

        // Media rows created before this feature (seeders, demo data) never had
        // a real file written to disk — a plain download() would 500 on those
        // via a Flysystem UnableToRetrieveMetadata exception. 404 instead.
        abort_unless(Storage::disk($media->disk)->exists($media->path), 404);

        try {
            return StoredFiles::stream($media->disk, $media->path, $media->encryption_key_id, UploadName::clean($media->original_name), $media->type, [
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (UnreadableStoredFile $exception) {
            throw $exception->forRow('media', $media->id);
        }
    }
}
