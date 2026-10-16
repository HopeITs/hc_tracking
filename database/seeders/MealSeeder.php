<?php

namespace Database\Seeders;

use App\Enums\CollectionFrequency;
use App\Enums\DenominatorBasis;
use App\Enums\IndicatorCollector;
use App\Enums\IndicatorMeasure;
use App\Enums\MonthlyReportStatus;
use App\Enums\ProjectStatus;
use App\Enums\ResultType;
use App\Models\AnnualPlan;
use App\Models\Indicator;
use App\Models\IndicatorProgress;
use App\Models\LibraryIndicator;
use App\Models\MealCoverageAssignment;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportAnswer;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\Result;
use App\Models\User;
use App\Support\IndicatorCode;
use App\Support\MonthlyReportGenerator;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\PicksHrRecords;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The MEAL layer: the logframe, the targets planned against it, the
 * actuals reported back, and the MEAL officers who validate them.
 *
 * Depends on DemoDeliverySeeder.
 */
class MealSeeder extends Seeder
{
    use PicksHrRecords;

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
        $this->seedMealCoverage();
        $this->seedMonthlyReports($users);
    }

    /**
     * Three months for up to two projects (active ones first): from the
     * project start, or the last three months when the demo project has no
     * dates, generated the way activation does: the oldest validated with
     * an answer, the next submitted, the rest drafts (contracts §13).
     *
     * @param  Collection<int, User>  $users
     */
    protected function seedMonthlyReports(Collection $users): void
    {
        $user = $users->first();

        if ($user === null) {
            return;
        }

        $projects = Project::query()
            ->orderByRaw('status = ? desc', [ProjectStatus::Active->value])
            ->orderBy('id')
            ->limit(2)
            ->get();

        foreach ($projects as $project) {
            $start = $project->start_date === null
                ? CarbonImmutable::today()->startOfMonth()->subMonths(2)
                : CarbonImmutable::parse($project->start_date)->startOfMonth();
            $end = $start->addMonths(2);
            $ids = app(MonthlyReportGenerator::class)->generate($project, $start, $end);
            $reports = MonthlyReport::query()->whereKey($ids)->orderBy('period')->get();

            $reports->get(0)?->forceFill([
                'status' => MonthlyReportStatus::Validated,
                'prepared_by' => $user->id,
                'submitted_at' => now(),
                'submitted_by' => $user->id,
                'validated_at' => now(),
                'validated_by' => $user->id,
            ])->save();

            $reports->get(1)?->forceFill([
                'status' => MonthlyReportStatus::Submitted,
                'prepared_by' => $user->id,
                'submitted_at' => now(),
                'submitted_by' => $user->id,
            ])->save();

            if ($reports->isNotEmpty()) {
                MonthlyReportAnswer::factory()->create([
                    'monthly_report_id' => $reports->first()->id,
                    'question_text' => 'ما أبرز ما تحقق هذا الشهر؟',
                    'answer' => 'نُفذت جلسات التوعية المخططة ووُزعت المواد في موعدها.',
                ]);
            }
        }
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
     * One active employee per governorate that has projects, and one project
     * with an officer of its own from another governorate (D-12/D-12a).
     */
    protected function seedMealCoverage(): void
    {
        $employeeIds = $this->hrEmployeeIds()->values();
        $cityIds = Project::query()->whereNotNull('city_id')->distinct()->orderBy('city_id')->pluck('city_id');

        foreach ($cityIds as $index => $cityId) {
            MealCoverageAssignment::factory()->forCity((int) $cityId)->create([
                'employee_id' => $employeeIds[$index % $employeeIds->count()],
            ]);
        }

        $project = Project::query()->orderBy('id')->first(['id']);

        if ($project !== null) {
            MealCoverageAssignment::factory()->forProject($project->id)->create([
                'employee_id' => $employeeIds->last(),
            ]);
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
