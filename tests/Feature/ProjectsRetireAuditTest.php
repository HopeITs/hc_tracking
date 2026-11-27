<?php

declare(strict_types=1);

use App\Enums\MealCoverageScope;
use App\Models\Activity;
use App\Models\Indicator;
use App\Models\MealCoverageAssignment;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Risk;
use App\Models\Task;
use App\Support\Locations\ProjectsRetireAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Y-18 (D-92): the audit that gates the drop. Every row must read zero before
 * a single column of slice 2 is dropped, so each row has to count what it
 * says it counts — a row that is quietly always zero would wave the drop
 * through. Each case plants one leftover and asserts that its own row sees it.
 */
function audit(): array
{
    return app(ProjectsRetireAudit::class)->run();
}

/**
 * A project with no location row at all: the state the audit's last row is
 * about, and the cheapest way to plant a leftover in a legacy column.
 *
 * Built without recording, because creating a project writes a
 * `project`-subject activity of its own and every case here plants exactly
 * one leftover — the activities row has its own case.
 */
function orphanProject(array $attributes = []): Project
{
    return Activity::withoutRecording(fn (): Project => Project::factory()->create($attributes));
}

/**
 * One activity whose subject is still a project. `activities` has no
 * `happened_at`, and `module`, `changes` and `properties` carry no default.
 */
function projectActivity(Project $project): int
{
    return DB::table('activities')->insertGetId([
        'subject_type' => 'project',
        'subject_id' => $project->id,
        'module' => 'projects',
        'event' => 'project.activated',
        'changes' => json_encode([]),
        'properties' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * One row of $table carrying a project with no successor. Each arm plants the
 * leftover that table's audit row is about, and nothing else.
 */
function plantLegacyRow(string $table): void
{
    $project = orphanProject();

    match ($table) {
        'risks' => Risk::factory()->create(['project_id' => $project->id, 'proposal_location_id' => null]),
        'indicators' => Indicator::factory()->create(['project_id' => $project->id, 'proposal_location_id' => null]),
        'meal_coverages' => placeless(
            MealCoverageAssignment::factory()->forProject($project->id)->create(),
        ),
        'plan_indicators' => unplacedTarget($project),
    };
}

/**
 * A coverage row as Y-14 left the legacy ones: scoped to a project, with no
 * place key, "retained for rollback".
 */
function placeless(MealCoverageAssignment $coverage): void
{
    DB::table('meal_coverages')->where('id', $coverage->id)->update([
        'scope_type' => MealCoverageScope::Project->value,
        'scope_key' => null,
        'program_id' => null,
        'city_id' => null,
    ]);
}

/**
 * A proposal target that carries a project and has neither a row nor a place:
 * when the column goes it has nowhere to be.
 */
function unplacedTarget(Project $project): void
{
    $target = PlanIndicator::factory()->create([
        'source_type' => 'proposal',
        'source_id' => Proposal::factory()->create()->id,
        'project_id' => $project->id,
    ]);

    DB::table('plan_indicators')->where('id', $target->id)->update([
        'proposal_location_id' => null,
        'city_id' => null,
        'program_id' => null,
    ]);
}

/**
 * One row of $column still naming the `project` morph key.
 */
function plantMorphRow(string $column): void
{
    $project = orphanProject();

    match ($column) {
        'tasks.source_type' => Task::factory()->create(['source_type' => 'project', 'source_id' => $project->id]),
        'media.mediable_type' => insertMedia('project', $project->id, userWithRole('MEAL')->id),
        'assignments.assignable_type' => DB::table('assignments')->insert([
            'assignable_type' => 'project',
            'assignable_id' => $project->id,
            'field' => 'user_id',
            'employee_id' => 1,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]),
        'activities.subject_type' => projectActivity($project),
        'notifications.subject_type' => DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\AlertNotification',
            'notifiable_type' => 'user',
            'notifiable_id' => userWithRole('MEAL')->id,
            'data' => json_encode(['key' => 'project.activated']),
            'module' => 'projects',
            'subject_type' => 'project',
            'subject_id' => $project->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]),
        'plan_indicators.source_type' => DB::table('plan_indicators')
            ->where('id', PlanIndicator::factory()->create(['project_id' => $project->id])->id)
            ->update(['source_type' => 'project']),
    };
}

it('reads zero in every row, and a zero total, on a database that has left nothing behind', function (): void {
    $result = audit();

    expect($result['total'])->toBe(0)
        ->and($result['columns'])->not->toBeEmpty()
        ->and($result['morphs'])->not->toBeEmpty()
        ->and($result['projects_without_row'])->toBe(0);

    foreach ($result['columns'] as $table => $count) {
        expect($count)->toBe(0, $table.' counted a leftover on clean data');
    }

    foreach ($result['morphs'] as $column => $count) {
        expect($count)->toBe(0, $column.' counted a leftover on clean data');
    }
});

it('names every legacy column and every morph column it has to clear', function (): void {
    $result = audit();

    expect(array_keys($result['columns']))->toBe([
        'activity_plan_follow_up_tasks',
        'activity_schedules',
        'indicators',
        'meal_coverages',
        'monthly_reports',
        'plan_indicators',
        'plan_updates',
        'project_activity_statuses',
        'proposal_locations',
        'report_templates',
        'risks',
        'schedule_baselines',
        'schedule_dependencies',
    ])->and(array_keys($result['morphs']))->toBe([
        'tasks.source_type',
        'media.mediable_type',
        'assignments.assignable_type',
        'activities.subject_type',
        'notifications.subject_type',
        'plan_indicators.source_type',
    ]);
});

it('counts a project that no location row carries', function (): void {
    orphanProject();

    expect(audit()['projects_without_row'])->toBe(1);
});

it('does not count a project a location row carries', function (): void {
    projectWithRow();

    expect(audit()['projects_without_row'])->toBe(0);
});

it('counts a row whose legacy column is set while the column that replaces it is empty', function (string $table): void {
    plantLegacyRow($table);

    expect(audit()['columns'][$table])->toBe(1);
})->with(['risks', 'indicators', 'meal_coverages', 'plan_indicators']);

it('leaves an annual-plan target alone: D-44 gives it a place and no row', function (): void {
    $program = Program::factory()->create();
    $target = PlanIndicator::factory()->create(['project_id' => orphanProject()->id]);

    DB::table('plan_indicators')->where('id', $target->id)->update([
        'source_type' => 'annual_plan',
        'proposal_location_id' => null,
        'city_id' => 10,
        'program_id' => $program->id,
    ]);

    expect(audit()['columns']['plan_indicators'])->toBe(0);
});

it('counts a morph column still naming a project', function (string $column): void {
    plantMorphRow($column);

    expect(audit()['morphs'][$column])->toBe(1);
})->with([
    'tasks.source_type',
    'media.mediable_type',
    'assignments.assignable_type',
    'activities.subject_type',
    'notifications.subject_type',
    'plan_indicators.source_type',
]);

it('adds its total up out of every row it printed', function (): void {
    plantMorphRow('tasks.source_type');
    plantLegacyRow('risks');

    $result = audit();

    expect($result['total'])->toBe(
        array_sum($result['columns']) + array_sum($result['morphs']) + $result['projects_without_row'],
    )->and($result['total'])->toBeGreaterThan(0);
});

it('exits zero on clean data and non-zero while anything is left behind', function (): void {
    $this->artisan('projects:retire-audit')->assertExitCode(0);

    orphanProject();

    $this->artisan('projects:retire-audit')->assertExitCode(1);
});
