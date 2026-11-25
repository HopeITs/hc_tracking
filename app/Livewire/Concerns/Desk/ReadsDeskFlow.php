<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Desk;

use App\Enums\ProjectStatus;
use App\Models\ProposalLocation;
use App\Support\Desk\ProjectStage;
use Livewire\Attributes\Computed;

/**
 * Project flow: how many live projects stand on each of the seven stages
 * (App\Support\Desk\ProjectStage, the same derivation as the Projects
 * panel), how many of them are off track (something late), how many need a
 * look (something due soon, nothing late) and how many of the late ones
 * the viewer owns ("need you"), with up to three
 * project names per stage (late first) for the stage's tooltip. Closed and archived projects are left
 * out: the flow is the work still moving. The host provides visible()
 * (IsDeskPanel).
 *
 * Y-16 (D-93): a line is a **location row**, not a project. A row is of
 * exactly one proposal, so its lead is that proposal; "need you" reads the
 * row's responsible employee, and the name is the row's label (place and
 * programme).
 *
 * @property-read array{stages: list<array{stage: int, count: int, late: int, mine: int, top: list<array{name: string, late: bool}>}>, total: int, late: int, watch: int, mine: int} $flow  #[Computed] flow()
 */
trait ReadsDeskFlow
{
    /**
     * @return array{stages: list<array{stage: int, count: int, late: int, mine: int, top: list<array{name: string, late: bool}>}>, total: int, late: int, watch: int, mine: int}
     */
    #[Computed]
    public function flow(): array
    {
        $stages = [];
        $watch = 0;

        foreach (range(0, 6) as $stage) {
            $stages[$stage] = ['stage' => $stage, 'count' => 0, 'late' => 0, 'mine' => 0, 'top' => []];
        }

        $employeeId = auth()->user()->employee_id ?? 0;
        $money = $this->canViewFundingPosition();

        $rows = $this->visible(ProposalLocation::query(), 'projects')
            ->select(['id', 'proposal_id', 'city_id', 'program_id', 'city_wide', 'project_id', 'status', 'end_date', 'responsible_employee_id'])
            ->whereNotIn('status', [ProjectStatus::Archived, ProjectStatus::Closed])
            ->with([
                'proposal:id,status',
                'city:id,name_en,name_ar',
                'program:id',
                'centers',
                'monthlyReports:id,project_id,period,status',
                ...($money ? ['proposal.donorProposals:id,proposal_id', 'proposal.donorProposals.transfers'] : []),
            ])
            ->get();

        foreach ($rows as $row) {
            $stage = ProjectStage::stage($row, ProjectStage::leadOf($row));

            if ($stage > 6) {
                continue;
            }

            $signals = ProjectStage::signals($row);
            $late = $signals['late'] > 0;
            $watch += ! $late && $signals['soon'] > 0 ? 1 : 0;
            $stages[$stage]['count']++;
            $stages[$stage]['late'] += $late ? 1 : 0;
            $stages[$stage]['mine'] += $late && $employeeId !== 0 && (int) $row->responsible_employee_id === (int) $employeeId ? 1 : 0;
            $stages[$stage]['top'][] = ['name' => $row->label(), 'late' => $late];
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
            'watch' => $watch,
            'mine' => array_sum(array_column($list, 'mine')),
        ];
    }
}
