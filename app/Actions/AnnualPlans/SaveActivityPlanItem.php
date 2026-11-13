<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Enums\CountUnit;
use App\Models\ActivityPlanItem;
use App\Models\User;
use App\Support\ActivityPlanScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Step 3, one activity: how it runs, what it counts, its batches of Monday
 * weeks, where it runs, the expected people and a note. Saving keeps a
 * draft; "Done" ($ready) also needs every batch counted (and timed, for
 * sessions), a governorate and a name for an "Other" unit, and marks the
 * activity Ready. A later change that leaves it incomplete clears Ready.
 *
 * Input: single_week bool, unit, unit_other, cities list<int>,
 * expected_people, note, batches list<{start: Y-m-d, end: Y-m-d, count, hours}>.
 */
final class SaveActivityPlanItem
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $user, ActivityPlanItem $item, array $input, bool $ready): ActivityPlanItem
    {
        $plan = $item->plan()->firstOrFail();
        Gate::forUser($user)->authorize('update', $plan);
        $calendar = $plan->calendar();
        $data = self::validated($input, ActivityPlanScope::cityIds($plan), $calendar->firstMonday(), $calendar->lastMonday());
        $complete = self::isComplete($data);

        if ($ready && ! $complete) {
            throw ValidationException::withMessages(['item' => __('annual_plans.item_incomplete')]);
        }

        DB::transaction(fn () => self::write($item, $data, $ready || ($item->is_ready && $complete)));

        return $item->refresh();
    }

    /**
     * @param  array{single_week: bool, unit: string, unit_other: string|null, cities: list<int>, expected_people: int|null, note: string|null, batches: list<array{start: string, end: string, count: int|null, hours: float|null}>}  $data
     */
    public static function write(ActivityPlanItem $item, array $data, bool $isReady): void
    {
        $unit = CountUnit::from($data['unit']);
        $item->fill([
            'is_single_week' => $data['single_week'],
            'unit' => $unit->value,
            'unit_other' => $unit === CountUnit::Other ? $data['unit_other'] : null,
            'expected_people' => $data['expected_people'],
            'note' => $data['note'],
            'is_ready' => $isReady,
        ])->save();

        $item->batches()->delete();

        foreach ($data['batches'] as $sort => $batch) {
            $item->batches()->create([
                'start_week' => $batch['start'],
                'end_week' => $data['single_week'] ? $batch['start'] : $batch['end'],
                'planned_count' => $batch['count'],
                'hours_each' => $unit->needsHours() ? $batch['hours'] : null,
                'sort' => $sort,
            ]);
        }

        $item->cities()->delete();

        foreach ($data['cities'] as $cityId) {
            $item->cities()->create(['city_id' => $cityId]);
        }
    }

    /**
     * Shape-check the input; dates must be Mondays of the plan.
     *
     * @param  array<string, mixed>  $input
     * @param  list<int>  $planCityIds
     * @return array{single_week: bool, unit: string, unit_other: string|null, cities: list<int>, expected_people: int|null, note: string|null, batches: list<array{start: string, end: string, count: int|null, hours: float|null}>}
     */
    public static function validated(array $input, array $planCityIds, CarbonImmutable $firstMonday, CarbonImmutable $lastMonday): array
    {
        $data = Validator::make($input, [
            'single_week' => ['required', 'boolean'],
            'unit' => ['required', 'in:'.implode(',', CountUnit::values())],
            'unit_other' => ['nullable', 'string', 'max:60'],
            'cities' => ['present', 'array'],
            'cities.*' => ['integer', 'in:'.implode(',', $planCityIds === [] ? [0] : $planCityIds)],
            'expected_people' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:500'],
            'batches' => ['required', 'array', 'min:1', 'max:30'],
            'batches.*.start' => ['required', 'date_format:Y-m-d'],
            'batches.*.end' => ['nullable', 'date_format:Y-m-d'],
            'batches.*.count' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'batches.*.hours' => ['nullable', 'numeric', 'min:0.5', 'max:24'],
        ])->validate();

        $single = (bool) $data['single_week'];
        $batches = [];

        foreach (array_values($data['batches']) as $index => $batch) {
            $start = CarbonImmutable::parse($batch['start'])->startOfDay();
            $end = $single || blank($batch['end'] ?? null) ? $start : CarbonImmutable::parse($batch['end'])->startOfDay();

            foreach ([$start, $end] as $week) {
                if (! $week->isMonday() || $week->lt($firstMonday) || $week->gt($lastMonday)) {
                    throw ValidationException::withMessages(["batches.{$index}" => __('annual_plans.week_outside_plan')]);
                }
            }

            if ($end->lt($start)) {
                throw ValidationException::withMessages(["batches.{$index}" => __('annual_plans.end_before_start')]);
            }

            $batches[] = [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'count' => filled($batch['count'] ?? null) ? (int) $batch['count'] : null,
                'hours' => filled($batch['hours'] ?? null) ? (float) $batch['hours'] : null,
            ];
        }

        $cities = array_values(array_unique(array_map('intval', $data['cities'])));
        sort($cities);
        $other = trim((string) ($data['unit_other'] ?? ''));
        $note = trim((string) ($data['note'] ?? ''));

        return [
            'single_week' => $single,
            'unit' => (string) $data['unit'],
            'unit_other' => $other === '' ? null : $other,
            'cities' => $cities,
            'expected_people' => filled($data['expected_people'] ?? null) ? (int) $data['expected_people'] : null,
            'note' => $note === '' ? null : $note,
            'batches' => $batches,
        ];
    }

    /**
     * Whether the activity has everything "Ready" needs.
     *
     * @param  array{unit: string, unit_other: string|null, cities: list<int>, batches: list<array{count: int|null, hours: float|null}>}  $data
     */
    public static function isComplete(array $data): bool
    {
        $unit = CountUnit::from($data['unit']);

        if ($data['cities'] === [] || $data['batches'] === [] || ($unit === CountUnit::Other && $data['unit_other'] === null)) {
            return false;
        }

        foreach ($data['batches'] as $batch) {
            if ($batch['count'] === null || ($unit->needsHours() && $batch['hours'] === null)) {
                return false;
            }
        }

        return true;
    }
}
