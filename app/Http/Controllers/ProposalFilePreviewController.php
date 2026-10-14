<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FileType;
use App\Exceptions\UnreadableStoredFile;
use App\Models\Media;
use App\Models\Proposal;
use App\Support\StoredFiles;
use App\Support\UploadName;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProposalFilePreviewController extends Controller
{
    /**
     * Stream a document inline for the proposal page's preview modal.
     *
     * Serves a file filed on this proposal, or one filed on a project this
     * proposal funds — the two lists the documents tab shows. Anything else
     * is a 404, whatever id the URL carries. As on the report preview, only
     * PDFs and images are served inline, the Content-Type comes from the
     * validated FileType rather than the stored client MIME, and nosniff
     * stops the browser reinterpreting the body.
     */
    public function __invoke(Proposal $proposal, Media $media): StreamedResponse
    {
        Gate::authorize('view', $proposal);

        $belongsHere = match ($media->mediable_type) {
            'proposal' => $media->mediable_id === $proposal->id,
            'project' => $proposal->projects()->whereKey($media->mediable_id)->exists(),
            default => false,
        };

        abort_unless($belongsHere, 404);

        abort_if(
            $media->collection->isFundingPaper() && ! auth()->user()->can('funding-agreements.view'),
            403
        );
        abort_unless($media->isPreviewable() && $media->isStored(), 404);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Cache-Control' => 'private, max-age=300',
        ];

        // A sandboxed image opened in its own tab can never act as the app.
        // PDFs are left out: Chrome refuses to render a PDF in a sandboxed
        // document, and its viewer already isolates the file's scripts.
        if ($media->type !== FileType::Pdf) {
            $headers['Content-Security-Policy'] = 'sandbox';
        }

        try {
            return StoredFiles::stream($media->disk, $media->path, $media->encryption_key_id, UploadName::clean($media->original_name), $media->type, $headers, 'inline');
        } catch (UnreadableStoredFile $exception) {
            throw $exception->forRow('media', $media->id);
        }
    }
}
