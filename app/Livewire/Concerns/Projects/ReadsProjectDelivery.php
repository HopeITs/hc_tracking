<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Projects;

use App\Enums\IndicatorMeasure;
use App\Enums\ResultType;
use App\Models\Indicator;
use App\Models\Milestone;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Result;
use App\Models\Risk;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

/**
 * The delivery reads on projects/show: milestone progress and timeline, the
 * project's tasks, risks and indicator targets.
 *
 * Used by projects/show only. The page provides projectId and the #[Computed]
 * project().
 *
 * @property int $projectId
 * @property-read Project $project  #[Computed] project()
 * @property-read Collection<int, Milestone> $milestonesFlat  #[Computed] milestonesFlat()
 * @property-read Collection<int, PlanIndicator> $targets  #[Computed] targets()
 */
trait ReadsProjectDelivery
{
    /**
     * Weighted milestone completion, the same formula used on the project
     * directory and program detail pages: reached weight over every weight
     * that still counts (cancelled milestones are excluded from both sides).
     */
    #[Computed]
    public function milestoneProgressPercent(): ?float
    {
        $planned = (float) ($this->project->milestones_planned_weight ?? 0);

        if ($planned <= 0) {
            return null;
        }

        $reached = (float) ($this->project->milestones_reached_weight ?? 0);

        return round(min(100, ($reached / $planned) * 100), 1);
    }

    /**
     * Every milestone in the project, flattened and ordered by sequence, for
     * the delivery timeline: the same reading as the program detail page's
     * project timeline, one row per milestone instead of per project.
     *
     * @return Collection<int, Milestone>
     */
    #[Computed]
    public function milestonesFlat(): Collection
    {
        return Milestone::query()
            ->select(['id', 'project_id', 'name', 'planned_start_date', 'planned_end_date', 'actual_start_date', 'actual_end_date', 'status', 'sequence', 'weight_percentage', 'responsible_user_id'])
            ->where('project_id', $this->projectId)
            ->with('responsibleUser:id,first_name_en,last_name_en,first_name_ar,last_name_ar')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();
    }

    /**
     * The shared time axis for the milestone timeline: the project's own
     * schedule, widened to fit every milestone's planned and actual date plus
     * today, so nothing renders off-window.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    #[Computed]
    public function milestoneWindow(): array
    {
        $dates = collect([$this->project->start_date, $this->project->end_date])->filter();

        foreach ($this->milestonesFlat as $milestone) {
            $dates->push($milestone->planned_start_date);
            $dates->push($milestone->planned_end_date);
            $dates->push($milestone->actual_start_date);
            $dates->push($milestone->actual_end_date);
        }

        $dates = $dates->filter()->push(CarbonImmutable::today());

        return ['start' => $dates->min(), 'end' => $dates->max()];
    }

    /**
     * Tasks raised straight against this project or against one of its
     * milestones — a task has no project column, so a milestone-sourced task
     * is reached through the milestone ids belonging to this project.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function tasks(): Collection
    {
        $milestoneIds = Milestone::query()->where('project_id', $this->projectId)->pluck('id');

        return Task::query()
            ->select(['id', 'source_type', 'source_id', 'title', 'status', 'priority', 'due_date', 'progress_percentage'])
            ->with(['taskAssignees.employee:id,first_name_en,last_name_en,first_name_ar,last_name_ar', 'source'])
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('source_type', 'project')->where('source_id', $this->projectId))
                ->orWhere(fn (Builder $query) => $query->where('source_type', 'milestone')->whereIn('source_id', $milestoneIds)))
            ->orderByRaw('due_date is null, due_date')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, Risk> */
    #[Computed]
    public function risks(): Collection
    {
        return Risk::query()
            ->select(['id', 'name', 'impact', 'solution', 'happened', 'project_id'])
            ->where('project_id', $this->projectId)
            ->orderByDesc('happened')
            ->orderBy('name')
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
        $resultOrder = [ResultType::Impact->value => 0, ResultType::Outcome->value => 1, ResultType::Output->value => 2];

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
            ->sortBy(fn (array $group) => $resultOrder[$group['result']->type->value] ?? 99)
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
