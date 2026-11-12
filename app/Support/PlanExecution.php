<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\PlanUpdateStatus;
use App\Enums\PlanWeekStatus;
use App\Models\ActivityPlan;
use App\Models\ActivityPlanBatch;
use App\Models\ActivityPlanItem;
use App\Models\PlanUpdate;
use App\Models\PlanUpdateWeek;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * How an approved plan is being carried out (2026-11-11 agreement): its
 * centres, the monthly updates sent for them, and, per centre and month,
 * which batches the update covers, whether it can be sent, and what each
 * activity's status is. Reads the plan once; every answer is in memory.
 *
 * Only updates that are sent and not returned count on the live plan
 * (PlanUpdateStatus::isOnThePlan()); a returned month's weeks are hidden
 * until it is sent again.
 */
final class PlanExecution
{
    public readonly PlanCalendar $calendar;

    /** @var list<Project> */
    private array $projects;

    /** @var Collection<string, PlanUpdate> keyed "projectId|Y-m" */
    private Collection $updates;

    public function __construct(public readonly ActivityPlan $plan)
    {
        $plan->loadMissing(['proposal', 'items.activity:id,statement', 'items.batches', 'items.cities']);
        $this->calendar = PlanCalendar::forProposal($plan->proposal);
        $this->projects = ActivityPlanScope::projects($plan);
        $this->updates = PlanUpdate::query()
            ->where('activity_plan_id', $plan->id)
            ->with(['weeks', 'numbers'])
            ->get()
            ->keyBy(fn (PlanUpdate $update): string => $update->project_id.'|'.$update->month->format('Y-m'));
    }

    public static function for(ActivityPlan $plan): self
    {
        return new self($plan);
    }

    /**
     * The plan's centres that run at least one of its activities.
     *
     * @return list<Project>
     */
    public function projects(): array
    {
        return array_values(array_filter($this->projects, fn (Project $project): bool => $this->itemsFor($project) !== []));
    }

    public function project(int $projectId): ?Project
    {
        foreach ($this->projects as $project) {
            if ($project->id === $projectId) {
                return $project;
            }
        }

        return null;
    }

    /**
     * The plan's activities that run in the centre's governorate.
     *
     * @return list<ActivityPlanItem>
     */
    public function itemsFor(Project $project): array
    {
        $cityId = MealCoverage::cityIdOf($project);

        return array_values($this->plan->items->filter(fn (ActivityPlanItem $item): bool => in_array($cityId, $item->cityIds(), true))->all());
    }

    /**
     * The plan's months up to and including today's.
     *
     * @return list<CarbonImmutable>
     */
    public function startedMonths(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();

        return array_values(array_filter($this->calendar->months(), fn (CarbonImmutable $month): bool => $month->lte($today)));
    }

    public function update(Project $project, CarbonImmutable $month): ?PlanUpdate
    {
        return $this->updates->get($project->id.'|'.$month->format('Y-m'));
    }

    /**
     * Every update of the plan, keyed "projectId|Y-m".
     *
     * @return Collection<string, PlanUpdate>
     */
    public function updates(): Collection
    {
        return $this->updates;
    }

    /**
     * The batches a centre's update for a month covers: planned in one of
     * its weeks, still running (or late) the week before, or rescheduled
     * into it.
     *
     * @return list<array{item: ActivityPlanItem, batch: ActivityPlanBatch, index: int}>
     */
    public function batchesFor(Project $project, CarbonImmutable $month): array
    {
        $mondays = $this->calendar->mondaysOf($month);

        if ($mondays === []) {
            return [];
        }

        $previous = $mondays[0]->subWeek();
        $rows = [];

        foreach ($this->itemsFor($project) as $item) {
            foreach ($item->batches->values() as $index => $batch) {
                $planned = array_filter($mondays, fn (CarbonImmutable $monday): bool => $monday->betweenIncluded($batch->start_week, $batch->end_week)) !== [];
                $carried = in_array($this->entry($project, $batch, $previous)?->status, [PlanWeekStatus::Started, PlanWeekStatus::Ongoing, PlanWeekStatus::Suspended, PlanWeekStatus::Rescheduled, PlanWeekStatus::NotStarted], true);
                $movedIn = $this->movedInto($project, $batch, $mondays);

                if ($planned || $carried || $movedIn) {
                    $rows[] = ['item' => $item, 'batch' => $batch, 'index' => $index];
                }
            }
        }

        return $rows;
    }

    /**
     * What a centre reported for a batch in one week, from a sent update.
     */
    public function entry(Project $project, ActivityPlanBatch $batch, CarbonImmutable $monday): ?PlanUpdateWeek
    {
        $update = $this->update($project, $monday->startOfMonth());

        if ($update === null || ! $update->status->isOnThePlan()) {
            return null;
        }

        return $update->weeks->first(fn (PlanUpdateWeek $week): bool => $week->activity_plan_batch_id === $batch->id && $week->week->equalTo($monday));
    }

    /**
     * Why a centre's update for a month cannot be sent yet: the month has
     * not ended, or last month's update is not approved by the MEAL officer.
     *
     * @return list<string>
     */
    public function sendBlockers(Project $project, CarbonImmutable $month, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $reasons = [];
        $opens = $month->startOfMonth()->addMonth();

        if ($today->lt($opens)) {
            $reasons[] = __('annual_plans.up.blocked_month', ['date' => $opens->translatedFormat(__('formats.date')), 'month' => $month->translatedFormat('F')]);
        }

        $months = $this->calendar->months();
        $position = array_search($month->format('Y-m'), array_map(fn (CarbonImmutable $value): string => $value->format('Y-m'), $months), true);

        if ($position !== false && $position > 0) {
            $previous = $this->update($project, $months[$position - 1]);

            if ($previous === null || ! $previous->status->isOfficerApproved()) {
                $reasons[] = __('annual_plans.up.blocked_previous', ['month' => $months[$position - 1]->translatedFormat('F')]);
            }
        }

        return $reasons;
    }

    /**
     * Every week a centre reported for an activity, from sent updates, oldest first.
     *
     * @return list<array{batch: int, week: CarbonImmutable, entry: PlanUpdateWeek}>
     */
    public function entriesFor(Project $project, ActivityPlanItem $item): array
    {
        $batchIds = $item->batches->pluck('id')->all();
        $entries = [];

        foreach ($this->updates as $update) {
            if ($update->project_id !== $project->id || ! $update->status->isOnThePlan()) {
                continue;
            }

            foreach ($update->weeks as $week) {
                if (in_array($week->activity_plan_batch_id, $batchIds, true)) {
                    $entries[] = ['batch' => $week->activity_plan_batch_id, 'week' => $week->week, 'entry' => $week];
                }
            }
        }

        usort($entries, fn (array $a, array $b): int => $a['week'] <=> $b['week']);

        return $entries;
    }

    /**
     * The last Monday a centre has reported, through its sent updates; null when none.
     */
    public function lastReportedMonday(Project $project): ?CarbonImmutable
    {
        $last = null;

        foreach ($this->updates as $update) {
            if ($update->project_id === $project->id && $update->status->isOnThePlan()) {
                $mondays = $this->calendar->mondaysOf($update->month);
                $end = $mondays === [] ? null : $mondays[count($mondays) - 1];

                if ($end !== null && ($last === null || $end->gt($last))) {
                    $last = $end;
                }
            }
        }

        return $last;
    }

    /**
     * An activity's status in a centre: cancelled, done (every batch completed
     * or conducted), suspended (in the last reported week), not started yet,
     * late by N weeks (2+), or on track.
     *
     * @return array{key: string, weeks: int}
     */
    public function status(Project $project, ActivityPlanItem $item): array
    {
        $entries = $this->entriesFor($project, $item);
        $last = $this->lastReportedMonday($project);

        if (collect($entries)->contains(fn (array $row): bool => $row['entry']->status === PlanWeekStatus::Cancelled)) {
            return ['key' => 'cancelled', 'weeks' => 0];
        }

        if ($item->batches->isNotEmpty() && $item->batches->every(fn (ActivityPlanBatch $batch): bool => collect($entries)->contains(fn (array $row): bool => $row['batch'] === $batch->id && $row['entry']->status->isFinish()))) {
            return ['key' => 'done', 'weeks' => 0];
        }

        if ($last !== null && collect($entries)->contains(fn (array $row): bool => $row['week']->equalTo($last) && $row['entry']->status === PlanWeekStatus::Suspended)) {
            return ['key' => 'suspended', 'weeks' => 0];
        }

        $firstStart = $item->batches->min('start_week');

        if ($last === null || $firstStart === null || $firstStart->gt($last)) {
            return ['key' => 'not_started', 'weeks' => 0];
        }

        $late = 0;

        foreach ($item->batches as $batch) {
            if ($batch->start_week->gt($last)) {
                continue;
            }

            $active = collect($entries)->filter(fn (array $row): bool => $row['batch'] === $batch->id && $row['entry']->status->isActive())->pluck('week');
            $first = $active->min();
            $weeks = $first === null
                ? intdiv((int) $batch->start_week->diffInDays($last), 7) + 1
                : max(0, intdiv((int) $batch->start_week->diffInDays($first), 7));
            $late = max($late, $weeks);
        }

        return $late >= 2 ? ['key' => 'late', 'weeks' => $late] : ['key' => 'on_track', 'weeks' => 0];
    }

    /**
     * Units held for an activity in a centre, from sent updates.
     */
    public function held(Project $project, ActivityPlanItem $item): int
    {
        $batchIds = $item->batches->pluck('id')->all();
        $total = 0;

        foreach ($this->updates as $update) {
            if ($update->project_id === $project->id && $update->status->isOnThePlan()) {
                $total += (int) $update->numbers->whereIn('activity_plan_batch_id', $batchIds)->sum('done_count');
            }
        }

        return $total;
    }

    /**
     * Units the plan expected by the last reported week, pro rata per batch.
     */
    public function plannedToDate(Project $project, ActivityPlanItem $item): int
    {
        $last = $this->lastReportedMonday($project);

        if ($last === null) {
            return 0;
        }

        $total = 0.0;

        foreach ($item->batches as $batch) {
            $weeks = $batch->weekCount();
            $covered = 0;

            foreach ($batch->mondays() as $monday) {
                if ($monday->lte($last)) {
                    $covered++;
                }
            }

            $total += ($batch->planned_count ?? 0) * $covered / max(1, $weeks);
        }

        return (int) round($total);
    }

    /**
     * Done everywhere it runs.
     */
    public function isDoneEverywhere(ActivityPlanItem $item): bool
    {
        $projects = array_filter($this->projects(), fn (Project $project): bool => in_array(MealCoverage::cityIdOf($project), $item->cityIds(), true));

        return $projects !== [] && array_filter($projects, fn (Project $project): bool => $this->status($project, $item)['key'] !== 'done') === [];
    }

    /**
     * @param  list<CarbonImmutable>  $mondays
     */
    private function movedInto(Project $project, ActivityPlanBatch $batch, array $mondays): bool
    {
        $keys = array_map(fn (CarbonImmutable $monday): string => $monday->toDateString(), $mondays);

        foreach ($this->updates as $update) {
            if ($update->project_id !== $project->id || ! $update->status->isOnThePlan()) {
                continue;
            }

            foreach ($update->weeks as $week) {
                if ($week->activity_plan_batch_id === $batch->id && $week->status === PlanWeekStatus::Rescheduled && $week->new_week !== null && in_array($week->new_week->toDateString(), $keys, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
