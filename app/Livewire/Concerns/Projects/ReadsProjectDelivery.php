<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Projects;

use App\Enums\IndicatorMeasure;
use App\Enums\TaskStatus;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Result;
use App\Models\Risk;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;

/**
 * The delivery reads on projects/show: the project's tasks, the next ones
 * due, risks and indicator targets.
 *
 * Used by projects/show only. The page provides projectId and the #[Computed]
 * project().
 *
 * @property int $projectId
 * @property-read Project $project  #[Computed] project()
 * @property-read Collection<int, Task> $tasks  #[Computed] tasks()
 * @property-read Collection<int, PlanIndicator> $targets  #[Computed] targets()
 */
trait ReadsProjectDelivery
{
    /**
     * Tasks raised straight against this project.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function tasks(): Collection
    {
        return Task::query()
            ->select(['id', 'source_type', 'source_id', 'title', 'status', 'priority', 'due_date', 'progress_percentage'])
            ->with(['taskAssignees.employee:id,first_name_en,last_name_en,first_name_ar,last_name_ar', 'source'])
            ->where('source_type', 'project')
            ->where('source_id', $this->projectId)
            ->orderByRaw('due_date is null, due_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * The next three open tasks with a due date, soonest first, for the
     * Overview's Coming up card.
     *
     * @return \Illuminate\Support\Collection<int, Task>
     */
    #[Computed]
    public function upcomingTasks(): \Illuminate\Support\Collection
    {
        return $this->tasks
            ->toBase()
            ->filter(fn (Task $task): bool => $task->due_date !== null && $task->status !== TaskStatus::Done)
            ->sortBy(fn (Task $task): string => $task->due_date->toDateString())
            ->take(3)
            ->values();
    }

    /**
     * The risks the place's manager should be aware of (W-09, D-61): those
     * picked onto the logframes of the proposals linked to this project, plus
     * the place's own register. Read-only; one query. Framework risks sit with
     * their proposal, the register rows in a group of their own.
     *
     * Since Y-13 (D-93) a register risk belongs to a location row and carries
     * that row's proposal, so the two groups are told apart by the row (see
     * `Risk::isOnLogframe()`): the register group is read through the rows
     * carrying this project, not through `proposal_id`. A register risk the
     * backfill could not map (D-80) is still shown by its project, so nothing
     * drops out of the panel.
     *
     * @return Collection<int, Risk>
     */
    #[Computed]
    public function riskAwareness(): Collection
    {
        $rowIds = DB::table('proposal_locations')->select('id')->where('project_id', $this->projectId);

        return Risk::query()
            ->select(['id', 'name', 'name_en', 'impact', 'solution', 'happened', 'likelihood', 'severity', 'project_id', 'proposal_location_id', 'proposal_id', 'result_id'])
            ->with(['proposal:id,name', 'location:id,program_id,city_id', 'location.program:id'])
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $logframe) => $logframe
                    ->whereNull('proposal_location_id')
                    ->whereIn('proposal_id', DB::table('project_proposal')->select('proposal_id')->where('project_id', $this->projectId)))
                ->orWhereIn('proposal_location_id', $rowIds)
                ->orWhere(fn (Builder $unmapped) => $unmapped
                    ->whereNull('proposal_location_id')
                    ->whereNull('proposal_id')
                    ->where('project_id', $this->projectId)))
            ->orderByDesc('happened')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, PlanIndicator> */
    #[Computed]
    public function targets(): Collection
    {
        return PlanIndicator::query()
            ->select(['id', 'source_type', 'source_id', 'indicator_id', 'target', 'project_id', 'period'])
            ->where('project_id', $this->projectId)
            ->with(['indicator:id,name,result_id,measure,unit', 'indicator.result:id,statement,type'])
            ->withCount('reports')
            ->orderBy('id')
            ->get();
    }

    /**
     * Targets grouped by the result they measure and, within that, by the
     * indicator committed against — the indicator -> result and
     * indicator -> source/period connections shown on the targets tab.
     * Groups sort in logframe order (impact, then outcome, then output).
     *
     * @return \Illuminate\Support\Collection<int, array{result: Result, indicators: \Illuminate\Support\Collection<int, array{indicator: Indicator, targets: Collection<int, PlanIndicator>}>}>
     */
    #[Computed]
    public function targetsByResult(): \Illuminate\Support\Collection
    {
        return $this->targets
            ->groupBy(fn (PlanIndicator $target) => $target->indicator->result_id)
            ->map(fn (Collection $groupTargets) => [
                'result' => $groupTargets->first()->indicator->result,
                'indicators' => $groupTargets
                    ->groupBy('indicator_id')
                    ->map(fn (Collection $indicatorTargets) => [
                        'indicator' => $indicatorTargets->first()->indicator,
                        'targets' => $indicatorTargets,
                    ])
                    ->values(),
            ])
            ->sortBy(fn (array $group): int => $group['result']->type->rank())
            ->values();
    }

    /**
     * The project's indicators from every source (T13): instances in the
     * frameworks of the proposals funding it, and the program framework's
     * indicators that are organisation-wide or scoped to this project.
     * Grouped by source, proposals first; one query.
     *
     * @return \Illuminate\Support\Collection<int, array{key: string, proposal: ?Proposal, indicators: Collection<int, Indicator>}>
     */
    #[Computed]
    public function projectIndicators(): \Illuminate\Support\Collection
    {
        $proposalIds = $this->project->proposals->modelKeys();
        $programId = $this->project->program_id;

        $indicators = Indicator::query()
            ->select(['id', 'name', 'code', 'result_id', 'library_indicator_id', 'project_id', 'measure', 'unit'])
            ->whereIn('result_id', Result::query()
                ->select('id')
                ->where(fn (Builder $query): Builder => $query
                    ->whereIn('proposal_id', $proposalIds)
                    ->orWhere(fn (Builder $programFramework): Builder => $programFramework->whereNull('proposal_id')->where('program_id', $programId ?? 0))))
            ->where(fn (Builder $query): Builder => $query->whereNull('project_id')->orWhere('project_id', $this->projectId))
            ->with([
                'result:id,statement,type,proposal_id,program_id',
                'result.proposal:id,name,slug',
                'libraryIndicator:id,name_ar,name_en',
            ])
            ->orderBy('id')
            ->get();

        return $indicators
            ->groupBy(fn (Indicator $indicator): string => $indicator->result->proposal_id === null ? 'program' : 'proposal-'.$indicator->result->proposal_id)
            ->map(fn (Collection $group, string $key): array => [
                'key' => $key,
                'proposal' => $group->first()->result->proposal,
                'indicators' => $group->sortBy(fn (Indicator $indicator): array => [$indicator->result->type->rank(), $indicator->result_id, $indicator->id])->values(),
            ])
            ->sortBy(fn (array $group): array => [$group['proposal'] === null ? 1 : 0, $group['proposal']?->name ?? ''])
            ->values();
    }

    /**
     * How many distinct indicators are named by this project's targets, for
     * the overview strip above the target lineage.
     */
    #[Computed]
    public function targetedIndicatorCount(): int
    {
        return $this->targets->pluck('indicator_id')->unique()->count();
    }

    /**
     * A committed target's value, in the indicator's own unit: a percentage
     * indicator reads "42.00%", an indicator with a recorded unit reads
     * "42.00 households", and one with neither reads as a bare number.
     */
    protected function formatTargetValue(PlanIndicator $target): string
    {
        $value = number_format((float) $target->target, 2);

        if ($target->indicator->measure === IndicatorMeasure::Percentage) {
            return __('projects.target_value_percentage', ['value' => $value]);
        }

        if ($target->indicator->unit !== null) {
            return __('projects.target_value_with_unit', ['value' => $value, 'unit' => $target->indicator->unit]);
        }

        return $value;
    }
}
