<?php

use App\Enums\MonthlyReportStatus;
use App\Enums\ProposalStatus;
use App\Events\ProjectActivated;
use App\Events\ProjectPeriodChanged;
use App\Models\Activity;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportLine;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateLine;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\MonthlyReportGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

/*
| Monthly report generation (contracts §13 "generate", D-04b, D-05, D-29):
| one draft per month at activation, new months only on a period change,
| one grouped notification per activation, and the prepare / backfill
| commands.
*/

beforeEach(fn () => $this->travelTo('2026-10-16 10:00:00'));

/**
 * An active project owned by an employee who has a tracking account.
 *
 * @return array{project: Project, owner: User}
 */
function mrgProject(string $start = '2026-11-01', string $end = '2027-04-30'): array
{
    $ownerId = insertHrEmployee(firstNameEn: 'Rami');
    $owner = userWithRole('Managers', $ownerId);
    $project = Activity::withoutRecording(fn (): Project => Project::factory()->active()->create([
        'user_id' => $ownerId,
        'start_date' => $start,
        'end_date' => $end,
    ]));

    return ['project' => $project, 'owner' => $owner];
}

function mrgActivate(Project $project, string $start, string $end): void
{
    ProjectActivated::dispatch($project->id, null, CarbonImmutable::parse($start), CarbonImmutable::parse($end), 'template_confirmation');
}

/**
 * @return list<string>
 */
function mrgPeriods(Project $project): array
{
    return MonthlyReport::query()->where('project_id', $project->id)->orderBy('period')->get()
        ->map(fn (MonthlyReport $report): string => $report->period->format('Y-m'))->all();
}

function mrgGeneratedNotifications(User $user): int
{
    return UserNotification::query()->forUser($user)->get()
        ->filter(fn (UserNotification $row): bool => $row->data['key'] === 'notifications.events.monthly_reports.generated')
        ->count();
}

/**
 * A confirmed, monthly template line for a new target of a donor-approved
 * proposal covering the project period.
 */
function mrgConfirmedLine(Project $project, string $from = '2026-11-01', string $to = '2027-04-01'): ReportTemplateLine
{
    return Activity::withoutRecording(function () use ($project, $from, $to): ReportTemplateLine {
        $proposal = Proposal::factory()->create([
            'status' => ProposalStatus::Active,
            'actual_date_begin' => $from,
            'actual_date_end' => CarbonImmutable::parse($to)->endOfMonth()->toDateString(),
        ]);
        $proposal->projects()->attach($project->id, ['target_beneficiaries' => 100]);
        $template = ReportTemplate::query()->where('project_id', $project->id)->first()
            ?? ReportTemplate::factory()->confirmed()->create(['project_id' => $project->id]);
        $indicator = Indicator::factory()->create(['library_indicator_id' => LibraryIndicator::factory()->create()->id]);
        $target = PlanIndicator::factory()->forProposal($proposal)->create(['indicator_id' => $indicator->id, 'project_id' => $project->id]);

        return ReportTemplateLine::factory()->confirmed()->monthly()->create([
            'report_template_id' => $template->id,
            'plan_indicator_id' => $target->id,
            'effective_from' => $from,
            'effective_to' => $to,
        ]);
    });
}

test('activation creates one draft per month of the period, with no lines', function () {
    ['project' => $project] = mrgProject();

    mrgActivate($project, '2026-11-01', '2027-04-30');

    expect(mrgPeriods($project))->toBe(['2026-11', '2026-12', '2027-01', '2027-02', '2027-03', '2027-04']);

    $report = MonthlyReport::query()->where('project_id', $project->id)->orderBy('period')->firstOrFail();
    expect($report->status)->toBe(MonthlyReportStatus::Draft)
        ->and($report->period->toDateString())->toBe('2026-11-01')
        ->and($report->slug)->toBe($project->slug.'-2026-11')
        ->and($report->prepared_by)->toBeNull()
        ->and(MonthlyReportLine::query()->count())->toBe(0);
});

test('a start in the middle of a month starts with that month', function () {
    ['project' => $project] = mrgProject('2026-11-17', '2027-01-10');

    mrgActivate($project, '2026-11-17', '2027-01-10');

    expect(mrgPeriods($project))->toBe(['2026-11', '2026-12', '2027-01']);
});

test('a re-delivered activation adds nothing and tells the owner once', function () {
    ['project' => $project, 'owner' => $owner] = mrgProject();

    mrgActivate($project, '2026-11-01', '2027-04-30');
    mrgActivate($project, '2026-11-01', '2027-04-30');

    expect(MonthlyReport::query()->where('project_id', $project->id)->count())->toBe(6)
        ->and(mrgGeneratedNotifications($owner))->toBe(1);
});

test('the owner gets one grouped notification per activation, never one per month', function () {
    ['project' => $project, 'owner' => $owner] = mrgProject();

    mrgActivate($project, '2026-11-01', '2027-04-30');

    $row = UserNotification::query()->forUser($owner)->get()
        ->sole(fn (UserNotification $row): bool => $row->data['key'] === 'notifications.events.monthly_reports.generated');

    expect($row->subject_type)->toBe('project')
        ->and($row->subject_id)->toBe($project->id)
        ->and($row->data['params']['subject'])->toBe($project->name);
});

test('the notification goes out only after the activation commits', function () {
    ['project' => $project, 'owner' => $owner] = mrgProject();

    DB::transaction(function () use ($project, $owner): void {
        mrgActivate($project, '2026-11-01', '2027-04-30');

        expect(MonthlyReport::query()->where('project_id', $project->id)->count())->toBe(0)
            ->and(mrgGeneratedNotifications($owner))->toBe(0);
    });

    expect(MonthlyReport::query()->where('project_id', $project->id)->count())->toBe(6)
        ->and(mrgGeneratedNotifications($owner))->toBe(1);
});

test('a rolled-back activation generates nothing', function () {
    ['project' => $project] = mrgProject();

    try {
        DB::transaction(function () use ($project): void {
            mrgActivate($project, '2026-11-01', '2027-04-30');

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    expect(MonthlyReport::query()->count())->toBe(0);
});

test('a project that is no longer active generates nothing', function () {
    ['project' => $project] = mrgProject();
    Activity::withoutRecording(fn () => $project->forceFill(['status' => 'closed'])->save());

    mrgActivate($project, '2026-11-01', '2027-04-30');

    expect(MonthlyReport::query()->count())->toBe(0);
});

test('a period change adds the new months only and keeps every existing report', function () {
    ['project' => $project, 'owner' => $owner] = mrgProject();
    mrgActivate($project, '2026-11-01', '2027-04-30');
    $first = MonthlyReport::query()->where('project_id', $project->id)->orderBy('period')->firstOrFail();
    Activity::withoutRecording(fn () => $first->forceFill(['status' => MonthlyReportStatus::Submitted, 'submitted_at' => now()])->save());

    ProjectPeriodChanged::dispatch(
        $project->id,
        CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2027-04-30'),
        CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2027-06-30'),
    );

    expect(mrgPeriods($project))->toBe(['2026-11', '2026-12', '2027-01', '2027-02', '2027-03', '2027-04', '2027-05', '2027-06'])
        ->and($first->fresh()->status)->toBe(MonthlyReportStatus::Submitted)
        ->and(mrgGeneratedNotifications($owner))->toBe(1);
});

test('generation runs in a fixed number of queries, whatever the number of months', function () {
    ['project' => $short] = mrgProject('2026-11-01', '2027-01-31');
    ['project' => $long] = mrgProject('2026-11-01', '2027-10-31');
    $generator = app(MonthlyReportGenerator::class);

    $count = function (Project $project, string $end) use ($generator): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $generator->generate($project->fresh(), CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse($end));
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    expect($count($short, '2027-01-31'))->toBe($count($long, '2027-10-31'));
});

test('generate returns the ids it created and nothing for months that exist', function () {
    ['project' => $project] = mrgProject();
    $generator = app(MonthlyReportGenerator::class);

    $created = $generator->generate($project, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-12-31'));
    $again = $generator->generate($project, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2027-01-31'));

    expect($created)->toHaveCount(2)
        ->and($again)->toHaveCount(1)
        ->and(MonthlyReport::query()->whereKey($again)->value('period')->format('Y-m'))->toBe('2027-01');
});

test('backfill starts at --from, never before the project start, and builds the lines of started months', function () {
    ['project' => $old] = mrgProject('2026-03-01', '2027-02-28');
    ['project' => $late] = mrgProject('2026-12-01', '2027-03-31');
    mrgConfirmedLine($old, '2026-03-01', '2027-02-01');

    $this->artisan('monthly-reports:backfill', ['--from' => '2026-10'])
        ->expectsOutputToContain(__('monthly_reports.console.backfill_row', ['project' => $old->id, 'created' => 5, 'skipped' => 0, 'lines' => 1]))
        ->expectsOutputToContain(__('monthly_reports.console.backfill_row', ['project' => $late->id, 'created' => 4, 'skipped' => 0, 'lines' => 0]))
        ->assertSuccessful();

    expect(mrgPeriods($old))->toBe(['2026-10', '2026-11', '2026-12', '2027-01', '2027-02'])
        ->and(mrgPeriods($late))->toBe(['2026-12', '2027-01', '2027-02', '2027-03'])
        ->and(MonthlyReportLine::query()->count())->toBe(1)
        ->and(MonthlyReportLine::query()->sole()->monthlyReport->period->format('Y-m'))->toBe('2026-10');
});

test('backfill is idempotent and counts the months it skipped', function () {
    ['project' => $project] = mrgProject('2026-03-01', '2027-02-28');

    $this->artisan('monthly-reports:backfill', ['--from' => '2026-10'])->assertSuccessful();
    $this->artisan('monthly-reports:backfill', ['--from' => '2026-10'])
        ->expectsOutputToContain(__('monthly_reports.console.backfill_row', ['project' => $project->id, 'created' => 0, 'skipped' => 5, 'lines' => 0]))
        ->assertSuccessful();

    expect(MonthlyReport::query()->count())->toBe(5);
});

test('backfill --dry-run writes nothing', function () {
    ['project' => $project] = mrgProject('2026-03-01', '2027-02-28');
    mrgConfirmedLine($project, '2026-03-01', '2027-02-01');

    $this->artisan('monthly-reports:backfill', ['--from' => '2026-10', '--dry-run' => true])
        ->expectsOutputToContain(__('monthly_reports.console.backfill_row', ['project' => $project->id, 'created' => 5, 'skipped' => 0, 'lines' => 1]))
        ->expectsOutputToContain(__('monthly_reports.console.dry_run'))
        ->assertSuccessful();

    expect(MonthlyReport::query()->count())->toBe(0)
        ->and(MonthlyReportLine::query()->count())->toBe(0);
});

test('backfill skips projects that are not active and refuses a malformed --from', function () {
    ['project' => $project] = mrgProject('2026-03-01', '2027-02-28');
    Activity::withoutRecording(fn () => $project->forceFill(['status' => 'closed'])->save());

    $this->artisan('monthly-reports:backfill', ['--from' => '2026-10'])->assertSuccessful();
    $this->artisan('monthly-reports:backfill', ['--from' => 'October'])
        ->expectsOutputToContain(__('monthly_reports.console.invalid_from'))
        ->assertFailed();

    expect(MonthlyReport::query()->count())->toBe(0);
});

test('prepare builds the current month\'s lines on the first working day, idempotently', function () {
    $this->travelTo('2026-11-02 06:00:00');
    ['project' => $project] = mrgProject();
    mrgActivate($project, '2026-11-01', '2027-04-30');
    mrgConfirmedLine($project);

    $this->artisan('monthly-reports:prepare')->assertSuccessful();
    $this->artisan('monthly-reports:prepare')->assertSuccessful();

    $november = MonthlyReport::query()->where('project_id', $project->id)->where('period', '2026-11-01')->sole();
    expect($november->lines()->count())->toBe(1)
        ->and(MonthlyReportLine::query()->count())->toBe(1)
        ->and($november->lines()->sole()->shares()->count())->toBe(1);
});

test('prepare does nothing on any other day, and --dry-run writes nothing', function () {
    $this->travelTo('2026-11-03 06:00:00');
    ['project' => $project] = mrgProject();
    mrgActivate($project, '2026-11-01', '2027-04-30');
    mrgConfirmedLine($project);

    $this->artisan('monthly-reports:prepare')->assertSuccessful();
    expect(MonthlyReportLine::query()->count())->toBe(0);

    $this->travelTo('2026-11-02 06:00:00');
    $this->artisan('monthly-reports:prepare', ['--dry-run' => true])
        ->expectsOutputToContain(__('monthly_reports.console.dry_run'))
        ->assertSuccessful();
    expect(MonthlyReportLine::query()->count())->toBe(0);
});

test('prepare follows the project\'s own working days', function () {
    // Sunday 1 November 2026 is a working day for a Sunday–Thursday project.
    $this->travelTo('2026-11-01 06:00:00');
    ['project' => $project] = mrgProject();
    Activity::withoutRecording(fn () => $project->forceFill(['working_days' => [7, 1, 2, 3, 4]])->save());
    mrgActivate($project, '2026-11-01', '2027-04-30');
    mrgConfirmedLine($project);

    $this->artisan('monthly-reports:prepare')->assertSuccessful();

    expect(MonthlyReportLine::query()->count())->toBe(1);
});

test('prepare is scheduled daily on one server without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'monthly-reports:prepare'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 5 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});
