<?php

use App\Actions\ReportTemplates\ConfirmReportTemplate;
use App\Enums\AssignmentStatus;
use App\Enums\MonthlyReportStatus;
use App\Enums\ProposalStatus;
use App\Events\ProjectPeriodChanged;
use App\Events\ReportTemplateConfirmed;
use App\Models\Activity;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportLine;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateLine;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\MonthlyReportGenerator;
use App\Support\Reporting\PlaceBridge;
use App\Support\Reporting\ReportPlace;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Monthly report generation (contracts §13 "generate", D-04b, D-05, D-29,
| Y-03): one draft per month of the template's window when the template is
| first confirmed, new months only when the window grows, one grouped
| notification per confirmation, and the prepare / backfill commands.
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

/**
 * A proposal running from $start to $end (actual dates).
 *
 * @param  array<string, mixed>  $attributes
 */
function mrgProposal(string $start, string $end, array $attributes = []): Proposal
{
    return Activity::withoutRecording(fn (): Proposal => Proposal::factory()->create([
        'status' => ProposalStatus::Active,
        'actual_date_begin' => $start,
        'actual_date_end' => $end,
        ...$attributes,
    ]));
}

/**
 * The project's template (legacy kind: it carries the project), joined by a
 * new proposal of the project running from $start to $end.
 */
function mrgTemplate(Project $project, string $start = '2026-11-01', string $end = '2027-04-30', bool $confirmed = true): ReportTemplate
{
    $proposal = mrgProposal($start, $end);

    return Activity::withoutRecording(function () use ($project, $proposal, $confirmed): ReportTemplate {
        $proposal->projects()->attach($project->id, ['target_beneficiaries' => 100]);
        $factory = $confirmed ? ReportTemplate::factory()->confirmed() : ReportTemplate::factory();
        $template = $factory->create(['project_id' => $project->id]);
        // The test's own proposal sets the window, not the one the factory carries the template with.
        $template->proposals()->detach();
        $template->proposals()->attach($proposal->id, ['joined_at' => now()]);
        // Its row at the template's place names the project owner as responsible: the preparer (D-84).
        $row = ProposalLocation::factory()->create([
            'proposal_id' => $proposal->id,
            'city_id' => $template->city_id,
            'program_id' => $template->program_id,
            'city_wide' => $template->city_wide,
            'responsible_employee_id' => $project->user_id,
        ]);

        if ($template->center_id !== null) {
            $row->centers()->create(['center_id' => $template->center_id]);
        }

        return $template;
    });
}

function mrgConfirm(ReportTemplate $template): void
{
    ReportTemplateConfirmed::dispatch($template->id);
}

/**
 * @return list<string>
 */
function mrgPeriods(ReportTemplate $template): array
{
    return MonthlyReport::query()->where('report_template_id', $template->id)->orderBy('period')->get()
        ->map(fn (MonthlyReport $report): string => $report->period->format('Y-m'))->all();
}

/**
 * The template as the console rows name it: its id and its proposals' names.
 */
function mrgLabel(ReportTemplate $template): string
{
    return implode(' · ', [(string) $template->id, ...$template->proposals()->orderBy('proposals.id')->pluck('name')->all()]);
}

function mrgGeneratedNotifications(User $user): int
{
    return UserNotification::query()->forUser($user)->get()
        ->filter(fn (UserNotification $row): bool => $row->data['key'] === 'notifications.events.monthly_reports.generated')
        ->count();
}

/**
 * A confirmed, monthly template line for a new target of a donor-approved
 * proposal covering the project period, on the project's template.
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

/**
 * A confirmed template at a place of the project's row, joined by a proposal
 * with no row carrying a project: it has no legacy project.
 */
function mrgTemplateWithoutProject(Project $project, string $start, string $end): ReportTemplate
{
    $proposal = mrgProposal($start, $end);
    $place = new ReportPlace((int) $project->city_id, (int) $project->program_id, insertHrCenter((int) $project->city_id, 'No project '.Str::random(4)), false);

    return Activity::withoutRecording(fn (): ReportTemplate => ReportTemplate::factory()->forPlace($place, $proposal)->confirmed()->create());
}

/**
 * A confirmed, monthly line on the template for a new target of its first
 * proposal.
 */
function mrgTemplateLine(ReportTemplate $template, string $from, string $to): ReportTemplateLine
{
    return Activity::withoutRecording(function () use ($template, $from, $to): ReportTemplateLine {
        $proposal = $template->proposals()->orderBy('proposals.id')->firstOrFail();
        $indicator = Indicator::factory()->create(['library_indicator_id' => LibraryIndicator::factory()->create()->id]);
        $target = PlanIndicator::factory()->forProposal($proposal)->create(['indicator_id' => $indicator->id]);

        return ReportTemplateLine::factory()->confirmed()->monthly()->create([
            'report_template_id' => $template->id,
            'plan_indicator_id' => $target->id,
            'effective_from' => $from,
            'effective_to' => $to,
        ]);
    });
}

test('confirmation creates one draft per month of the template window, with no lines', function () {
    ['project' => $project] = mrgProject();
    $template = mrgTemplate($project);

    mrgConfirm($template);

    expect(mrgPeriods($template))->toBe(['2026-11', '2026-12', '2027-01', '2027-02', '2027-03', '2027-04']);

    $report = MonthlyReport::query()->where('report_template_id', $template->id)->orderBy('period')->firstOrFail();
    expect($report->status)->toBe(MonthlyReportStatus::Draft)
        ->and($report->period->toDateString())->toBe('2026-11-01')
        ->and($report->project_id)->toBe($project->id)
        ->and($report->slug)->toBe('report-'.$template->id.'-2026-11')
        ->and($report->prepared_by)->toBeNull()
        ->and(MonthlyReportLine::query()->count())->toBe(0);
});

test('the months follow the template proposals: earliest start to latest end, extensions included', function () {
    ['project' => $project] = mrgProject();
    $template = mrgTemplate($project, '2026-12-01', '2027-02-28');
    $planned = mrgProposal('2026-11-10', '2027-01-31', ['actual_date_begin' => null, 'actual_date_end' => null, 'planned_date_begin' => '2026-11-10', 'planned_date_end' => '2027-01-31']);
    Activity::withoutRecording(function () use ($template, $planned): void {
        $template->proposals()->attach($planned->id, ['joined_at' => now()]);
        $template->proposals()->orderBy('proposals.id')->firstOrFail()->forceFill(['extended_date_end' => '2027-03-15'])->saveQuietly();
    });

    mrgConfirm($template);

    expect(mrgPeriods($template))->toBe(['2026-11', '2026-12', '2027-01', '2027-02', '2027-03'])
        ->and(app(MonthlyReportGenerator::class)->windowOf($template->fresh())[0]->toDateString())->toBe('2026-11-10');
});

test('a start in the middle of a month starts with that month', function () {
    ['project' => $project] = mrgProject('2026-11-17', '2027-01-10');
    $template = mrgTemplate($project, '2026-11-17', '2027-01-10');

    mrgConfirm($template);

    expect(mrgPeriods($template))->toBe(['2026-11', '2026-12', '2027-01']);
});

test('a re-delivered confirmation adds nothing and tells the owner once', function () {
    ['project' => $project, 'owner' => $owner] = mrgProject();
    $template = mrgTemplate($project);

    mrgConfirm($template);
    $ids = MonthlyReport::query()->where('report_template_id', $template->id)->orderBy('id')->pluck('id')->all();
    mrgConfirm($template);

    expect(MonthlyReport::query()->where('report_template_id', $template->id)->orderBy('id')->pluck('id')->all())->toBe($ids)
        ->and($ids)->toHaveCount(6)
        ->and(app(MonthlyReportGenerator::class)->generate($template, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2027-04-30')))->toBe([])
        ->and(mrgGeneratedNotifications($owner))->toBe(1);
});

test('the template\'s preparer gets one grouped notification per confirmation, never one per month, naming its proposals', function () {
    ['project' => $project, 'owner' => $owner] = mrgProject();
    $template = mrgTemplate($project);

    mrgConfirm($template);

    $row = UserNotification::query()->forUser($owner)->get()
        ->sole(fn (UserNotification $row): bool => $row->data['key'] === 'notifications.events.monthly_reports.generated');
    $first = MonthlyReport::query()->where('report_template_id', $template->id)->orderBy('period')->firstOrFail();

    expect($row->subject_type)->toBe('monthly_report')
        ->and($row->subject_id)->toBe($first->id)
        ->and($row->data['params']['subject'])->toContain($template->proposals()->value('name'));
});

test('the generated notification goes to the template\'s row responsible, not to the legacy project owner (D-84)', function () {
    ['project' => $project, 'owner' => $owner] = mrgProject();
    $template = mrgTemplate($project);
    $responsible = insertHrEmployee(firstNameEn: 'Rana');
    $responsibleUser = userWithRole('Managers', $responsible);
    ProposalLocation::query()->whereIn('proposal_id', $template->proposals()->pluck('proposals.id'))->update(['responsible_employee_id' => $responsible]);

    mrgConfirm($template);

    expect(mrgGeneratedNotifications($responsibleUser))->toBe(1)
        ->and(mrgGeneratedNotifications($owner))->toBe(0);
});

test('the reports are generated only after the confirmation commits', function () {
    ['project' => $project, 'owner' => $owner] = mrgProject();
    $template = mrgTemplate($project);

    DB::transaction(function () use ($template, $owner): void {
        mrgConfirm($template);

        expect(MonthlyReport::query()->where('report_template_id', $template->id)->count())->toBe(0)
            ->and(mrgGeneratedNotifications($owner))->toBe(0);
    });

    expect(MonthlyReport::query()->where('report_template_id', $template->id)->count())->toBe(6)
        ->and(mrgGeneratedNotifications($owner))->toBe(1);
});

test('a rolled-back confirmation generates nothing', function () {
    ['project' => $project] = mrgProject();
    $template = mrgTemplate($project);

    try {
        DB::transaction(function () use ($template): void {
            mrgConfirm($template);

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    expect(MonthlyReport::query()->count())->toBe(0);
});

test('a template that is not confirmed, or has no proposal, generates nothing', function () {
    ['project' => $project] = mrgProject();
    $pending = mrgTemplate($project, confirmed: false);
    ['project' => $other] = mrgProject();
    $empty = Activity::withoutRecording(fn (): ReportTemplate => ReportTemplate::factory()->confirmed()->create(['project_id' => $other->id]));
    $empty->proposals()->detach();

    mrgConfirm($pending);
    mrgConfirm($empty);

    expect(MonthlyReport::query()->count())->toBe(0);
});

test('ConfirmReportTemplate generates the months on the first confirmation only', function () {
    // Created while recording: the owner assignment comes from the project's own hooks.
    $project = Project::factory()->approved()->create(['start_date' => '2026-11-01', 'end_date' => '2027-01-31']);
    $project->currentAssignment('user_id')?->forceFill(['status' => AssignmentStatus::Acknowledged])->save();
    $owner = userWithRole('Team Member', $project->user_id);
    $actor = mealManager();
    $proposal = mrgProposal('2026-11-01', '2027-01-31', ['status' => ProposalStatus::Approved]);
    $template = Activity::withoutRecording(function () use ($project, $proposal): ReportTemplate {
        $proposal->projects()->attach($project->id, ['target_beneficiaries' => 100]);
        $template = ReportTemplate::factory()->create(['project_id' => $project->id]);
        $template->proposals()->attach($proposal->id, ['joined_at' => now()]);

        return $template;
    });
    $lines = Activity::withoutRecording(fn (): array => array_map(fn (): ReportTemplateLine => ReportTemplateLine::factory()->create([
        'report_template_id' => $template->id,
        'plan_indicator_id' => PlanIndicator::factory()->forProposal($proposal)->create([
            'indicator_id' => Indicator::factory()->create(['library_indicator_id' => LibraryIndicator::factory()->create()->id])->id,
            'project_id' => $project->id,
        ])->id,
        'effective_from' => '2026-11-01',
        'effective_to' => '2027-01-01',
    ]), [1, 2]));
    $this->actingAs($actor);

    app(ConfirmReportTemplate::class)->handle($template, [$lines[0]->id]);
    $ids = MonthlyReport::query()->where('report_template_id', $template->id)->orderBy('id')->pluck('id')->all();
    app(ConfirmReportTemplate::class)->handle($template->fresh(), [$lines[1]->id]);

    expect(mrgPeriods($template))->toBe(['2026-11', '2026-12', '2027-01'])
        ->and(MonthlyReport::query()->where('report_template_id', $template->id)->orderBy('id')->pluck('id')->all())->toBe($ids)
        ->and(mrgGeneratedNotifications($owner))->toBe(1);
});

test('two confirmed templates at one place each get their own draft for the month', function () {
    ['project' => $project] = mrgProject();
    $proposal = mrgProposal('2026-11-01', '2027-01-31');
    $row = proposalRowForProject($proposal, $project);
    $place = new ReportPlace((int) $project->city_id, (int) $project->program_id, (int) $row->centers()->value('center_id'), false);
    $other = mrgProposal('2026-11-01', '2027-01-31');
    [$first, $second] = Activity::withoutRecording(fn (): array => [
        ReportTemplate::factory()->forPlace($place, $proposal)->confirmed()->create(),
        ReportTemplate::factory()->forPlace($place, $other)->confirmed()->create(),
    ]);

    mrgConfirm($first);
    mrgConfirm($second);

    $november = MonthlyReport::query()->where('period', '2026-11-01')->orderBy('report_template_id')->get();
    expect($november)->toHaveCount(2)
        ->and($november->pluck('report_template_id')->all())->toBe([$first->id, $second->id])
        ->and($november[0]->project_id)->toBe(app(PlaceBridge::class)->legacyProjectIdFor($first))
        ->and($november[0]->project_id)->toBe($project->id)
        ->and($november[0]->slug)->toBe('report-'.$first->id.'-2026-11')
        ->and($november[1]->project_id)->toBeNull()
        ->and($november[1]->slug)->toBe('report-'.$second->id.'-2026-11')
        ->and(mrgPeriods($second))->toBe(['2026-11', '2026-12', '2027-01']);
});

test('a period change adds the new months only and keeps every existing report', function () {
    ['project' => $project, 'owner' => $owner] = mrgProject();
    $template = mrgTemplate($project);
    mrgConfirm($template);
    $first = MonthlyReport::query()->where('report_template_id', $template->id)->orderBy('period')->firstOrFail();
    Activity::withoutRecording(fn () => $first->forceFill(['status' => MonthlyReportStatus::Submitted, 'submitted_at' => now()])->save());
    $template->proposals()->sole()->forceFill(['actual_date_end' => '2027-06-30'])->saveQuietly();

    ProjectPeriodChanged::dispatch(
        $project->id,
        CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2027-04-30'),
        CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2027-06-30'),
    );

    expect(mrgPeriods($template))->toBe(['2026-11', '2026-12', '2027-01', '2027-02', '2027-03', '2027-04', '2027-05', '2027-06'])
        ->and($first->fresh()->status)->toBe(MonthlyReportStatus::Submitted)
        ->and($first->fresh()->updated_at->equalTo($first->updated_at))->toBeTrue()
        ->and(mrgGeneratedNotifications($owner))->toBe(1);
});

test('a proposal joining the template adds the new months only', function () {
    ['project' => $project] = mrgProject();
    $template = mrgTemplate($project, '2026-11-01', '2027-01-31');
    mrgConfirm($template);
    $before = MonthlyReport::query()->where('report_template_id', $template->id)->orderBy('id')->get(['id', 'period', 'status', 'updated_at']);
    $later = mrgProposal('2026-12-01', '2027-03-31');
    Activity::withoutRecording(fn () => $template->proposals()->attach($later->id, ['joined_at' => now()]));

    mrgConfirm($template);

    $after = MonthlyReport::query()->where('report_template_id', $template->id)->orderBy('id')->get(['id', 'period', 'status', 'updated_at']);
    expect(mrgPeriods($template))->toBe(['2026-11', '2026-12', '2027-01', '2027-02', '2027-03'])
        ->and($after->take(3)->toArray())->toBe($before->toArray());
});

test('generation runs in a fixed number of queries, whatever the number of months', function () {
    ['project' => $shortProject] = mrgProject();
    ['project' => $longProject] = mrgProject();
    $short = mrgTemplate($shortProject, '2026-11-01', '2027-01-31');
    $long = mrgTemplate($longProject, '2026-11-01', '2027-10-31');
    $generator = app(MonthlyReportGenerator::class);

    $count = function (ReportTemplate $template, string $end) use ($generator): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $generator->generate($template->fresh(), CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse($end));
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    expect($count($short, '2027-01-31'))->toBe($count($long, '2027-10-31'))
        ->and(mrgPeriods($short))->toHaveCount(3)
        ->and(mrgPeriods($long))->toHaveCount(12);
});

test('generate returns the ids it created and nothing for months that exist', function () {
    ['project' => $project] = mrgProject();
    $template = mrgTemplate($project);
    $generator = app(MonthlyReportGenerator::class);

    $created = $generator->generate($template, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-12-31'));
    $again = $generator->generate($template, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2027-01-31'));

    expect($created)->toHaveCount(2)
        ->and($again)->toHaveCount(1)
        ->and(MonthlyReport::query()->whereKey($again)->value('period')->format('Y-m'))->toBe('2027-01');
});

test('backfill starts at --from, never before the template window, and builds the lines of started months', function () {
    ['project' => $old] = mrgProject('2026-03-01', '2027-02-28');
    ['project' => $late] = mrgProject('2026-12-01', '2027-03-31');
    $oldTemplate = mrgTemplate($old, '2026-03-01', '2027-02-28');
    $lateTemplate = mrgTemplate($late, '2026-12-01', '2027-03-31');
    mrgConfirmedLine($old, '2026-03-01', '2027-02-01');

    $this->artisan('monthly-reports:backfill', ['--from' => '2026-10'])
        ->expectsOutputToContain(__('monthly_reports.console.backfill_template_row', ['template' => mrgLabel($oldTemplate), 'created' => 5, 'skipped' => 0, 'lines' => 1]))
        ->expectsOutputToContain(__('monthly_reports.console.backfill_template_row', ['template' => mrgLabel($lateTemplate), 'created' => 4, 'skipped' => 0, 'lines' => 0]))
        ->assertSuccessful();

    expect(mrgPeriods($oldTemplate))->toBe(['2026-10', '2026-11', '2026-12', '2027-01', '2027-02'])
        ->and(mrgPeriods($lateTemplate))->toBe(['2026-12', '2027-01', '2027-02', '2027-03'])
        ->and(MonthlyReportLine::query()->count())->toBe(1)
        ->and(MonthlyReportLine::query()->sole()->monthlyReport->period->format('Y-m'))->toBe('2026-10');
});

test('backfill is idempotent and counts the months it skipped', function () {
    ['project' => $project] = mrgProject('2026-03-01', '2027-02-28');
    $template = mrgTemplate($project, '2026-03-01', '2027-02-28');

    $this->artisan('monthly-reports:backfill', ['--from' => '2026-10'])->assertSuccessful();
    $this->artisan('monthly-reports:backfill', ['--from' => '2026-10'])
        ->expectsOutputToContain(__('monthly_reports.console.backfill_template_row', ['template' => mrgLabel($template), 'created' => 0, 'skipped' => 5, 'lines' => 0]))
        ->assertSuccessful();

    expect(MonthlyReport::query()->count())->toBe(5);
});

test('backfill --dry-run writes nothing', function () {
    ['project' => $project] = mrgProject('2026-03-01', '2027-02-28');
    $template = mrgTemplate($project, '2026-03-01', '2027-02-28');
    mrgConfirmedLine($project, '2026-03-01', '2027-02-01');

    $this->artisan('monthly-reports:backfill', ['--from' => '2026-10', '--dry-run' => true])
        ->expectsOutputToContain(__('monthly_reports.console.backfill_template_row', ['template' => mrgLabel($template), 'created' => 5, 'skipped' => 0, 'lines' => 1]))
        ->expectsOutputToContain(__('monthly_reports.console.dry_run'))
        ->assertSuccessful();

    expect(MonthlyReport::query()->count())->toBe(0)
        ->and(MonthlyReportLine::query()->count())->toBe(0);
});

test('backfill skips templates that are not confirmed and refuses a malformed --from', function () {
    ['project' => $project] = mrgProject('2026-03-01', '2027-02-28');
    mrgTemplate($project, '2026-03-01', '2027-02-28', confirmed: false);

    $this->artisan('monthly-reports:backfill', ['--from' => '2026-10'])->assertSuccessful();
    $this->artisan('monthly-reports:backfill', ['--from' => 'October'])
        ->expectsOutputToContain(__('monthly_reports.console.invalid_from'))
        ->assertFailed();

    expect(MonthlyReport::query()->count())->toBe(0);
});

test('backfill builds the lines of a template with no project', function () {
    ['project' => $project] = mrgProject();
    $template = mrgTemplateWithoutProject($project, '2026-09-01', '2026-12-31');
    mrgTemplateLine($template, '2026-09-01', '2026-12-01');

    $this->artisan('monthly-reports:backfill', ['--from' => '2026-09'])
        ->expectsOutputToContain(__('monthly_reports.console.backfill_template_row', ['template' => mrgLabel($template), 'created' => 4, 'skipped' => 0, 'lines' => 2]))
        ->assertSuccessful();

    expect(mrgPeriods($template))->toBe(['2026-09', '2026-10', '2026-11', '2026-12'])
        ->and(MonthlyReport::query()->whereNotNull('project_id')->count())->toBe(0)
        ->and(MonthlyReportLine::query()->count())->toBe(2);
});

test('prepare builds the current month\'s lines on the first working day, idempotently', function () {
    $this->travelTo('2026-11-02 06:00:00');
    ['project' => $project] = mrgProject();
    $template = mrgTemplate($project);
    mrgConfirm($template);
    mrgConfirmedLine($project);

    $this->artisan('monthly-reports:prepare')
        ->expectsOutputToContain(__('monthly_reports.console.prepare_template_row', ['template' => mrgLabel($template), 'lines' => 1]))
        ->assertSuccessful();
    $this->artisan('monthly-reports:prepare')->assertSuccessful();

    $november = MonthlyReport::query()->where('report_template_id', $template->id)->where('period', '2026-11-01')->sole();
    expect($november->lines()->count())->toBe(1)
        ->and(MonthlyReportLine::query()->count())->toBe(1)
        ->and($november->lines()->sole()->shares()->count())->toBe(1);
});

test('prepare does nothing on any other day, and --dry-run writes nothing', function () {
    $this->travelTo('2026-11-03 06:00:00');
    ['project' => $project] = mrgProject();
    mrgConfirm(mrgTemplate($project));
    mrgConfirmedLine($project);

    $this->artisan('monthly-reports:prepare')->assertSuccessful();
    expect(MonthlyReportLine::query()->count())->toBe(0);

    $this->travelTo('2026-11-02 06:00:00');
    $this->artisan('monthly-reports:prepare', ['--dry-run' => true])
        ->expectsOutputToContain(__('monthly_reports.console.dry_run'))
        ->assertSuccessful();
    expect(MonthlyReportLine::query()->count())->toBe(0);
});

test('prepare follows the working days of the report\'s project', function () {
    // Sunday 1 November 2026 is a working day for a Sunday–Thursday project.
    $this->travelTo('2026-11-01 06:00:00');
    ['project' => $project] = mrgProject();
    Activity::withoutRecording(fn () => $project->forceFill(['working_days' => [7, 1, 2, 3, 4]])->save());
    mrgConfirm(mrgTemplate($project));
    mrgConfirmedLine($project);

    $this->artisan('monthly-reports:prepare')->assertSuccessful();

    expect(MonthlyReportLine::query()->count())->toBe(1);
});

test('prepare builds the lines of a template with no project', function () {
    $this->travelTo('2026-11-02 06:00:00');
    ['project' => $project] = mrgProject();
    $template = mrgTemplateWithoutProject($project, '2026-11-01', '2027-01-31');
    mrgTemplateLine($template, '2026-11-01', '2027-01-01');
    mrgConfirm($template);

    $this->artisan('monthly-reports:prepare')
        ->expectsOutputToContain(__('monthly_reports.console.prepare_template_row', ['template' => mrgLabel($template), 'lines' => 1]))
        ->assertSuccessful();

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
