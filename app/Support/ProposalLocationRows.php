<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ActivitySchedule;
use App\Models\PlanIndicator;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Support\Reporting\PlaceBridge;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The one writer of `proposal_locations` rows (contracts.md §7, W-34a).
 *
 * Every entry point expects the proposal row already locked by its caller
 * (SaveProposal) and runs inside that caller's transaction. It writes rows
 * and centers and returns the counts the caller records and dispatches; it
 * records nothing itself.
 *
 * A row is identified by its city and program: the unique index
 * `(proposal_id, city_id, program_id)` leaves "no program yet" rows alone,
 * so the application keeps at most one of those per city. After approval
 * only fillEmpty writes: centers or `city_wide` on a row that has neither
 * (D-83).
 *
 * Targets follow their row (Y-10): every change of a row's centers or
 * field-work flag calls PlanIndicator::syncPlaceFromRow() in the same
 * transaction, and a row that carries targets is never removed.
 */
final class ProposalLocationRows
{
    /**
     * The rows that keep the proposal from being approved: the one
     * definition of the `locations_complete` key (contracts.md §2). A row is
     * complete when it names a program and a responsible, and runs from at
     * least one center or is city-wide field work with no center (D-88).
     * Y-17: "stands for a project" is gone — a place IS a row (D-93), so
     * there is nothing for it to stand for.
     * A proposal without rows is complete: it approves as before W-34a.
     *
     * @return list<int>
     */
    public static function incompleteRowIds(Proposal $proposal): array
    {
        $ids = [];

        /** @var ProposalLocation $row */
        foreach ($proposal->locations()->with('centers')->orderBy('sort')->orderBy('id')->get() as $row) {
            $isComplete = $row->program_id !== null
                && $row->responsible_employee_id !== null
                && ($row->city_wide || $row->centers->isNotEmpty());

            if (! $isComplete) {
                $ids[] = $row->id;
            }
        }

        return $ids;
    }

    /**
     * Replace the proposal's rows with the normalised set the form sent.
     *
     * @param  list<array{city_id: int, program_id: int|null, responsible_employee_id: int|null, center_ids: list<int>, is_new: bool, city_wide?: bool}>  $rows
     * @return array{added: int, removed: int, changed: int}
     */
    public function replace(Proposal $locked, array $rows): array
    {
        $existing = $this->existingByKey($locked);
        $sentKeys = $this->keyed($rows);
        $counts = ['added' => 0, 'removed' => 0, 'changed' => 0];
        $kept = [];

        foreach ($existing as $key => $row) {
            if (! array_key_exists($key, $sentKeys)) {
                $this->refuseWhenRowInUse($row);
                $row->delete();
                $counts['removed']++;
                unset($existing[$key]);
            }
        }

        foreach ($rows as $sort => $sent) {
            $key = $this->key($sent['city_id'], $sent['program_id']);
            $row = $existing[$key] ?? null;

            if ($row === null) {
                $row = $this->insert($locked, $sent, $sort);
                $counts['added']++;
            } elseif ($this->update($row, $sent, $sort)) {
                $counts['changed']++;
            }

            $kept[$row->id] = true;
        }

        $next = count($rows);

        foreach ($existing as $row) {
            if (! isset($kept[$row->id]) && $row->sort !== $next) {
                $row->forceFill(['sort' => $next])->save();
            }

            if (! isset($kept[$row->id])) {
                $next++;
            }
        }

        return $counts;
    }

    /**
     * Fill the rows of an approved proposal (D-83, D-88): a row with no
     * center and not city-wide gains the sent centers or is marked
     * city-wide; every other row is left exactly as it is, and no row is
     * added, removed or linked to a project (D-74). SaveProposal has already
     * refused any other difference. `changed` counts the rows filled.
     *
     * @param  list<array{city_id: int|null, program_id: int|null, center_ids: list<int>, city_wide?: bool}>  $rows
     * @return array{added: int, removed: int, changed: int}
     */
    public function fillEmpty(Proposal $locked, array $rows): array
    {
        $existing = $this->existingByKey($locked);
        $counts = ['added' => 0, 'removed' => 0, 'changed' => 0];

        foreach ($rows as $sent) {
            $row = $existing[$this->key((int) $sent['city_id'], $sent['program_id'])] ?? null;

            if ($row === null || $row->city_wide || $row->centerIds() !== []) {
                continue;
            }

            if ($sent['city_wide'] ?? false) {
                $row->forceFill(['city_wide' => true])->save();
                PlanIndicator::syncPlaceFromRow($row);
                $counts['changed']++;
            } elseif ($sent['center_ids'] !== []) {
                $this->writeCenters($row, $sent['center_ids']);
                PlanIndicator::syncPlaceFromRow($row);
                $counts['changed']++;
            }
        }

        return $counts;
    }

    /**
     * The proposal's rows keyed by city and program.
     *
     * @return array<string, ProposalLocation>
     */
    private function existingByKey(Proposal $locked): array
    {
        /** @var Collection<int, ProposalLocation> $rows */
        $rows = $locked->locations()->with('centers')->orderBy('sort')->orderBy('id')->get();
        $byKey = [];

        foreach ($rows as $row) {
            $byKey[$this->key($row->city_id, $row->program_id)] = $row;
        }

        return $byKey;
    }

    /**
     * @param  list<array{city_id: int, program_id: int|null}>  $rows
     * @return array<string, true>
     */
    private function keyed(array $rows): array
    {
        $keys = [];

        foreach ($rows as $row) {
            $keys[$this->key($row['city_id'], $row['program_id'])] = true;
        }

        return $keys;
    }

    private function key(int $cityId, ?int $programId): string
    {
        return $cityId.':'.($programId ?? '');
    }

    /**
     * @param  array{city_id: int, program_id: int|null, responsible_employee_id: int|null, center_ids: list<int>, is_new: bool, city_wide?: bool}  $sent
     */
    private function insert(Proposal $locked, array $sent, int $sort): ProposalLocation
    {
        $row = new ProposalLocation([
            'proposal_id' => $locked->id,
            'city_id' => $sent['city_id'],
            'program_id' => $sent['program_id'],
            'responsible_employee_id' => $sent['responsible_employee_id'],
            'is_new' => $sent['is_new'],
            'city_wide' => $cityWide = $sent['city_wide'] ?? false,
            'sort' => $sort,
        ]);
        $row->save();
        $this->writeCenters($row, $cityWide ? [] : $sent['center_ids']);

        return $row;
    }

    /**
     * Bring a kept row in line with what was sent. Sort changes do not count.
     *
     * @param  array{program_id: int|null, responsible_employee_id: int|null, center_ids: list<int>, is_new: bool, city_wide?: bool}  $sent
     */
    private function update(ProposalLocation $row, array $sent, int $sort): bool
    {
        $changed = false;
        $isNew = $sent['is_new'];
        $cityWide = $sent['city_wide'] ?? false;
        $centerIds = $cityWide ? [] : $sent['center_ids'];
        $placeChanged = $row->city_wide !== $cityWide;

        if ($row->responsible_employee_id !== $sent['responsible_employee_id'] || $row->is_new !== $isNew || $row->city_wide !== $cityWide) {
            $row->forceFill(['responsible_employee_id' => $sent['responsible_employee_id'], 'is_new' => $isNew, 'city_wide' => $cityWide]);
            $changed = true;
        }

        if ($row->sort !== $sort) {
            $row->forceFill(['sort' => $sort]);
        }

        if ($row->isDirty()) {
            $row->save();
        }

        if ($row->centerIds() !== $centerIds) {
            $this->writeCenters($row, $centerIds);
            $changed = true;
            $placeChanged = true;
        }

        if ($placeChanged) {
            PlanIndicator::syncPlaceFromRow($row);
        }

        return $changed;
    }

    /**
     * A row that carries targets is never removed (Y-10): its targets would
     * lose their place, and the foreign key restricts it anyway. MEAL clears
     * the row's targets first.
     *
     * A row that is already being delivered is not removed either (Y-17):
     * deleting it would take its milestones, risks, reported figures and
     * report template with it (MediaOwnerObserver removes its files), and a
     * place that has started belongs in the archive, not in the bin. This
     * holds before approval too, because a row can carry a milestone or a
     * risk while the proposal is still a draft.
     *
     * @throws ValidationException under `locations`
     */
    private function refuseWhenRowInUse(ProposalLocation $row): void
    {
        if ($row->targets()->exists()) {
            throw ValidationException::withMessages(['locations' => __('proposals.location_has_targets')]);
        }

        $inUse = $row->risks()->exists()
            || ActivitySchedule::query()->where('proposal_location_id', $row->getKey())->exists()
            || app(PlaceBridge::class)->templateIdsOfRow($row) !== [];

        if ($inUse) {
            throw ValidationException::withMessages(['locations' => __('proposals.location_in_use')]);
        }
    }

    /**
     * Replace the centers of a row with the given ids, ascending.
     *
     * @param  list<int>  $centerIds
     */
    private function writeCenters(ProposalLocation $row, array $centerIds): void
    {
        sort($centerIds);
        $row->centers()->delete();

        foreach ($centerIds as $centerId) {
            $row->centers()->create(['center_id' => $centerId]);
        }

        $row->unsetRelation('centers');
    }
}
