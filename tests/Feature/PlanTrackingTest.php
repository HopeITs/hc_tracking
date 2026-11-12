<?php

use App\Actions\AnnualPlans\FireActivityPlanFollowUps;
use App\Actions\AnnualPlans\ManagePlanUpdateFiles;
use App\Actions\AnnualPlans\ReviewPlanUpdate;
use App\Actions\AnnualPlans\SavePlanUpdate;
use App\Actions\AnnualPlans\SendPlanUpdate;
use App\Enums\FollowUpTrigger;
use App\Enums\MealCoverageScope;
use App\Enums\ResultType;
use App\Models\ActivityPlan;
use App\Models\ActivityPlanBatch;
use App\Models\ActivityPlanFollowUp;
use App\Models\ActivityPlanItem;
use App\Models\ActivityPlanItemCity;
use App\Models\MealCoverageAssignment;
use App\Models\PlanUpdate;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Result;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Cache::flush();
    $this->pmEmployee = insertHrEmployee(firstNameEn: 'Meghety', lastNameEn: 'Nakkashian');
    $this->programManager = userWithRole('Managers', $this->pmEmployee);
    $this->ownerEmployee = insertHrEmployee(firstNameEn: 'Youssef', lastNameEn: 'Kharbout');
    $this->projectManager = userWithRole('Managers', $this->ownerEmployee);
    $this->officerEmployee = insertHrEmployee(firstNameEn: 'Araz', lastNameEn: 'Saradjian');
    $this->officer = userWithRole('MEAL', $this->officerEmployee);
    $this->mealManager = userWithRole('MEAL', insertHrEmployee(firstNameEn: 'Rami', lastNameEn: 'Osman'));
    $this->mealManager->givePermissionTo('annual-plans.review');

    $this->program = Program::factory()->create(['manager_id' => $this->pmEmployee]);
    $this->proposal = Proposal::factory()->create(['planned_date_begin' => '2027-01-01', 'planned_date_end' => '2027-12-31']);
    $aleppo = insertHrCity('Aleppo', 'حلب');
    $this->centre = Project::factory()->create(['program_id' => $this->program->id, 'city_id' => $aleppo, 'user_id' => $this->ownerEmployee]);
    DB::table('project_proposal')->insert(['project_id' => $this->centre->id, 'proposal_id' => $this->proposal->id]);
    MealCoverageAssignment::query()->create(['scope_type' => MealCoverageScope::City->value, 'city_id' => $aleppo, 'employee_id' => $this->officerEmployee]);

    $this->plan = ActivityPlan::factory()->approved()->create(['proposal_id' => $this->proposal->id, 'program_id' => $this->program->id, 'approved_by' => $this->mealManager->id]);
    $output = Result::factory()->type(ResultType::Output)->create(['proposal_id' => $this->proposal->id, 'program_id' => $this->program->id]);
    $this->item = ActivityPlanItem::factory()->create(['activity_plan_id' => $this->plan->id, 'result_id' => Result::factory()->activity($output)->create(['statement' => 'Basic sessions'])->id, 'is_ready' => true]);
    ActivityPlanItemCity::query()->create(['activity_plan_item_id' => $this->item->id, 'city_id' => $aleppo]);
    $this->batch = ActivityPlanBatch::factory()->create(['activity_plan_item_id' => $this->item->id, 'start_week' => '2027-01-04', 'end_week' => '2027-02-22', 'planned_count' => 16, 'hours_each' => 2]);
});

function sendJanuaryWith(array $weeks): PlanUpdate
{
    $batch = test()->batch->id;
    $update = app(SavePlanUpdate::class)->handle(test()->projectManager, test()->plan, test()->centre, CarbonImmutable::parse('2027-01-01'), [
        'weeks' => collect($weeks)->mapWithKeys(fn (array $row, string $week): array => ["{$batch}|{$week}" => $row])->all(),
        'numbers' => [$batch => '8'],
        'activities_implemented' => 'Sessions ran in the morning.', 'challenges' => 'Two days of power cuts.', 'next_month' => 'Finish the first batch.',
        'males' => '30', 'females' => '35', 'with_disability' => '3',
    ]);
    app(ManagePlanUpdateFiles::class)->add(test()->projectManager, test()->plan, test()->centre, CarbonImmutable::parse('2027-01-01'), UploadedFile::fake()->create('attendance.pdf', 20, 'application/pdf'));

    return app(SendPlanUpdate::class)->handle(test()->projectManager, $update->fresh(), CarbonImmutable::parse('2027-02-01'));
}

function plainWeeks(): array
{
    return [
        '2027-01-04' => ['status' => 'started', 'reason' => '', 'new_week' => ''],
        '2027-01-11' => ['status' => 'ongoing', 'reason' => '', 'new_week' => ''],
        '2027-01-18' => ['status' => 'ongoing', 'reason' => '', 'new_week' => ''],
        '2027-01-25' => ['status' => 'ongoing', 'reason' => '', 'new_week' => ''],
    ];
}

test('sending an update notifies the officer, the MEAL manager and the program manager in the app', function () {
    sendJanuaryWith(plainWeeks());

    foreach ([$this->officer, $this->mealManager, $this->programManager] as $user) {
        expect($user->notifications()->where('data->key', 'notifications.events.annual_plan.update_sent')->exists())->toBeTrue();
    }

    expect($this->projectManager->notifications()->where('data->key', 'notifications.events.annual_plan.update_sent')->exists())->toBeFalse();
});

test('a reschedule of two weeks or more asks the program manager to revise the plan', function () {
    sendJanuaryWith([...plainWeeks(), '2027-01-18' => ['status' => 'rescheduled', 'reason' => 'Hall closed', 'new_week' => '2027-02-08']]);

    expect($this->programManager->notifications()->where('data->key', 'notifications.events.annual_plan.update_rescheduled')->exists())->toBeTrue();
});

test('a follow-up task fires once for the centre when the activity starts there', function () {
    ActivityPlanFollowUp::factory()->create(['activity_plan_id' => $this->plan->id, 'activity_plan_item_id' => $this->item->id, 'trigger' => FollowUpTrigger::Start->value, 'description' => 'Run the pre test']);

    sendJanuaryWith(plainWeeks());
    app(FireActivityPlanFollowUps::class)->handle(PlanUpdate::query()->firstOrFail());

    $task = Task::query()->withoutGlobalScopes()->firstOrFail();

    expect(DB::table('activity_plan_follow_up_tasks')->count())->toBe(1)
        ->and($task->title)->toContain('Run the pre test')
        ->and($task->evidence_required)->toBeTrue()
        ->and($task->due_date->toDateString())->toBe(now()->addWeeks(2)->toDateString())
        ->and($task->taskAssignees()->value('employee_id'))->toBe($this->officerEmployee);
});

test('a finish follow-up waits until the activity is done', function () {
    ActivityPlanFollowUp::factory()->create(['activity_plan_id' => $this->plan->id, 'activity_plan_item_id' => $this->item->id, 'trigger' => FollowUpTrigger::Finish->value, 'description' => 'Run the post test']);

    sendJanuaryWith(plainWeeks());

    expect(DB::table('activity_plan_follow_up_tasks')->count())->toBe(0);
});

test('the reminder command reminds in the last week and flags late updates once', function () {
    $this->artisan('annual-plans:remind', ['--date' => '2027-01-27'])->assertSuccessful();
    expect($this->projectManager->notifications()->where('data->key', 'notifications.events.annual_plan.update_due')->count())->toBe(1);

    $this->artisan('annual-plans:remind', ['--date' => '2027-02-04'])->assertSuccessful();
    $this->artisan('annual-plans:remind', ['--date' => '2027-02-05'])->assertSuccessful();

    expect($this->projectManager->notifications()->where('data->key', 'notifications.events.annual_plan.update_late')->count())->toBe(1)
        ->and($this->mealManager->notifications()->where('data->key', 'notifications.events.annual_plan.update_late')->count())->toBe(1);
});

test('the approved plan shows the live plan and the monthly updates', function () {
    sendJanuaryWith(plainWeeks());

    Livewire::actingAs($this->programManager)->test('pages::annual-plans.plan', ['activityPlan' => $this->plan])
        ->assertSeeHtml('data-live-plan')
        ->assertSeeHtml('data-centre-status="on_track"')
        ->assertSeeHtml('data-plan-updates');
});

test('the register shows the MEAL manager every update and the next one to review', function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-02'));
    $update = sendJanuaryWith(plainWeeks());
    app(ReviewPlanUpdate::class)->officerApprove($this->officer, $update, 'All fine.');

    Livewire::actingAs($this->mealManager)->test('pages::annual-plans.plans')
        ->assertSeeHtml('data-updates-grid')
        ->assertSee(__('annual_plans.next.meal_title', ['month' => 'January']));
});

test('the CEO sees the summary, not the grid', function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-02'));
    sendJanuaryWith(plainWeeks());

    Livewire::actingAs(userWithRole('General Manager'))->test('pages::annual-plans.plans')
        ->assertSeeHtml('data-ceo-summary')
        ->assertDontSeeHtml('data-updates-grid');
});

test('updates export as an Excel-readable sheet and a printable page', function () {
    sendJanuaryWith(plainWeeks());

    $this->actingAs($this->mealManager)->get(route('plan-updates.export', [$this->plan, $this->centre, '2027-01', 'format' => 'xlsx']))->assertOk()->assertDownload();
    $this->actingAs($this->mealManager)->get(route('plan-updates.export', [$this->plan, $this->centre, '2027-01', 'format' => 'pdf']))->assertOk()->assertSee('Sessions ran in the morning.');
});
