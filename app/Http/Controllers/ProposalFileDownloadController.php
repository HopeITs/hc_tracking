<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Media;
use App\Models\Proposal;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProposalFileDownloadController extends Controller
{
    /**
     * Stream a proposal file to the browser.
     *
     * The media row must belong to the proposal in the URL — the same IDOR
     * guard ProjectFileDownloadController carries.
     */
    public function __invoke(Proposal $proposal, Media $media): StreamedResponse
    {
        Gate::authorize('view', $proposal);

        abort_unless(
            $media->mediable_type === 'proposal' && $media->mediable_id === $proposal->id,
            404
        );

        abort_if(
            $media->collection->isFundingPaper() && ! auth()->user()->can('funding-agreements.view'),
            403
        );

        abort_unless(Storage::disk($media->disk)->exists($media->path), 404);

        return Storage::disk($media->disk)->download($media->path, $media->original_name, [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
