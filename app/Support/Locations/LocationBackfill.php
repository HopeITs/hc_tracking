<?php

declare(strict_types=1);

namespace App\Support\Locations;

use App\Models\Activity;
use App\Models\Project;
use App\Models\ProposalLocation;
use Illuminate\Support\Facades\DB;

/**
 * Make every location row a faithful copy of the project it carries
 * (Y-11, D-92, D-93, D-94). The one definition behind
 * `locations:backfill-from-projects`.
 *
 * It owns no rule of its own: what a row copies lives in
 * ProjectLocationMirror, which every project writer also calls, so history
 * and every write from now on follow the same definition. This class only
 * decides **which** projects to run over and tallies what was written.
 *
 * A project no row carries is reported and left alone (D-80): it needs a
 * proposal first (D-84, D-87), which is a human step. Records no activity and
 * dispatches nothing: this is history, not news. Safe to re-run — the mirror
 * writes only what differs.
 *
 * @deprecated Y-18 deletes this class with the `projects` table.
 */
final class LocationBackfill
{
    public function __construct(private ProjectLocationMirror $mirror) {}

    private int $rowsFilled = 0;

    /** @var array<string, int> */
    private array $rowsByStatus = [];

    /** @var list<int> */
    private array $projectsOnSeveralRows = [];

    private int $filesMirrored = 0;

    private int $filesByDate = 0;

    private int $filesByMostRecent = 0;

    private int $sectorsCopied = 0;

    private int $acknowledgementsCopied = 0;

    private int $slugsWritten = 0;

    /** @var array<int, string|null>|null */
    private ?array $proposalNumbers = null;

    /**
     * Run the backfill, writing only when $apply is true; a dry run does the
     * same work inside a transaction it rolls back, so its counts are exact
     * (the shape ProposalLocationBackfill established for W-34a).
     *
     * @return array{
     *     rows_filled: int,
     *     rows_by_status: array<string, int>,
     *     projects_on_several_rows: list<int>,
     *     projects_without_row: list<int>,
     *     files_mirrored: int,
     *     files_by_date: int,
     *     files_by_most_recent: int,
     *     sectors_copied: int,
     *     acknowledgements_copied: int,
     *     slugs_written: int,
     * }
     */
    public function run(bool $apply): array
    {
        $this->reset();
        $projectsWithoutRow = $this->projectsWithoutRow();

        DB::beginTransaction();

        try {
            Activity::withoutRecording(function (): void {
                foreach ($this->carriedProjectIds() as $projectId) {
                    $this->fillProject($projectId);
                }

                $this->fillRowsWithoutProject();
            });

            $apply ? DB::commit() : DB::rollBack();
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        return [
            'rows_filled' => $this->rowsFilled,
            'rows_by_status' => $this->rowsByStatus,
            'projects_on_several_rows' => $this->projectsOnSeveralRows,
            'projects_without_row' => $projectsWithoutRow,
            'files_mirrored' => $this->filesMirrored,
            'files_by_date' => $this->filesByDate,
            'files_by_most_recent' => $this->filesByMostRecent,
            'sectors_copied' => $this->sectorsCopied,
            'acknowledgements_copied' => $this->acknowledgementsCopied,
            'slugs_written' => $this->slugsWritten,
        ];
    }

    /**
     * Start from zero, so the same instance can run twice (the dry run the
     * second Y-11 migration makes, then the apply).
     */
    private function reset(): void
    {
        $this->rowsFilled = 0;
        $this->rowsByStatus = [];
        $this->projectsOnSeveralRows = [];
        $this->filesMirrored = 0;
        $this->filesByDate = 0;
        $this->filesByMostRecent = 0;
        $this->sectorsCopied = 0;
        $this->acknowledgementsCopied = 0;
        $this->slugsWritten = 0;
        $this->proposalNumbers = null;
    }

    /**
     * Run the mirror over one project and tally what it wrote.
     */
    private function fillProject(int $projectId): void
    {
        $project = Project::query()->whereKey($projectId)->lockForUpdate()->first();

        if ($project === null) {
            return;
        }

        $counts = $this->mirror->apply($project);

        if ($counts['rows'] > 1) {
            $this->projectsOnSeveralRows[] = $projectId;
        }

        $this->rowsFilled += $counts['rows_written'];
        $this->slugsWritten += $counts['slugs_written'];
        $this->sectorsCopied += $counts['sectors_copied'];
        $this->acknowledgementsCopied += $counts['acknowledgements_copied'];
        $this->filesMirrored += $counts['files_mirrored'];
        $this->filesByDate += $counts['files_by_date'];
        $this->filesByMostRecent += $counts['files_by_most_recent'];

        foreach ($counts['rows_by_status'] as $status => $count) {
            $this->rowsByStatus[$status] = ($this->rowsByStatus[$status] ?? 0) + $count;
        }
    }

    /**
     * A row whose project is not set is a row still waiting for one: it keeps
     * `draft` and no dates. It only needs a slug, and HasSlug writes that.
     */
    private function fillRowsWithoutProject(): void
    {
        ProposalLocation::query()
            ->whereNull('project_id')
            ->whereNull('slug')
            ->with(['city', 'program'])
            ->each(function (ProposalLocation $row): void {
                $row->slug = $this->slugFor($row);
                $row->saveQuietly();
                $this->slugsWritten++;
            });
    }

    /**
     * The slug of a row with no project, from the same parts the mirror uses.
     */
    private function slugFor(ProposalLocation $row): string
    {
        $number = $this->proposalNumbers()[$row->proposal_id] ?? null;

        $parts = array_filter([
            $number,
            $row->city?->name,
            $row->program_id === null ? null : $row->program?->name,
        ]);

        $base = \Str::slug($parts === [] ? 'location-'.$row->proposal_id : implode(' ', $parts));
        $base = $base === '' ? 'location-'.$row->id : $base;

        $slug = $base;
        $suffix = 1;

        while (ProposalLocation::query()->where('slug', $slug)->whereKeyNot($row->id)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }

    /**
     * `proposals.internal_project_number` by proposal id, read once per run.
     *
     * @return array<int, string|null>
     */
    private function proposalNumbers(): array
    {
        return $this->proposalNumbers ??= DB::table('proposals')
            ->pluck('internal_project_number', 'id')
            ->all();
    }

    /**
     * The ids of the projects at least one row carries, ascending.
     *
     * @return list<int>
     */
    private function carriedProjectIds(): array
    {
        return array_values(ProposalLocation::query()
            ->whereNotNull('project_id')
            ->distinct()
            ->orderBy('project_id')
            ->pluck('project_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }

    /**
     * The projects no row carries: reported, never guessed (D-80). Each needs
     * a proposal first (D-84; an internal-donor one when self-funded, D-87).
     *
     * @return list<int>
     */
    private function projectsWithoutRow(): array
    {
        $carried = $this->carriedProjectIds();

        return array_values(Project::query()
            ->when($carried !== [], fn ($query) => $query->whereNotIn('id', $carried))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }
}
