<?php

declare(strict_types=1);

namespace App\Actions\Results;

use App\Enums\ActivityKind;
use App\Enums\ResultType;
use App\Models\ActivitySchedule;
use App\Models\Proposal;
use App\Models\Result;
use App\Support\ProposalFramework;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Replace the calendar of one activity of a proposal's framework, for the
 * whole proposal or for one of its centers (contracts §11.2, D-49).
 *
 * - Who: ActivitySchedulePolicy::update (MEAL and the proposal editor before
 *   approval, MEAL alone after it). After approval the change is logged on
 *   the activity as `result.updated` (field `schedule`).
 * - Scope: the activity is loaded through the proposal's framework and the
 *   project through the proposal's links (BOLA).
 * - Shape: a continuous activity runs every month from its first to its last;
 *   an event keeps the months given. Months lie inside
 *   ProposalFramework::scheduleWindow(); weeks are 1–5 and only on scheduled
 *   months. A center calendar saved empty falls back to the proposal's.
 * - Concurrency: the editor sends the version it saw (ActivitySchedule::
 *   versionOf()); a calendar changed meanwhile is refused and nothing is
 *   written. The activity row lock also covers the proposal-calendar rows,
 *   whose NULL project the unique index cannot see.
 *
 * Error keys: months, weeks, schedule.
 */
final class SaveActivitySchedule
{
    /**
     * @param  array{months?: mixed, weeks?: mixed, expected_version?: mixed}  $input  months: list<'Y-m'>; weeks: array<'Y-m', list<int>> (empty = whole month)
     * @return array<string, list<int>|null> the saved calendar
     *
     * @throws ValidationException
     */
    public function handle(Proposal $proposal, int $activityId, ?int $projectId, array $input): array
    {
        Gate::authorize('update', [ActivitySchedule::class, $proposal]);

        $request = $this->validateInput($input);

        return DB::transaction(function () use ($proposal, $activityId, $projectId, $request): array {
            $locked = Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();

            // The status may have moved since the page loaded: decide on the locked row.
            Gate::authorize('update', [ActivitySchedule::class, $locked]);

            $activity = Result::query()
                ->proposalFramework($locked->id)
                ->where('type', ResultType::Activity->value)
                ->whereKey($activityId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($projectId !== null) {
                $locked->projects()->whereKey($projectId)->firstOrFail();
            }

            $current = ActivitySchedule::calendarOf(
                ActivitySchedule::query()->where('result_id', $activity->id)->where('project_id', $projectId)->get(['id', 'month', 'weeks'])
            );

            if (ActivitySchedule::versionOf($current) !== $request['expected_version']) {
                $this->refuse('schedule', 'results_framework.schedule_stale');
            }

            $calendar = $this->calendarFor($activity, $request['months'], $request['weeks'], ProposalFramework::scheduleWindow($locked));

            if ($calendar === $current) {
                return $current;
            }

            ActivitySchedule::query()->where('result_id', $activity->id)->where('project_id', $projectId)->delete();

            foreach ($calendar as $month => $weeks) {
                ActivitySchedule::query()->create([
                    'result_id' => $activity->id,
                    'project_id' => $projectId,
                    'month' => $month.'-01',
                    'weeks' => $weeks,
                ]);
            }

            if ($locked->status->isApprovedByDonor()) {
                $activity->recordActivity(
                    'result.updated',
                    ['schedule' => [ActivitySchedule::summaryOf($current), ActivitySchedule::summaryOf($calendar)]],
                    ['field' => 'schedule', 'project_id' => $projectId],
                );
            }

            return $calendar;
        });
    }

    /**
     * Shape-check the input. Months and weeks are checked against the
     * activity and the proposal later, under locks.
     *
     * @param  array<string, mixed>  $input
     * @return array{months: list<string>, weeks: array<string, list<int>>, expected_version: string}
     *
     * @throws ValidationException
     */
    private function validateInput(array $input): array
    {
        $data = Validator::make($input, [
            'months' => ['present', 'array'],
            'months.*' => ['string', 'date_format:Y-m'],
            'weeks' => ['present', 'array'],
            'weeks.*' => ['array'],
            'weeks.*.*' => ['integer'],
            'expected_version' => ['required', 'string'],
        ], [
            'months.*' => __('results_framework.month_outside_period'),
            'weeks.*' => __('results_framework.week_range'),
            'weeks.*.*' => __('results_framework.week_range'),
            'expected_version' => __('results_framework.schedule_stale'),
        ])->validate();

        $months = array_values(array_unique(array_map('strval', $data['months'])));
        sort($months);

        $weeks = [];

        foreach ($data['weeks'] as $month => $monthWeeks) {
            $weeks[(string) $month] = array_map('intval', array_values($monthWeeks));
        }

        return ['months' => $months, 'weeks' => $weeks, 'expected_version' => (string) $data['expected_version']];
    }

    /**
     * The calendar to store: the months completed for a continuous run,
     * checked against the window, with their weeks.
     *
     * @param  list<string>  $months  sorted 'Y-m'
     * @param  array<string, list<int>>  $weeks
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $window
     * @return array<string, list<int>|null>
     *
     * @throws ValidationException
     */
    private function calendarFor(Result $activity, array $months, array $weeks, array $window): array
    {
        if ($activity->activity_kind === ActivityKind::Continuous && $months !== []) {
            $months = $this->run($months[0], $months[count($months) - 1]);
        }

        [$first, $last] = $window;

        foreach ($months as $month) {
            $start = CarbonImmutable::createFromFormat('!Y-m', $month);

            if ($start === null || $start->lt($first) || $start->gt($last)) {
                $this->refuse('months', 'results_framework.month_outside_period');
            }
        }

        $calendar = array_fill_keys($months, null);

        foreach ($weeks as $month => $monthWeeks) {
            if (! array_key_exists($month, $calendar)) {
                $this->refuse('weeks', 'results_framework.weeks_need_month');
            }

            foreach ($monthWeeks as $week) {
                if ($week < 1 || $week > 5) {
                    $this->refuse('weeks', 'results_framework.week_range');
                }
            }

            $monthWeeks = array_values(array_unique($monthWeeks));
            sort($monthWeeks);
            $calendar[$month] = $monthWeeks === [] ? null : $monthWeeks;
        }

        return $calendar;
    }

    /**
     * Every month from the first to the last, inclusive.
     *
     * @return list<string>
     */
    private function run(string $firstMonth, string $lastMonth): array
    {
        $months = [];
        $cursor = CarbonImmutable::createFromFormat('!Y-m', $firstMonth);
        $last = CarbonImmutable::createFromFormat('!Y-m', $lastMonth);

        while ($cursor !== null && $last !== null && $cursor->lte($last)) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->addMonth();
        }

        return $months;
    }

    /**
     * @throws ValidationException
     */
    private function refuse(string $field, string $messageKey): never
    {
        throw ValidationException::withMessages([$field => __($messageKey)]);
    }
}
