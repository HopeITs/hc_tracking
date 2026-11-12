<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Enums\PlanWeekStatus;
use App\Models\ActivityPlan;
use App\Models\PlanUpdate;
use App\Models\Project;
use App\Models\User;
use App\Policies\PlanUpdatePolicy;
use App\Support\PlanExecution;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Save the project manager's draft of a monthly update (any time, also
 * mid-month; user decision 2026-11-11). Writes what was entered and keeps it:
 * week statuses (only for the month's Mondays and the batches it covers),
 * the numbers done, the narrative and the beneficiaries row. Nothing is
 * required here; SendPlanUpdate checks completeness.
 *
 * Draft: weeks ["batchId|Y-m-d" => {status, reason, new_week}], numbers
 * [batchId => count], activities_implemented, challenges, next_month,
 * males, females, with_disability.
 */
final class SavePlanUpdate
{
    /**
     * @param  array<string, mixed>  $draft
     */
    public function handle(User $user, ActivityPlan $plan, Project $project, CarbonImmutable $month, array $draft): PlanUpdate
    {
        $month = $month->startOfMonth();
        $existing = PlanUpdate::query()->where('activity_plan_id', $plan->id)->where('project_id', $project->id)->where('month', $month->toDateString())->first();

        if (! PlanUpdatePolicy::canFill($user, $plan, $project, $existing)) {
            throw new AuthorizationException;
        }

        $execution = PlanExecution::for($plan);

        if (! in_array($month->format('Y-m'), array_map(fn (CarbonImmutable $value): string => $value->format('Y-m'), $execution->calendar->months()), true)) {
            throw ValidationException::withMessages(['month' => __('annual_plans.up.month_outside_plan')]);
        }

        $batchIds = array_map(fn (array $row): int => $row['batch']->id, $execution->batchesFor($project, $month));
        $mondays = array_map(fn (CarbonImmutable $monday): string => $monday->toDateString(), $execution->calendar->mondaysOf($month));
        $planMondays = array_map(fn (CarbonImmutable $monday): string => $monday->toDateString(), $execution->calendar->mondays());

        return DB::transaction(function () use ($plan, $project, $month, $draft, $existing, $batchIds, $mondays, $planMondays): PlanUpdate {
            $update = $existing ?? PlanUpdate::query()->create([
                'activity_plan_id' => $plan->id,
                'project_id' => $project->id,
                'month' => $month->toDateString(),
            ]);

            $update->fill([
                'activities_implemented' => self::text($draft['activities_implemented'] ?? null),
                'challenges' => self::text($draft['challenges'] ?? null),
                'next_month' => self::text($draft['next_month'] ?? null),
                'males' => self::count($draft['males'] ?? null),
                'females' => self::count($draft['females'] ?? null),
                'with_disability' => self::count($draft['with_disability'] ?? null),
            ])->save();

            $update->weeks()->delete();

            foreach ((array) ($draft['weeks'] ?? []) as $key => $row) {
                [$batchId, $week] = array_pad(explode('|', (string) $key, 2), 2, '');
                $status = PlanWeekStatus::tryFrom((string) ($row['status'] ?? ''));

                if ($status === null || ! in_array((int) $batchId, $batchIds, true) || ! in_array($week, $mondays, true)) {
                    continue;
                }

                $newWeek = (string) ($row['new_week'] ?? '');
                $update->weeks()->create([
                    'activity_plan_batch_id' => (int) $batchId,
                    'week' => $week,
                    'status' => $status->value,
                    'reason' => self::text($row['reason'] ?? null, 500),
                    'new_week' => $status === PlanWeekStatus::Rescheduled && in_array($newWeek, $planMondays, true) && $newWeek > $week ? $newWeek : null,
                ]);
            }

            $update->numbers()->delete();

            foreach ((array) ($draft['numbers'] ?? []) as $batchId => $count) {
                $value = self::count($count);

                if ($value !== null && in_array((int) $batchId, $batchIds, true)) {
                    $update->numbers()->create(['activity_plan_batch_id' => (int) $batchId, 'done_count' => $value]);
                }
            }

            return $update->refresh();
        });
    }

    private static function text(mixed $value, int $max = 5000): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    private static function count(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return max(0, min(1000000, (int) $value));
    }
}
