<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\ReportTemplate;
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
     * The place of a proposal target, or null for a shared target (one row,
     * several centers, D-90) and for a target whose row has no center and is
     * not city-wide.
     *
     * Removed by Y-08, which moves targets to places: until then the place is
     * read from the target's project and from the location row that carries
     * that project.
     */
    public function placeOfTarget(PlanIndicator $target): ?ReportPlace
    {
        $project = $this->projectOf($target);

        if ($project === null) {
            return null;
        }

        if ($project->center_id !== null) {
            return new ReportPlace((int) $project->city_id, (int) $project->program_id, (int) $project->center_id, false);
        }

        $places = $this->rowsOf($project, $target)
            ->map(fn (ProposalLocation $row): ?ReportPlace => $this->singlePlaceOfRow($row))
            ->unique(fn (?ReportPlace $place): string => $place?->key() ?? 'none')
            ->values();

        return $places->count() === 1 ? $places->first() : null;
    }

    /**
     * Whether the target reports at the place: its own place, or a shared
     * target (D-90) of a row that lists the place's center.
     *
     * Removed by Y-08, with placeOfTarget().
     */
    public function targetCoversPlace(PlanIndicator $target, ReportPlace $place): bool
    {
        $own = $this->placeOfTarget($target);

        if ($own !== null) {
            return $own->equals($place);
        }

        $project = $this->projectOf($target);

        if ($project === null || $project->center_id !== null || $place->centerId === null || $place->cityWide) {
            return false;
        }

        return $this->rowsOf($project, $target)->contains(
            fn (ProposalLocation $row): bool => ! $row->city_wide
                && $row->centers->count() > 1
                && $row->city_id === $place->cityId
                && $row->program_id === $place->programId
                && $row->centers->contains('center_id', $place->centerId),
        );
    }

    /**
     * Whether the target is shared (D-90): its project has no center and is
     * carried by a row with several centers. Such a target has no single place.
     *
     * Removed by Y-08, with placeOfTarget().
     */
    public function isSharedTarget(PlanIndicator $target): bool
    {
        $project = $this->projectOf($target);

        if ($project === null || $project->center_id !== null || $this->placeOfTarget($target) !== null) {
            return false;
        }

        return $this->rowsOf($project, $target)->contains(fn (ProposalLocation $row): bool => ! $row->city_wide && $row->centers->count() > 1);
    }

    /**
     * `project_proposal.target_beneficiaries` of a proposal target's project
     * (D-25), the source of a `fixed` line's denominator.
     *
     * Removed by Y-08, with placeOfTarget().
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

    private function projectOf(PlanIndicator $target): ?Project
    {
        return $target->project_id === null ? null : Project::query()->find($target->project_id);
    }

    /**
     * The location rows that carry the project, narrowed to the target's
     * own proposal when it has rows there.
     *
     * @return Collection<int, ProposalLocation>
     */
    private function rowsOf(Project $project, PlanIndicator $target): Collection
    {
        $rows = ProposalLocation::query()->with('centers')->where('project_id', $project->id)->orderBy('id')->get();

        if ($target->source_type === 'proposal') {
            $own = $rows->where('proposal_id', $target->source_id);

            if ($own->isNotEmpty()) {
                return $own->values();
            }
        }

        return $rows;
    }

    /**
     * The one place of a row, or null when it has none or several.
     */
    private function singlePlaceOfRow(ProposalLocation $row): ?ReportPlace
    {
        if ($row->program_id === null) {
            return null;
        }

        if ($row->city_wide) {
            return new ReportPlace($row->city_id, $row->program_id, null, true);
        }

        if ($row->centers->count() === 1) {
            return new ReportPlace($row->city_id, $row->program_id, $row->centers->first()->center_id, false);
        }

        return null;
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
