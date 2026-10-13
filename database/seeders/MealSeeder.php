<?php

namespace Database\Seeders;

use App\Enums\CollectionFrequency;
use App\Enums\DenominatorBasis;
use App\Enums\IndicatorCollector;
use App\Enums\IndicatorMeasure;
use App\Enums\ResultType;
use App\Models\AnnualPlan;
use App\Models\Indicator;
use App\Models\IndicatorProgress;
use App\Models\LibraryIndicator;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\Result;
use App\Models\User;
use App\Support\IndicatorCode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

        $this->seedIndicatorLibrary();
    }

    /**
     * A few institutional (HC) Indicator Library entries: one per measure,
     * plus an Arabic-only and an inactive one. Per-program entries are left
     * out because their codes come from HR program codes, which can be
     * duplicated in HR and are then refused (D-10a).
     */
    protected function seedIndicatorLibrary(): void
    {
        $entries = [
            ['level_ref' => 'G', 'name_ar' => 'عدد الأسر التي تحسّن وضعها المعيشي', 'name_en' => 'Households whose living conditions improved', 'unit' => 'households', 'frequency' => CollectionFrequency::Annual],
            ['level_ref' => 'OC1', 'name_ar' => 'نسبة المستفيدين الراضين عن الخدمات', 'name_en' => 'Share of beneficiaries satisfied with services', 'unit' => null, 'measure' => IndicatorMeasure::Percentage, 'denominator_basis' => DenominatorBasis::Entered, 'collected_by' => IndicatorCollector::Meal, 'frequency' => CollectionFrequency::Quarterly],
            ['level_ref' => 'OP1', 'name_ar' => 'متوسط درجات الاختبار البعدي', 'name_en' => 'Average post-test score', 'unit' => 'points', 'measure' => IndicatorMeasure::Average, 'collected_by' => IndicatorCollector::Meal, 'frequency' => CollectionFrequency::PerEvent],
            ['level_ref' => 'OP1', 'name_ar' => 'عدد الجلسات التوعوية المنفذة', 'name_en' => null, 'unit' => 'sessions', 'frequency' => CollectionFrequency::Monthly, 'definition' => 'تُعدّ الجلسات المنفذة فعليًا خلال الشهر.'],
            ['level_ref' => 'AC1.1', 'name_ar' => 'عدد السلال الغذائية الموزعة', 'name_en' => 'Food baskets distributed', 'unit' => 'baskets', 'frequency' => CollectionFrequency::Monthly, 'is_active' => false],
        ];

        foreach ($entries as $entry) {
            DB::transaction(fn (): LibraryIndicator => LibraryIndicator::factory()->create([...$entry, ...IndicatorCode::next(null, $entry['level_ref'])]));
        }
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
