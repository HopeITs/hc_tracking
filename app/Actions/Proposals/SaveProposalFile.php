<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Enums\MediaCollection;
use App\Models\Media;
use App\Models\Proposal;
use App\Support\StoredFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * Add a file to a proposal, or rename, recategorise and optionally replace one.
 *
 * A new need assessment (T07) may also come from a reviewer who may create
 * results (ProposalPolicy::uploadNeedAssessment) and tells the review loop.
 * Every other file needs proposals.update.
 */
class SaveProposalFile
{
    /**
     * @param  array<string, mixed>  $validated  keyed by the file form's property names
     */
    public function handle(Proposal $proposal, ?Media $existing, ?UploadedFile $upload, array $validated): Media
    {
        $isNewNeedAssessment = $existing === null && $validated['fileCategory'] === MediaCollection::NeedAssessment->value;

        Gate::authorize($isNewNeedAssessment ? 'uploadNeedAssessment' : 'update', $proposal);

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

        if ($isNewNeedAssessment) {
            $proposal->recordActivity('proposal.need_assessment_added', properties: ['file' => $file->original_name]);
        }

        return $file;
    }
}
