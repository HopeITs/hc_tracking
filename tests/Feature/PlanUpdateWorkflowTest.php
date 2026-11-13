<?php

use App\Actions\AnnualPlans\ManagePlanUpdateFiles;
use App\Actions\AnnualPlans\ReviewPlanUpdate;
use App\Actions\AnnualPlans\SavePlanUpdate;
use App\Actions\AnnualPlans\SendPlanUpdate;
use App\Enums\MealCoverageScope;
use App\Enums\PlanUpdateStatus;
use App\Enums\ResultType;
use App\Models\ActivityPlan;
use App\Models\ActivityPlanBatch;
use App\Models\ActivityPlanItem;
use App\Models\ActivityPlanItemCity;
use App\Models\MealCoverageAssignment;
use App\Models\PlanUpdate;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Result;
use App\Support\PlanExecution;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->pmEmployee = insertHrEmployee(firstNameEn: 'Meghety', lastNameEn: 'Nakkashian');
    $this->programManager = userWithRole('Managers', $this->pmEmployee);
    $this->aleppoOwner = insertHrEmployee(firstNameEn: 'Youssef', lastNameEn: 'Kharbout');
    $this->projectManager = userWithRole('Managers', $this->aleppoOwner);
    $this->officerEmployee = insertHrEmployee(firstNameEn: 'Araz', lastNameEn: 'Saradjian');
    $this->officer = userWithRole('MEAL', $this->officerEmployee);
    $this->mealManager = userWithRole('MEAL', insertHrEmployee(firstNameEn: 'Rami', lastNameEn: 'Osman'));
    $this->mealManager->givePermissionTo('annual-plans.review');

    $this->program = Program::factory()->create(['manager_id' => $this->pmEmployee]);
    $this->proposal = Proposal::factory()->create(['planned_date_begin' => '2027-01-01', 'planned_date_end' => '2027-12-31']);
    $this->aleppo = insertHrCity('Aleppo', 'حلب');
    $this->centre = Project::factory()->create(['program_id' => $this->program->id, 'city_id' => $this->aleppo, 'user_id' => $this->aleppoOwner, 'name' => 'Children University Aleppo']);
    DB::table('project_proposal')->insert(['project_id' => $this->centre->id, 'proposal_id' => $this->proposal->id]);
    MealCoverageAssignment::query()->create(['scope_type' => MealCoverageScope::City->value, 'city_id' => $this->aleppo, 'employee_id' => $this->officerEmployee]);

    $this->plan = ActivityPlan::factory()->approved()->create(['proposal_id' => $this->proposal->id, 'program_id' => $this->program->id]);
    $output = Result::factory()->type(ResultType::Output)->create(['proposal_id' => $this->proposal->id, 'program_id' => $this->program->id]);
    $this->item = ActivityPlanItem::factory()->create(['activity_plan_id' => $this->plan->id, 'result_id' => Result::factory()->activity($output)->create(['statement' => 'Basic sessions'])->id, 'is_ready' => true]);
    ActivityPlanItemCity::query()->create(['activity_plan_item_id' => $this->item->id, 'city_id' => $this->aleppo]);
    $this->batch = ActivityPlanBatch::factory()->create(['activity_plan_item_id' => $this->item->id, 'start_week' => '2027-01-04', 'end_week' => '2027-02-22', 'planned_count' => 16, 'hours_each' => 2]);
    $this->january = CarbonImmutable::parse('2027-01-01');
});

/**
 * A complete January draft for the centre.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function januaryDraft(array $overrides = []): array
{
    $batch = test()->batch->id;

    return [
        'weeks' => [
            "{$batch}|2027-01-04" => ['status' => 'started', 'reason' => '', 'new_week' => ''],
            "{$batch}|2027-01-11" => ['status' => 'ongoing', 'reason' => '', 'new_week' => ''],
            "{$batch}|2027-01-18" => ['status' => 'suspended', 'reason' => 'Roads closed', 'new_week' => ''],
            "{$batch}|2027-01-25" => ['status' => 'ongoing', 'reason' => '', 'new_week' => ''],
        ],
        'numbers' => [$batch => '7'],
        'activities_implemented' => 'Sessions ran in the morning.',
        'challenges' => 'Two days of power cuts.',
        'next_month' => 'Finish the first batch.',
        'males' => '30', 'females' => '35', 'with_disability' => '3',
        ...$overrides,
    ];
}

function sentJanuary(): PlanUpdate
{
    $update = app(SavePlanUpdate::class)->handle(test()->projectManager, test()->plan, test()->centre, test()->january, januaryDraft());
    app(ManagePlanUpdateFiles::class)->add(test()->projectManager, test()->plan, test()->centre, test()->january, UploadedFile::fake()->create('attendance.pdf', 20, 'application/pdf'));

    return app(SendPlanUpdate::class)->handle(test()->projectManager, $update->fresh(), CarbonImmutable::parse('2027-02-01'));
}

test('a month covers the batches planned in it', function () {
    $execution = PlanExecution::for($this->plan);

    expect($execution->batchesFor($this->centre, $this->january))->toHaveCount(1)
        ->and($execution->batchesFor($this->centre, CarbonImmutable::parse('2027-04-01')))->toBe([]);
});

test('only the centre project manager fills the update; a draft keeps what was typed', function () {
    expect(fn () => app(SavePlanUpdate::class)->handle($this->officer, $this->plan, $this->centre, $this->january, januaryDraft()))
        ->toThrow(AuthorizationException::class);

    $update = app(SavePlanUpdate::class)->handle($this->projectManager, $this->plan, $this->centre, $this->january, januaryDraft(['challenges' => '']));

    expect($update->status)->toBe(PlanUpdateStatus::Draft)
        ->and($update->weeks()->count())->toBe(4)
        ->and($update->numbers()->first()->done_count)->toBe(7)
        ->and($update->challenges)->toBeNull();
});

test('an update cannot be sent before its month ends', function () {
    $update = app(SavePlanUpdate::class)->handle($this->projectManager, $this->plan, $this->centre, $this->january, januaryDraft());

    expect(fn () => app(SendPlanUpdate::class)->handle($this->projectManager, $update, CarbonImmutable::parse('2027-01-31')))
        ->toThrow(ValidationException::class);
});

test('a complete update needs every planned week, a reason, a number, a file, the narrative and a valid beneficiaries row', function () {
    $draft = januaryDraft();
    unset($draft['weeks'][$this->batch->id.'|2027-01-25']);
    $draft['weeks'][$this->batch->id.'|2027-01-18']['reason'] = '';
    $draft['with_disability'] = '99';
    $update = app(SavePlanUpdate::class)->handle($this->projectManager, $this->plan, $this->centre, $this->january, $draft);

    $missing = SendPlanUpdate::missing(PlanExecution::for($this->plan), $this->centre, $update->load('weeks', 'numbers', 'files'));

    expect($missing)->toContain('week:'.$this->batch->id.'|2027-01-25', 'reason:'.$this->batch->id.'|2027-01-18', 'files', 'disability_total');
});

test('the next month can be sent only once the officer approved the previous one', function () {
    sentJanuary();
    $february = CarbonImmutable::parse('2027-02-01');
    $execution = PlanExecution::for($this->plan);

    expect($execution->sendBlockers($this->centre, $february, CarbonImmutable::parse('2027-03-01')))->toHaveCount(1);
});

test('after the officer, the MEAL manager and the program manager both mark it seen, in either order; the note is optional', function () {
    $update = sentJanuary();

    expect(fn () => app(ReviewPlanUpdate::class)->officerApprove($this->officer, $update, ' '))->toThrow(ValidationException::class);

    $update = app(ReviewPlanUpdate::class)->officerApprove($this->officer, $update, 'Two complaints, both answered.');
    expect($update->status)->toBe(PlanUpdateStatus::OfficerApproved)
        ->and(PlanExecution::for($this->plan)->sendBlockers($this->centre, CarbonImmutable::parse('2027-02-01'), CarbonImmutable::parse('2027-03-01')))->toBe([]);

    $update = app(ReviewPlanUpdate::class)->markSeen($this->programManager, $update, 'Add photos next time.');
    expect($update->status)->toBe(PlanUpdateStatus::OfficerApproved)
        ->and($this->programManager->can('markSeen', $update))->toBeFalse()
        ->and($this->programManager->can('decideAsMealManager', $update))->toBeFalse();

    $update = app(ReviewPlanUpdate::class)->mealReviewed($this->mealManager, $update);
    expect($update->status)->toBe(PlanUpdateStatus::Seen)
        ->and($update->program_manager_note)->toBe('Add photos next time.');

});

test('the MEAL manager first, then the program manager without a note, completes it', function () {
    $update = app(ReviewPlanUpdate::class)->officerApprove($this->officer, sentJanuary(), 'Fine.');
    $update = app(ReviewPlanUpdate::class)->mealReviewed($this->mealManager, $update);
    expect($update->status)->toBe(PlanUpdateStatus::Reviewed);

    $update = app(ReviewPlanUpdate::class)->markSeen($this->programManager, $update, '');
    expect($update->status)->toBe(PlanUpdateStatus::Seen)->and($update->program_manager_note)->toBeNull();
});

test('the program manager page offers mark as seen with a note, never a return, and the note shows below the report', function () {
    $update = app(ReviewPlanUpdate::class)->officerApprove($this->officer, sentJanuary(), 'Fine.');

    Livewire::actingAs($this->programManager)->test('pages::annual-plans.update', ['activityPlan' => $this->plan, 'project' => $this->centre, 'month' => '2027-01'])
        ->assertSet('mode', 'program')
        ->assertDontSeeHtml('data-test="meal-return"')
        ->set('pmNote', 'Check attendance sheets.')
        ->call('markSeen')
        ->assertHasNoErrors();

    expect($update->fresh()->seen_at)->not->toBeNull()
        ->and($update->fresh()->program_manager_note)->toBe('Check attendance sheets.');
});

test('a MEAL return clears the program manager seen mark', function () {
    $update = app(ReviewPlanUpdate::class)->officerApprove($this->officer, sentJanuary(), 'Fine.');
    $update = app(ReviewPlanUpdate::class)->markSeen($this->programManager, $update, 'ok');
    $back = app(ReviewPlanUpdate::class)->mealReturn($this->mealManager, $update, 'Numbers look low', 'project_manager');

    expect($back->seen_at)->toBeNull()->and($back->program_manager_note)->toBeNull();
});

test('a return keeps everything and is sent again', function () {
    $update = sentJanuary();
    $update = app(ReviewPlanUpdate::class)->officerReturn($this->officer, $update, 'Attendance sheet is unreadable', 'Draft accountability');

    expect($update->status)->toBe(PlanUpdateStatus::Returned)
        ->and($update->weeks()->count())->toBe(4)
        ->and($update->files()->count())->toBe(1)
        ->and($update->accountability)->toBe('Draft accountability')
        ->and(PlanExecution::for($this->plan)->entriesFor($this->centre, $this->item))->toBe([]);

    $again = app(SendPlanUpdate::class)->handle($this->projectManager, $update->fresh(), CarbonImmutable::parse('2027-02-02'));

    expect($again->status)->toBe(PlanUpdateStatus::Sent)->and($again->resent)->toBeTrue();
});

test('the MEAL manager returns to the officer or to the project manager', function () {
    $update = app(ReviewPlanUpdate::class)->officerApprove($this->officer, sentJanuary(), 'Fine.');
    $toOfficer = app(ReviewPlanUpdate::class)->mealReturn($this->mealManager, $update, 'Add complaint numbers', 'officer');

    expect($toOfficer->status)->toBe(PlanUpdateStatus::Sent)->and($toOfficer->returned_to)->toBe('officer');

    $update = app(ReviewPlanUpdate::class)->officerApprove($this->officer, $toOfficer, 'Complaints #14 and #15.');
    $toPm = app(ReviewPlanUpdate::class)->mealReturn($this->mealManager, $update, 'Numbers look low', 'project_manager');

    expect($toPm->status)->toBe(PlanUpdateStatus::Returned);
});

test('questions are asked by the reviewer holding the update and answered by the project manager', function () {
    $update = sentJanuary();
    app(ReviewPlanUpdate::class)->ask($this->officer, $update, 'Why only 7 sessions?');
    app(ReviewPlanUpdate::class)->answer($this->projectManager, $update, 'The hall was closed one day.');

    expect($update->questions()->count())->toBe(2)
        ->and($update->fresh()->status)->toBe(PlanUpdateStatus::Sent);
});

test('the live plan reads sent weeks and finds late and suspended activities', function () {
    sentJanuary();
    $execution = PlanExecution::for($this->plan->fresh());

    expect($execution->held($this->centre, $this->item))->toBe(7)
        ->and($execution->status($this->centre, $this->item)['key'])->toBe('on_track')
        ->and($execution->lastReportedMonday($this->centre)->toDateString())->toBe('2027-01-25');
});

test('the project manager fills the update on its page and the officer sees the decision', function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-01 10:00'));

    Livewire::actingAs($this->projectManager)->test('pages::annual-plans.update', ['activityPlan' => $this->plan, 'project' => $this->centre, 'month' => '2027-01'])
        ->assertSet('mode', 'fill')
        ->call('fillFromPlan')
        ->assertSet('draft.weeks.'.$this->batch->id.'|2027-01-04.status', 'started')
        ->call('saveDraft');

    expect(PlanUpdate::query()->first()->weeks()->count())->toBe(4);

    $this->travelBack();
    $update = sentJanuary();

    Livewire::actingAs($this->officer)->test('pages::annual-plans.update', ['activityPlan' => $this->plan, 'project' => $this->centre, 'month' => '2027-01'])
        ->assertSet('mode', 'officer')
        ->assertSeeHtml('data-decision="officer"')
        ->assertSeeHtml('target="_blank" rel="noopener" data-test="open-plan-update-file"')
        ->set('accountability', 'All fine.')
        ->call('officerApprove');

    expect($update->fresh()->status)->toBe(PlanUpdateStatus::OfficerApproved);
});

test('the CEO does not open updates; others read them', function () {
    sentJanuary();
    $ceo = userWithRole('General Manager');

    $this->actingAs($ceo)->get(route('plan-updates.show', [$this->plan, $this->centre, '2027-01']))->assertForbidden();
    $this->actingAs(userWithRole('Team Member'))->get(route('plan-updates.show', [$this->plan, $this->centre, '2027-01']))->assertOk();
});
