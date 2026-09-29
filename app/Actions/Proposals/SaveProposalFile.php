<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Models\Media;
use App\Models\Proposal;
use App\Support\StoredFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * Add a file to a proposal, or rename, recategorise and optionally replace one.
 */
class SaveProposalFile
{
    /**
     * @param  array<string, mixed>  $validated  keyed by the file form's property names
     */
    public function handle(Proposal $proposal, ?Media $existing, ?UploadedFile $upload, array $validated): Media
    {
        Gate::authorize('update', $proposal);

        $file = $existing ?? new Media(['mediable_type' => 'proposal', 'mediable_id' => $proposal->id, 'disk' => 'local']);

        StoredFiles::save(
            $file,
            $upload,
            'media/proposals/'.$proposal->id,
            (string) ($validated['fileDisplayName'] ?? ''),
            ['collection' => $validated['fileCategory']],
            ['uploaded_at' => now()],
            'proposal file',
        );

        return $file;
    }
}
