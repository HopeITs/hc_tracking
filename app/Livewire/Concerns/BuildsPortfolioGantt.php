<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Enums\ProjectStatus;
use App\Enums\ProposalPhase;
use App\Enums\ProposalStatus;
use App\Models\DonorProposal;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ScheduleDependency;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Read-only Gantt payloads that look across projects, for the Timeline page
 * and the funding agreement page:
 *
 * - portfolio: project groups (by program, status or owner) → project →
 *   milestone tree, with each project's links, total float, critical path and
 *   MS-Project predecessors text;
 * - connected: the portfolio with the money around it — the proposals that
 *   fund those projects on top, their transfers and donor reports below, and
 *   `relations` tying each proposal to the projects it funds and each
 *   obligation to its proposal, so the chart can trace money to work;
 * - pipeline: every proposal grouped by lifecycle phase, with the projects it
 *   funds under it (award → delivery);
 * - agreement: one funding agreement's proposal, funded delivery and its own
 *   transfers and donor reports.
 *
 * Rows share the shape BuildsProjectGantt produces, so the same x-ui.gantt
 * engine draws them.
 */
trait BuildsPortfolioGantt
{
    use BuildsProjectGantt;

    /** The ways the timeline can group its projects. */
    public const TIMELINE_GROUPINGS = ['program', 'status', 'owner'];

    /**
     * @return array<string, mixed>
     */
    protected function portfolioGanttPayload(?int $programId = null, ?string $status = null, string $groupBy = 'program'): array
    {
        $projects = $this->timelineProjects($programId, $status);
        $milestones = $this->timelineMilestones($projects);

        $rows = collect();
        $this->pushProjectGroups($projects, $milestones, $groupBy, $rows);

        return $this->readOnlyPayload($rows, $this->decoratePortfolioSchedule($projects, $milestones, $rows));
    }

    /**
     * The portfolio with its money: fundraising on top, delivery in the
     * middle, donor obligations at the bottom, and the relations between them.
     *
     * @return array<string, mixed>
     */
    protected function connectedGanttPayload(?int $programId = null, ?string $status = null, string $groupBy = 'program'): array
    {
        $projects = $this->timelineProjects($programId, $status);
        $milestones = $this->timelineMilestones($projects);
        $projectIds = $projects->modelKeys();

        $proposals = Proposal::query()
            ->select(['id', 'name', 'status', 'planned_date_begin', 'planned_date_end', 'actual_date_begin', 'actual_date_end'])
            ->whereHas('projects', fn ($query) => $query->whereIn('projects.id', $projectIds))
            ->with(['donors:id,name', 'projects:id'])
            ->orderBy('planned_date_begin')
            ->orderBy('id')
            ->get();

        $rows = collect();
        $relations = collect();

        if ($proposals->isNotEmpty()) {
            $rows->push($this->ganttGroupRow('fundraising', '1', __('gantt.fundraising')));

            foreach ($proposals->values() as $index => $proposal) {
                $rows->push($this->proposalRow($proposal, 'fundraising', 1, '1.'.($index + 1)));

                foreach ($proposal->projects->whereIn('id', $projectIds) as $project) {
                    $relations->push(['from' => 'proposal-'.$proposal->id, 'to' => 'project-'.$project->id, 'type' => 'funds']);
                }
            }

            $this->rollUp($rows, 0);
        }

        $this->pushProjectGroups($projects, $milestones, $groupBy, $rows);
        $links = $this->decoratePortfolioSchedule($projects, $milestones, $rows);
        $this->pushObligationRows($proposals->modelKeys(), $rows, $relations);

        return $this->readOnlyPayload($rows, $links, $relations->values()->all());
    }

    /**
     * @return array<string, mixed>
     */
    protected function pipelineGanttPayload(?string $phase = null): array
    {
        $proposals = Proposal::query()
            ->select(['id', 'name', 'status', 'planned_date_begin', 'planned_date_end', 'actual_date_begin', 'actual_date_end'])
            ->when($phase !== null && ProposalPhase::tryFrom($phase) !== null, fn ($query) => $query->whereIn('status', ProposalPhase::from($phase)->statuses()))
            ->with([
                'donors:id,name',
                'projects:id,name,status,start_date,end_date',
            ])
            ->orderBy('planned_date_begin')
            ->orderBy('id')
            ->get();

        $rows = collect();
        $groupIndex = 0;

        foreach (ProposalPhase::cases() as $proposalPhase) {
            $inPhase = $proposals->filter(fn (Proposal $proposal): bool => $proposal->status->phase() === $proposalPhase)->values();

            if ($inPhase->isEmpty()) {
                continue;
            }

            $groupIndex++;
            $groupId = 'phase-'.$proposalPhase->value;
            $groupAt = $rows->count();
            $rows->push($this->ganttGroupRow($groupId, (string) $groupIndex, $proposalPhase->label()));

            foreach ($inPhase as $index => $proposal) {
                $wbs = $groupIndex.'.'.($index + 1);
                $at = $rows->count();

                $rows->push($this->proposalRow($proposal, $groupId, 1, $wbs));

                foreach ($proposal->projects->sortBy('start_date')->values() as $projectIndex => $project) {
                    $rows->push($this->ganttRow(
                        id: 'funded-'.$proposal->id.'-'.$project->id,
                        parent: 'proposal-'.$proposal->id,
                        depth: 2,
                        kind: 'project',
                        wbs: $wbs.'.'.($projectIndex + 1),
                        name: $project->name,
                        start: $project->start_date,
                        end: $project->end_date ?? $project->start_date,
                        status: $project->status->value,
                        statusLabel: $project->status->label(),
                        tone: $this->projectTone($project),
                        url: route('projects.show', $project),
                    ));
                }

                // A proposal row keeps its own span; the projects it funds are listed, not rolled into it.
                $rows->put($at, array_merge($rows->get($at), ['summary' => false]));
            }

            $this->rollUp($rows, $groupAt);
        }

        return $this->readOnlyPayload($rows, []);
    }

    /**
     * @return array<string, mixed>
     */
    protected function agreementGanttPayload(DonorProposal $agreement): array
    {
        $proposal = $agreement->proposal()->first(['id', 'name', 'status', 'planned_date_begin', 'planned_date_end', 'actual_date_begin', 'actual_date_end']);
        $rows = collect();

        if ($proposal !== null) {
            $rows->push($this->ganttGroupRow('fundraising', '1', __('gantt.fundraising')));
            $rows->push($this->ganttRow(
                id: 'proposal-'.$proposal->id,
                parent: 'fundraising',
                depth: 1,
                kind: 'proposal',
                wbs: '1.1',
                name: $proposal->name,
                start: $proposal->planned_date_begin,
                end: $proposal->planned_date_end ?? $proposal->planned_date_begin,
                actualStart: $proposal->actual_date_begin,
                actualEnd: $proposal->actual_date_end,
                progress: $this->proposalProgress($proposal->status),
                status: $proposal->status->value,
                statusLabel: $proposal->status->label(),
                tone: 'funding',
                url: route('proposals.show', $proposal),
            ));
            $this->rollUp($rows, 0);

            $projects = $proposal->projects()
                ->select(['projects.id', 'projects.name', 'projects.status', 'projects.start_date', 'projects.end_date', 'projects.user_id'])
                ->with('user:id,first_name_en,last_name_en,first_name_ar,last_name_ar')
                ->orderBy('projects.start_date')
                ->get();

            if ($projects->isNotEmpty()) {
                $milestones = $this->timelineMilestones($projects);

                $groupAt = $rows->count();
                $rows->push($this->ganttGroupRow('delivery', '2', __('gantt.delivery')));

                foreach ($projects->values() as $index => $project) {
                    $this->pushProjectBranch($project, $milestones->get($project->id, collect()), 'delivery', 1, '2.'.($index + 1), $rows);
                }

                $this->rollUp($rows, $groupAt);
            }
        }

        $this->pushObligationRows($agreement->id, $rows);

        return $this->readOnlyPayload($rows, $this->linksBetween($rows));
    }

    /**
     * The projects on the timeline, narrowed by the page filters.
     *
     * @return EloquentCollection<int, Project>
     */
    private function timelineProjects(?int $programId, ?string $status): EloquentCollection
    {
        return Project::query()
            ->select(['id', 'name', 'status', 'start_date', 'end_date', 'program_id', 'user_id', 'schedule_weekend'])
            ->when($programId !== null, fn ($query) => $query->where('program_id', $programId))
            ->when($status !== null && $status !== '', fn ($query) => $query->where('status', $status))
            ->with(['program:id', 'user:id,first_name_en,last_name_en,first_name_ar,last_name_ar'])
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Every milestone of the given projects, grouped by project id.
     *
     * @param  EloquentCollection<int, Project>  $projects
     * @return Collection<int, Collection<int, Milestone>>
     */
    private function timelineMilestones(EloquentCollection $projects): Collection
    {
        return Milestone::query()
            ->select(['id', 'project_id', 'parent_milestone_id', 'name', 'planned_start_date', 'planned_end_date', 'actual_start_date', 'actual_end_date', 'status', 'sequence', 'responsible_user_id'])
            ->whereIn('project_id', $projects->modelKeys())
            ->with('responsibleUser:id,first_name_en,last_name_en,first_name_ar,last_name_ar')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->groupBy('project_id');
    }

    /**
     * One group row per program, status or owner, each followed by its
     * projects and their milestone trees. Group numbering continues after
     * whatever top-level rows are already on the chart.
     *
     * @param  EloquentCollection<int, Project>  $projects
     * @param  Collection<int, Collection<int, Milestone>>  $milestones
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function pushProjectGroups(EloquentCollection $projects, Collection $milestones, string $groupBy, Collection $rows): void
    {
        $groupIndex = $rows->where('depth', 0)->count();

        foreach ($this->groupProjects($projects, $groupBy) as $group) {
            $groupIndex++;
            $groupAt = $rows->count();
            $rows->push($this->ganttGroupRow($group['id'], (string) $groupIndex, $group['name']));

            foreach ($group['projects']->values() as $index => $project) {
                $this->pushProjectBranch($project, $milestones->get($project->id, collect()), $group['id'], 1, $groupIndex.'.'.($index + 1), $rows);
            }

            $this->rollUp($rows, $groupAt);
        }
    }

    /**
     * @param  EloquentCollection<int, Project>  $projects
     * @return list<array{id: string, name: string, projects: Collection<int, Project>}>
     */
    private function groupProjects(EloquentCollection $projects, string $groupBy): array
    {
        if ($groupBy === 'status') {
            $groups = [];

            foreach (ProjectStatus::cases() as $status) {
                $inStatus = $projects->filter(fn (Project $project): bool => $project->status === $status);

                if ($inStatus->isNotEmpty()) {
                    $groups[] = ['id' => 'status-'.$status->value, 'name' => $status->label(), 'projects' => $inStatus];
                }
            }

            return $groups;
        }

        if ($groupBy === 'owner') {
            return $projects
                ->groupBy(fn (Project $project): int => (int) $project->user_id)
                ->map(fn (Collection $owned, int $userId): array => [
                    'id' => 'owner-'.($userId === 0 ? 'none' : $userId),
                    'name' => $owned->first()->user?->name ?? __('gantt.no_owner'),
                    'projects' => $owned,
                ])
                ->sortBy(fn (array $group): string => ($group['id'] === 'owner-none' ? '1' : '0').mb_strtolower($group['name']))
                ->values()
                ->all();
        }

        $index = 0;

        return $projects
            ->groupBy(fn (Project $project): string => $project->program?->name ?? '—')
            ->sortKeys()
            ->map(function (Collection $inProgram, int|string $name) use (&$index): array {
                return ['id' => 'program-'.(++$index), 'name' => (string) $name, 'projects' => $inProgram];
            })
            ->values()
            ->all();
    }

    /**
     * One project summary row followed by its milestone tree.
     *
     * @param  Collection<int, Milestone>  $milestones
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function pushProjectBranch(Project $project, Collection $milestones, string $parentRowId, int $depth, string $wbs, Collection $rows): void
    {
        $at = $rows->count();
        $rowId = 'project-'.$project->id;

        $rows->push($this->ganttRow(
            id: $rowId,
            parent: $parentRowId,
            depth: $depth,
            kind: 'project',
            wbs: $wbs,
            name: $project->name,
            start: $project->start_date,
            end: $project->end_date ?? $project->start_date,
            status: $project->status->value,
            statusLabel: $project->status->label(),
            tone: $this->projectTone($project),
            owner: $project->user?->name,
            url: route('projects.show', $project),
        ) + [
            'planUrl' => route('projects.show', ['project' => $project, 'tab' => 'plan']),
            'program' => $project->relationLoaded('program') ? $project->program?->name : null,
        ]);

        if ($milestones->isEmpty()) {
            return;
        }

        $byParent = $milestones->groupBy(fn (Milestone $milestone): int => (int) $milestone->parent_milestone_id);
        $this->pushMilestoneBranch($byParent, collect(), collect(), 0, $rowId, $depth + 1, $wbs, 0, $rows);
        $this->rollUp($rows, $at);
    }

    /**
     * A proposal bar: its lifecycle span, donors and the money signed on it.
     *
     * @return array<string, mixed>
     */
    private function proposalRow(Proposal $proposal, string $parent, int $depth, string $wbs): array
    {
        return $this->ganttRow(
            id: 'proposal-'.$proposal->id,
            parent: $parent,
            depth: $depth,
            kind: 'proposal',
            wbs: $wbs,
            name: $proposal->name,
            start: $proposal->planned_date_begin,
            end: $proposal->planned_date_end ?? $proposal->planned_date_begin,
            actualStart: $proposal->actual_date_begin,
            actualEnd: $proposal->actual_date_end,
            progress: $this->proposalProgress($proposal->status),
            status: $proposal->status->value,
            statusLabel: $proposal->status->label(),
            tone: $this->proposalTone($proposal),
            owner: $proposal->donors->pluck('name')->unique()->implode(', ') ?: null,
            url: route('proposals.show', $proposal),
            money: $this->ganttMoney($proposal->donors->map(fn ($donor): array => [$donor->pivot->budget, $donor->pivot->currency])),
        );
    }

    /**
     * Give each project's milestones the MS Project schedule reading the
     * project Plan tab shows — total float, critical flag and predecessors
     * text — worked on that project's own working week, and return the links
     * whose two ends are both on the chart.
     *
     * @param  EloquentCollection<int, Project>  $projects
     * @param  Collection<int, Collection<int, Milestone>>  $milestones
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function decoratePortfolioSchedule(EloquentCollection $projects, Collection $milestones, Collection $rows): array
    {
        if ($milestones->isEmpty()) {
            return [];
        }

        $byId = $rows->keyBy('id');
        $links = ScheduleDependency::query()
            ->whereIn('project_id', $projects->modelKeys())
            ->where('predecessor_type', 'milestone')
            ->where('successor_type', 'milestone')
            ->orderBy('id')
            ->get()
            ->filter(fn (ScheduleDependency $link): bool => $byId->has($link->predecessorRowId()) && $byId->has($link->successorRowId()));

        $float = [];

        foreach ($projects as $project) {
            $items = [];

            foreach ($milestones->get($project->id, collect()) as $milestone) {
                $row = $byId->get('milestone-'.$milestone->id);

                if ($row !== null && $row['start'] !== null && $row['end'] !== null) {
                    $items[$row['id']] = ['start' => $this->dayNumber($row['start']), 'end' => $this->dayNumber($row['end'])];
                }
            }

            $float += $this->totalFloat($items, $links->where('project_id', $project->id), $this->weekendDays($project));
        }

        $predecessors = [];

        foreach ($links as $link) {
            $lag = $link->lag_days === 0 ? '' : sprintf('%+dd', $link->lag_days);
            $predecessors[$link->successorRowId()][] = $byId[$link->predecessorRowId()]['wbs'].$link->type->code().$lag;
        }

        $rows->transform(function (array $row) use ($float, $predecessors): array {
            if ($row['kind'] !== 'milestone') {
                return $row;
            }

            $row['float'] = $float[$row['id']] ?? null;
            $row['critical'] = isset($float[$row['id']]) && $float[$row['id']] <= 0 && $row['progress'] < 100;
            $row['predecessors'] = implode(', ', $predecessors[$row['id']] ?? []);

            return $row;
        });

        return $links->map(fn (ScheduleDependency $link): array => [
            'id' => $link->id,
            'from' => $link->predecessorRowId(),
            'to' => $link->successorRowId(),
            'type' => $link->type->value,
            'lag' => $link->lag_days,
            'label' => $link->type->label(),
        ])->values()->all();
    }

    /**
     * Links whose two ends are both on the chart.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function linksBetween(Collection $rows): array
    {
        $ids = $rows->pluck('id')->flip();
        $milestoneIds = $rows->where('kind', 'milestone')->map(fn (array $row): int => (int) substr($row['id'], strlen('milestone-')))->values();

        if ($milestoneIds->isEmpty()) {
            return [];
        }

        return ScheduleDependency::query()
            ->where('predecessor_type', 'milestone')->whereIn('predecessor_id', $milestoneIds)
            ->where('successor_type', 'milestone')->whereIn('successor_id', $milestoneIds)
            ->get()
            ->filter(fn (ScheduleDependency $link): bool => $ids->has($link->predecessorRowId()) && $ids->has($link->successorRowId()))
            ->map(fn (ScheduleDependency $link): array => [
                'id' => $link->id,
                'from' => $link->predecessorRowId(),
                'to' => $link->successorRowId(),
                'type' => $link->type->value,
                'lag' => $link->lag_days,
                'label' => $link->type->label(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $links
     * @param  list<array{from: string, to: string, type: string}>  $relations
     * @return array<string, mixed>
     */
    private function readOnlyPayload(Collection $rows, array $links, array $relations = []): array
    {
        $today = CarbonImmutable::today()->toDateString();
        $dates = $rows->flatMap(fn (array $row): array => [$row['start'], $row['end'], $row['actualStart'], $row['actualEnd']])->push($today)->filter();
        $ids = $rows->pluck('id')->flip();

        return [
            'rows' => $rows->map(fn (array $row): array => $row + ['float' => null, 'critical' => false, 'predecessors' => '', 'baselineStart' => null, 'baselineEnd' => null])->values()->all(),
            'links' => $links,
            'relations' => array_values(array_filter($relations, fn (array $relation): bool => $ids->has($relation['from']) && $ids->has($relation['to']))),
            'baselines' => [],
            'baselineId' => null,
            'weekend' => 'sat-sun',
            'from' => $dates->min(),
            'to' => $dates->max(),
            'today' => $today,
        ];
    }

    /**
     * How far along the fundraising lifecycle a proposal is, for its bar.
     */
    private function proposalProgress(ProposalStatus $status): int
    {
        return match ($status) {
            ProposalStatus::ConceptNote => 5,
            ProposalStatus::ProposalDraft => 15,
            ProposalStatus::Submitted => 30,
            ProposalStatus::Approved => 45,
            ProposalStatus::Rejected => 0,
            ProposalStatus::Active => 65,
            ProposalStatus::FinalReports => 90,
            ProposalStatus::Completed => 100,
        };
    }

    private function proposalTone(Proposal $proposal): string
    {
        $phase = $proposal->status->phase();
        $overdue = $proposal->planned_date_end !== null && $proposal->planned_date_end->isPast() && $phase === ProposalPhase::Planning;

        return match (true) {
            $proposal->status === ProposalStatus::Rejected => 'muted',
            $proposal->status === ProposalStatus::Completed => 'good',
            $overdue => 'critical',
            $phase === ProposalPhase::Planning => 'neutral',
            $proposal->status === ProposalStatus::Submitted => 'warn',
            default => 'funding',
        };
    }

    private function projectTone(Project $project): string
    {
        return match (true) {
            $project->status === ProjectStatus::Archived => 'muted',
            $project->status === ProjectStatus::Closed => 'good',
            $project->end_date !== null && $project->end_date->isPast() && $project->status->isOpen() => 'critical',
            $project->status === ProjectStatus::Active => 'warn',
            default => 'neutral',
        };
    }
}
