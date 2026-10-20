<?php

use App\Enums\ActivityKind;
use App\Enums\ActivityStatus;
use App\Enums\CollectionFrequency;
use App\Enums\DenominatorBasis;
use App\Enums\DonorAttribution;
use App\Enums\IndicatorCollector;
use App\Enums\IndicatorMeasure;
use App\Enums\MonthlyReportStatus;
use App\Enums\NarrativeQuestionFrequency;
use App\Enums\ProposalStatus;
use App\Enums\ReopenRequestStatus;
use App\Enums\ResultType;
use App\Models\Activity;
use App\Models\ActivitySchedule;
use App\Models\Donor;
use App\Models\EvidenceType;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\MealCoverageAssignment;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportActivityEntry;
use App\Models\MonthlyReportAnswer;
use App\Models\MonthlyReportFile;
use App\Models\MonthlyReportLine;
use App\Models\MonthlyReportLineShare;
use App\Models\MonthlyReportReopenRequest;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\ProjectActivityStatus;
use App\Models\Proposal;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateLine;
use App\Models\ReportTemplateQuestion;
use App\Models\Result;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| The Monthly Report page (T17B slice 1; contracts §13, §13.1 items 2, 6, 7
| and 9; §11.1; D-07, D-08, D-09a, D-25, D-46, D-48a, D-50). One grid of
| lines that autosaves on blur through SaveMonthlyReportLines, and every
| workflow action through T17A's actions with the status the page rendered.
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
function mrsProject(DonorAttribution $attribution = DonorAttribution::Shared): Project
{
    return Activity::withoutRecording(fn (): Project => Project::factory()->active()->create([
        'name' => 'Hope Center Aleppo',
        'program_id' => Program::factory()->create(['donor_attribution' => $attribution])->id,
        'city_id' => test()->city,
        'center_id' => null,
        'user_id' => test()->ownerEmployee,
        'start_date' => '2026-10-01',
        'end_date' => '2026-12-31',
    ]));
}

function mrsReport(Project $project, string $month = '2026-10-01', MonthlyReportStatus $status = MonthlyReportStatus::Draft): MonthlyReport
{
    return Activity::withoutRecording(fn (): MonthlyReport => match ($status) {
        MonthlyReportStatus::Draft => MonthlyReport::factory()->create(['project_id' => $project->id, 'period' => $month]),
        MonthlyReportStatus::Submitted => MonthlyReport::factory()->submitted()->create(['project_id' => $project->id, 'period' => $month, 'submitted_by' => test()->owner->id, 'prepared_by' => test()->owner->id]),
        MonthlyReportStatus::Validated => MonthlyReport::factory()->validated()->create(['project_id' => $project->id, 'period' => $month, 'submitted_by' => test()->owner->id, 'prepared_by' => test()->owner->id]),
    });
}

/**
 * A line of the report on a new library entry, with one share per fixed
 * denominator given; each share's target is funded by its own donor.
 *
 * @param  array<string, mixed>  $library
 * @param  list<int|null>  $shareDenominators
 * @param  list<string>  $donorNames
 */
function mrsLine(MonthlyReport $report, array $library = [], array $shareDenominators = [null], ?int $lineDenominator = null, IndicatorCollector $enteredBy = IndicatorCollector::ProjectTeam, array $donorNames = []): MonthlyReportLine
{
    return Activity::withoutRecording(function () use ($report, $library, $shareDenominators, $lineDenominator, $enteredBy, $donorNames): MonthlyReportLine {
        $libraryIndicator = LibraryIndicator::factory()->create($library);
        $line = MonthlyReportLine::factory()->create([
            'monthly_report_id' => $report->id,
            'library_indicator_id' => $libraryIndicator->id,
            'entered_by' => $enteredBy,
            'fixed_denominator' => $lineDenominator,
        ]);

        foreach ($shareDenominators as $index => $denominator) {
            $indicator = Indicator::factory()->create(['library_indicator_id' => $libraryIndicator->id, 'measure' => $libraryIndicator->measure]);
            $proposal = Proposal::factory()->create(isset($donorNames[$index]) ? ['donor_id' => Donor::factory()->create(['name' => $donorNames[$index]])->id] : []);
            $target = PlanIndicator::factory()->forProposal($proposal)->create(['indicator_id' => $indicator->id, 'project_id' => $report->project_id, 'target' => 120]);
            MonthlyReportLineShare::factory()->create([
                'monthly_report_line_id' => $line->id,
                'plan_indicator_id' => $target->id,
                'fixed_denominator' => $denominator,
            ]);
        }

        return $line->load('shares');
    });
}

function mrsPage(MonthlyReport $report, ?User $as = null): mixed
{
    return Livewire::actingAs($as ?? test()->owner)->test('pages::monthly-reports.show', ['monthlyReport' => $report->fresh()]);
}

function mrsToast(string $text): Closure
{
    return fn (string $event, array $params): bool => ($params['slots']['text'] ?? null) === $text;
}

test('guests are redirected, reports.view holders read the page and others are refused', function () {
    $report = mrsReport(mrsProject());

    $this->get(route('monthly-reports.show', $report))->assertRedirect(route('login'));
    $this->actingAs(userWithRole('Team Member'))->get(route('monthly-reports.show', $report))->assertOk()->assertDontSee('wire:model.blur', false);
    $this->actingAs(User::factory()->create())->get(route('monthly-reports.show', $report))->assertForbidden();
});

test('the grid shows the code, both names, the frequency note, the target and the preparer\'s inputs; a blur saves that line only', function () {
    $report = mrsReport(mrsProject());
    $line = mrsLine($report, ['name_ar' => 'عدد الأطفال الملقحين', 'name_en' => 'Children vaccinated', 'frequency_note' => 'Counted at each session']);
    $other = mrsLine($report);

    mrsPage($report)
        ->assertSeeText($line->libraryIndicator->code)
        ->assertSeeText('Children vaccinated')
        ->assertSeeText('عدد الأطفال الملقحين')
        ->assertSeeText('Counted at each session')
        ->assertSeeHtml('data-target="'.$line->id.'"')
        ->assertSeeHtml('wire:model.blur="figures.'.$line->id.'.value"')
        ->set('figures.'.$line->id.'.value', '12')
        ->assertHasNoErrors();

    expect((float) $line->fresh()->value)->toBe(12.0)
        ->and($other->fresh()->value)->toBeNull()
        ->and($report->fresh()->prepared_by)->toBe($this->owner->id);
});

test('a line id from another report shows an error and writes nothing', function () {
    $report = mrsReport(mrsProject());
    mrsLine($report);
    $foreign = mrsLine(mrsReport(mrsProject(), '2026-11-01'));

    mrsPage($report)
        ->set('figures.'.$foreign->id.'.value', '5')
        ->assertHasErrors('figures.'.$foreign->id);

    expect($foreign->fresh()->value)->toBeNull();
});

test('a server error on a figure is shown inline on that input', function () {
    $report = mrsReport(mrsProject());
    $line = mrsLine($report, ['measure' => IndicatorMeasure::Percentage, 'denominator_basis' => DenominatorBasis::Fixed], [180], 180);

    mrsPage($report)
        ->set('figures.'.$line->id.'.numerator', '181')
        ->assertHasErrors(['figures.'.$line->id.'.numerator' => __('monthly_reports.above_fixed_denominator', ['count' => 180])]);

    expect($line->fresh()->numerator)->toBeNull();
});

test('a fixed percentage takes the count, shows the proposal denominator and the computed percentage read-only', function () {
    $report = mrsReport(mrsProject());
    $line = mrsLine($report, ['measure' => IndicatorMeasure::Percentage, 'denominator_basis' => DenominatorBasis::Fixed, 'numerator_label' => 'Trained teachers'], [180], 180);

    mrsPage($report)
        ->assertSeeText('Trained teachers')
        ->assertSeeText(__('monthly_reports.out_of_beneficiaries', ['count' => '180']))
        ->assertDontSeeHtml('wire:model.blur="figures.'.$line->id.'.denominator"')
        ->set('figures.'.$line->id.'.numerator', '36')
        ->assertSeeHtml('data-computed="'.$line->id.'">20%');
});

test('a ratio takes numerator and denominator, and Not measured saves no value', function () {
    $report = mrsReport(mrsProject());
    $line = mrsLine($report, ['measure' => IndicatorMeasure::Percentage, 'denominator_basis' => DenominatorBasis::Entered, 'numerator_label' => 'Passed', 'denominator_label' => 'Tested']);

    $page = mrsPage($report)
        ->assertSeeText('Passed')
        ->assertSeeText('Tested')
        ->assertSeeHtml('wire:model.blur="figures.'.$line->id.'.numerator"')
        ->assertSeeHtml('wire:model.blur="figures.'.$line->id.'.denominator"')
        ->set('figures.'.$line->id.'.numerator', '30')
        ->set('figures.'.$line->id.'.denominator', '40')
        ->assertSeeHtml('data-computed="'.$line->id.'">75%');

    expect((float) $line->fresh()->value)->toBe(75.0);

    $page->set('figures.'.$line->id.'.not_measured', true)->assertHasNoErrors();

    expect($line->fresh()->value)->toBeNull()
        ->and($line->fresh()->not_measured)->toBeTrue();
});

test('split attribution shows one sub-row per donor share and the line total', function () {
    $report = mrsReport(mrsProject(DonorAttribution::Split));
    $line = mrsLine($report, shareDenominators: [null, null], donorNames: ['UNICEF', 'ECHO']);
    [$first, $second] = $line->shares->all();

    mrsPage($report)
        ->assertSeeText('UNICEF')
        ->assertSeeText('ECHO')
        ->assertSeeHtml('data-share="'.$first->id.'"')
        ->assertSeeHtml('data-share="'.$second->id.'"')
        ->set('figures.'.$line->id.'.shares.'.$first->id.'.value', '6')
        ->set('figures.'.$line->id.'.shares.'.$second->id.'.value', '4')
        ->assertSeeHtml('data-split-total="'.$line->id.'">10');

    expect((float) $line->fresh()->value)->toBe(10.0);
});

test('a disaggregated line takes males and females with persons with disabilities, and the total is their sum', function () {
    $report = mrsReport(mrsProject());
    $line = mrsLine($report, ['is_disaggregated' => true]);

    mrsPage($report)
        ->assertSeeHtml('wire:model.blur="figures.'.$line->id.'.breakdowns.male.value"')
        ->assertSeeHtml('wire:model.blur="figures.'.$line->id.'.breakdowns.female.disabled_value"')
        ->assertDontSeeHtml('wire:model.blur="figures.'.$line->id.'.value"')
        ->set('figures.'.$line->id.'.breakdowns.male.value', '10')
        ->set('figures.'.$line->id.'.breakdowns.female.value', '17')
        ->set('figures.'.$line->id.'.breakdowns.female.disabled_value', '2')
        ->assertHasNoErrors();

    expect((float) $line->fresh()->value)->toBe(27.0)
        ->and((float) $line->fresh()->breakdowns()->where('gender', 'female')->value('disabled_value'))->toBe(2.0);
});

test('a MEAL line is read-only for the manager; the validator fills it while submitted and Validate waits for it', function () {
    $draft = mrsReport(mrsProject());
    $draftMeal = mrsLine($draft, enteredBy: IndicatorCollector::Meal);

    mrsPage($draft)
        ->assertSeeText(__('monthly_reports.filled_by_meal'))
        ->assertDontSeeHtml('wire:model.blur="figures.'.$draftMeal->id.'.value"');

    $submitted = mrsReport(mrsProject(), status: MonthlyReportStatus::Submitted);
    $manager = mrsLine($submitted);
    $meal = mrsLine($submitted, enteredBy: IndicatorCollector::Meal);

    mrsPage($submitted, $this->officer)
        ->assertDontSeeHtml('wire:model.blur="figures.'.$manager->id.'.value"')
        ->assertSeeHtml('wire:model.blur="figures.'.$meal->id.'.value"')
        ->assertSeeHtml('data-validate-blocked')
        ->assertSeeText(__('monthly_reports.meal_lines_pending'))
        ->set('figures.'.$meal->id.'.value', '8')
        ->assertDontSeeHtml('data-validate-blocked')
        ->call('openValidateModal')
        ->assertSet('showValidateModal', true)
        ->call('validateReport')
        ->assertSet('showValidateModal', false);

    expect((float) $meal->fresh()->value)->toBe(8.0)
        ->and($submitted->fresh()->status)->toBe(MonthlyReportStatus::Validated);
});

test('Submit lists the incomplete lines, then locks the report: no inputs and a save shows the locked message', function () {
    $report = mrsReport(mrsProject());
    $line = mrsLine($report, ['name_en' => 'Sessions held']);

    $page = mrsPage($report)
        ->call('openSubmitModal')
        ->assertSet('showSubmitModal', true)
        ->assertSeeHtml('data-incomplete-line="'.$line->id.'"')
        ->set('figures.'.$line->id.'.value', '0')
        ->assertDontSeeHtml('data-incomplete-line="'.$line->id.'"')
        ->call('submitReport')
        ->assertSet('showSubmitModal', false)
        ->assertDontSeeHtml('wire:model.blur="figures.'.$line->id.'.value"')
        ->set('figures.'.$line->id.'.value', '9')
        ->assertDispatched('toast-show', mrsToast(__('monthly_reports.locked')));

    expect($report->fresh()->status)->toBe(MonthlyReportStatus::Submitted)
        ->and((float) $line->fresh()->value)->toBe(0.0);
});

test('Submit is disabled with the reason before the last working day of the month', function () {
    $this->travelTo('2026-10-15 10:00:00');
    $report = mrsReport(mrsProject());
    mrsLine($report);

    mrsPage($report)
        ->assertSeeHtml('data-submit-blocked')
        ->assertSeeText(__('monthly_reports.submit_too_early'));
});

test('Submit is disabled with the reason while the earlier month is unsubmitted', function () {
    $this->travelTo('2026-12-01 10:00:00');
    $project = mrsProject();
    mrsLine(mrsReport($project, '2026-10-01'));
    $november = mrsReport($project, '2026-11-01');
    mrsLine($november);

    mrsPage($november)
        ->assertSeeHtml('data-submit-blocked')
        ->assertSeeText(__('monthly_reports.submit_previous_first', ['month' => 'October 2026']));
});

test('MEAL sees Validate and Return without manager inputs; Return needs a reason of 10 characters', function () {
    $report = mrsReport(mrsProject(), status: MonthlyReportStatus::Submitted);
    $line = mrsLine($report);

    mrsPage($report, $this->officer)
        ->assertSeeHtml('data-test="validate-button"')
        ->assertSeeHtml('data-test="return-button"')
        ->assertDontSeeHtml('wire:model.blur="figures.'.$line->id.'.value"')
        ->call('openReturnModal')
        ->assertSet('showReturnModal', true)
        ->set('returnReason', 'Too short')
        ->call('returnReport')
        ->assertHasErrors(['returnReason' => __('monthly_reports.reason_min')])
        ->set('returnReason', 'Attendance sheets are missing for week 2.')
        ->call('returnReport')
        ->assertHasNoErrors()
        ->assertSet('showReturnModal', false)
        ->assertSeeText('Attendance sheets are missing for week 2.');

    expect($report->fresh()->status)->toBe(MonthlyReportStatus::Draft)
        ->and($report->fresh()->return_reason)->toBe('Attendance sheets are missing for week 2.');
});

test('the manager requests a reopen of a validated month and the MEAL Manager grants it', function () {
    $report = mrsReport(mrsProject(), status: MonthlyReportStatus::Validated);
    mrsLine($report);

    mrsPage($report)
        ->assertSeeHtml('data-test="reopen-button"')
        ->call('openReopenModal')
        ->assertSet('showReopenModal', true)
        ->set('reopenReason', 'Two sessions were counted twice in week 3.')
        ->call('requestReopen')
        ->assertHasNoErrors()
        ->assertSet('showReopenModal', false)
        ->assertSeeHtml('data-reopen-request')
        ->assertDontSeeHtml('data-test="reopen-button"');

    $request = MonthlyReportReopenRequest::query()->sole();

    mrsPage($report, mealManager())
        ->call('openDecisionModal', $request->id)
        ->assertSet('showDecisionModal', true)
        ->call('decideReopen', true)
        ->assertHasNoErrors()
        ->assertSet('showDecisionModal', false);

    expect($request->fresh()->status)->toBe(ReopenRequestStatus::Granted)
        ->and($report->fresh()->status)->toBe(MonthlyReportStatus::Draft);
});

test('a decision on another report\'s request is refused', function () {
    $report = mrsReport(mrsProject(), status: MonthlyReportStatus::Validated);
    $foreign = Activity::withoutRecording(fn () => MonthlyReportReopenRequest::factory()->create(['requested_by' => $this->owner->id]));

    mrsPage($report, mealManager())
        ->call('openDecisionModal', $foreign->id)
        ->assertNotFound();
});

test('MEAL marks a submitted report as seen and the page shows when', function () {
    $report = mrsReport(mrsProject(), status: MonthlyReportStatus::Submitted);

    mrsPage($report, $this->officer)
        ->call('markSeen')
        ->assertDontSeeHtml('data-test="mark-seen-button"')
        ->assertSeeHtml('data-seen');

    expect($report->fresh()->seen_by_meal_at)->not->toBeNull();
});

test('Refresh Lines adds the line of a target confirmed after the month opened', function () {
    $project = mrsProject();
    $report = mrsReport($project);
    Activity::withoutRecording(function () use ($project): void {
        $proposal = Proposal::factory()->create(['status' => ProposalStatus::Active, 'actual_date_begin' => '2026-10-01', 'actual_date_end' => '2026-12-31']);
        $proposal->projects()->attach($project->id, ['target_beneficiaries' => 100]);
        $template = ReportTemplate::factory()->confirmed()->create(['project_id' => $project->id]);
        $library = LibraryIndicator::factory()->create(['frequency' => CollectionFrequency::Monthly, 'name_en' => 'Late indicator']);
        $target = PlanIndicator::factory()->forProposal($proposal)->create([
            'indicator_id' => Indicator::factory()->create(['library_indicator_id' => $library->id])->id,
            'project_id' => $project->id,
        ]);
        ReportTemplateLine::factory()->confirmed()->create([
            'report_template_id' => $template->id, 'plan_indicator_id' => $target->id, 'schedule' => 'library_default',
            'effective_from' => '2026-10-01', 'effective_to' => '2026-12-01',
        ])->forceFill(['status' => 'confirmed'])->save();
    });

    mrsPage($report)
        ->assertDontSeeText('Late indicator')
        ->call('refreshLines')
        ->assertDispatched('toast-show', mrsToast(trans_choice('monthly_reports.lines_added', 1, ['count' => 1])))
        ->assertSeeText('Late indicator');

    expect($report->lines()->count())->toBe(1);
});

test('the header warns when the owner works at another center, without blocking', function () {
    $project = mrsProject();
    $ownCenter = insertHrCenter($this->city);
    $otherCenter = insertHrCenter($this->city, 'Other');
    insertHrContract($this->ownerEmployee, insertHrPosition(insertHrDepartment(), centerId: $ownCenter));
    Activity::withoutRecording(fn () => $project->forceFill(['center_id' => $otherCenter])->save());
    $report = mrsReport($project);
    $line = mrsLine($report);

    mrsPage($report)
        ->assertSeeText(__('monthly_reports.owner_center_mismatch'))
        ->assertSeeHtml('wire:model.blur="figures.'.$line->id.'.value"');
});

test('the preparer deletes a never-submitted draft and lands on the list', function () {
    $report = mrsReport(mrsProject());

    mrsPage($report)
        ->call('openDeleteModal')
        ->assertSet('showDeleteModal', true)
        ->call('deleteReport')
        ->assertRedirect(route('monthly-reports.index'));

    expect(MonthlyReport::query()->whereKey($report->id)->exists())->toBeFalse();
});

test('a stale page turns a blur into a toast and reloads the report', function () {
    $report = mrsReport(mrsProject());
    $line = mrsLine($report);
    $page = mrsPage($report);

    Activity::withoutRecording(fn () => $report->forceFill(['status' => MonthlyReportStatus::Submitted, 'submitted_at' => now(), 'submitted_by' => $this->owner->id])->save());

    $page->set('figures.'.$line->id.'.value', '4')
        ->assertDispatched('toast-show', mrsToast(__('monthly_reports.stale')))
        ->assertDontSeeHtml('wire:model.blur="figures.'.$line->id.'.value"');

    expect($line->fresh()->value)->toBeNull();
});

test('the page reads a fixed number of queries for 5 and for 40 lines', function () {
    $project = mrsProject(DonorAttribution::Split);
    $small = mrsReport($project, '2026-10-01');
    $large = mrsReport($project, '2026-11-01');

    foreach (range(1, 5) as $index) {
        mrsLine($small, ['is_disaggregated' => $index === 1], [null, null]);
    }

    foreach (range(1, 40) as $index) {
        mrsLine($large, ['is_disaggregated' => $index === 1], [null, null]);
    }

    $count = function (MonthlyReport $report): int {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        mrsPage($report);

        return $queries;
    };

    mrsPage($small);

    expect($count($large))->toBe($count($small));
});

test('the page renders in Arabic right to left', function () {
    $report = mrsReport(mrsProject());
    mrsLine($report);
    $this->owner->forceFill(['locale' => 'ar'])->save();

    $this->actingAs($this->owner)->get(route('monthly-reports.show', $report))
        ->assertOk()
        ->assertSee('dir="rtl"', false)
        ->assertSee(trans('monthly_reports.grid_indicator', [], 'ar'));
});

/*
| Slice 2: activities this month, answers and typed evidence (contracts §13,
| §13.1 items 3 and 4, §16; D-42, D-43a, D-49b, D-51).
*/

/**
 * An activity of a donor-approved proposal linked to the project.
 */
function mrsActivity(Project $project, ActivityKind $kind = ActivityKind::Continuous, string $statement = 'Run parenting sessions'): Result
{
    return Activity::withoutRecording(function () use ($project, $kind, $statement): Result {
        $proposal = $project->proposals()->first();

        if ($proposal === null) {
            $proposal = Proposal::factory()->create(['status' => ProposalStatus::Approved]);
            $project->proposals()->attach($proposal->id, ['target_beneficiaries' => 100]);
        }

        $output = Result::factory()->type(ResultType::Output)->create(['proposal_id' => $proposal->id]);

        return Result::factory()->activity($output)->create(['activity_kind' => $kind, 'statement' => $statement]);
    });
}

/**
 * A fake private disk. The suite travels to November while the temporary
 * upload is written with today's real time, so Livewire's "older than 24
 * hours" cleanup would delete it before saveFile reads it.
 */
function mrsFakeStorage(): void
{
    Storage::fake('local');
    config(['livewire.temporary_file_upload.cleanup' => false]);
}

/**
 * @param  list<int>|null  $weeks
 */
function mrsSchedule(Result $activity, string $month, ?array $weeks = null): void
{
    ActivitySchedule::query()->create(['result_id' => $activity->id, 'project_id' => null, 'month' => $month, 'weeks' => $weeks]);
}

test('an activity\'s status select offers only the statuses of its kind', function () {
    $project = mrsProject();
    $report = mrsReport($project);
    $continuous = mrsActivity($project);
    $event = mrsActivity($project, ActivityKind::Event, 'Open day');
    mrsSchedule($continuous, '2026-10-01');
    mrsSchedule($event, '2026-10-01');

    mrsPage($report)
        ->assertSeeText('Run parenting sessions')
        ->assertSeeHtml('data-activity-option="'.$continuous->id.'-ongoing"')
        ->assertDontSeeHtml('data-activity-option="'.$continuous->id.'-conducted"')
        ->assertSeeHtml('data-activity-option="'.$event->id.'-conducted"')
        ->assertDontSeeHtml('data-activity-option="'.$event->id.'-ongoing"');
});

test('each status asks for its own inputs and saves the activity row', function () {
    $project = mrsProject();
    $report = mrsReport($project);
    $activity = mrsActivity($project);
    mrsSchedule($activity, '2026-10-01');
    $key = 'activityEntries.'.$activity->id;

    $page = mrsPage($report)
        ->set($key.'.status', 'started')
        ->assertHasErrors([$key.'.started_on' => __('monthly_reports.activity_date_required')])
        ->assertSeeHtml('wire:model.blur="'.$key.'.started_on"')
        ->assertDontSeeHtml('wire:model.blur="'.$key.'.reason"')
        ->set($key.'.started_on', '2026-10-12')
        ->assertHasNoErrors();

    $entry = MonthlyReportActivityEntry::query()->where('monthly_report_id', $report->id)->sole();
    expect($entry->status)->toBe(ActivityStatus::Started)
        ->and(CarbonImmutable::parse($entry->started_on)->toDateString())->toBe('2026-10-12');

    $page->set($key.'.status', 'not_started')
        ->assertSeeHtml('wire:model.blur="'.$key.'.reason"')
        ->set($key.'.reason', 'Too short')
        ->assertHasErrors([$key.'.reason' => __('monthly_reports.activity_reason_min')]);

    expect($entry->fresh()->status)->toBe(ActivityStatus::Started);
});

test('planned weeks and the carry-over badges are shown', function () {
    $this->travelTo('2026-12-02 10:00:00');
    $project = mrsProject();
    $november = mrsReport($project, '2026-11-01', MonthlyReportStatus::Validated);
    $report = mrsReport($project, '2026-12-01');
    $scheduled = mrsActivity($project, statement: 'Scheduled activity');
    mrsSchedule($scheduled, '2026-12-01', [2, 3]);
    $continuing = mrsActivity($project, statement: 'Continuing activity');
    $late = mrsActivity($project, statement: 'Late activity');
    ProjectActivityStatus::query()->create(['project_id' => $project->id, 'result_id' => $continuing->id, 'status' => ActivityStatus::Ongoing, 'monthly_report_id' => $november->id]);
    ProjectActivityStatus::query()->create(['project_id' => $project->id, 'result_id' => $late->id, 'status' => ActivityStatus::NotStarted, 'monthly_report_id' => $november->id]);

    mrsPage($report)
        ->assertSeeText(trans_choice('monthly_reports.planned_weeks', 2, ['weeks' => '2'.__('monthly_reports.weeks_and').'3']))
        ->assertSeeText(__('monthly_reports.continuing_from', ['month' => 'November 2026']))
        ->assertSeeText(__('monthly_reports.late_from', ['month' => 'November 2026']));
});

test('answers save with the question text, and quarter-end questions are labelled', function () {
    $this->travelTo('2026-12-02 10:00:00');
    $project = mrsProject();
    $template = Activity::withoutRecording(fn (): ReportTemplate => ReportTemplate::factory()->confirmed()->create(['project_id' => $project->id]));
    $monthly = ReportTemplateQuestion::factory()->create(['report_template_id' => $template->id, 'text_ar' => 'ما أبرز الإنجازات؟', 'text_en' => 'What went well?']);
    ReportTemplateQuestion::factory()->create(['report_template_id' => $template->id, 'text_en' => 'Quarter lessons?', 'frequency' => NarrativeQuestionFrequency::QuarterEnd]);
    $report = mrsReport($project, '2026-12-01');

    mrsPage($report)
        ->assertSeeText('What went well?')
        ->assertSeeText('Quarter lessons?')
        ->assertSeeText(__('monthly_reports.quarter_end_question'))
        ->set('answers.'.$monthly->id, 'Three new groups started.')
        ->assertHasNoErrors();

    $answer = MonthlyReportAnswer::query()->sole();
    expect($answer->answer)->toBe('Three new groups started.')
        ->and($answer->question_text)->toBe('ما أبرز الإنجازات؟');
});

test('evidence uploads to a line, downloads through its report only, lists missing required types and deletes', function () {
    mrsFakeStorage();
    $project = mrsProject();
    $report = mrsReport($project);
    $line = mrsLine($report);
    $type = EvidenceType::factory()->create(['name_en' => 'Attendance sheet']);
    $template = Activity::withoutRecording(fn (): ReportTemplate => ReportTemplate::factory()->confirmed()->create(['project_id' => $project->id]));
    $templateLine = Activity::withoutRecording(fn (): ReportTemplateLine => ReportTemplateLine::factory()->confirmed()->create(['report_template_id' => $template->id, 'plan_indicator_id' => $line->shares->first()->plan_indicator_id]));
    $templateLine->evidenceTypes()->attach($type->id);

    $page = mrsPage($report)
        ->assertSeeHtml('data-missing-evidence="'.$line->id.'"')
        ->assertSeeText('Attendance sheet')
        ->call('openFileModal', $line->id, null)
        ->set('newFileTypeId', (string) $type->id)
        ->set('newFile', validUpload('evidence.pdf'))
        ->call('saveFile')
        ->assertHasNoErrors()
        ->assertDontSeeHtml('data-missing-evidence="'.$line->id.'"');

    $file = MonthlyReportFile::query()->sole();
    expect($file->monthly_report_line_id)->toBe($line->id)
        ->and($file->evidence_type_id)->toBe($type->id);
    $page->assertSeeHtml(route('monthly-reports.files.download', [$report, $file]));

    $this->app['auth']->forgetGuards();
    $this->get(route('monthly-reports.files.download', [$report, $file]))->assertRedirect(route('login'));
    $this->actingAs($this->owner)->get(route('monthly-reports.files.download', [$report, $file]))->assertOk();
    $other = mrsReport(mrsProject(), '2026-11-01');
    $this->actingAs($this->owner)->get(route('monthly-reports.files.download', [$other, $file]))->assertNotFound();

    $page->call('confirmDeleteFile', $file->id)
        ->call('deleteFile')
        ->assertHasNoErrors();

    expect(MonthlyReportFile::query()->count())->toBe(0);
});

test('evidence attaches to a saved activity entry', function () {
    mrsFakeStorage();
    $project = mrsProject();
    $report = mrsReport($project);
    $activity = mrsActivity($project);
    mrsSchedule($activity, '2026-10-01');
    $type = EvidenceType::factory()->create();

    $page = mrsPage($report)
        ->set('activityEntries.'.$activity->id.'.status', 'ongoing')
        ->assertHasNoErrors();
    $entry = MonthlyReportActivityEntry::query()->sole();

    $page->call('openFileModal', null, $entry->id)
        ->set('newFileTypeId', (string) $type->id)
        ->set('newFile', validUpload('photo.png'))
        ->call('saveFile')
        ->assertHasNoErrors();

    expect(MonthlyReportFile::query()->sole()->monthly_report_activity_entry_id)->toBe($entry->id);
});

test('a file of another report cannot be deleted from this page', function () {
    mrsFakeStorage();
    $report = mrsReport(mrsProject());
    $foreign = Activity::withoutRecording(fn (): MonthlyReportFile => MonthlyReportFile::factory()->create(['monthly_report_id' => mrsReport(mrsProject(), '2026-11-01')->id]));

    mrsPage($report)->call('confirmDeleteFile', $foreign->id)->assertNotFound();

    expect($foreign->fresh())->not->toBeNull();
});

test('the progress line counts what the preparer filled and lists what is still needed', function () {
    $project = mrsProject();
    $report = mrsReport($project);
    $filled = mrsLine($report);
    $empty = mrsLine($report);
    mrsLine($report, enteredBy: IndicatorCollector::Meal);
    $activity = mrsActivity($project);
    mrsSchedule($activity, '2026-10-01');

    mrsPage($report)
        ->set('figures.'.$filled->id.'.value', '4')
        ->assertSeeText(__('monthly_reports.progress_done', ['done' => 1, 'total' => 3]))
        ->assertSeeHtml('data-still-needed')
        ->assertSeeText(trans_choice('monthly_reports.meal_lines_group', 1, ['count' => 1]))
        ->set('figures.'.$empty->id.'.value', '0')
        ->set('activityEntries.'.$activity->id.'.status', 'ongoing')
        ->assertSeeText(__('monthly_reports.progress_done', ['done' => 3, 'total' => 3]))
        ->assertSeeText(__('monthly_reports.all_done'))
        ->assertDontSeeHtml('data-still-needed');
});
