<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Enums\PlanUpdateStatus;
use App\Enums\PlanWeekStatus;
use App\Models\PlanUpdate;
use App\Models\Project;
use App\Models\User;
use App\Policies\PlanUpdatePolicy;
use App\Support\PlanExecution;
use App\Support\PlanNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Send a monthly update to the MEAL officer. Allowed only after the month
 * ends and once last month's update is approved by the officer
 * (user decision 2026-11-12), and only when complete: a status for every
 * planned week (a reason for Not started, Rescheduled, Suspended and
 * Cancelled, and the new week for Rescheduled), a number for every batch, at
 * least one supporting file, the three written sections and the
 * beneficiaries row (with a disability ≤ total). Locked once sent.
 */
final class SendPlanUpdate
{
    public function handle(User $user, PlanUpdate $update, ?CarbonImmutable $today = null): PlanUpdate
    {
        $update->loadMissing(['plan', 'project', 'weeks', 'numbers', 'files']);
        $plan = $update->plan;
        $project = $update->project;

        if (! PlanUpdatePolicy::canFill($user, $plan, $project, $update)) {
            throw new AuthorizationException;
        }

        $execution = PlanExecution::for($plan);
        $blockers = $execution->sendBlockers($project, $update->month, $today);

        if ($blockers !== []) {
            throw ValidationException::withMessages(['send' => implode(' ', $blockers)]);
        }

        $missing = self::missing($execution, $project, $update);

        if ($missing !== []) {
            throw ValidationException::withMessages(['send' => __('annual_plans.up.incomplete', ['count' => count($missing)])]);
        }

        $sent = DB::transaction(function () use ($user, $update): PlanUpdate {
            $locked = PlanUpdate::query()->lockForUpdate()->findOrFail($update->id);
            $wasReturned = $locked->status === PlanUpdateStatus::Returned;
            $locked->forceFill([
                'status' => PlanUpdateStatus::Sent,
                'sent_at' => now(),
                'sent_by' => $user->id,
                'resent' => $locked->resent || $wasReturned,
                'officer_approved_at' => null,
                'officer_approved_by' => null,
                'returned_to' => null,
            ])->save();

            return $locked;
        });

        PlanNotifier::update($sent, 'annual_plan.update_sent', ['officer', 'meal', 'program_manager'], [], $user);
        app(FireActivityPlanFollowUps::class)->handle($sent);
        self::alertOnWeeks($sent->load('weeks.batch.item.activity'));

        return $sent;
    }

    /**
     * A batch rescheduled two weeks or more tells the program manager to
     * revise the plan (it never blocks sending); a suspension or a
     * cancellation tells the program manager and MEAL.
     */
    private static function alertOnWeeks(PlanUpdate $update): void
    {
        foreach ($update->weeks as $week) {
            $activity = (string) $week->batch?->item?->activity?->statement;
            $moved = $week->new_week === null ? 0 : intdiv((int) $week->week->diffInDays($week->new_week), 7);

            if ($week->status === PlanWeekStatus::Rescheduled && $moved >= 2) {
                PlanNotifier::update($update, 'annual_plan.update_rescheduled', ['program_manager'], ['activity' => $activity, 'weeks' => (string) $moved], null, true);
            }

            if ($week->status === PlanWeekStatus::Suspended || $week->status === PlanWeekStatus::Cancelled) {
                PlanNotifier::update($update, 'annual_plan.update_paused', ['program_manager', 'meal'], ['activity' => $activity, 'status' => $week->status->label()]);
            }
        }
    }

    /**
     * What a complete update still lacks, as error keys the page outlines:
     * "week:batch|date", "reason:batch|date", "new_week:batch|date",
     * "number:batch", "files", "activities_implemented", "challenges",
     * "next_month", "males", "females", "with_disability", "disability_total".
     *
     * @return list<string>
     */
    public static function missing(PlanExecution $execution, Project $project, PlanUpdate $update): array
    {
        $missing = [];
        $mondays = $execution->calendar->mondaysOf($update->month);
        $weeks = $update->weeks->keyBy(fn ($week): string => $week->activity_plan_batch_id.'|'.$week->week->toDateString());

        foreach ($execution->batchesFor($project, $update->month) as $row) {
            $batch = $row['batch'];

            foreach ($mondays as $monday) {
                $key = $batch->id.'|'.$monday->toDateString();
                $entry = $weeks->get($key);
                $planned = $monday->betweenIncluded($batch->start_week, $batch->end_week);
                $moved = $weeks->contains(fn ($week): bool => $week->activity_plan_batch_id === $batch->id
                    && $week->status === PlanWeekStatus::Rescheduled && $week->week->lt($monday)
                    && $week->new_week !== null && $week->new_week->gt($monday));

                if ($planned && $entry === null && ! $moved) {
                    $missing[] = 'week:'.$key;
                }

                if ($entry !== null && $entry->status->needsReason() && blank($entry->reason)) {
                    $missing[] = 'reason:'.$key;
                }

                if ($entry !== null && $entry->status === PlanWeekStatus::Rescheduled && $entry->new_week === null) {
                    $missing[] = 'new_week:'.$key;
                }
            }

            if (! $update->numbers->contains('activity_plan_batch_id', $batch->id)) {
                $missing[] = 'number:'.$batch->id;
            }
        }

        if ($update->files->isEmpty()) {
            $missing[] = 'files';
        }

        foreach (['activities_implemented', 'challenges', 'next_month'] as $field) {
            if (mb_strlen(trim((string) $update->{$field})) < 10) {
                $missing[] = $field;
            }
        }

        foreach (['males', 'females', 'with_disability'] as $field) {
            if ($update->{$field} === null) {
                $missing[] = $field;
            }
        }

        if ($update->with_disability !== null && $update->with_disability > ($update->males ?? 0) + ($update->females ?? 0)) {
            $missing[] = 'disability_total';
        }

        return $missing;
    }
}
