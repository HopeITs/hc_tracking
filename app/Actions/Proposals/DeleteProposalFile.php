<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Models\Media;
use App\Models\Proposal;
use App\Support\StoredFiles;
use Illuminate\Support\Facades\Gate;

/**
 * Delete a proposal file's row, then the stored document.
 *
 * The row goes first: a failed row delete leaves the document intact behind a
 * row that still opens it, never a row pointing at nothing.
 */
class DeleteProposalFile
{
    public function handle(Proposal $proposal, Media $file): void
    {
        Gate::authorize('update', $proposal);

        $file->delete();

        StoredFiles::forget($file->disk, $file->path, 'proposal file', ['media_id' => $file->id]);
    }
}
