<?php

use App\Actions\MonthlyReports\DeleteMonthlyReport;
use App\Actions\MonthlyReports\MarkMonthlyReportSeen;
use App\Actions\MonthlyReports\OpenMonthlyReport;
use App\Actions\MonthlyReports\RefreshMonthlyReportLines;
use App\Actions\MonthlyReports\ReturnMonthlyReport;
use App\Actions\MonthlyReports\SaveMonthlyReportLines;
use App\Actions\MonthlyReports\SubmitMonthlyReport;
use App\Actions\MonthlyReports\ValidateMonthlyReport;
use App\Enums\DenominatorBasis;
use App\Enums\DonorAttribution;
use App\Enums\IndicatorCollector;
use App\Enums\IndicatorMeasure;
use App\Enums\MonthlyReportStatus;
use App\Enums\ReportStatus;
use App\Jobs\FanOutActivity;
use App\Models\Activity;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\MealCoverageAssignment;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportLine;
use App\Models\MonthlyReportLineShare;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

/*
| The monthly report workflow (contracts §13 Entry and Workflow, §13.1 items
| 2, 5, 7; D-07, D-08, D-25, D-46, D-48a, D-50): the server computes every
| figure, shares follow the attribution mode, mirrors carry the figures into
| `reports`, and every move is guarded by the expected status.
*/

beforeEach(function () {
    $this->travelTo('2026-11-02 10:00:00');
    $this->city = insertHrCity('Aleppo');
    $this->ownerEmployee = insertHrEmployee(firstNameEn: 'Omar', lastNameEn: 'Owner');
    $this->owner = userWithRole('Public Relations', $this->ownerEmployee);
    $this->officerEmployee = insertHrEmployee(firstNameEn: 'Nour', lastNameEn: 'Officer');
    Activity::withoutRecording(fn () => MealCoverageAssignment::factory()->forCity($this->city)->create(['employee_id' => $this->officerEmployee]));
    $this->officer = userWithRole('MEAL', $this->officerEmployee);
});

/**
 * An active project (Oct – Dec 2026) of the owner in a program of the given
 * attribution mode.
 */
function mrwProject(DonorAttribution $attribution = DonorAttribution::Shared): Project
{
    return Activity::withoutRecording(fn (): Project => Project::factory()->active()->create([
        'program_id' => Program::factory()->create(['donor_attribution' => $attribution])->id,
        'city_id' => test()->city,
        'center_id' => null,
        'user_id' => test()->ownerEmployee,
        'start_date' => '2026-10-01',
        'end_date' => '2026-12-31',
    ]));
}

function mrwReport(Project $project, string $month = '2026-10-01', MonthlyReportStatus $status = MonthlyReportStatus::Draft): MonthlyReport
{
    return Activity::withoutRecording(fn (): MonthlyReport => match ($status) {
        MonthlyReportStatus::Draft => MonthlyReport::factory()->create(['project_id' => $project->id, 'period' => $month]),
        MonthlyReportStatus::Submitted => MonthlyReport::factory()->submitted()->create(['project_id' => $project->id, 'period' => $month, 'submitted_by' => test()->owner->id, 'prepared_by' => test()->owner->id]),
        MonthlyReportStatus::Validated => MonthlyReport::factory()->validated()->create(['project_id' => $project->id, 'period' => $month, 'submitted_by' => test()->owner->id, 'prepared_by' => test()->owner->id]),
    });
}

/**
 * A line of the report on a new library indicator, with one share per
 * fixed denominator given (null = no fixed denominator).
 *
 * @param  array<string, mixed>  $library
 * @param  list<int|null>  $shareDenominators
 */
function mrwLine(MonthlyReport $report, array $library = [], array $shareDenominators = [null], ?int $lineDenominator = null, IndicatorCollector $enteredBy = IndicatorCollector::ProjectTeam): MonthlyReportLine
{
    return Activity::withoutRecording(function () use ($report, $library, $shareDenominators, $lineDenominator, $enteredBy): MonthlyReportLine {
        $libraryIndicator = LibraryIndicator::factory()->create($library);
        $line = MonthlyReportLine::factory()->create([
            'monthly_report_id' => $report->id,
            'library_indicator_id' => $libraryIndicator->id,
            'entered_by' => $enteredBy,
            'fixed_denominator' => $lineDenominator,
        ]);

        foreach ($shareDenominators as $denominator) {
            $indicator = Indicator::factory()->create([
                'library_indicator_id' => $libraryIndicator->id,
                'measure' => $libraryIndicator->measure,
            ]);
            $target = PlanIndicator::factory()->forProposal()->create(['indicator_id' => $indicator->id, 'project_id' => $report->project_id]);
            MonthlyReportLineShare::factory()->create([
                'monthly_report_line_id' => $line->id,
                'plan_indicator_id' => $target->id,
                'fixed_denominator' => $denominator,
            ]);
        }

        return $line->load('shares');
    });
}

/**
 * @param  list<array<string, mixed>>  $lines
 */
function mrwSave(MonthlyReport $report, array $lines, ?User $as = null, MonthlyReportStatus $expected = MonthlyReportStatus::Draft): MonthlyReport
{
    test()->actingAs($as ?? test()->owner);

    return app(SaveMonthlyReportLines::class)->handle($report->fresh(), $expected, $lines);
}

function mrwSubmit(MonthlyReport $report, MonthlyReportStatus $expected = MonthlyReportStatus::Draft): MonthlyReport
{
    test()->actingAs(test()->owner);

    return app(SubmitMonthlyReport::class)->handle($report->fresh(), $expected);
}

function mrwValidate(MonthlyReport $report): MonthlyReport
{
    test()->actingAs(test()->officer);

    return app(ValidateMonthlyReport::class)->handle($report->fresh(), MonthlyReportStatus::Submitted);
}

/**
 * @return array<string, list<string>>
 */
function mrwErrors(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

test('a foreign line or share id rejects the whole batch', function () {
    $report = mrwReport(mrwProject());
    $line = mrwLine($report);
    $foreign = mrwLine(mrwReport(mrwProject(), '2026-11-01'));
    $split = mrwLine($splitReport = mrwReport(mrwProject(DonorAttribution::Split)));

    expect(fn () => mrwSave($report, [['id' => $line->id, 'value' => 5], ['id' => $foreign->id, 'value' => 7]]))
        ->toThrow(ModelNotFoundException::class);

    expect(fn () => mrwSave($splitReport, [['id' => $split->id, 'shares' => [['id' => $foreign->shares->first()->id, 'value' => 3]]]]))
        ->toThrow(ModelNotFoundException::class)
        ->and($line->fresh()->value)->toBeNull()
        ->and(Report::query()->count())->toBe(0);
});

test('a fixed-basis percentage takes the count only: 36 of 180 is 20%', function () {
    $report = mrwReport(mrwProject());
    $line = mrwLine($report, ['measure' => IndicatorMeasure::Percentage, 'denominator_basis' => DenominatorBasis::Fixed], [180], 180);

    mrwSave($report, [['id' => $line->id, 'numerator' => 36, 'denominator' => 999, 'value' => 99]]);

    $mirror = Report::query()->sole();

    expect((float) $line->fresh()->value)->toBe(20.0)
        ->and((float) $line->fresh()->denominator)->toBe(180.0)
        ->and((float) $mirror->numerator)->toBe(36.0)
        ->and((float) $mirror->denominator)->toBe(180.0)
        ->and($mirror->status)->toBe(ReportStatus::Draft)
        ->and($mirror->user_id)->toBe($this->owner->id)
        ->and($mirror->planned_date->toDateString())->toBe('2026-10-31')
        ->and($mirror->period_start->toDateString())->toBe('2026-10-01')
        ->and($mirror->period_end->toDateString())->toBe('2026-10-31');
});

test('a count above the fixed denominator is rejected', function () {
    $report = mrwReport(mrwProject());
    $line = mrwLine($report, ['measure' => IndicatorMeasure::Percentage, 'denominator_basis' => DenominatorBasis::Fixed], [180], 180);

    expect(mrwErrors(fn () => mrwSave($report, [['id' => $line->id, 'numerator' => 181]]))['lines.0.numerator'] ?? [])
        ->toBe([__('monthly_reports.above_fixed_denominator', ['count' => 180])]);
});

test('split shares are entered one by one and the line is their sum', function () {
    $report = mrwReport(mrwProject(DonorAttribution::Split));
    $line = mrwLine($report, shareDenominators: [100, 60], lineDenominator: 160);
    [$first, $second] = $line->shares->all();

    mrwSave($report, [['id' => $line->id, 'shares' => [['id' => $first->id, 'value' => 6], ['id' => $second->id, 'value' => 4]]]]);

    expect((float) $line->fresh()->value)->toBe(10.0)
        ->and(Report::query()->orderBy('monthly_report_line_share_id')->pluck('value')->map(fn ($value) => (float) $value)->all())->toBe([6.0, 4.0]);
});

test('split fixed percentages divide each share by its own proposal', function () {
    $report = mrwReport(mrwProject(DonorAttribution::Split));
    $line = mrwLine($report, ['measure' => IndicatorMeasure::Percentage, 'denominator_basis' => DenominatorBasis::Fixed], [100, 50], 150);
    [$first, $second] = $line->shares->all();

    mrwSave($report, [['id' => $line->id, 'shares' => [['id' => $first->id, 'numerator' => 30], ['id' => $second->id, 'numerator' => 15]]]]);

    expect(Report::query()->orderBy('monthly_report_line_share_id')->get()->map(fn (Report $mirror) => [(float) $mirror->numerator, (float) $mirror->denominator, (float) $mirror->value])->all())
        ->toBe([[30.0, 100.0, 30.0], [15.0, 50.0, 30.0]])
        ->and((float) $line->fresh()->value)->toBe(30.0);
});

test('entered ratio shares pool: 9/10 and 1/2 make 10/12', function () {
    $report = mrwReport(mrwProject(DonorAttribution::Split));
    $line = mrwLine($report, ['measure' => IndicatorMeasure::Percentage, 'denominator_basis' => DenominatorBasis::Entered], [null, null]);
    [$first, $second] = $line->shares->all();

    mrwSave($report, [['id' => $line->id, 'shares' => [
        ['id' => $first->id, 'numerator' => 9, 'denominator' => 10],
        ['id' => $second->id, 'numerator' => 1, 'denominator' => 2],
    ]]]);

    $line->refresh();

    expect((float) $line->numerator)->toBe(10.0)
        ->and((float) $line->denominator)->toBe(12.0)
        ->and((float) $line->value)->toBe(83.33);
});

test('a shared line is entered once and copied to every share', function () {
    $report = mrwReport(mrwProject());
    $line = mrwLine($report, shareDenominators: [100, 60], lineDenominator: 100);

    mrwSave($report, [['id' => $line->id, 'value' => 30]]);

    expect(Report::query()->pluck('value')->map(fn ($value) => (float) $value)->all())->toBe([30.0, 30.0])
        ->and($line->shares()->pluck('value')->map(fn ($value) => (float) $value)->all())->toBe([30.0, 30.0]);
});

test('a disaggregated count is the sum of its breakdown', function () {
    $report = mrwReport(mrwProject());
    $line = mrwLine($report, ['is_disaggregated' => true]);

    mrwSave($report, [['id' => $line->id, 'value' => 999, 'breakdowns' => [
        ['gender' => 'male', 'value' => 10, 'disabled_value' => 2],
        ['gender' => 'female', 'value' => 17, 'disabled_value' => 1],
    ]]]);

    expect((float) $line->fresh()->value)->toBe(27.0)
        ->and($line->breakdowns()->count())->toBe(2);
});

test('more persons with disabilities than the gender, a numerator above its denominator and a zero denominator are rejected', function () {
    $report = mrwReport(mrwProject());
    $count = mrwLine($report, ['is_disaggregated' => true]);
    $ratio = mrwLine($report, ['measure' => IndicatorMeasure::Average, 'denominator_basis' => DenominatorBasis::Entered]);
    $percent = mrwLine($report, ['measure' => IndicatorMeasure::Percentage, 'denominator_basis' => DenominatorBasis::Entered]);

    $errors = mrwErrors(fn () => mrwSave($report, [
        ['id' => $count->id, 'breakdowns' => [['gender' => 'male', 'value' => 3, 'disabled_value' => 4]]],
        ['id' => $ratio->id, 'numerator' => 5, 'denominator' => 0],
        ['id' => $percent->id, 'numerator' => 11, 'denominator' => 10],
    ]));

    expect($errors['lines.0.breakdowns.0.disabled_value'] ?? [])->toBe([__('monthly_reports.disabled_above_gender')])
        ->and($errors['lines.1.denominator'] ?? [])->toBe([__('monthly_reports.zero_denominator')])
        ->and($errors['lines.2.numerator'] ?? [])->toBe([__('monthly_reports.numerator_above_denominator')])
        ->and(Report::query()->count())->toBe(0);
});

test('not measured leaves the value empty and achievement unchanged', function () {
    $report = mrwReport(mrwProject());
    $line = mrwLine($report, ['measure' => IndicatorMeasure::Percentage, 'denominator_basis' => DenominatorBasis::Entered]);
    $target = $line->shares->first()->planIndicator;

    mrwSave($report, [['id' => $line->id, 'numerator' => 4, 'denominator' => 8, 'not_measured' => true]]);
    mrwSubmit($report);
    mrwValidate($report);

    expect($line->fresh()->not_measured)->toBeTrue()
        ->and($line->fresh()->value)->toBeNull()
        ->and(Report::query()->sole()->value)->toBeNull()
        ->and(PlanIndicator::query()->with(['reports', 'indicator'])->find($target->id)->achievedValue())->toBe(0.0);
});

test('an empty count blocks submit, a zero does not', function () {
    $report = mrwReport(mrwProject());
    $line = mrwLine($report);

    expect(mrwErrors(fn () => mrwSubmit($report))['lines'] ?? [])->toBe([__('monthly_reports.value_required')]);

    mrwSave($report, [['id' => $line->id, 'value' => 0]]);

    expect(mrwSubmit($report)->status)->toBe(MonthlyReportStatus::Submitted)
        ->and(Report::query()->sole()->status)->toBe(ReportStatus::Reviewed)
        ->and(Report::query()->sole()->submit_date->toDateString())->toBe('2026-11-02');
});

test('submit creates the mirrors of shares never written', function () {
    $report = mrwReport(mrwProject());
    mrwLine($report, enteredBy: IndicatorCollector::Meal);

    mrwSubmit($report);

    expect(Report::query()->sole())
        ->status->toBe(ReportStatus::Reviewed)
        ->value->toBeNull()
        ->user_id->toBe($this->owner->id);
});

test('submit before the month\'s last working day is refused', function () {
    $this->travelTo('2026-10-29 10:00:00');
    $report = mrwReport(mrwProject());

    expect(mrwErrors(fn () => mrwSubmit($report))['status'] ?? [])->toBe([__('monthly_reports.submit_too_early')])
        ->and($report->fresh()->status)->toBe(MonthlyReportStatus::Draft);
});

test('the nearest earlier month with lines must be submitted first; a line-less month in between does not count', function () {
    $this->travelTo('2027-01-04 10:00:00');
    $project = mrwProject();
    mrwLine(mrwReport($project, '2026-10-01'));
    mrwReport($project, '2026-11-01');
    $december = mrwReport($project, '2026-12-01');
    $other = mrwProject();
    mrwReport($other, '2026-11-01');
    $lineless = mrwReport($other, '2026-12-01');

    expect(mrwErrors(fn () => mrwSubmit($december))['status'] ?? [])
        ->toBe([__('monthly_reports.submit_previous_first', ['month' => CarbonImmutable::parse('2026-10-01')->translatedFormat('F Y')])]);

    expect(mrwSubmit($lineless)->status)->toBe(MonthlyReportStatus::Submitted);
});

test('validation approves the mirrors, so achievement changes', function () {
    $report = mrwReport(mrwProject());
    $line = mrwLine($report);
    $targetId = $line->shares->first()->plan_indicator_id;
    $achieved = fn (): float => PlanIndicator::query()->with(['reports', 'indicator'])->find($targetId)->achievedValue();

    mrwSave($report, [['id' => $line->id, 'value' => 12]]);
    mrwSubmit($report);

    expect($achieved())->toBe(0.0);

    $validated = mrwValidate($report);

    expect($validated->status)->toBe(MonthlyReportStatus::Validated)
        ->and($validated->validated_by)->toBe($this->officer->id)
        ->and(Report::query()->sole()->status)->toBe(ReportStatus::Approved)
        ->and($achieved())->toBe(12.0);
});

test('an unfilled MEAL line blocks validation, not submission; the validator fills it, the manager cannot', function () {
    $report = mrwReport(mrwProject());
    $meal = mrwLine($report, enteredBy: IndicatorCollector::Meal);

    expect(fn () => mrwSave($report, [['id' => $meal->id, 'value' => 3]]))->toThrow(AuthorizationException::class);

    mrwSubmit($report);

    expect(mrwErrors(fn () => mrwValidate($report))['lines'] ?? [])->toBe([__('monthly_reports.meal_lines_pending')]);

    mrwSave($report, [['id' => $meal->id, 'value' => 3]], $this->officer, MonthlyReportStatus::Submitted);

    expect($meal->fresh()->filled_by)->toBe($this->officer->id)
        ->and(Report::query()->sole()->status)->toBe(ReportStatus::Reviewed)
        ->and(mrwValidate($report)->status)->toBe(MonthlyReportStatus::Validated);
});

test('the validator cannot change the manager\'s lines', function () {
    $report = mrwReport(mrwProject(), status: MonthlyReportStatus::Submitted);
    $line = mrwLine($report);

    expect(fn () => mrwSave($report, [['id' => $line->id, 'value' => 3]], $this->officer, MonthlyReportStatus::Submitted))
        ->toThrow(AuthorizationException::class);
});

test('a return needs a reason of ten characters and sends the report back to draft', function () {
    $report = mrwReport(mrwProject());
    mrwLine($report, ['measure' => IndicatorMeasure::Numeric]);
    $report->lines()->update(['value' => 4]);
    mrwSubmit($report);
    $this->actingAs($this->officer);

    expect(mrwErrors(fn () => app(ReturnMonthlyReport::class)->handle($report->fresh(), MonthlyReportStatus::Submitted, 'too short'))['reason'] ?? [])
        ->toBe([__('monthly_reports.reason_min')]);

    $returned = app(ReturnMonthlyReport::class)->handle($report->fresh(), MonthlyReportStatus::Submitted, 'Please recheck the totals.');

    expect($returned->status)->toBe(MonthlyReportStatus::Draft)
        ->and($returned->returned_by)->toBe($this->officer->id)
        ->and($returned->return_reason)->toBe('Please recheck the totals.')
        ->and(Report::query()->sole())->status->toBe(ReportStatus::Draft)->submit_date->toBeNull();
});

test('the General Manager and the preparer cannot validate', function () {
    $report = mrwReport(mrwProject(), status: MonthlyReportStatus::Submitted);

    $this->actingAs(userWithRole('General Manager', insertHrEmployee(firstNameEn: 'Gm', lastNameEn: 'Boss')));
    expect(fn () => app(ValidateMonthlyReport::class)->handle($report->fresh(), MonthlyReportStatus::Submitted))->toThrow(AuthorizationException::class);

    $this->actingAs($this->owner);
    expect(fn () => app(ValidateMonthlyReport::class)->handle($report->fresh(), MonthlyReportStatus::Submitted))->toThrow(AuthorizationException::class);
});

test('a stale expected status writes nothing', function () {
    $report = mrwReport(mrwProject());
    $line = mrwLine($report);

    expect(mrwErrors(fn () => mrwSave($report, [['id' => $line->id, 'value' => 3]], expected: MonthlyReportStatus::Submitted))['status'] ?? [])
        ->toBe([__('monthly_reports.stale')])
        ->and($line->fresh()->value)->toBeNull();
});

test('a double submit records one submission and notifies once, after commit', function () {
    Queue::fake();
    $report = mrwReport(mrwProject());

    mrwSubmit($report);

    expect(mrwErrors(fn () => mrwSubmit($report))['status'] ?? [])->toBe([__('monthly_reports.stale')])
        ->and(Activity::query()->where('event', 'monthly_report.submitted')->count())->toBe(1);

    Queue::assertPushed(FanOutActivity::class, 1);
});

test('opening refuses a future month and a month outside the project, and creates a started month\'s draft', function () {
    $project = mrwProject();
    $this->actingAs($this->owner);
    $open = fn (string $month) => app(OpenMonthlyReport::class)->handle($project, CarbonImmutable::parse($month));

    expect(mrwErrors(fn () => $open('2026-12-01'))['month'] ?? [])->toBe([__('monthly_reports.future_month')])
        ->and(mrwErrors(fn () => $open('2026-09-01'))['month'] ?? [])->toBe([__('monthly_reports.outside_period')]);

    $report = $open('2026-11-15');

    expect($report->period->toDateString())->toBe('2026-11-01')
        ->and($report->prepared_by)->toBeNull()
        ->and($open('2026-11-01')->id)->toBe($report->id);
});

test('refresh recomputes a draft line\'s fixed denominator from its shares', function () {
    $report = mrwReport(mrwProject(DonorAttribution::Split));
    $line = mrwLine($report, shareDenominators: [100, 60], lineDenominator: 100);
    $this->actingAs($this->owner);

    app(RefreshMonthlyReportLines::class)->handle($report, MonthlyReportStatus::Draft);

    expect($line->fresh()->fixed_denominator)->toBe(160);
});

test('MEAL marks a submitted report seen once', function () {
    $report = mrwReport(mrwProject(), status: MonthlyReportStatus::Submitted);
    $this->actingAs($this->officer);

    expect(app(MarkMonthlyReportSeen::class)->handle($report))->toBeTrue()
        ->and(app(MarkMonthlyReportSeen::class)->handle($report))->toBeFalse()
        ->and($report->fresh()->seen_by_meal_at)->not->toBeNull();
});

test('a never-submitted draft is deleted with its lines and mirrors', function () {
    $report = mrwReport(mrwProject());
    $line = mrwLine($report);
    mrwSave($report, [['id' => $line->id, 'value' => 2]]);

    app(DeleteMonthlyReport::class)->handle($report->fresh(), MonthlyReportStatus::Draft);

    expect(MonthlyReport::query()->count())->toBe(0)
        ->and(MonthlyReportLine::query()->count())->toBe(0)
        ->and(Report::query()->count())->toBe(0);
});

test('a report writer outside the project is refused every move', function (Closure $move) {
    $report = mrwReport($project = mrwProject());
    $this->actingAs(userWithRole('Public Relations', insertHrEmployee(firstNameEn: 'Sara', lastNameEn: 'Stranger')));

    expect(fn () => $move($report, $project))->toThrow(AuthorizationException::class);
})->with([
    'open' => [fn (MonthlyReport $report, Project $project) => app(OpenMonthlyReport::class)->handle($project, CarbonImmutable::parse('2026-10-01'))],
    'save' => [fn (MonthlyReport $report) => app(SaveMonthlyReportLines::class)->handle($report, MonthlyReportStatus::Draft, [])],
    'submit' => [fn (MonthlyReport $report) => app(SubmitMonthlyReport::class)->handle($report, MonthlyReportStatus::Draft)],
    'refresh' => [fn (MonthlyReport $report) => app(RefreshMonthlyReportLines::class)->handle($report, MonthlyReportStatus::Draft)],
    'delete' => [fn (MonthlyReport $report) => app(DeleteMonthlyReport::class)->handle($report, MonthlyReportStatus::Draft)],
    'mark seen' => [fn (MonthlyReport $report) => app(MarkMonthlyReportSeen::class)->handle($report)],
]);
