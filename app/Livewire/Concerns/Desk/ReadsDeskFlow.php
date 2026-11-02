<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Desk;

use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Support\Desk\ProjectStage;
use Livewire\Attributes\Computed;

/**
 * Project flow: how many live projects stand on each of the seven stages
 * (App\Support\Desk\ProjectStage, the same derivation as the Projects
 * panel), how many of them are off track (something late) and how many of
 * those the viewer owns ("need you"), with up to three
 * project names per stage (late first) for the stage's tooltip. Closed and archived projects are left
 * out: the flow is the work still moving. The host provides visible()
 * (IsDeskPanel).
 *
 * @property-read array{stages: list<array{stage: int, count: int, late: int, mine: int, top: list<array{name: string, late: bool}>}>, total: int, late: int, mine: int} $flow  #[Computed] flow()
 */
trait ReadsDeskFlow
{
    /**
     * @return array{stages: list<array{stage: int, count: int, late: int, mine: int, top: list<array{name: string, late: bool}>}>, total: int, late: int, mine: int}
     */
    #[Computed]
    public function flow(): array
    {
        $stages = [];

        foreach (range(0, 6) as $stage) {
            $stages[$stage] = ['stage' => $stage, 'count' => 0, 'late' => 0, 'mine' => 0, 'top' => []];
        }

        $employeeId = auth()->user()->employee_id ?? 0;
        $money = $this->canViewFundingPosition();

        $projects = $this->visible(Project::query(), 'projects')
            ->select(['id', 'name', 'status', 'end_date', 'user_id'])
            ->whereNotIn('status', [ProjectStatus::Archived, ProjectStatus::Closed])
            ->with([
                'proposals:id,status',
                'milestones' => fn ($query) => $query->select(['id', 'project_id', 'planned_end_date', 'status'])->whereIn('status', [MilestoneStatus::Planned, MilestoneStatus::Ongoing]),
                'monthlyReports:id,project_id,period,status',
                ...($money ? ['proposals.donorProposals:id,proposal_id', 'proposals.donorProposals.transfers'] : []),
            ])
            ->get();

        foreach ($projects as $project) {
            $stage = ProjectStage::stage($project, ProjectStage::lead($project->proposals));

            if ($stage > 6) {
                continue;
            }

            $late = ProjectStage::signals($project)['late'] > 0;
            $stages[$stage]['count']++;
            $stages[$stage]['late'] += $late ? 1 : 0;
            $stages[$stage]['mine'] += $late && $employeeId !== 0 && (int) $project->user_id === (int) $employeeId ? 1 : 0;
            $stages[$stage]['top'][] = ['name' => (string) $project->name, 'late' => $late];
        }

        foreach ($stages as $stage => $row) {
            usort($row['top'], fn (array $a, array $b): int => [$b['late'], $a['name']] <=> [$a['late'], $b['name']]);
            $stages[$stage]['top'] = array_slice($row['top'], 0, 3);
        }

        $list = array_values($stages);

        return [
            'stages' => $list,
            'total' => array_sum(array_column($list, 'count')),
            'late' => array_sum(array_column($list, 'late')),
            'mine' => array_sum(array_column($list, 'mine')),
        ];
    }
}
