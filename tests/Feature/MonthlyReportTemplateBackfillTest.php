<?php

declare(strict_types=1);

use App\Enums\MonthlyReportStatus;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Models\ReportTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Both Y-03 migrations, in the order they run.
 *
 * @return array{0: object, 1: object}
 */
function monthlyReportTemplateMigrations(): array
{
    return [
        require database_path('migrations/2026_11_17_143747_add_template_to_monthly_reports_table.php'),
        require database_path('migrations/2026_11_17_143748_require_template_on_monthly_reports_table.php'),
    ];
}

function monthlyReportsIndexExists(string $name): bool
{
    return collect(Schema::getIndexes('monthly_reports'))->firstWhere('name', $name) !== null;
}

/**
 * Back to the pre-Y-03 schema: one report per project and month, no template.
 */
function rollBackMonthlyReportTemplates(): void
{
    [$add, $require] = monthlyReportTemplateMigrations();

    $require->down();
    $add->down();
}

/**
 * Re-applies whatever step is missing, then removes the rows the test
 * committed: DDL ends the RefreshDatabase transaction in MySQL.
 *
 * @param  list<int>  $projectIds
 */
function restoreMonthlyReportTemplates(array $projectIds): void
{
    [$add, $require] = monthlyReportTemplateMigrations();

    DB::table('monthly_reports')->where('slug', 'like', 'legacy-%')->delete();

    if (! Schema::hasColumn('monthly_reports', 'report_template_id')) {
        $add->up();
    }

    if (! monthlyReportsIndexExists('monthly_reports_template_period_unique')) {
        $require->up();
    }

    DB::table('monthly_reports')->whereIn('project_id', $projectIds)->delete();
    DB::table('report_templates')->whereIn('project_id', $projectIds)->delete();
    DB::table('projects')->whereIn('id', $projectIds)->delete();
}

it('gives a report built for a project that project\'s template', function (): void {
    $project = Project::factory()->create();
    $template = ReportTemplate::factory()->create(['project_id' => $project->id]);

    $october = MonthlyReport::factory()->for($project)->create(['period' => '2026-10-01']);
    $november = MonthlyReport::factory()->create(['project_id' => $project->id, 'period' => '2026-11-01']);

    expect($october->report_template_id)->toBe($template->id)
        ->and($november->report_template_id)->toBe($template->id)
        ->and($october->template->is($template))->toBeTrue()
        ->and($template->monthlyReports()->count())->toBe(2);
});

it('shares one new template between two months of a project that had none', function (): void {
    $project = Project::factory()->create();

    $october = MonthlyReport::factory()->for($project)->create(['period' => '2026-10-01']);
    $november = MonthlyReport::factory()->for($project)->create(['period' => '2026-11-01']);

    expect($october->report_template_id)->toBe($november->report_template_id)
        ->and(ReportTemplate::query()->where('project_id', $project->id)->count())->toBe(1);
});

it('slugs a report without a project after its template and reads the program from it', function (): void {
    $report = MonthlyReport::factory()->create(['project_id' => null, 'period' => '2026-10-01']);

    expect($report->slug)->toBe("report-{$report->report_template_id}-2026-10")
        ->and($report->activityProgramId())->toBe($report->template->program_id);
});

it('names a new report by its template even when it has a legacy project (Y-03 slice 3, Q3)', function (): void {
    $project = Project::factory()->create();

    $report = MonthlyReport::factory()->for($project)->create(['period' => '2026-10-01']);

    expect($report->slug)->toBe('report-'.$report->report_template_id.'-2026-10');
});

it('rolls both steps back on backfilled data and maps every report to its project template again', function (): void {
    $projectA = Project::factory()->create();
    $templateA = ReportTemplate::factory()->create(['project_id' => $projectA->id]);
    MonthlyReport::factory()->for($projectA)->create(['period' => '2026-09-01']);
    MonthlyReport::factory()->for($projectA)->create(['period' => '2026-10-01']);
    $reportB = MonthlyReport::factory()->create(['period' => '2026-10-01']);
    $projectIds = [$projectA->id, $reportB->project_id];
    $slugs = DB::table('monthly_reports')->orderBy('id')->pluck('slug', 'id')->all();
    [$add, $require] = monthlyReportTemplateMigrations();

    try {
        $require->down();

        expect(monthlyReportsIndexExists('monthly_reports_project_period_unique'))->toBeTrue()
            ->and(monthlyReportsIndexExists('monthly_reports_template_period_unique'))->toBeFalse();

        $add->down();

        expect(Schema::hasColumn('monthly_reports', 'report_template_id'))->toBeFalse();

        $add->up();
        $require->up();

        expect(DB::table('monthly_reports')->where('project_id', $projectA->id)->pluck('report_template_id')->unique()->values()->all())->toBe([$templateA->id])
            ->and(DB::table('monthly_reports')->where('id', $reportB->id)->value('report_template_id'))->toBe($reportB->report_template_id)
            ->and(DB::table('monthly_reports')->orderBy('id')->pluck('slug', 'id')->all())->toBe($slugs)
            ->and(monthlyReportsIndexExists('monthly_reports_template_period_unique'))->toBeTrue()
            ->and(monthlyReportsIndexExists('monthly_reports_project_period_unique'))->toBeFalse();
    } finally {
        restoreMonthlyReportTemplates($projectIds);
    }
});

it('stops step one before any change when the project has :dataset', function (int $templates): void {
    $project = Project::factory()->create();
    ReportTemplate::factory()->count($templates)->create(['project_id' => $project->id]);
    [$add] = monthlyReportTemplateMigrations();

    try {
        rollBackMonthlyReportTemplates();

        $reportId = DB::table('monthly_reports')->insertGetId([
            'slug' => 'legacy-'.Str::random(6),
            'project_id' => $project->id,
            'period' => '2026-10-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(fn () => $add->up())->toThrow(RuntimeException::class, (string) $reportId)
            ->and(Schema::hasColumn('monthly_reports', 'report_template_id'))->toBeFalse();

        $this->artisan('monthly-reports:template-backfill')->assertFailed();
    } finally {
        restoreMonthlyReportTemplates([$project->id]);
    }
})->with(['no template' => 0, 'two templates' => 2]);

it('prints the counts on a dry run and writes nothing', function (): void {
    $draft = MonthlyReport::factory()->create(['period' => '2026-10-01']);
    $submitted = MonthlyReport::factory()->submitted()->create(['period' => '2026-10-01']);
    $projectIds = [$draft->project_id, $submitted->project_id];

    try {
        rollBackMonthlyReportTemplates();

        $this->artisan('monthly-reports:template-backfill', ['--dry-run' => true])
            ->expectsOutput(__('monthly_reports.console.template_backfill_reports', ['count' => 2]))
            ->expectsOutput(__('monthly_reports.console.template_backfill_mapped', ['count' => 2]))
            ->expectsOutput(__('monthly_reports.console.template_backfill_unmapped', ['count' => 0]))
            ->expectsOutput(__('monthly_reports.console.template_backfill_status', ['status' => MonthlyReportStatus::Draft->value, 'count' => 1]))
            ->expectsOutput(__('monthly_reports.console.template_backfill_status', ['status' => MonthlyReportStatus::Submitted->value, 'count' => 1]))
            ->expectsOutput(__('monthly_reports.console.dry_run'))
            ->assertSuccessful();

        expect(Schema::hasColumn('monthly_reports', 'report_template_id'))->toBeFalse();
    } finally {
        restoreMonthlyReportTemplates($projectIds);
    }
});

it('writes the template with the command once the column exists', function (): void {
    $report = MonthlyReport::factory()->create(['period' => '2026-10-01']);
    $projectIds = [$report->project_id];
    [$add] = monthlyReportTemplateMigrations();

    try {
        rollBackMonthlyReportTemplates();
        DB::statement('ALTER TABLE monthly_reports ADD report_template_id BIGINT UNSIGNED NULL');

        $this->artisan('monthly-reports:template-backfill', ['--dry-run' => true])->assertSuccessful();

        expect(DB::table('monthly_reports')->where('id', $report->id)->value('report_template_id'))->toBeNull();

        $this->artisan('monthly-reports:template-backfill')->assertSuccessful();

        expect(DB::table('monthly_reports')->where('id', $report->id)->value('report_template_id'))->toBe($report->report_template_id);

        DB::statement('ALTER TABLE monthly_reports DROP COLUMN report_template_id');
    } finally {
        restoreMonthlyReportTemplates($projectIds);
    }
});

it('refuses to restore one report per project and month when :dataset', function (Closure $arrange): void {
    $arrange();

    [, $require] = monthlyReportTemplateMigrations();

    expect(fn () => $require->down())->toThrow(RuntimeException::class)
        ->and(monthlyReportsIndexExists('monthly_reports_template_period_unique'))->toBeTrue()
        ->and(monthlyReportsIndexExists('monthly_reports_project_period_unique'))->toBeFalse();
})->with([
    'two reports share a project and month' => function (): void {
        $project = Project::factory()->create();
        [$first, $second] = ReportTemplate::factory()->count(2)->create(['project_id' => $project->id])->all();
        MonthlyReport::factory()->create(['project_id' => $project->id, 'report_template_id' => $first->id, 'period' => '2026-10-01']);
        MonthlyReport::factory()->create(['project_id' => $project->id, 'report_template_id' => $second->id, 'period' => '2026-10-01']);
    },
    'a report has no project' => function (): void {
        MonthlyReport::factory()->create(['project_id' => null, 'period' => '2026-10-01']);
    },
]);
