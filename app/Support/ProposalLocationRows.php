<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ProgramRequestStatus;
use App\Models\ProgramRequest;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The one writer of `proposal_locations` rows (contracts.md §7, W-34a).
 *
 * Every entry point expects the proposal row already locked by its caller
 * (SaveProposal, LinkProposalProject, UnlinkProposalProject, LinkProgramRequest,
 * the backfill)
 * and runs inside that caller's transaction. It writes rows and centers,
 * keeps the program request of a "needs a program" row in step, and returns
 * the counts the caller records and dispatches; it records nothing itself.
 *
 * A row is identified by its city and program: the unique index
 * `(proposal_id, city_id, program_id)` leaves "no program yet" rows alone,
 * so the application keeps at most one of those per city. Rows that carry a
 * project are owned by the link: a replacement updates them but never
 * deletes them, only UnlinkProposalProject does.
 */
final class ProposalLocationRows
{
    /**
     * The rows that keep the proposal from being approved: the one
     * definition of the `locations_complete` key (contracts.md §2). A row is
     * complete when it names a program and a responsible, stands for a
     * project or runs from at least one center (a project cannot be created
     * without a placement, W-01), and has no program request still pending.
     * A proposal without rows is complete: it approves as before W-34a.
     *
     * @return list<int>
     */
    public static function incompleteRowIds(Proposal $proposal): array
    {
        $ids = [];

        /** @var ProposalLocation $row */
        foreach ($proposal->locations()->with(['centers', 'programRequest'])->orderBy('sort')->orderBy('id')->get() as $row) {
            $isComplete = $row->program_id !== null
                && $row->responsible_employee_id !== null
                && ($row->project_id !== null || $row->centers->isNotEmpty())
                && ! ($row->programRequest?->isPending() ?? false);

            if (! $isComplete) {
                $ids[] = $row->id;
            }
        }

        return $ids;
    }

    /**
     * Replace the proposal's rows with the normalised set the form sent.
     *
     * @param  list<array{city_id: int, program_id: int|null, responsible_employee_id: int|null, center_ids: list<int>, is_new: bool, new_program_name_en: string, new_program_name_ar: string}>  $rows
     * @return array{added: int, removed: int, changed: int}
     */
    public function replace(Proposal $locked, array $rows, User $actor): array
    {
        $existing = $this->existingByKey($locked);
        $sentKeys = $this->keyed($rows);
        $counts = ['added' => 0, 'removed' => 0, 'changed' => 0];
        $kept = [];

        foreach ($existing as $key => $row) {
            if (! array_key_exists($key, $sentKeys) && $row->project_id === null) {
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
                $this->syncRequest($row, $sent, $actor);
                $counts['added']++;
            } elseif ($this->update($row, $sent, $sort, $actor)) {
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
     * Give a linked project its row: the row waiting on the project's city
     * and program is filled, else a new row is added with the project's
     * placement. Returns the counts of what changed.
     *
     * @return array{added: int, removed: int, changed: int}
     */
    public function attachProject(Proposal $locked, Project $project): array
    {
        $counts = ['added' => 0, 'removed' => 0, 'changed' => 0];

        if ($locked->locations()->where('project_id', $project->id)->exists()) {
            return $counts;
        }

        $row = $locked->locations()
            ->where('city_id', $project->city_id)
            ->where('program_id', $project->program_id)
            ->with('centers')
            ->first();

        $centerIds = $project->center_id === null ? [] : [(int) $project->center_id];

        if ($row === null) {
            $row = new ProposalLocation([
                'proposal_id' => $locked->id,
                'city_id' => $project->city_id,
                'program_id' => $project->program_id,
                'responsible_employee_id' => $project->user_id,
                'is_new' => false,
                'project_id' => $project->id,
                'sort' => (int) $locked->locations()->max('sort') + ($locked->locations()->exists() ? 1 : 0),
            ]);
            $row->save();
            $this->writeCenters($row, $centerIds);
            $counts['added']++;

            return $counts;
        }

        if ($row->project_id !== null) {
            // A second project on the same city and program (another center):
            // the row keeps its first project and gains the center.
            $this->writeCenters($row, array_values(array_unique([...$row->centerIds(), ...$centerIds])));
            $counts['changed']++;

            return $counts;
        }

        $row->forceFill([
            'project_id' => $project->id,
            'is_new' => false,
            'responsible_employee_id' => $row->responsible_employee_id ?? $project->user_id,
        ])->save();
        $this->writeCenters($row, array_values(array_unique([...$row->centerIds(), ...$centerIds])));
        $row->programRequest()->where('status', ProgramRequestStatus::Pending->value)->delete();
        $counts['changed']++;

        return $counts;
    }

    /**
     * Give a row waiting on a program request the HR program the admin
     * linked: the row takes the program, the request is marked created.
     * Refused when the proposal already has a row for that city and program
     * (the unique index would reject it). Returns the counts of what changed.
     *
     * @return array{added: int, removed: int, changed: int}
     *
     * @throws ValidationException under `programId`
     */
    public function linkRequestedProgram(Proposal $locked, ProgramRequest $request, int $programId): array
    {
        /** @var ProposalLocation $row */
        $row = $locked->locations()->whereKey($request->proposal_location_id)->firstOrFail();

        $isTaken = $locked->locations()
            ->where('city_id', $row->city_id)
            ->where('program_id', $programId)
            ->whereKeyNot($row->id)
            ->exists();

        if ($isTaken) {
            throw ValidationException::withMessages(['programId' => __('program_requests.program_already_on_proposal')]);
        }

        $row->forceFill(['program_id' => $programId])->save();
        $request->forceFill(['status' => ProgramRequestStatus::Created, 'hr_program_id' => $programId])->save();
        $row->unsetRelation('programRequest');

        return ['added' => 0, 'removed' => 0, 'changed' => 1];
    }

    /**
     * Remove the row of an unlinked project. Returns the counts of what changed.
     *
     * @return array{added: int, removed: int, changed: int}
     */
    public function detachProject(Proposal $locked, int $projectId): array
    {
        $removed = 0;

        foreach ($locked->locations()->where('project_id', $projectId)->get() as $row) {
            $row->delete();
            $removed++;
        }

        return ['added' => 0, 'removed' => $removed, 'changed' => 0];
    }

    /**
     * The proposal's rows keyed by city and program.
     *
     * @return array<string, ProposalLocation>
     */
    private function existingByKey(Proposal $locked): array
    {
        /** @var Collection<int, ProposalLocation> $rows */
        $rows = $locked->locations()->with(['centers', 'programRequest'])->orderBy('sort')->orderBy('id')->get();
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
     * @param  array{city_id: int, program_id: int|null, responsible_employee_id: int|null, center_ids: list<int>, is_new: bool}  $sent
     */
    private function insert(Proposal $locked, array $sent, int $sort): ProposalLocation
    {
        $row = new ProposalLocation([
            'proposal_id' => $locked->id,
            'city_id' => $sent['city_id'],
            'program_id' => $sent['program_id'],
            'responsible_employee_id' => $sent['responsible_employee_id'],
            'is_new' => $sent['is_new'],
            'sort' => $sort,
        ]);
        $row->save();
        $this->writeCenters($row, $sent['center_ids']);

        return $row;
    }

    /**
     * Bring a kept row in line with what was sent. A row that carries a
     * project stays linked and never new. Sort changes do not count.
     *
     * @param  array{program_id: int|null, responsible_employee_id: int|null, center_ids: list<int>, is_new: bool, new_program_name_en: string, new_program_name_ar: string}  $sent
     */
    private function update(ProposalLocation $row, array $sent, int $sort, User $actor): bool
    {
        $changed = false;
        $isNew = $row->project_id === null && $sent['is_new'];

        if ($row->responsible_employee_id !== $sent['responsible_employee_id'] || $row->is_new !== $isNew) {
            $row->forceFill(['responsible_employee_id' => $sent['responsible_employee_id'], 'is_new' => $isNew]);
            $changed = true;
        }

        if ($row->sort !== $sort) {
            $row->forceFill(['sort' => $sort]);
        }

        if ($row->isDirty()) {
            $row->save();
        }

        if ($row->centerIds() !== $sent['center_ids']) {
            $this->writeCenters($row, $sent['center_ids']);
            $changed = true;
        }

        return $this->syncRequest($row, $sent, $actor) || $changed;
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

    /**
     * Open, rename or drop the program request of a row: a new row without
     * a program needs one; any other row has none pending. Returns whether
     * anything changed.
     *
     * @param  array{program_id: int|null, is_new: bool, new_program_name_en: string, new_program_name_ar: string}  $sent
     */
    private function syncRequest(ProposalLocation $row, array $sent, User $actor): bool
    {
        $request = $row->programRequest()->first();
        $needsOne = $row->project_id === null && $sent['is_new'] && $sent['program_id'] === null;

        if (! $needsOne) {
            if ($request !== null && $request->isPending()) {
                $request->delete();
                $row->unsetRelation('programRequest');

                return true;
            }

            return false;
        }

        if ($request === null) {
            ProgramRequest::query()->create([
                'proposal_location_id' => $row->id,
                'name_en' => $sent['new_program_name_en'],
                'name_ar' => $sent['new_program_name_ar'],
                'requested_by' => $actor->id,
            ]);
            $row->unsetRelation('programRequest');

            return true;
        }

        if ($request->name_en !== $sent['new_program_name_en'] || $request->name_ar !== $sent['new_program_name_ar']) {
            $request->forceFill(['name_en' => $sent['new_program_name_en'], 'name_ar' => $sent['new_program_name_ar']])->save();

            return true;
        }

        return false;
    }
}
