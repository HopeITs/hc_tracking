<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Enums\ReportStatus;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\HrCenter;
use App\Models\HrCity;
use App\Models\HrEmployee;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\Report;
use App\Models\Task;
use App\Support\ProposalLocationRows;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

/**
 * The delivery on proposals/show: the funded projects, the tasks and
 * targets behind them, and the reports owed on both sides.
 *
 * Used by proposals/show only. The page provides proposalId and the
 * #[Computed] proposal(); agreements comes from ReadsProposalFunding.
 *
 * @property int $proposalId
 * @property-read Proposal $proposal  #[Computed] proposal()
 * @property-read Collection<int, DonorProposal> $agreements  #[Computed] agreements()
 * @property-read Collection<int, Project> $projects  #[Computed] projects()
 * @property-read Collection<int, PlanIndicator> $targets  #[Computed] targets()
 */
trait ReadsProposalDelivery
{
    /**
     * The Locations chapter (Y-01): one entry per location row in `sort`
     * order, with its place and a single status. The status comes from the
     * one definition of `locations_complete` (ProposalLocationRows): a row it
     * does not list is `complete`, a listed one says why, program first. A
     * city-wide row (D-88) shows field work in place of centers and is never
     * `missing_center`. City, center, employee and program names are read from
     * HR, never copied; the rows load their relations once.
     *
     * @return list<array{id: int, city: string, program: string|null, responsible: string|null, centers: list<string>, fieldWork: bool, status: string, project: string|null}>
     */
    #[Computed]
    public function locationRows(): array
    {
        $rows = $this->proposal->locations()
            ->with(['centers', 'program:id', 'project:id,slug,name,status'])
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        $incomplete = array_flip(ProposalLocationRows::incompleteRowIds($this->proposal));
        $cities = HrCity::query()
            ->whereKey($rows->pluck('city_id')->unique()->all())
            ->get(['id', 'name_en', 'name_ar'])
            ->keyBy('id');
        $employees = HrEmployee::query()
            ->whereKey($rows->pluck('responsible_employee_id')->filter()->unique()->all())
            ->get(HrEmployee::NameColumns)
            ->keyBy('id');

        return $rows->map(function (ProposalLocation $row) use ($incomplete, $cities, $employees): array {
            $status = match (true) {
                ! isset($incomplete[$row->id]) => 'complete',
                $row->program_id === null => 'waiting_program',
                $row->responsible_employee_id === null => 'missing_responsible',
                default => 'missing_center',
            };

            return [
                'id' => $row->id,
                'city' => $cities->get($row->city_id)?->name ?? __('common.unknown_city'),
                'program' => $row->program?->name,
                'responsible' => $row->responsible_employee_id === null ? null : ($employees->get($row->responsible_employee_id)?->name ?? '#'.$row->responsible_employee_id),
                'centers' => $row->city_wide ? [] : array_map(fn (int $centerId): string => HrCenter::remembered($centerId)?->name ?? '#'.$centerId, $row->centerIds()),
                'fieldWork' => $row->city_wide,
                'status' => $status,
                'project' => $row->project?->name,
            ];
        })->all();
    }

    /**
     * The places this proposal pays for (Y-16, D-93).
     *
     * @return Collection<int, ProposalLocation>
     */
    #[Computed]
    public function projects(): Collection
    {
        return $this->proposal->locations()
            ->select(['proposal_locations.id', 'proposal_locations.proposal_id', 'proposal_locations.project_id', 'proposal_locations.slug', 'proposal_locations.city_id', 'proposal_locations.city_wide', 'proposal_locations.program_id', 'proposal_locations.status', 'proposal_locations.start_date', 'proposal_locations.end_date'])
            ->with(['city:id,name_en,name_ar', 'program:id', 'centers', 'proposal:id,name'])
            ->withCount('risks')
            ->get()
            ->sortBy(fn (ProposalLocation $row): string => $row->label())
            ->values();
    }

    /**
     * Tasks delivering this proposal, carrying the place they reach through.
     *
     * A task has one source: either this proposal directly, or one of the
     * proposal's places (Y-16, D-93). Tasks sourced straight from the proposal
     * are a real state and get their own group rather than being dropped.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function tasks(): Collection
    {
        $fundedLocationIds = $this->projects->modelKeys();

        return Task::query()
            ->select(['id', 'slug', 'source_type', 'source_id', 'parent_task_id', 'department_id', 'kind', 'media_kinds', 'delivery_state', 'title', 'status', 'priority', 'start_date', 'due_date', 'progress_percentage'])
            ->with(['taskAssignees.employee:id,first_name_en,last_name_en,first_name_ar,last_name_ar', 'source'])
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('source_type', 'proposal')->where('source_id', $this->proposalId))
                ->orWhere(fn (Builder $query) => $query->where('source_type', 'proposal_location')->whereIn('source_id', $fundedLocationIds)))
            ->orderByRaw('due_date is null, due_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Targets committed in this proposal, with the reports filed against them.
     *
     * Filtered on the morph map key 'proposal', never a class name. The source
     * is hydrated with the proposal's places so chainIssues() can answer
     * whether a target was committed at a place this proposal does not pay
     * for — without that relation it would query once per target.
     *
     * @return Collection<int, PlanIndicator>
     */
    #[Computed]
    public function targets(): Collection
    {
        $targets = PlanIndicator::query()
            ->select(['id', 'source_type', 'source_id', 'indicator_id', 'target', 'project_id', 'proposal_location_id', 'period'])
            ->where('source_type', 'proposal')
            ->where('source_id', $this->proposalId)
            ->with([
                // chainIssuesForRow() reads the instance's own row (Y-16, D-93).
                'indicator:id,name,result_id,project_id,proposal_location_id,measure,unit',
                'indicator.result:id,statement,type,program_id,proposal_id',
                'project:id,name,program_id',
                // chainIssues() reads the row now (Y-16, D-93).
                'location:id,proposal_id,program_id',
                'reports:id,plan_indicator_id,status,value,planned_date,period_start,period_end,submit_date',
            ])
            ->get();

        $source = $this->proposal->setRelation('locations', $this->projects);
        $targets->each(fn (PlanIndicator $target) => $target->setRelation('source', $source));

        return $targets;
    }

    /**
     * Every report filed against a target committed in this proposal — what
     * the donor is owed, newest due date first.
     *
     * Read off the targets already loaded, so the tab costs no extra query.
     * Each report carries its target so the row can name the indicator.
     *
     * @return \Illuminate\Support\Collection<int, Report>
     */
    #[Computed]
    public function reports(): \Illuminate\Support\Collection
    {
        return $this->targets
            ->flatMap(fn (PlanIndicator $target) => $target->reports->each(fn (Report $report) => $report->setRelation('planIndicator', $target)))
            ->sortBy([
                fn (Report $a, Report $b): int => $b->planned_date <=> $a->planned_date,
                fn (Report $a, Report $b): int => $b->id <=> $a->id,
            ])
            ->values();
    }

    /**
     * The reports owed to donors under this proposal's agreements, carrying
     * their agreement and donor so a row can name who is owed.
     *
     * @return Collection<int, DonorReport>
     */
    #[Computed]
    public function donorReports(): Collection
    {
        if ($this->agreements->isEmpty()) {
            return new Collection;
        }

        return DonorReport::query()
            ->select(['id', 'donor_proposal_id', 'title', 'type', 'due_on', 'submitted_on'])
            ->whereIn('donor_proposal_id', $this->agreements->modelKeys())
            ->orderBy('due_on')
            ->orderBy('id')
            ->get()
            ->each(fn (DonorReport $report) => $report->setRelation('donorProposal', $this->agreements->find($report->donor_proposal_id)));
    }

    /**
     * Whether a report is still owed: drafted but never submitted for review.
     */
    protected function reportIsOwed(Report $report): bool
    {
        return $report->status === ReportStatus::Draft && $report->submit_date === null;
    }
}
