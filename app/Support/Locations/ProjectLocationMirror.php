<?php

declare(strict_types=1);

namespace App\Support\Locations;

use App\Enums\AssignmentStatus;
use App\Enums\ProjectStatus;
use App\Models\Assignment;
use App\Models\Media;
use App\Models\Project;
use App\Models\ProposalLocation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Keep every location row a faithful copy of the project it carries, while
 * `projects` is still the writer (Y-11 slice 2, D-92, D-93, wave4/AGENT.md §8.2).
 *
 * This class holds **the** definition of what a row copies: status, period,
 * calendar, description, the five beneficiary targets, sectors, the owner's
 * acknowledgement and the files. `LocationBackfill` runs it over history and
 * every project writer calls it at the end of its transaction, so the rule
 * exists once and a row can never drift by a route nobody updated.
 *
 * What goes to **every** row of a project describes the place and has no
 * date: the calendar, the description, the targets, the sectors. What has a
 * date goes to exactly one row, chosen by ProjectSuccessor (D-94): a file by
 * its own date, the owner's acknowledgement to the row of the most recent
 * proposal.
 *
 * Nothing here is user-facing yet. Until Y-16 moves the pages, `projects` is
 * what every screen reads, so the mirror only ever **adds** to the rows and
 * never takes anything off a project (§8.3).
 *
 * @see docs/lifecycle/contracts.md §7
 * @deprecated Y-17 makes the row the writer and this class goes away with it.
 */
final class ProjectLocationMirror
{
    /**
     * The project columns a row copies. The mirror re-reads the project when
     * a caller passes a model that was loaded with a narrower select, so no
     * writer has to know what the mirror needs (`Model::shouldBeStrict` would
     * otherwise raise MissingAttributeException — W-34a's own backfill
     * selects six columns).
     */
    private const ProjectColumns = [
        'status',
        'start_date',
        'end_date',
        'schedule_weekend',
        'working_days',
        'description',
        'target_beneficiaries',
        'target_men',
        'target_women',
        'target_boys',
        'target_girls',
        'user_id',
    ];

    public function __construct(private ProjectSuccessor $successor) {}

    /**
     * The project with every column a row copies, re-read only when the one
     * passed in is missing any of them (so a full model costs no query).
     */
    private function complete(Project $project): Project
    {
        $loaded = $project->getAttributes();

        foreach (self::ProjectColumns as $column) {
            if (! array_key_exists($column, $loaded)) {
                return Project::query()->whereKey($project->getKey())->firstOrFail();
            }
        }

        return $project;
    }

    /**
     * The contract entry (contracts.md §7): bring every row carrying the
     * project back in step. Call it inside the writer's transaction, after
     * the project row is saved.
     */
    public function sync(Project $project): void
    {
        $this->syncColumns($project);
    }

    /**
     * Sectors on every row of the project. Called by SyncProjectSectors, the
     * only writer of `project_sector` (contracts.md §12.5), so the rows follow
     * a sector added or removed without every other project write paying for
     * the comparison.
     */
    public function syncSectors(Project $project): void
    {
        $rows = $this->successor->rowsFor($project->getKey());

        if ($rows->isNotEmpty()) {
            $this->writeSectors($project, $rows->all());
        }
    }

    /**
     * The owner's acknowledgement on every row. Called from
     * `Project::assignmentResponded()` — an accept or a reject — so the rows
     * carry the decision without every project write re-reading assignments.
     */
    public function syncAcknowledgement(Project $project): void
    {
        $rows = $this->successor->rowsFor($project->getKey());

        if ($rows->isNotEmpty()) {
            $this->writeAcknowledgement($this->complete($project), array_values($rows->all()), $rows->first());
        }
    }

    /**
     * sync() with the counts the backfill reports. Same work, same rules —
     * the backfill needs to tally what it wrote and the contract fixes
     * `sync(): void`, so the counting version lives here rather than being a
     * second copy of the rules over in LocationBackfill.
     *
     * @return array{
     *     rows_written: int,
     *     rows_by_status: array<string, int>,
     *     rows: int,
     *     sectors_copied: int,
     *     acknowledgements_copied: int,
     *     files_mirrored: int,
     *     files_by_date: int,
     *     files_by_most_recent: int,
     *     slugs_written: int,
     * }
     */
    public function apply(Project $project): array
    {
        [$written, $byStatus, $rowCount, $slugs] = $this->syncColumns($project);

        $counts = [
            'rows_written' => $written,
            'rows_by_status' => $byStatus,
            'rows' => $rowCount,
            'sectors_copied' => 0,
            'acknowledgements_copied' => 0,
            'files_mirrored' => 0,
            'files_by_date' => 0,
            'files_by_most_recent' => 0,
            'slugs_written' => $slugs,
        ];

        if ($rowCount === 0) {
            return $counts;
        }

        $project = $this->complete($project);
        $rows = $this->successor->rowsFor($project->getKey());
        $mostRecent = $rows->first();

        $counts['sectors_copied'] = $this->writeSectors($project, $rows->all());
        $counts['acknowledgements_copied'] = $this->writeAcknowledgement($project, array_values($rows->all()), $mostRecent);

        [$files, $byDate, $byRecent] = $this->mirrorFiles($project);
        $counts['files_mirrored'] = $files;
        $counts['files_by_date'] = $byDate;
        $counts['files_by_most_recent'] = $byRecent;

        return $counts;
    }

    /**
     * The project's own columns onto every row that carries it: status,
     * period, calendar, description and the five beneficiary targets.
     *
     * This is what `sync()` does, and it is deliberately the cheap part —
     * three queries for a project with one row (the rows, their proposals,
     * one update). Sectors, the acknowledgement and the files are synced by
     * the writers that change them (`syncSectors()`, `syncAcknowledgement()`,
     * `attachFile()`/`detachFile()`), so an ordinary project save does not
     * re-read all of them. `locations:verify-mirror` still checks every
     * facet, so a writer that skips its own is caught rather than trusted.
     *
     * @return array{0: int, 1: array<string, int>, 2: int, 3: int} rows written, by status, rows, slugs
     */
    private function syncColumns(Project $project): array
    {
        $rows = $this->successor->rowsFor($project->getKey());

        if ($rows->isEmpty()) {
            return [0, [], 0, 0];
        }

        $project = $this->complete($project);
        $mostRecent = $rows->first();
        $beneficiaries = $this->beneficiariesByProposal($project);

        $written = 0;
        $byStatus = [];
        $slugs = 0;

        foreach ($rows as $row) {
            $status = $this->statusFor($project, $row, $mostRecent);

            [$start, $end] = $this->periodFor($project, $row);

            $row->forceFill([
                'status' => $status->value,
                'start_date' => $start?->toDateString(),
                'end_date' => $end?->toDateString(),
                'schedule_weekend' => $project->schedule_weekend,
                'working_days' => $project->working_days,
                'description' => $project->description,
                'target_beneficiaries' => $beneficiaries[$row->proposal_id] ?? $project->target_beneficiaries,
                'target_men' => $project->target_men,
                'target_women' => $project->target_women,
                'target_boys' => $project->target_boys,
                'target_girls' => $project->target_girls,
            ]);

            if ($row->slug === null) {
                $row->slug = $this->uniqueSlug($row);
                $slugs++;
            }

            // Only a row this call actually changes is written, so a second
            // call costs nothing and a dry run reports the real work.
            if ($row->isDirty()) {
                $row->saveQuietly();

                $written++;
                $byStatus[$status->value] = ($byStatus[$status->value] ?? 0) + 1;
            }
        }

        return [$written, $byStatus, $rows->count(), $slugs];
    }

    /**
     * A file just added to a project goes to the one row its date picks, on
     * top of staying on the project (SaveProjectFile).
     */
    public function attachFile(Project $project, Media $file): void
    {
        $this->mirrorOneFile($project, $file);
    }

    /**
     * A file removed from a project takes its mirrored row with it
     * (DeleteProjectFile). Matched on the stored path, which is what the two
     * rows share.
     */
    public function detachFile(Project $project, Media $file): void
    {
        Media::query()
            ->where('mediable_type', 'proposal_location')
            ->where('path', $file->path)
            ->whereIn('mediable_id', $this->successor->rowsFor($project->getKey())->pluck('id'))
            ->delete();
    }

    /**
     * Every way a row disagrees with the project it carries, in words, for
     * `locations:verify-mirror`. An empty list means the mirror is clean.
     *
     * @return list<string>
     */
    public function differences(Project $project): array
    {
        $rows = $this->successor->rowsFor($project->getKey());

        if ($rows->isEmpty()) {
            return [];
        }

        $project = $this->complete($project);
        $mostRecent = $rows->first();
        $beneficiaries = $this->beneficiariesByProposal($project);
        $projectSectors = $this->projectSectorIds($project);
        $differences = [];

        foreach ($rows as $row) {
            $expected = [
                'status' => $this->statusFor($project, $row, $mostRecent)->value,
                'schedule_weekend' => $project->schedule_weekend,
                'description' => $project->description,
                'target_beneficiaries' => $beneficiaries[$row->proposal_id] ?? $project->target_beneficiaries,
                'target_men' => $project->target_men,
                'target_women' => $project->target_women,
                'target_boys' => $project->target_boys,
                'target_girls' => $project->target_girls,
            ];

            foreach ($expected as $column => $want) {
                $has = $column === 'status' ? $row->status->value : $row->{$column};

                if ($has != $want) {
                    $differences[] = $this->line($project, $row, $column, $want, $has);
                }
            }

            [$start, $end] = $this->periodFor($project, $row);

            if ($row->start_date?->toDateString() !== $start?->toDateString()) {
                $differences[] = $this->line($project, $row, 'start_date', $start?->toDateString(), $row->start_date?->toDateString());
            }

            if ($row->end_date?->toDateString() !== $end?->toDateString()) {
                $differences[] = $this->line($project, $row, 'end_date', $end?->toDateString(), $row->end_date?->toDateString());
            }

            if ($this->normalised($row->working_days) !== $this->normalised($project->working_days)) {
                $differences[] = $this->line($project, $row, 'working_days', $this->readable($project->working_days), $this->readable($row->working_days));
            }

            $rowSectors = array_values(DB::table('proposal_location_sector')
                ->where('proposal_location_id', $row->id)
                ->pluck('sector_id')
                ->map(intval(...))
                ->sort()
                ->values()
                ->all());

            if ($rowSectors !== $projectSectors) {
                $differences[] = $this->line($project, $row, 'sectors', implode(',', $projectSectors), implode(',', $rowSectors));
            }

            if ($row->slug === null) {
                $differences[] = $this->line($project, $row, 'slug', 'a slug', 'none');
            }
        }

        $projectAssignment = $this->projectAssignment($project);

        if ($projectAssignment !== null) {
            $onRows = Assignment::query()
                ->where('assignable_type', 'proposal_location')
                ->where('field', 'responsible_employee_id')
                ->whereIn('assignable_id', $rows->pluck('id'))
                ->pluck('assignable_id')
                ->map(intval(...))
                ->all();

            foreach ($rows as $row) {
                if (! in_array($row->id, $onRows, true)) {
                    $differences[] = $this->line($project, $row, 'owner acknowledgement', 'an assignment row', 'none');
                }
            }
        }

        return $differences;
    }

    /**
     * The status of one row: the project's status on the row of its most
     * recent proposal; a row of an earlier proposal is `closed` once that
     * proposal's period has ended, else the project's status. Archived stays
     * archived everywhere.
     */
    private function statusFor(Project $project, ProposalLocation $row, ProposalLocation $mostRecent): ProjectStatus
    {
        if ($project->status === ProjectStatus::Archived) {
            return ProjectStatus::Archived;
        }

        if ($row->is($mostRecent)) {
            return $project->status;
        }

        $end = $this->proposalEnd($row);

        return $end !== null && $end->isPast() ? ProjectStatus::Closed : $project->status;
    }

    /**
     * The row's period: its own proposal's period clipped to the project's
     * dates, and the project's dates when the proposal has none.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function periodFor(Project $project, ProposalLocation $row): array
    {
        $projectStart = $project->start_date;
        $projectEnd = $project->end_date;

        $proposalStart = $this->proposalBegin($row);
        $proposalEnd = $this->proposalEnd($row);

        if ($proposalStart === null && $proposalEnd === null) {
            return [$projectStart, $projectEnd];
        }

        $start = $proposalStart ?? $projectStart;
        $end = $proposalEnd ?? $projectEnd;

        if ($start !== null && $projectStart !== null && $start->lt($projectStart)) {
            $start = $projectStart;
        }

        if ($end !== null && $projectEnd !== null && $end->gt($projectEnd)) {
            $end = $projectEnd;
        }

        return [$start, $end];
    }

    private function proposalBegin(ProposalLocation $row): ?CarbonImmutable
    {
        $proposal = $row->proposal;

        if ($proposal === null) {
            return null;
        }

        return $proposal->actual_date_begin ?? $proposal->planned_date_begin;
    }

    private function proposalEnd(ProposalLocation $row): ?CarbonImmutable
    {
        $proposal = $row->proposal;

        if ($proposal === null) {
            return null;
        }

        return $proposal->effectiveDateEnd() ?? $proposal->planned_date_end;
    }

    /**
     * `project_proposal.target_beneficiaries` per proposal of the project.
     *
     * @return array<int, int>
     */
    private function beneficiariesByProposal(Project $project): array
    {
        return DB::table('project_proposal')
            ->where('project_id', $project->getKey())
            ->whereNotNull('target_beneficiaries')
            ->pluck('target_beneficiaries', 'proposal_id')
            ->map(fn (mixed $value): int => (int) $value)
            ->all();
    }

    /**
     * @return list<int>
     */
    private function projectSectorIds(Project $project): array
    {
        return array_values(DB::table('project_sector')
            ->where('project_id', $project->getKey())
            ->pluck('sector_id')
            ->map(intval(...))
            ->sort()
            ->values()
            ->all());
    }

    /**
     * Sectors describe the place, so every row of the project gets all of
     * them, and a sector taken off the project is taken off the rows too
     * (SyncProjectSectors is the project's only sector writer).
     *
     * @param  array<int, ProposalLocation>  $rows
     * @return int the pairs written
     */
    private function writeSectors(Project $project, array $rows): int
    {
        $sectorIds = $this->projectSectorIds($project);
        $rowIds = array_map(fn (ProposalLocation $row): int => $row->id, $rows);

        $existing = DB::table('proposal_location_sector')
            ->whereIn('proposal_location_id', $rowIds)
            ->get(['proposal_location_id', 'sector_id']);

        $written = 0;
        $insert = [];

        foreach ($rows as $row) {
            $has = array_values($existing
                ->where('proposal_location_id', $row->id)
                ->pluck('sector_id')
                ->map(intval(...))
                ->all());

            foreach (array_diff($sectorIds, $has) as $sectorId) {
                $insert[] = [
                    'proposal_location_id' => $row->id,
                    'sector_id' => $sectorId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $written++;
            }

            $stale = array_diff($has, $sectorIds);

            if ($stale !== []) {
                DB::table('proposal_location_sector')
                    ->where('proposal_location_id', $row->id)
                    ->whereIn('sector_id', $stale)
                    ->delete();
            }
        }

        if ($insert !== []) {
            DB::table('proposal_location_sector')->insert($insert);
        }

        return $written;
    }

    /**
     * The project's standing owner assignment, or null when nobody was asked.
     */
    private function projectAssignment(Project $project): ?Assignment
    {
        return Assignment::query()
            ->where('assignable_type', 'project')
            ->where('assignable_id', $project->getKey())
            ->where('field', 'user_id')
            ->where('status', '!=', AssignmentStatus::Superseded->value)
            ->latest('id')
            ->first();
    }

    /**
     * The owner's acknowledgement is not duplicated as a decision: the row of
     * the most recent proposal carries the project's current assignment, and
     * the earlier rows are written as acknowledged at the same moment when
     * the project was (D-94).
     *
     * A row that already has an assignment has its status brought in step,
     * so an acknowledge or reject on the project reaches the rows.
     *
     * @param  list<ProposalLocation>  $rows
     * @return int the assignment rows written
     */
    private function writeAcknowledgement(Project $project, array $rows, ProposalLocation $mostRecent): int
    {
        $assignment = $this->projectAssignment($project);

        if ($assignment === null) {
            return 0;
        }

        $existing = Assignment::query()
            ->where('assignable_type', 'proposal_location')
            ->where('field', 'responsible_employee_id')
            ->whereIn('assignable_id', array_map(fn (ProposalLocation $row): int => $row->id, $rows))
            ->get()
            ->keyBy('assignable_id');

        $written = 0;

        foreach ($rows as $row) {
            $status = $row->is($mostRecent)
                ? $assignment->status
                : ($assignment->status === AssignmentStatus::Rejected ? AssignmentStatus::Rejected : AssignmentStatus::Acknowledged);

            $already = $existing->get($row->id);

            if ($already !== null) {
                if ($already->status !== $status) {
                    $already->forceFill([
                        'status' => $status->value,
                        'responded_at' => $status === AssignmentStatus::Pending ? null : ($assignment->responded_at ?? now()),
                        'responded_by' => $status === AssignmentStatus::Pending ? null : $assignment->responded_by,
                    ])->save();

                    $written++;
                }

                continue;
            }

            Assignment::query()->create([
                'assignable_type' => 'proposal_location',
                'assignable_id' => $row->id,
                'field' => 'responsible_employee_id',
                'employee_id' => $row->responsible_employee_id ?? (int) $project->user_id,
                'assigned_by' => $assignment->assigned_by,
                'status' => $status->value,
                'seen_at' => $assignment->seen_at,
                'responded_at' => $status === AssignmentStatus::Pending ? null : $assignment->responded_at,
                'responded_by' => $status === AssignmentStatus::Pending ? null : $assignment->responded_by,
                'response_note' => $row->is($mostRecent) ? $assignment->response_note : null,
            ]);

            $written++;
        }

        return $written;
    }

    /**
     * Every file of the project gets a mirrored row on the one row its own
     * date picks (D-94) — never on every row, which is what "a file is not
     * copied" rules out. The project keeps its own row: until Y-16 moves the
     * pages, the project Files tab is what users see (§8.2, §8.3). Both rows
     * point at the same stored object; nothing is written to disk.
     *
     * @return array{0: int, 1: int, 2: int} mirrored, by date, by most recent
     */
    private function mirrorFiles(Project $project): array
    {
        $files = Media::query()
            ->where('mediable_type', 'project')
            ->where('mediable_id', $project->getKey())
            ->get();

        $mirrored = 0;
        $byDate = 0;
        $byRecent = 0;

        foreach ($files as $file) {
            $decidedByDate = $this->mirrorOneFile($project, $file);

            if ($decidedByDate === null) {
                continue;
            }

            $mirrored++;
            $decidedByDate ? $byDate++ : $byRecent++;
        }

        return [$mirrored, $byDate, $byRecent];
    }

    /**
     * Mirror one file. Returns whether its own date decided the row, or null
     * when there was nothing to do.
     */
    private function mirrorOneFile(Project $project, Media $file): ?bool
    {
        $date = $file->created_at === null ? null : CarbonImmutable::parse($file->created_at);
        [$row, $decidedByDate] = $this->successor->rowAndReasonFor($project->getKey(), $date);

        if ($row === null) {
            return null;
        }

        $alreadyThere = Media::query()
            ->where('mediable_type', 'proposal_location')
            ->where('mediable_id', $row->id)
            ->where('path', $file->path)
            ->exists();

        if ($alreadyThere) {
            return null;
        }

        $mirroredFile = $file->replicate(['mediable_type', 'mediable_id']);
        $mirroredFile->mediable_type = 'proposal_location';
        $mirroredFile->mediable_id = $row->id;
        $mirroredFile->created_at = $file->created_at;
        $mirroredFile->updated_at = $file->updated_at;
        $mirroredFile->saveQuietly();

        return $decidedByDate;
    }

    /**
     * The row's slug, unique with a numeric suffix.
     *
     * The proposal number is not read off `$row->proposal`: ProjectSuccessor
     * loads that relation with a narrow select, so the column is absent and
     * strict mode raises MissingAttributeException. A caller that already has
     * it passes it as a hint.
     */
    private function uniqueSlug(ProposalLocation $row, ?string $numberHint = null): string
    {
        $number = $numberHint ?? DB::table('proposals')->where('id', $row->proposal_id)->value('internal_project_number');

        $row->loadMissing(['city', 'program']);

        $parts = array_filter([
            $number,
            $row->city?->name,
            $row->program_id === null ? null : $row->program?->name,
        ]);

        $base = Str::slug($parts === [] ? 'location-'.$row->proposal_id : implode(' ', $parts));
        $base = $base === '' ? 'location-'.$row->id : $base;

        $slug = $base;
        $suffix = 1;

        while (ProposalLocation::query()->where('slug', $slug)->whereKeyNot($row->id)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }

    /**
     * @param  list<int>|null  $days
     * @return list<int>
     */
    private function normalised(?array $days): array
    {
        if ($days === null) {
            return [];
        }

        $values = array_values(array_unique(array_map(intval(...), $days)));
        sort($values);

        return $values;
    }

    /**
     * @param  list<int>|null  $days
     */
    private function readable(?array $days): string
    {
        return $days === null ? 'the global calendar' : implode(',', $this->normalised($days));
    }

    private function line(Project $project, ProposalLocation $row, string $column, mixed $want, mixed $has): string
    {
        return sprintf(
            'project %d, row %d: %s should be %s but is %s',
            $project->getKey(),
            $row->id,
            $column,
            $want === null || $want === '' ? 'empty' : (string) $want,
            $has === null || $has === '' ? 'empty' : (string) $has,
        );
    }
}
