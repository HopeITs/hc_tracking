<?php

namespace Database\Seeders;

use App\Enums\ResultType;
use App\Models\AnnualPlan;
use App\Models\Indicator;
use App\Models\IndicatorProgress;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\Result;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * The MEAL layer: the logframe, the targets planned against it, and the
 * actuals reported back.
 *
 * Depends on DemoDeliverySeeder.
 */
class MealSeeder extends Seeder
{
    /**
     * Seed the MEAL tables.
     */
    public function run(): void
    {
        $users = User::query()->get();

        Program::query()->each(function (Program $program) use ($users): void {
            $outcome = Result::factory()->type(ResultType::Outcome)->create([
                'program_id' => $program->id,
                'statement' => 'Improved '.strtolower($program->name).' outcomes for affected populations.',
            ]);

            $output = Result::factory()->type(ResultType::Output)->create([
                'program_id' => $program->id,
                'parent_id' => $outcome->id,
                'statement' => 'Services delivered to targeted communities.',
            ]);

            $indicators = collect(['Number of people reached', 'Number of service points established'])
                ->map(fn (string $name) => Indicator::factory()->create([
                    'result_id' => $output->id,
                    'name' => $name,
                ]));

            $annualPlan = $program->annualPlans()->first();
            $project = $program->projects()->with('proposals')->first();

            if ($annualPlan === null) {
                return;
            }

            foreach ($indicators as $indicator) {
                $this->seedTargets($annualPlan, $indicator, $project, $users);
                $this->seedProgress($indicator, $users);
            }
        });
    }

    /**
     * Record a rising quarterly series against an indicator.
     *
     * The values climb from the baseline toward the target rather than being
     * random, so the trend chart reads as a trend and the RAG rating lands
     * somewhere meaningful instead of flickering between colours per seed.
     *
     * @param  Collection<int, User>  $users
     */
    protected function seedProgress(Indicator $indicator, Collection $users): void
    {
        $baseline = (float) ($indicator->baseline ?? 0);
        $target = (float) ($indicator->target ?? 0);
        $steps = 4;

        for ($quarter = 1; $quarter <= $steps; $quarter++) {
            IndicatorProgress::factory()->create([
                'indicator_id' => $indicator->id,
                'date' => now()->subQuarters($steps - $quarter)->endOfQuarter()->toDateString(),
                'value' => round($baseline + (($target - $baseline) * ($quarter / ($steps + 1))), 2),
                'recorded_by' => $users->random()->id,
            ]);
        }
    }

    /**
     * Plan one target for an indicator and report a first actual against it.
     *
     * @param  Collection<int, User>  $users
     */
    protected function seedTargets(AnnualPlan $annualPlan, Indicator $indicator, ?Project $project, Collection $users): void
    {
        $planIndicator = PlanIndicator::factory()->forAnnualPlan($annualPlan)->create([
            'indicator_id' => $indicator->id,
            'project_id' => $project?->id,
        ]);

        $proposal = $project?->proposals->first();

        if ($proposal !== null) {
            PlanIndicator::factory()->forProposal($proposal)->create([
                'indicator_id' => $indicator->id,
                'project_id' => $project->id,
                'target' => $planIndicator->target,
            ]);
        }

        Report::factory()->approved()->withNarrative()->create([
            'plan_indicator_id' => $planIndicator->id,
            'user_id' => $users->random()->id,
        ]);

        Report::factory()->reviewed()->withNarrative()->create([
            'plan_indicator_id' => $planIndicator->id,
            'user_id' => $users->random()->id,
        ]);

        $draft = Report::factory()->create([
            'plan_indicator_id' => $planIndicator->id,
            'user_id' => $users->random()->id,
        ]);

        ReportFile::factory(2)->create([
            'report_id' => $draft->id,
        ]);
    }
}
