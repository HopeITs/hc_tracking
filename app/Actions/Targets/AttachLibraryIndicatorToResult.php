<?php

declare(strict_types=1);

namespace App\Actions\Targets;

use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\Proposal;
use App\Models\Result;
use App\Support\ProposalFramework;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Attach a library entry to a node of a proposal's own framework, as a new
 * `indicators` instance (contracts §12.1, §11.1, D-10a).
 *
 * - Who: `indicators.create`. Terminal proposals are read-only.
 * - Scope: the result is loaded through the proposal's framework (BOLA); the
 *   entry is active and belongs to a program of the proposal's projects, or
 *   is institutional; the node's level equals the entry's suggested level
 *   when one is set.
 * - Copies only name, code and measure, and maps the frequency to a Period
 *   (calendar frequencies 1:1, the rest keep the column default).
 * - The same entry twice under one node is "already attached" (unique
 *   `(result_id, name)`).
 *
 * Error keys: proposal, library_indicator, result.
 */
final class AttachLibraryIndicatorToResult
{
    /**
     * @throws ValidationException
     */
    public function handle(Proposal $proposal, int $libraryIndicatorId, int $resultId): Indicator
    {
        Gate::authorize('indicators.create');

        return DB::transaction(function () use ($proposal, $libraryIndicatorId, $resultId): Indicator {
            $locked = Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status->isTerminal()) {
                $this->refuse('proposal', __('targets.proposal_read_only'));
            }

            $result = Result::query()->proposalFramework($locked->id)->whereKey($resultId)->firstOrFail();
            $entry = LibraryIndicator::query()->findOrFail($libraryIndicatorId);

            if (! $entry->is_active) {
                $this->refuse('library_indicator', __('targets.entry_inactive'));
            }

            if ($entry->program_id !== null && ! in_array($entry->program_id, ProposalFramework::programIds($locked), true)) {
                $this->refuse('library_indicator', __('targets.entry_outside_proposal_programs'));
            }

            if ($entry->suggested_level !== null && $entry->suggested_level !== $result->type) {
                $this->refuse('result', __('targets.level_incompatible', ['level' => $entry->suggested_level->label()]));
            }

            $attributes = [
                'name' => $entry->name_ar,
                'result_id' => $result->id,
                'library_indicator_id' => $entry->id,
                'code' => $entry->code,
                'measure' => $entry->measure,
            ];

            $period = $entry->frequency->period();

            if ($period !== null) {
                $attributes['frequency'] = $period;
            }

            if (Indicator::query()->where('result_id', $result->id)->where('name', $entry->name_ar)->exists()) {
                $this->refuse('library_indicator', __('targets.already_attached'));
            }

            try {
                return Indicator::query()->create($attributes);
            } catch (UniqueConstraintViolationException) {
                $this->refuse('library_indicator', __('targets.already_attached'));
            }
        });
    }

    /**
     * @throws ValidationException
     */
    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
