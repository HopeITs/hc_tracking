<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\ReportTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one wave 4 class that may name `Project` (D-74, D-85, contracts.md
 * §12.4): it answers "which place?" for things that still hang on a project.
 * Everything else asks it and never reads `projects` itself.
 */
final class PlaceBridge
{
    /**
     * The place of a target, read from its own columns (Y-08, D-85): no
     * `projects` read is left in it. Null for a target with no place and for a
     * shared target (D-90).
     */
    public function placeOfTarget(PlanIndicator $target): ?ReportPlace
    {
        return $target->place();
    }

    /**
     * Whether the target reports at the place: its own place, or a shared
     * target (D-90) of a row that lists the place's center. No `projects`
     * read is left in it (Y-08).
     */
    public function targetCoversPlace(PlanIndicator $target, ReportPlace $place): bool
    {
        return $target->coversPlace($place);
    }

    /**
     * Whether the target is shared (D-90): reached together by every center of
     * its proposal's location row. Such a target has no single place. No
     * `projects` read is left in it (Y-08).
     */
    public function isSharedTarget(PlanIndicator $target): bool
    {
        return $target->all_centers;
    }

    /**
     * `project_proposal.target_beneficiaries` of a proposal target's project
     * (D-25), the source of a `fixed` line's denominator. Since Y-10 a
     * proposal target's `project_id` is its location row's project, so this
     * reads the row's beneficiaries.
     *
     * Removed by Y-11, when the location row carries its beneficiaries.
     */
    public function targetBeneficiariesOf(PlanIndicator $target): ?int
    {
        if ($target->project_id === null || $target->source_type !== 'proposal') {
            return null;
        }

        $value = DB::table('project_proposal')
            ->where('project_id', $target->project_id)
            ->where('proposal_id', $target->source_id)
            ->value('target_beneficiaries');

        return $value === null ? null : (int) $value;
    }

    /**
     * One place per row and center, one city-wide place for a city-wide row
     * and one unset place for a row with neither. A row with no program
     * (waiting for HR) has no place yet and is left out.
     *
     * @return list<ReportPlace>
     */
    public function placesOfProposal(Proposal $proposal): array
    {
        return $this->placesOfRows(
            $proposal->locations()->whereNotNull('program_id')->with('centers')->orderBy('sort')->orderBy('id')->get(),
        );
    }

    /**
     * The places of the proposal's rows that carry the project (the launch
     * command pre-fills one project at a time).
     *
     * Removed by Y-17, when the row becomes the only writer.
     *
     * @return list<ReportPlace>
     */
    public function placesOfProject(Proposal $proposal, int $projectId): array
    {
        return $this->placesOfRows(
            $proposal->locations()->where('project_id', $projectId)->whereNotNull('program_id')->with('centers')->orderBy('sort')->orderBy('id')->get(),
        );
    }

    /**
     * The proposals with one of the statuses that fund the project, by id.
     *
     * Removed by Y-17.
     *
     * @param  list<string>  $statuses  proposal status values
     * @return Collection<int, Proposal>
     */
    public function proposalsOfProject(int $projectId, array $statuses): Collection
    {
        $ids = DB::table('project_proposal')->where('project_id', $projectId)->pluck('proposal_id')
            ->merge(ProposalLocation::query()->where('project_id', $projectId)->pluck('proposal_id'))
            ->unique()
            ->values()
            ->all();

        return Proposal::query()->whereKey($ids)->whereIn('status', $statuses)->orderBy('id')->get();
    }

    /**
     * The project an untouched reader (ActivateProject) still needs for a
     * template: see legacyProjectIdsFor().
     *
     * Removed by Y-17, when the row becomes the only writer.
     */
    public function legacyProjectIdFor(ReportTemplate $template): ?int
    {
        return $this->legacyProjectIdsFor([$template])[$template->id] ?? null;
    }

    /**
     * The legacy project of each template, in a fixed number of queries: its
     * own `project_id` when it has one (it existed before Y-02), else the
     * project carried by a location row of one of its proposals at its place.
     *
     * Removed by Y-17.
     *
     * @param  iterable<ReportTemplate>  $templates
     * @return array<int, int|null> template id => project id
     */
    public function legacyProjectIdsFor(iterable $templates): array
    {
        $templates = collect($templates)->keyBy('id');
        $result = $templates->map(fn (ReportTemplate $template): ?int => $template->project_id)->all();
        $open = $templates->filter(fn (ReportTemplate $template): bool => $template->project_id === null && ! $template->place()->isUnset());

        if ($open->isEmpty()) {
            return $result;
        }

        $links = DB::table('proposal_report_template')->whereIn('report_template_id', $open->keys()->all())->get(['report_template_id', 'proposal_id']);
        $rows = ProposalLocation::query()
            ->with('centers')
            ->whereIn('proposal_id', $links->pluck('proposal_id')->unique()->all())
            ->whereNotNull('project_id')
            ->orderBy('id')
            ->get();

        foreach ($open as $id => $template) {
            $place = $template->place();
            $proposalIds = $links->where('report_template_id', $id)->pluck('proposal_id')->map(fn (mixed $value): int => (int) $value)->all();

            $row = $rows->first(fn (ProposalLocation $row): bool => in_array($row->proposal_id, $proposalIds, true)
                && $row->city_id === $place->cityId
                && $row->program_id === $place->programId
                && ($place->cityWide ? $row->city_wide : $row->centers->contains('center_id', $place->centerId)));

            $result[$id] = $row?->project_id;
        }

        return $result;
    }

    /**
     * The legacy project of a template as a model (the status gate of
     * ConfirmReportTemplate and the MEAL coverage read of the policy),
     * optionally locked.
     *
     * Removed by Y-17.
     */
    public function legacyProjectFor(ReportTemplate $template, bool $lock = false): ?Project
    {
        $id = $this->legacyProjectIdFor($template);

        if ($id === null) {
            return null;
        }

        return Project::query()->whereKey($id)->when($lock, fn ($query) => $query->lockForUpdate())->first();
    }

    /**
     * Projects by id with the columns the queue and the policy read.
     *
     * Removed by Y-17.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Project>
     */
    public function projectsByIds(array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        return Project::query()
            ->select(['id', 'name', 'slug', 'status', 'city_id', 'center_id', 'program_id'])
            ->whereKey($ids)
            ->get()
            ->keyBy('id')
            ->toBase();
    }

    /**
     * The templates of a project: the ones that still carry its id, and the
     * ones at the places of the rows that carry it, for the row's proposal.
     *
     * Removed by Y-17.
     *
     * @return list<int>
     */
    public function templateIdsOfProject(int $projectId): array
    {
        $ids = ReportTemplate::query()->where('project_id', $projectId)->pluck('id')->all();
        $rows = ProposalLocation::query()->with('centers')->where('project_id', $projectId)->whereNotNull('program_id')->get();

        foreach ($rows as $row) {
            foreach ($this->placesOfRows(collect([$row])) as $place) {
                $ids = [...$ids, ...ReportTemplate::query()
                    ->where('city_id', $place->cityId)
                    ->where('program_id', $place->programId)
                    ->where('city_wide', $place->cityWide)
                    ->where('center_id', $place->centerId)
                    ->whereHas('proposals', fn ($query) => $query->whereKey($row->proposal_id))
                    ->pluck('id')
                    ->all()];
            }
        }

        $ids = array_values(array_unique(array_map(intval(...), $ids)));
        sort($ids);

        return $ids;
    }

    /**
     * The place a new or re-pointed target gets.
     *
     * A proposal target (Y-10, D-91): the place of the proposal's location
     * row that carries the project (PlanIndicator::placeColumnsForRow); no
     * row, no place. The project is never read for it any more.
     *
     * An annual-plan target (Y-08, D-44, unchanged): the project's city and
     * program; its center when it has one; else what every row carrying the
     * project says (as on main: Dev 1, 17 Nov): its single center, city-wide
     * (D-88) or shared by several centers (D-90). Rows that disagree leave it
     * unset. Null without a project.
     */
    public function placeForTarget(Model $source, ?int $projectId): ?ReportPlace
    {
        if ($projectId === null) {
            return null;
        }

        $columns = $this->placeColumnsForTargets($source, [$projectId])[$projectId];

        if ($columns['city_id'] === null || $columns['program_id'] === null || $columns['all_centers']) {
            return null;
        }

        return new ReportPlace($columns['city_id'], $columns['program_id'], $columns['center_id'], $columns['city_wide']);
    }

    /**
     * The place columns a writer stores next to `project_id`, per project, in
     * a fixed number of queries (the targets grid saves many cells at once).
     * Same rule as placeForTarget(), which cannot express a shared target:
     * the row's place for a proposal source (Y-10), the project branch for an
     * annual plan only (until Y-17).
     *
     * @param  list<int|null>  $projectIds
     * @return array<int|string, array{city_id: int|null, program_id: int|null, center_id: int|null, city_wide: bool, all_centers: bool}> keyed by project id ('' for none)
     */
    public function placeColumnsForTargets(Model $source, array $projectIds): array
    {
        $none = ['city_id' => null, 'program_id' => null, 'center_id' => null, 'city_wide' => false, 'all_centers' => false];
        $ids = array_values(array_unique(array_filter($projectIds, fn (?int $id): bool => $id !== null)));
        $result = in_array(null, $projectIds, true) ? ['' => $none] : [];

        if ($ids === []) {
            return $result;
        }

        if ($source->getMorphClass() === 'proposal') {
            $rows = ProposalLocation::query()
                ->with('centers')
                ->where('proposal_id', $source->getKey())
                ->whereIn('project_id', $ids)
                ->orderBy('id')
                ->get();

            foreach ($ids as $id) {
                $row = $rows->firstWhere('project_id', $id);
                $result[$id] = $row === null ? $none : PlanIndicator::placeColumnsForRow($row);
            }

            return $result;
        }

        $projects = Project::query()->select(['id', 'city_id', 'program_id', 'center_id'])->whereKey($ids)->get()->keyBy('id');
        $rows = ProposalLocation::query()
            ->with('centers')
            ->whereIn('project_id', $ids)
            ->orderBy('id')
            ->get();

        foreach ($ids as $id) {
            $project = $projects->get($id);

            if ($project === null) {
                $result[$id] = $none;

                continue;
            }

            $answers = $rows->where('project_id', $id)
                ->map(fn (ProposalLocation $row): array => [
                    'city_wide' => (bool) $row->city_wide,
                    'center_ids' => $row->city_wide ? [] : $row->centers->pluck('center_id')->map(intval(...))->sort()->values()->all(),
                ])
                ->unique(fn (array $answer): string => $answer['city_wide'] ? 'cw' : implode('-', $answer['center_ids']))
                ->values();

            $columns = [...$none, 'city_id' => (int) $project->city_id, 'program_id' => (int) $project->program_id];

            if ($project->center_id !== null) {
                $columns['center_id'] = (int) $project->center_id;
            } elseif ($answers->count() === 1) {
                $answer = $answers->first();
                $columns['city_wide'] = $answer['city_wide'];
                $columns['all_centers'] = count($answer['center_ids']) > 1;
                $columns['center_id'] = count($answer['center_ids']) === 1 ? $answer['center_ids'][0] : null;
            }

            $result[$id] = $columns;
        }

        return $result;
    }

    /**
     * @param  Collection<int, ProposalLocation>  $rows  with `centers` loaded
     * @return list<ReportPlace>
     */
    private function placesOfRows(Collection $rows): array
    {
        $places = [];

        foreach ($rows as $row) {
            $cityId = $row->city_id;
            $programId = (int) $row->program_id;

            if ($row->city_wide) {
                $places[] = new ReportPlace($cityId, $programId, null, true);
            } elseif ($row->centers->isEmpty()) {
                $places[] = new ReportPlace($cityId, $programId, null, false);
            } else {
                foreach ($row->centers as $center) {
                    $places[] = new ReportPlace($cityId, $programId, $center->center_id, false);
                }
            }
        }

        return $places;
    }
}
