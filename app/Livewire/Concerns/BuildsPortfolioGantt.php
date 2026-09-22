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
use Illuminate\Support\Collection;

/**
 * Read-only Gantt payloads that look across projects, for the Timeline page
 * and the funding agreement page:
 *
 * - portfolio: program → project → milestone tree, with each project's links;
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

    /**
     * @return array<string, mixed>
     */
    public function portfolioGanttPayload(?int $programId = null, ?string $status = null): array
    {
        $projects = Project::query()
            ->select(['id', 'name', 'status', 'start_date', 'end_date', 'program_id', 'user_id'])
            ->when($programId !== null, fn ($query) => $query->where('program_id', $programId))
            ->when($status !== null && $status !== '', fn ($query) => $query->where('status', $status))
            ->with(['program:id,name', 'user:id,first_name_en,last_name_en,first_name_ar,last_name_ar'])
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        $milestones = Milestone::query()
            ->select(['id', 'project_id', 'parent_milestone_id', 'name', 'planned_start_date', 'planned_end_date', 'actual_start_date', 'actual_end_date', 'status', 'sequence', 'responsible_user_id'])
            ->whereIn('project_id', $projects->modelKeys())
            ->with('responsibleUser:id,first_name_en,last_name_en,first_name_ar,last_name_ar')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->groupBy('project_id');

        $rows = collect();
        $groupIndex = 0;

        foreach ($projects->groupBy(fn (Project $project): string => $project->program?->name ?? '—')->sortKeys() as $programName => $programProjects) {
            $groupIndex++;
            $groupAt = $rows->count();
            $rows->push($this->ganttGroupRow('program-'.$groupIndex, (string) $groupIndex, (string) $programName));

            foreach ($programProjects->values() as $index => $project) {
                $this->pushProjectBranch($project, $milestones->get($project->id, collect()), 'program-'.$groupIndex, 1, $groupIndex.'.'.($index + 1), $rows);
            }

            $this->rollUp($rows, $groupAt);
        }

        return $this->readOnlyPayload($rows, $this->linksBetween($rows));
    }

    /**
     * @return array<string, mixed>
     */
    public function pipelineGanttPayload(?string $phase = null): array
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

                $rows->push($this->ganttRow(
                    id: 'proposal-'.$proposal->id,
                    parent: $groupId,
                    depth: 1,
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
                ));

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
    public function agreementGanttPayload(DonorProposal $agreement): array
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
                $milestones = Milestone::query()
                    ->select(['id', 'project_id', 'parent_milestone_id', 'name', 'planned_start_date', 'planned_end_date', 'actual_start_date', 'actual_end_date', 'status', 'sequence', 'responsible_user_id'])
                    ->whereIn('project_id', $projects->modelKeys())
                    ->with('responsibleUser:id,first_name_en,last_name_en,first_name_ar,last_name_ar')
                    ->orderBy('sequence')
                    ->orderBy('id')
                    ->get()
                    ->groupBy('project_id');

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
        ));

        if ($milestones->isEmpty()) {
            return;
        }

        $byParent = $milestones->groupBy(fn (Milestone $milestone): int => (int) $milestone->parent_milestone_id);
        $this->pushMilestoneBranch($byParent, collect(), collect(), 0, $rowId, $depth + 1, $wbs, 0, $rows);
        $this->rollUp($rows, $at);
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
     * @return array<string, mixed>
     */
    private function readOnlyPayload(Collection $rows, array $links): array
    {
        $today = CarbonImmutable::today()->toDateString();
        $dates = $rows->flatMap(fn (array $row): array => [$row['start'], $row['end'], $row['actualStart'], $row['actualEnd']])->push($today)->filter();

        return [
            'rows' => $rows->map(fn (array $row): array => $row + ['float' => null, 'critical' => false, 'predecessors' => '', 'baselineStart' => null, 'baselineEnd' => null])->values()->all(),
            'links' => $links,
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
