<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Enums\MediaCollection;
use App\Enums\ProposalStatus;
use App\Enums\ReviewStage;
use App\Enums\StatusNoteKind;
use App\Models\Media;
use App\Models\Proposal;
use App\Models\ProposalReviewer;
use App\Models\ProposalStatusNote;
use App\Notifications\EmployeeRecipients;
use App\Support\DonorReportFiles;
use App\Support\StoredFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Record what the donor asked for while a proposal is submitted (W-07, D-59).
 *
 * Writes a `negotiation` status note and an optional file; the status never
 * moves. Only a `submitted` proposal takes one. The activity names the
 * reviewers who have not yet seen the proposal: the open actions of D-59.
 */
final class AddNegotiationNote
{
    public function __construct(private EmployeeRecipients $employees) {}

    /**
     * @param  array{text: string, file?: UploadedFile|null}  $input
     *
     * @throws ValidationException stale status, wrong status, short text, bad file
     */
    public function handle(Proposal $proposal, ProposalStatus $expectedStatus, array $input): ProposalStatusNote
    {
        Gate::authorize('update', $proposal);

        $text = trim($input['text']);
        $file = $input['file'] ?? null;

        if (mb_strlen($text) < 10) {
            throw ValidationException::withMessages(['negotiationText' => __('proposals.negotiation_text_min')]);
        }

        if ($file !== null) {
            DonorReportFiles::validateUploads([$file], 'negotiationFile');
        }

        $stored = null;

        try {
            return DB::transaction(function () use ($proposal, $expectedStatus, $text, $file, &$stored): ProposalStatusNote {
                $locked = Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->status !== $expectedStatus) {
                    throw ValidationException::withMessages(['status' => __('proposals.stale_status')]);
                }

                if ($locked->status !== ProposalStatus::Submitted) {
                    throw ValidationException::withMessages(['status' => __('proposals.negotiation_only_submitted')]);
                }

                $note = ProposalStatusNote::query()->create([
                    'proposal_id' => $locked->id,
                    'status' => $locked->status,
                    'context' => $text,
                    'date' => today()->toDateString(),
                    'changed_by' => Auth::id(),
                    'kind' => StatusNoteKind::Negotiation,
                ]);

                if ($file !== null) {
                    $stored = new Media(['mediable_type' => $note->getMorphClass(), 'mediable_id' => $note->id, 'disk' => 'local']);

                    StoredFiles::save(
                        $stored,
                        $file,
                        'media/proposals/'.$locked->id,
                        '',
                        ['collection' => MediaCollection::Negotiation->value],
                        ['uploaded_at' => now()],
                        'negotiation file',
                    );
                }

                $pendingIds = ProposalReviewer::query()
                    ->where('proposal_id', $locked->id)
                    ->forStage(ReviewStage::Proposal)
                    ->unseen()
                    ->pluck('employee_id')
                    ->all();
                $pendingNames = array_values($this->employees->names($pendingIds));

                $locked->recordActivity('proposal.negotiation_noted', properties: [
                    'file' => $stored?->original_name,
                    'pending_reviewers' => $pendingNames,
                ]);

                return $note;
            });
        } catch (Throwable $exception) {
            if ($stored?->exists === true || $stored?->path !== null) {
                StoredFiles::forget($stored->disk, $stored->path, 'negotiation file');
            }

            throw $exception;
        }
    }
}
