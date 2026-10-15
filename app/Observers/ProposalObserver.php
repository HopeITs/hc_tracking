<?php

declare(strict_types=1);

namespace App\Observers;

use App\Exceptions\InvalidParentException;
use App\Models\Proposal;

/**
 * Keeps the renewal chain (proposals.previous_proposal_id) free of cycles.
 *
 * The foreign key stops a dangling link but not a proposal pointing at itself
 * or at one of its own successors. The guard walks up from the chosen
 * predecessor, one query per level, and refuses when it meets the proposal
 * being saved. It runs only when the link changes to a non-null value, so
 * detaching and untouched saves stay cheap. A raw query-builder write bypasses
 * it: move proposals through Eloquent.
 */
final class ProposalObserver
{
    /**
     * @throws InvalidParentException
     */
    public function saving(Proposal $proposal): void
    {
        if (! $proposal->isDirty('previous_proposal_id') || $proposal->previous_proposal_id === null) {
            return;
        }

        $cursor = $proposal->previous_proposal_id;
        $seen = [];

        while ($cursor !== null && ! in_array($cursor, $seen, true)) {
            if ($proposal->exists && $cursor === $proposal->id) {
                throw InvalidParentException::proposalCycle();
            }

            $seen[] = $cursor;
            $next = Proposal::query()->whereKey($cursor)->value('previous_proposal_id');
            $cursor = $next === null ? null : (int) $next;
        }
    }
}
