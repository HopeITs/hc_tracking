<?php

declare(strict_types=1);

namespace App\Actions\Locations;

use App\Models\ProposalLocation;
use App\Models\Sector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Set the sectors a location row covers (contracts.md §12.5, D-47). The one
 * writer of `proposal_location_sector`, and the twin of the former
 * SyncProjectSectors moved onto the row (Y-17).
 *
 * Only active sectors can be chosen; an inactive one the row already has may
 * stay. With `$requireOne` at least one is required. The whole batch is
 * refused when any id is not a usable sector, and a change is recorded as
 * `project.sectors_changed` with sector names only.
 */
final class SyncLocationSectors
{
    /**
     * @param  list<int>  $sectorIds
     *
     * @throws ValidationException under the `sectorIds` key
     */
    public function handle(ProposalLocation $row, array $sectorIds, bool $requireOne = false): void
    {
        Gate::authorize('update', $row);

        $chosen = $this->normalize($sectorIds);

        if ($requireOne && $chosen === []) {
            throw ValidationException::withMessages(['sectorIds' => __('sectors.choose_at_least_one')]);
        }

        DB::transaction(function () use ($row, $chosen): void {
            $locked = ProposalLocation::query()->whereKey($row->getKey())->lockForUpdate()->firstOrFail();

            /** @var list<int> $current */
            $current = array_values($locked->sectors()->pluck('sectors.id')->map(fn (mixed $id): int => (int) $id)->all());

            $usable = Sector::query()
                ->whereIn('id', $chosen)
                ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $current))
                ->sharedLock()
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            if (count($usable) !== count($chosen)) {
                throw ValidationException::withMessages(['sectorIds' => __('sectors.unavailable')]);
            }

            $result = $locked->sectors()->sync($chosen);

            /** @var list<int> $added */
            $added = array_map(intval(...), $result['attached']);
            /** @var list<int> $removed */
            $removed = array_map(intval(...), $result['detached']);

            if ($added === [] && $removed === []) {
                return;
            }

            $names = Sector::query()->whereIn('id', [...$added, ...$removed])->ordered()->pluck('name_ar', 'id');
            $addedNames = array_values($names->only($added)->all());
            $removedNames = array_values($names->only($removed)->all());

            $locked->recordActivity('project.sectors_changed', properties: [
                'added' => $addedNames === [] ? '—' : implode('، ', $addedNames),
                'removed' => $removedNames === [] ? '—' : implode('، ', $removedNames),
                'added_sectors' => $addedNames,
                'removed_sectors' => $removedNames,
            ]);
        });

        $row->unsetRelation('sectors');
    }

    /**
     * Distinct positive integer ids; anything else refuses the whole batch.
     *
     * @param  array<mixed>  $sectorIds
     * @return list<int>
     */
    private function normalize(array $sectorIds): array
    {
        $ids = [];

        foreach ($sectorIds as $id) {
            $value = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($value === false) {
                throw ValidationException::withMessages(['sectorIds' => __('sectors.unavailable')]);
            }

            $ids[$value] = $value;
        }

        return array_values($ids);
    }
}
