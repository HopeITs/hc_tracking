<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Enums\ProposalChangeRequestStatus;
use App\Events\ProposalChangeDecided;
use App\Models\Proposal;
use App\Models\ProposalChangeRequest;
use App\Models\User;
use App\Support\Reporting\ReportingCenters;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Approve a pending change request and apply it (contracts.md §6, Y-09,
 * D-89a, D-70). Only holders of `proposals.approve` decide; a requester who
 * holds it may decide their own request. Refused while a review is still
 * waiting (a slot with no holder is skipped first; `remarks` never blocks).
 *
 * Inside one transaction on the locked proposal the payload is re-checked
 * against the proposal as it is now, with the requester as actor so the
 * money fields read as they did when the request was made; any difference
 * from the stored `changes` makes the request stale and nothing is applied.
 * The change is written through SaveProposal::applyChange(); donor approval,
 * MaterialiseProposalLocations, the pre-fill and placement are not re-run.
 */
final class ApproveProposalChange
{
    /**
     * @throws ValidationException stale or decided request, a review still waiting, a stale `from`, an invalid payload
     */
    public function handle(ProposalChangeRequest $request, string $expectedStatus = 'pending'): ProposalChangeRequest
    {
        /** @var Proposal $proposal */
        $proposal = $request->proposal()->firstOrFail();

        Gate::authorize('decideChange', $proposal);

        /** @var User $actor */
        $actor = Auth::user();

        $decided = DB::transaction(function () use ($proposal, $request, $expectedStatus, $actor): ProposalChangeRequest {
            $lockedProposal = Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();

            $lockedRequest = ProposalChangeRequest::query()
                ->whereKey($request->getKey())
                ->where('proposal_id', $lockedProposal->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRequest->status->value !== $expectedStatus || ! $lockedRequest->isPending()) {
                throw ValidationException::withMessages(['changeRequest' => __('proposals.change_request_not_pending')]);
            }

            if (! $lockedProposal->status->isApprovedByDonor()) {
                throw ValidationException::withMessages(['status' => __('proposals.change_request_only_approved')]);
            }

            $lockedRequest->skipVacantReviews();

            if ($lockedRequest->reviews()->whereNull('verdict')->exists()) {
                throw ValidationException::withMessages(['changeRequest' => __('proposals.change_request_reviews_pending')]);
            }

            /** @var User $requester */
            $requester = $lockedRequest->requester()->firstOrFail();

            $now = app(SaveProposal::class)->changesFor($lockedProposal, $lockedRequest->payload, $requester);
            $staleFields = $this->staleFields($lockedRequest->getAttribute('changes'), $now);

            if ($staleFields !== []) {
                throw ValidationException::withMessages([
                    'changeRequest' => __('proposals.change_request_stale', ['fields' => implode(', ', $staleFields)]),
                ]);
            }

            $templateIds = app(ReportingCenters::class)->guard($lockedProposal, $now);
            $fields = $lockedRequest->changedFields();

            app(SaveProposal::class)->applyChange($lockedProposal, $lockedRequest->payload, $lockedRequest->reason, $requester);

            $lockedRequest->forceFill([
                'status' => ProposalChangeRequestStatus::Approved,
                'decided_by' => $actor->id,
                'decided_at' => now(),
            ])->save();

            $lockedProposal->recordActivity('proposal.change_approved', properties: [
                'change_request_id' => $lockedRequest->id,
                'fields' => $fields,
                // The templates at the places the change leaves: not moved, listed for MEAL to settle.
                'templates' => $templateIds,
            ]);

            ProposalChangeDecided::dispatch($lockedProposal->id, $lockedRequest->id, $actor->id, ProposalChangeRequestStatus::Approved);

            return $lockedRequest;
        });

        $request->refresh();

        return $decided;
    }

    /**
     * The fields whose entries differ between the stored changes and the
     * changes computed now, compared canonically (keys sorted at every
     * level, since the json column re-orders them). Empty when they match.
     *
     * @param  list<array<string, mixed>>  $stored
     * @param  list<array<string, mixed>>  $now
     * @return list<string>
     */
    private function staleFields(array $stored, array $now): array
    {
        $storedKeys = array_map(fn (array $change): string => $this->canonical($change), $stored);
        $nowKeys = array_map(fn (array $change): string => $this->canonical($change), $now);

        $sortedStored = $storedKeys;
        $sortedNow = $nowKeys;
        sort($sortedStored);
        sort($sortedNow);

        if ($sortedStored === $sortedNow) {
            return [];
        }

        $fields = [];

        foreach ($stored as $index => $change) {
            if (! in_array($storedKeys[$index], $nowKeys, true)) {
                $fields[] = (string) $change['field'];
            }
        }

        foreach ($now as $index => $change) {
            if (! in_array($nowKeys[$index], $storedKeys, true)) {
                $fields[] = (string) $change['field'];
            }
        }

        $fields = array_values(array_unique($fields));

        return $fields === [] ? $this->fieldsOf([...$stored, ...$now]) : $fields;
    }

    /**
     * The field names of a list of changes, never values or amounts.
     *
     * @param  list<array<string, mixed>>  $changes
     * @return list<string>
     */
    private function fieldsOf(array $changes): array
    {
        return array_values(array_unique(array_map(fn (array $change): string => (string) $change['field'], $changes)));
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function canonical(array $value): string
    {
        return (string) json_encode($this->sortKeys($value));
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function sortKeys(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortKeys($item);
            }
        }

        return $value;
    }
}
