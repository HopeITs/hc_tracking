<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Media;
use App\Models\Project;
use App\Support\UploadName;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectFileDownloadController extends Controller
{
    /**
     * Stream a project file to the browser.
     *
     * The media row must belong to the project in the URL — this is the IDOR
     * guard: without it, a signed-in user could download any project's file
     * by tampering with the media id.
     */
    public function __invoke(Project $project, Media $media): StreamedResponse
    {
        Gate::authorize('view', $project);

        abort_unless(
            $media->mediable_type === 'project' && $media->mediable_id === $project->id,
            404
        );

        // Media rows created before this feature (seeders, demo data) never had
        // a real file written to disk — a plain download() would 500 on those
        // via a Flysystem UnableToRetrieveMetadata exception. 404 instead.
        abort_unless(Storage::disk($media->disk)->exists($media->path), 404);

        return Storage::disk($media->disk)->download($media->path, UploadName::clean($media->original_name), [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
