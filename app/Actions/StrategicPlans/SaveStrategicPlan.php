<?php

declare(strict_types=1);

namespace App\Actions\StrategicPlans;

use App\Enums\IndicatorMeasure;
use App\Models\Program;
use App\Models\StrategicPlan;
use App\Models\StrategicPlanTarget;
use App\Models\StrategicPlanYear;
use App\Models\Strategy;
use App\Models\User;
use App\Support\StrategicPlanIndicators;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Write a program's strategic plan: for each year of the strategy, what must
 * be achieved, and the program indicators (with a figure per year) that
 * measure it. Creates the plan on its first save; only a draft changes.
 *
 * - Who: StrategicPlanPolicy::write (the MEAL manager).
 * - The program must be linked to the strategy; an indicator outside the
 *   program's own framework is refused.
 * - A figure is optional (a linked indicator may wait for one), never
 *   negative, and at most 100 on a percentage.
 *
 * Error keys: plan, indicators, targets.{indicator}.{year}.
 */
final class SaveStrategicPlan
{
    private const float MaxValue = 9999999999.99;

    /**
     * @param  array{goals?: array<int|string, string|null>, indicators?: list<int|string>, targets?: array<int|string, array<int|string, string|int|float|null>>}  $input
     *
     * @throws ValidationException
     */
    public function handle(User $user, Strategy $strategy, Program $program, array $input): StrategicPlan
    {
        $existing = StrategicPlan::query()->where('strategy_id', $strategy->id)->where('program_id', $program->id)->first();

        Gate::forUser($user)->authorize('write', [StrategicPlan::class, $existing]);

        if ($program->strategy_id !== $strategy->id) {
            throw ValidationException::withMessages(['plan' => __('strategic_plans.program_not_in_strategy')]);
        }

        $years = range($strategy->start_year, $strategy->end_year);
        $measures = StrategicPlanIndicators::for($program->id)->mapWithKeys(fn ($indicator): array => [$indicator->id => $indicator->measure])->all();
        $linked = array_values(array_unique(array_map('intval', $input['indicators'] ?? [])));

        if (array_diff($linked, array_keys($measures)) !== []) {
            throw ValidationException::withMessages(['indicators' => __('strategic_plans.indicator_not_in_program')]);
        }

        $figures = $this->figures($linked, $years, $input['targets'] ?? [], $measures);

        return DB::transaction(function () use ($user, $strategy, $program, $years, $input, $linked, $figures): StrategicPlan {
            $plan = StrategicPlan::query()->where('strategy_id', $strategy->id)->where('program_id', $program->id)->lockForUpdate()->first()
                ?? StrategicPlan::query()->create(['strategy_id' => $strategy->id, 'program_id' => $program->id]);

            Gate::forUser($user)->authorize('write', [StrategicPlan::class, $plan]);

            $plan->years()->whereNotIn('year', $years)->delete();

            foreach ($years as $year) {
                $goals = trim((string) ($input['goals'][$year] ?? ''));

                $planYear = StrategicPlanYear::query()->updateOrCreate(
                    ['strategic_plan_id' => $plan->id, 'year' => $year],
                    ['goals' => $goals === '' ? null : $goals],
                );

                $planYear->targets()->whereNotIn('indicator_id', $linked === [] ? [0] : $linked)->delete();

                foreach ($linked as $indicatorId) {
                    StrategicPlanTarget::query()->updateOrCreate(
                        ['strategic_plan_year_id' => $planYear->id, 'indicator_id' => $indicatorId],
                        ['target' => $figures[$indicatorId][$year]],
                    );
                }
            }

            $plan->touch();

            return $plan;
        });
    }

    /**
     * The figure of every linked indicator in every year, null when blank.
     *
     * @param  list<int>  $linked
     * @param  list<int>  $years
     * @param  array<int|string, array<int|string, string|int|float|null>>  $targets
     * @param  array<int, IndicatorMeasure>  $measures
     * @return array<int, array<int, string|null>>
     *
     * @throws ValidationException
     */
    private function figures(array $linked, array $years, array $targets, array $measures): array
    {
        $figures = [];
        $errors = [];

        foreach ($linked as $indicatorId) {
            $isPercentage = $measures[$indicatorId] === IndicatorMeasure::Percentage;

            foreach ($years as $year) {
                $raw = trim((string) ($targets[$indicatorId][$year] ?? ''));
                $figures[$indicatorId][$year] = null;

                if ($raw === '') {
                    continue;
                }

                if (! is_numeric($raw) || (float) $raw < 0 || (float) $raw > ($isPercentage ? 100.0 : self::MaxValue)) {
                    $errors["targets.{$indicatorId}.{$year}"] = $isPercentage ? __('strategic_plans.target_percentage') : __('strategic_plans.target_number');

                    continue;
                }

                $figures[$indicatorId][$year] = number_format((float) $raw, 2, '.', '');
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $figures;
    }
}
