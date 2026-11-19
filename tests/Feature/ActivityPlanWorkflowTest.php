<?php

use App\Actions\AnnualPlans\AddPlanActivity;
use App\Actions\AnnualPlans\ChooseActivityPlanActivities;
use App\Actions\AnnualPlans\CopyActivityPlanItemSettings;
use App\Actions\AnnualPlans\CreateActivityPlan;
use App\Actions\AnnualPlans\ManageActivityPlanFollowUps;
use App\Actions\AnnualPlans\ReviewActivityPlan;
use App\Actions\AnnualPlans\SaveActivityPlanItem;
use App\Actions\AnnualPlans\SubmitActivityPlan;
use App\Enums\ActivityPlanStatus;
use App\Enums\ResultType;
use App\Models\ActivityPlan;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Result;
use App\Models\UserNotification;
use App\Support\ActivityPlanScope;
use App\Support\PlanNotifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->managerEmployee = insertHrEmployee(firstNameEn: 'Meghety', lastNameEn: 'Nakkashian');
    $this->pm = userWithRole('Managers', $this->managerEmployee);
    $this->otherManager = userWithRole('Managers', insertHrEmployee(firstNameEn: 'Other', lastNameEn: 'Manager'));
    $this->meal = userWithRole('MEAL', insertHrEmployee(firstNameEn: 'Rami', lastNameEn: 'Osman'));
    $this->meal->givePermissionTo('annual-plans.review');
    $this->program = Program::factory()->create(['manager_id' => $this->managerEmployee]);
    $this->proposal = Proposal::factory()->create(['planned_date_begin' => '2027-01-01', 'planned_date_end' => '2027-12-31']);
    $this->aleppo = insertHrCity('Aleppo', 'حلب');
    $this->homs = insertHrCity('Homs', 'حمص');

    foreach ([$this->aleppo, $this->homs] as $cityId) {
        $project = Project::factory()->create(['program_id' => $this->program->id, 'city_id' => $cityId]);
        DB::table('project_proposal')->insert(['project_id' => $project->id, 'proposal_id' => $this->proposal->id]);
    }

    $outcome = Result::factory()->type(ResultType::Outcome)->create(['proposal_id' => $this->proposal->id, 'program_id' => $this->program->id, 'statement' => 'Children find an academic path']);
    $output = Result::factory()->type(ResultType::Output)->childOf($outcome)->create(['proposal_id' => $this->proposal->id, 'program_id' => $this->program->id]);
    $this->basic = Result::factory()->activity($output)->create(['statement' => 'Basic sessions']);
    $this->english = Result::factory()->activity($output)->create(['statement' => 'English sessions']);
});

/**
 * A plan with both activities chosen.
 */
function chosenPlan(): ActivityPlan
{
    $plan = app(CreateActivityPlan::class)->handle(test()->pm, test()->proposal->id, test()->program->id);

    return app(ChooseActivityPlanActivities::class)->handle(test()->pm, $plan, [test()->basic->id, test()->english->id]);
}

test('a manager adds a checkpoint on the live plan and marks it complete', function () {
    $plan = ActivityPlan::factory()->approved()->create(['proposal_id' => $this->proposal->id, 'program_id' => $this->program->id]);

    Livewire::actingAs($this->pm)->test('pages::annual-plans.plan', ['activityPlan' => $plan])
        ->call('openMilestoneModal')
        ->set('milestoneForm.title', 'Baseline complete')
        ->set('milestoneForm.due_date', '2027-06-15')
        ->call('saveMilestone')
        ->assertHasNoErrors()
        ->assertSee('Baseline complete');

    $milestone = $plan->milestones()->sole();
    Livewire::actingAs($this->pm)->test('pages::annual-plans.plan', ['activityPlan' => $plan])
        ->call('completeMilestone', $milestone->id, true);

    expect($milestone->fresh()->completed_at)->not->toBeNull();

    Livewire::actingAs($this->pm)->test('pages::annual-plans.plan', ['activityPlan' => $plan])
        ->call('confirmDeleteMilestone', $milestone->id)
        ->assertSet('showDeleteMilestoneModal', true)
        ->call('deleteMilestone')
        ->assertDontSee('Baseline complete');

    $this->assertDatabaseMissing('activity_plan_milestones', ['id' => $milestone->id]);
});

test('milestone details are visible to MEAL but not other plan viewers', function () {
    $plan = ActivityPlan::factory()->approved()->create(['proposal_id' => $this->proposal->id, 'program_id' => $this->program->id]);
    $plan->milestones()->create([
        'title' => 'Private management checkpoint', 'due_date' => '2027-06-15', 'measure' => 'checkpoint', 'created_by' => $this->pm->id,
    ]);
    $teamMember = userWithRole('Team Member', insertHrEmployee());

    Livewire::actingAs($this->meal)->test('pages::annual-plans.plan', ['activityPlan' => $plan])
        ->assertSee('Private management checkpoint')
        ->assertDontSee(__('annual_plans.milestones.add'));
    Livewire::actingAs($teamMember)->test('pages::annual-plans.plan', ['activityPlan' => $plan])
        ->assertDontSee('Private management checkpoint');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function itemInput(array $overrides = []): array
{
    return [
        'single_week' => false,
        'unit' => 'session',
        'unit_other' => '',
        'cities' => [test()->aleppo, test()->homs],
        'expected_people' => '60',
        'note' => '',
        'batches' => [['start' => '2027-03-01', 'end' => '2027-04-26', 'count' => 16, 'hours' => 2]],
        ...$overrides,
    ];
}

test('only the program manager starts a plan, once per proposal and program', function () {
    $plan = app(CreateActivityPlan::class)->handle($this->pm, $this->proposal->id, $this->program->id);
    $again = app(CreateActivityPlan::class)->handle($this->pm, $this->proposal->id, $this->program->id);

    expect($again->id)->toBe($plan->id)
        ->and($plan->status)->toBe(ActivityPlanStatus::Draft);

    expect(fn () => app(CreateActivityPlan::class)->handle($this->otherManager, $this->proposal->id, $this->program->id))
        ->toThrow(AuthorizationException::class);
});

test('chosen activities start with a default batch in every governorate and need details', function () {
    $plan = chosenPlan()->load('items.batches', 'items.cities');

    expect($plan->items)->toHaveCount(2)
        ->and($plan->step)->toBe(2)
        ->and($plan->items->first()->is_ready)->toBeFalse()
        ->and($plan->items->first()->cityIds())->toBe([$this->aleppo, $this->homs])
        ->and($plan->items->first()->batches->first()->start_week->toDateString())->toBe('2027-01-04');
});

test('an activity is ready only with every batch counted and timed', function () {
    $item = chosenPlan()->items()->first();

    expect(fn () => app(SaveActivityPlanItem::class)->handle($this->pm, $item, itemInput(['batches' => [['start' => '2027-03-01', 'end' => '2027-04-26', 'count' => 16, 'hours' => '']]]), true))
        ->toThrow(ValidationException::class);

    $saved = app(SaveActivityPlanItem::class)->handle($this->pm, $item, itemInput(), true);

    expect($saved->is_ready)->toBeTrue()
        ->and($saved->batches()->first()->planned_count)->toBe(16);
});

test('weeks must be mondays of the plan and an end cannot come before its start', function () {
    $item = chosenPlan()->items()->first();

    expect(fn () => app(SaveActivityPlanItem::class)->handle($this->pm, $item, itemInput(['batches' => [['start' => '2027-03-02', 'end' => '2027-04-26', 'count' => 4, 'hours' => 2]]]), false))
        ->toThrow(ValidationException::class);
    expect(fn () => app(SaveActivityPlanItem::class)->handle($this->pm, $item, itemInput(['batches' => [['start' => '2027-04-26', 'end' => '2027-03-01', 'count' => 4, 'hours' => 2]]]), false))
        ->toThrow(ValidationException::class);
});

test('hours are kept only for sessions, and other units need a name', function () {
    $item = chosenPlan()->items()->first();

    expect(fn () => app(SaveActivityPlanItem::class)->handle($this->pm, $item, itemInput(['unit' => 'other']), true))->toThrow(ValidationException::class);

    $saved = app(SaveActivityPlanItem::class)->handle($this->pm, $item, itemInput(['unit' => 'kit']), true);

    expect($saved->batches()->first()->hours_each)->toBeNull()
        ->and($saved->counted(120))->toBe('120 kits');
});

test('copying settings copies runs, unit, batches, places and people but keeps each note', function () {
    $plan = chosenPlan();
    [$source, $target] = $plan->items()->get()->all();
    $target->forceFill(['note' => 'Own note'])->save();
    app(SaveActivityPlanItem::class)->handle($this->pm, $source, itemInput(['note' => 'Source note']), true);

    $count = app(CopyActivityPlanItemSettings::class)->handle($this->pm, $source->fresh(), [$target->id]);
    $target->refresh()->load('batches', 'cities');

    expect($count)->toBe(1)
        ->and($target->note)->toBe('Own note')
        ->and($target->is_ready)->toBeTrue()
        ->and($target->expected_people)->toBe(60)
        ->and($target->batches->first()->end_week->toDateString())->toBe('2027-04-26');
});

test('settings only copy between activities of the same kind', function () {
    $output = Result::find($this->basic->parent_id);
    $this->basic->forceFill(['activity_kind' => 'continuous'])->save();
    $event = Result::factory()->activity($output)->create(['statement' => 'Final exam', 'activity_kind' => 'event']);

    $plan = app(CreateActivityPlan::class)->handle($this->pm, $this->proposal->id, $this->program->id);
    $plan = app(ChooseActivityPlanActivities::class)->handle($this->pm, $plan, [$this->basic->id, $event->id]);
    $source = $plan->items()->where('result_id', $this->basic->id)->first();
    $target = $plan->items()->where('result_id', $event->id)->first();
    app(SaveActivityPlanItem::class)->handle($this->pm, $source, itemInput(), true);

    expect(fn () => app(CopyActivityPlanItemSettings::class)->handle($this->pm, $source->fresh(), [$target->id]))
        ->toThrow(ValidationException::class, __('annual_plans.copy_same_kind'));
});

test('an incomplete activity names the field that is missing, not a generic message', function () {
    $item = chosenPlan()->items()->first();

    $errorsFor = function (array $overrides) use ($item): array {
        try {
            app(SaveActivityPlanItem::class)->handle($this->pm, $item, itemInput($overrides), true);
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        return [];
    };

    expect($errorsFor(['cities' => []]))->toHaveKey('cities')
        ->and($errorsFor(['unit' => 'other', 'unit_other' => '']))->toHaveKey('unit_other')
        ->and($errorsFor(['batches' => [['start' => '2027-03-01', 'end' => '2027-04-26', 'count' => null, 'hours' => 2]]]))->toHaveKey('batches.0')
        ->and($errorsFor(['batches' => [['start' => '2027-03-01', 'end' => '2027-04-26', 'count' => 4, 'hours' => null]]]))->toHaveKey('batches.0');
});

test('a plan goes to MEAL only when every activity is ready, and is then locked', function () {
    $plan = chosenPlan();
    [$first, $second] = $plan->items()->get()->all();
    app(SaveActivityPlanItem::class)->handle($this->pm, $first, itemInput(), true);

    expect(fn () => app(SubmitActivityPlan::class)->handle($this->pm, $plan))->toThrow(ValidationException::class);

    app(SaveActivityPlanItem::class)->handle($this->pm, $second, itemInput(), true);
    $sent = app(SubmitActivityPlan::class)->handle($this->pm, $plan->fresh());

    expect($sent->status)->toBe(ActivityPlanStatus::InReview)
        ->and($this->pm->can('update', $sent))->toBeFalse()
        ->and($this->meal->can('review', $sent))->toBeTrue()
        ->and($this->pm->can('review', $sent))->toBeFalse();
});

test('MEAL sends a plan back with a reason, then approves it and its calendar is written', function () {
    $plan = chosenPlan();
    foreach ($plan->items()->get() as $item) {
        app(SaveActivityPlanItem::class)->handle($this->pm, $item, itemInput(), true);
    }
    $plan = app(SubmitActivityPlan::class)->handle($this->pm, $plan->fresh());

    expect(fn () => app(ReviewActivityPlan::class)->sendBack($this->meal, $plan, '  '))->toThrow(ValidationException::class);

    $back = app(ReviewActivityPlan::class)->sendBack($this->meal, $plan, 'Add Homs to English');
    expect($back->status)->toBe(ActivityPlanStatus::Draft)
        ->and($back->step)->toBe(2)
        ->and($back->sent_back_reason)->toBe('Add Homs to English');

    $plan = app(SubmitActivityPlan::class)->handle($this->pm, $back);
    expect($plan->sent_back_reason)->toBeNull();

    $item = $plan->items()->first();
    app(ManageActivityPlanFollowUps::class)->add($this->meal, $plan, $item->id, 'finish', 'Run the post test');
    $approved = app(ReviewActivityPlan::class)->approve($this->meal, $plan);

    expect($approved->status)->toBe(ActivityPlanStatus::Approved)
        ->and($approved->followUps()->count())->toBe(1)
        ->and(DB::table('activity_schedules')->where('result_id', $this->basic->id)->whereNull('project_id')->count())->toBe(2);
});

test('only the MEAL manager reviews', function () {
    $plan = ActivityPlan::factory()->inReview()->create(['proposal_id' => $this->proposal->id, 'program_id' => $this->program->id]);

    expect(fn () => app(ReviewActivityPlan::class)->approve($this->otherManager, $plan))->toThrow(AuthorizationException::class);

    $slotHolder = mealManager();
    expect($slotHolder->can('review', $plan))->toBeTrue();
});

test('the plans register lists plans and points the MEAL manager to the plan to review', function () {
    $plan = ActivityPlan::factory()->inReview()->create(['proposal_id' => $this->proposal->id, 'program_id' => $this->program->id]);

    Livewire::actingAs($this->meal)->test('pages::annual-plans.plans')
        ->assertSee($this->proposal->name)
        ->assertSeeHtml('data-next-step')
        ->assertSee(__('annual_plans.wf.next_review_title'));
});

test('the program manager creates a plan from the register and lands on its page', function () {
    Livewire::actingAs($this->pm)->test('pages::annual-plans.plans')
        ->call('openCreateModal')
        ->assertSet('showCreateModal', true)
        ->set('newProposal', (string) $this->proposal->id)
        ->assertSet('newProgram', (string) $this->program->id)
        ->call('createPlan')
        ->assertRedirect(route('activity-plans.show', ActivityPlan::query()->firstOrFail()));
});

test('the plan page walks the program manager through the steps and saves an activity', function () {
    $plan = chosenPlan();

    $page = Livewire::actingAs($this->pm)->test('pages::annual-plans.plan', ['activityPlan' => $plan])
        ->assertSet('mode', 'plan')
        ->assertSeeHtml('data-plan-step="dates"')
        ->assertNotSet('openItemId', null);

    $page->set('form.batches.0.count', 12)->set('form.batches.0.hours', 2)->call('finishItem');

    expect($plan->items()->where('is_ready', true)->count())->toBe(1);
});

test('a number of weeks sets the batch end, capped at the plan end, and the dates step shows the whole calendar', function () {
    $plan = chosenPlan();

    $page = Livewire::actingAs($this->pm)->test('pages::annual-plans.plan', ['activityPlan' => $plan])
        ->assertSeeHtml('data-dates-calendar')
        ->set('form.batches.0.start', '2027-03-01')
        ->call('setBatchWeeks', 0, '4')
        ->assertSet('form.batches.0.end', '2027-03-22')
        ->call('setBatchWeeks', 0, '0')
        ->assertSet('form.batches.0.end', '2027-03-22')
        ->call('setBatchWeeks', 0, '500')
        ->assertSet('form.batches.0.end', '2027-12-27');
});

test('the MEAL manager sees every field of the plan read-only', function () {
    $plan = chosenPlan();
    foreach ($plan->items()->get() as $item) {
        app(SaveActivityPlanItem::class)->handle($this->pm, $item, itemInput(), true);
    }
    $plan = app(SubmitActivityPlan::class)->handle($this->pm, $plan->fresh());

    Livewire::actingAs($this->meal)->test('pages::annual-plans.plan', ['activityPlan' => $plan])
        ->assertSet('mode', 'review')
        ->assertSeeHtml('data-review-items')
        ->assertDontSeeHtml('wire:click="toggleItem')
        ->set('sendBackReason', 'Fix the English weeks')
        ->call('sendBackPlan');

    expect($plan->fresh()->status->value)->toBe('draft');
});

test('other people read a plan without editing it', function () {
    $plan = chosenPlan();
    $viewer = userWithRole('Team Member');

    Livewire::actingAs($viewer)->test('pages::annual-plans.plan', ['activityPlan' => $plan])
        ->assertSet('mode', 'read')
        ->assertSeeHtml('data-plan-preparing');

    $this->actingAs($viewer)->get(route('activity-plans.show', $plan))->assertOk();
    $this->actingAs($viewer)->get(route('annual-plans.index'))->assertOk();
});

test('the program manager adds a missing activity from the plan: under a bare outcome an output is made for it', function () {
    $plan = app(CreateActivityPlan::class)->handle($this->pm, $this->proposal->id, $this->program->id);
    $bareOutcome = Result::factory()->type(ResultType::Outcome)->create(['proposal_id' => $this->proposal->id, 'program_id' => $this->program->id, 'statement' => 'Families earn more']);
    $this->actingAs($this->pm);

    $activity = app(AddPlanActivity::class)->handle($this->pm, $plan, 'Vocational training', $bareOutcome->id, false);
    $output = Result::query()->find($activity->parent_id);

    expect($activity->type)->toBe(ResultType::Activity)
        ->and($output->type)->toBe(ResultType::Output)
        ->and($output->parent_id)->toBe($bareOutcome->id)
        ->and(ActivityPlanScope::activityIds($plan))->toContain($activity->id);

    $second = app(AddPlanActivity::class)->handle($this->pm, $plan, 'Job fair', $bareOutcome->id, true);
    expect($second->parent_id)->toBe($output->id);

    expect(fn () => app(AddPlanActivity::class)->handle($this->pm, $plan, '  ', $bareOutcome->id, false))->toThrow(ValidationException::class)
        ->and(fn () => app(AddPlanActivity::class)->handle($this->otherManager, $plan, 'X', $bareOutcome->id, false))->toThrow(AuthorizationException::class);
});

test('the activities step adds an activity and ticks it', function () {
    $plan = app(CreateActivityPlan::class)->handle($this->pm, $this->proposal->id, $this->program->id);
    $outcome = Result::query()->where('type', ResultType::Outcome->value)->first();

    Livewire::actingAs($this->pm)->test('pages::annual-plans.plan', ['activityPlan' => $plan])
        ->set('newActivityName', 'Parent meetings')
        ->set('newActivityParent', (string) $outcome->id)
        ->call('addActivity')
        ->assertHasNoErrors()
        ->assertSet('picked', fn (array $picked): bool => in_array((string) Result::query()->where('statement', 'Parent meetings')->value('id'), $picked, true))
        // The link back to the Logical Framework stays on the activities step once
        // activities exist, not only in the empty state, so more can be added.
        ->assertSeeHtml('data-test="open-logframe-link"');
});

test('a MEAL reviewer who arrives after the plan was sent is told once that it waits for them', function () {
    Cache::flush();
    $plan = ActivityPlan::factory()->inReview()->create(['proposal_id' => $this->proposal->id, 'program_id' => $this->program->id, 'submitted_by' => $this->pm->id]);
    $late = userWithRole('MEAL', insertHrEmployee(firstNameEn: 'Late'));
    $late->givePermissionTo('annual-plans.review');

    expect(PlanNotifier::notifyReviewers($plan))->toBeGreaterThan(0)
        ->and(PlanNotifier::notifyReviewers($plan))->toBe(0);

    $rows = UserNotification::query()->forUser($late)->get()
        ->filter(fn ($row): bool => $row->data['key'] === 'notifications.events.annual_plan.plan_submitted');
    expect($rows)->toHaveCount(1);
});

test('approving asks in the app modal, not the browser dialog, then the plan goes live', function () {
    $plan = chosenPlan();
    foreach ($plan->items()->get() as $item) {
        app(SaveActivityPlanItem::class)->handle($this->pm, $item, itemInput(), true);
    }
    $plan = app(SubmitActivityPlan::class)->handle($this->pm, $plan->fresh());

    Livewire::actingAs($this->meal)->test('pages::annual-plans.plan', ['activityPlan' => $plan])
        ->assertDontSeeHtml('wire:confirm')
        ->set('confirmingApproval', true)
        ->assertSeeHtml('data-test="confirm-approve-plan"')
        // The dialog names the plan it falls on: two plans can wait with MEAL at once.
        ->assertSeeHtml('data-confirm-record')
        ->assertSee($plan->proposal->name)
        ->assertSee($this->program->name)
        ->call('approvePlan')
        ->assertSet('confirmingApproval', false);

    expect($plan->fresh()->status)->toBe(ActivityPlanStatus::Approved);
});

test('the plans register lists several plans, drafts and approved, without lazy loading their program', function () {
    $other = Proposal::factory()->create();
    ActivityPlan::factory()->create(['proposal_id' => $this->proposal->id, 'program_id' => $this->program->id]);
    ActivityPlan::factory()->create(['proposal_id' => $other->id, 'program_id' => $this->program->id]);
    ActivityPlan::factory()->count(2)->create(['program_id' => $this->program->id, 'status' => ActivityPlanStatus::Approved, 'proposal_id' => fn () => Proposal::factory()->create(['planned_date_begin' => '2027-01-01', 'planned_date_end' => '2027-12-31'])->id]);

    Livewire::actingAs($this->pm)->test('pages::annual-plans.plans')->assertOk()->assertSee($other->name);
});

test('the plan page shows every section at once and keeps sending locked until the plan is ready', function () {
    $plan = app(CreateActivityPlan::class)->handle($this->pm, $this->proposal->id, $this->program->id);

    $page = Livewire::actingAs($this->pm)->test('pages::annual-plans.plan', ['activityPlan' => $plan])
        ->assertSeeHtml('data-plan-index')
        ->assertSeeHtml('data-plan-step="proposal"')
        ->assertSeeHtml('data-plan-step="activities"')
        ->assertSeeHtml('data-plan-step="dates"')
        ->assertDontSeeHtml('data-test="plan-next"')
        ->assertSeeText(__('annual_plans.wf.footer_pick_activities'))
        ->set('picked', [(string) $this->basic->id])
        ->assertSeeText(__('annual_plans.wf.footer_save_activities'))
        ->call('saveActivities')
        ->assertSeeHtml('data-plan-step="send"')
        ->assertNotSet('openItemId', null);

    expect($plan->fresh()->items()->count())->toBe(1);

    $page->call('submitPlan');
    expect($plan->fresh()->status->value)->toBe('draft');
});

// ---- AP-2: the send, the MEAL review, the send-back and the approval ------------

/**
 * A plan with every activity Ready, sent to MEAL.
 */
function sentPlan(): ActivityPlan
{
    $plan = chosenPlan();

    foreach ($plan->items()->get() as $item) {
        app(SaveActivityPlanItem::class)->handle(test()->pm, $item, itemInput(), true);
    }

    return app(SubmitActivityPlan::class)->handle(test()->pm, $plan->fresh());
}

/**
 * The toast the page showed, by its text.
 */
function planToast(string $text): Closure
{
    return fn (string $event, array $params): bool => ($params['slots']['text'] ?? null) === $text;
}

test('the send step refuses an unready plan with the ready rule, then sends it with a toast', function () {
    $plan = chosenPlan();
    [$first, $second] = $plan->items()->get()->all();
    app(SaveActivityPlanItem::class)->handle($this->pm, $first, itemInput(), true);

    Livewire::actingAs($this->pm)->test('pages::annual-plans.plan', ['activityPlan' => $plan])
        ->call('goToStep', 3)
        ->assertDispatched('toast-show', planToast(__('annual_plans.not_all_ready')));

    expect($plan->fresh()->step)->toBe(2)
        ->and($plan->fresh()->status)->toBe(ActivityPlanStatus::Draft);

    app(SaveActivityPlanItem::class)->handle($this->pm, $second, itemInput(), true);

    Livewire::actingAs($this->pm)->test('pages::annual-plans.plan', ['activityPlan' => $plan->fresh()])
        ->call('goToStep', 3)
        ->assertSeeHtml('data-plan-step="send"')
        ->assertSeeHtml('data-test="send-to-meal"')
        ->call('submitPlan')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show', planToast(__('annual_plans.wf.sent_to_meal')));

    expect($plan->fresh()->status)->toBe(ActivityPlanStatus::InReview);
});

test('after the send the program manager only reads the plan, told it is with MEAL', function () {
    $plan = sentPlan();

    Livewire::actingAs($this->pm)->test('pages::annual-plans.plan', ['activityPlan' => $plan])
        ->assertSet('mode', 'wait')
        ->assertSee(__('annual_plans.wf.with_meal_title'))
        ->assertSeeHtml('data-plan-waiting')
        ->assertDontSeeHtml('data-test="send-to-meal"')
        ->assertDontSeeHtml('data-plan-step="review"')
        ->call('goToStep', 2);

    expect($plan->fresh()->status)->toBe(ActivityPlanStatus::InReview)
        ->and($plan->fresh()->step)->toBe(3);

    expect(fn () => app(SaveActivityPlanItem::class)->handle($this->pm, $plan->items()->first(), itemInput(), true))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ChooseActivityPlanActivities::class)->handle($this->pm, $plan, [$this->basic->id]))->toThrow(AuthorizationException::class)
        ->and(fn () => app(AddPlanActivity::class)->handle($this->pm, $plan, 'Late activity', (int) $this->basic->parent_id, false))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SubmitActivityPlan::class)->handle($this->pm, $plan))->toThrow(AuthorizationException::class);
});

test('the MEAL manager hears that a plan waits, and the register points at it only for them', function () {
    Cache::flush();
    $plan = sentPlan();

    $told = UserNotification::query()->forUser($this->meal)->get()
        ->filter(fn ($row): bool => $row->data['key'] === 'notifications.events.annual_plan.plan_submitted');
    expect($told)->toHaveCount(1);

    Livewire::actingAs($this->meal)->test('pages::annual-plans.plans')
        ->assertSee(__('annual_plans.wf.next_review_title'));

    Livewire::actingAs($this->otherManager)->test('pages::annual-plans.plans')
        ->assertDontSee(__('annual_plans.wf.next_review_title'));
});

test('the review names who sent the plan and when, and says plainly that it is read-only', function () {
    $plan = sentPlan();

    Livewire::actingAs($this->meal)->test('pages::annual-plans.plan', ['activityPlan' => $plan])
        ->assertSet('mode', 'review')
        ->assertSee(__('annual_plans.wf.sent_by_on', ['name' => $this->pm->name, 'date' => now()->translatedFormat(__('formats.date'))]))
        ->assertSee(__('annual_plans.wf.read_only_info'))
        ->assertSee(__('annual_plans.wf.whole_plan'))
        ->assertSeeHtml('data-review-items');
});

test('a follow-up task needs its words and its activity, then it is listed and removed', function () {
    $plan = sentPlan();
    $item = $plan->items()->first();

    // The three fields are behind their own button, like adding an activity on step 2.
    $page = Livewire::actingAs($this->meal)->test('pages::annual-plans.plan', ['activityPlan' => $plan])
        ->assertSeeHtml('data-test="open-follow-up"')
        ->assertDontSeeHtml('data-add-follow-up-form')
        ->set('addingFollowUp', true)
        ->assertSeeHtml('data-add-follow-up-form')
        ->set('followUpText', 'Run the post test')
        ->set('followUpItem', '')
        ->call('addFollowUp')
        ->assertHasErrors('followUpItem')
        ->assertSee(__('annual_plans.wf.follow_up_pick_activity'))
        ->assertSeeHtml('data-add-follow-up-form');

    $page->set('followUpItem', (string) $item->id)
        ->set('followUpText', '   ')
        ->call('addFollowUp')
        ->assertHasErrors('followUpText')
        ->assertSee(__('annual_plans.follow_up_text_required'));

    expect($plan->followUps()->count())->toBe(0);

    $page->set('followUpText', 'Run the post test')
        ->set('followUpTrigger', 'start')
        ->call('addFollowUp')
        ->assertHasNoErrors()
        ->assertSee('Run the post test')
        ->assertSeeHtml('data-follow-up="'.$plan->followUps()->value('id').'"')
        // Once the task is on the list the form closes itself again.
        ->assertSet('addingFollowUp', false)
        ->assertDontSeeHtml('data-add-follow-up-form');

    expect($plan->followUps()->count())->toBe(1)
        ->and($plan->followUps()->value('trigger')->value)->toBe('start');

    $page->call('removeFollowUp', $plan->followUps()->value('id'));

    expect($plan->followUps()->count())->toBe(0);
});

test('a send back keeps everything the program manager entered and shows the reason in full', function () {
    $plan = sentPlan();
    $item = $plan->items()->first();
    app(ManageActivityPlanFollowUps::class)->add($this->meal, $plan, $item->id, 'middle', 'Watch the first batch');
    $reason = 'English sessions run in Aleppo only, but the proposal promises Homs as well, so add a Homs batch of the same size and move the second batch two weeks later so the two do not overlap with the exams.';

    Livewire::actingAs($this->meal)->test('pages::annual-plans.plan', ['activityPlan' => $plan])
        ->call('sendBackPlan')
        ->assertHasErrors('sendBackReason')
        ->assertSee(__('annual_plans.send_back_reason_required'))
        ->set('sendBackReason', $reason)
        ->call('sendBackPlan')
        ->assertDispatched('toast-show', planToast(__('annual_plans.wf.sent_back_toast')));

    $plan = $plan->fresh();
    expect($plan->status)->toBe(ActivityPlanStatus::Draft)
        ->and($plan->step)->toBe(2)
        ->and($plan->sent_back_reason)->toBe($reason);

    // Nothing the program manager entered was lost, and the reason is on the page whole.
    $item = $plan->items()->where('id', $item->id)->first()->load('batches', 'cities');
    expect($plan->items()->count())->toBe(2)
        ->and($plan->items()->where('is_ready', true)->count())->toBe(2)
        ->and($item->expected_people)->toBe(60)
        ->and($item->cityIds())->toBe([$this->aleppo, $this->homs])
        ->and($item->batches->first()->planned_count)->toBe(16)
        ->and($item->batches->first()->start_week->toDateString())->toBe('2027-03-01')
        ->and($plan->followUps()->count())->toBe(1);

    Livewire::actingAs($this->pm)->test('pages::annual-plans.plan', ['activityPlan' => $plan])
        ->assertSet('mode', 'plan')
        ->assertSeeHtml('data-sent-back')
        ->assertSee($reason)
        ->assertSee(__('annual_plans.wf.sent_back_by', ['name' => $this->meal->name]))
        // Sent again without re-entering a thing.
        ->call('goToStep', 3)
        ->call('submitPlan')
        ->assertHasNoErrors();

    expect($plan->fresh()->status)->toBe(ActivityPlanStatus::InReview)
        ->and($plan->fresh()->sent_back_reason)->toBeNull();
});

test('an approved plan is live, its program manager is told, and nobody edits it again', function () {
    $plan = sentPlan();
    $superAdmin = userWithRole('Super Admin');

    Livewire::actingAs($this->meal)->test('pages::annual-plans.plan', ['activityPlan' => $plan])
        ->call('approvePlan')
        ->assertDispatched('toast-show', planToast(__('annual_plans.wf.approved_toast')))
        ->assertSeeHtml('data-plan-step="approved"');

    $plan = $plan->fresh();
    expect($plan->status)->toBe(ActivityPlanStatus::Approved);

    $told = UserNotification::query()->forUser($this->pm)->get()
        ->filter(fn ($row): bool => $row->data['key'] === 'notifications.events.annual_plan.plan_approved');
    expect($told)->toHaveCount(1);

    foreach ([$this->pm, $this->meal, $superAdmin] as $user) {
        expect($user->can('update', $plan))->toBeFalse()
            ->and($user->can('review', $plan))->toBeFalse()
            ->and($user->can('delete', $plan))->toBeFalse();
    }

    expect(fn () => app(SaveActivityPlanItem::class)->handle($this->pm, $plan->items()->first(), itemInput(), true))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SaveActivityPlanItem::class)->handle($superAdmin, $plan->items()->first(), itemInput(), true))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ReviewActivityPlan::class)->sendBack($this->meal, $plan, 'Changed my mind'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ManageActivityPlanFollowUps::class)->add($this->meal, $plan, (int) $plan->items()->value('id'), 'finish', 'One more'))->toThrow(AuthorizationException::class);

    Livewire::actingAs($superAdmin)->test('pages::annual-plans.plan', ['activityPlan' => $plan])
        ->assertSet('mode', 'read')
        ->assertDontSeeHtml('data-test="send-to-meal"')
        ->assertDontSeeHtml('data-plan-step="review"');
});

test('the dates step writes out why the send step is still closed', function () {
    $plan = chosenPlan();
    [$first, $second] = $plan->items()->get()->all();
    app(SaveActivityPlanItem::class)->handle($this->pm, $first, itemInput(), true);

    // A disabled button fires no click, so the rule is on the page as words.
    Livewire::actingAs($this->pm)->test('pages::annual-plans.plan', ['activityPlan' => $plan->fresh()])
        ->assertSeeHtml('data-not-ready')
        ->assertSee(__('annual_plans.not_all_ready'))
        ->assertSeeHtml('disabled');

    app(SaveActivityPlanItem::class)->handle($this->pm, $second, itemInput(), true);

    Livewire::actingAs($this->pm)->test('pages::annual-plans.plan', ['activityPlan' => $plan->fresh()])
        ->assertDontSeeHtml('data-not-ready')
        ->assertDontSee(__('annual_plans.not_all_ready'));
});

test('a plan with MEAL waits on the MEAL manager from the desk too, and on nobody else', function () {
    $plan = sentPlan();

    Livewire::actingAs($this->meal)->test('desk.panels.needs', ['size' => 'L'])
        ->assertSee($plan->proposal->name)
        ->assertSee(__('desk.decision.plan'))
        ->assertSee($this->pm->name)
        ->assertSeeHtml('data-decision="plan"');

    Livewire::actingAs($this->otherManager)->test('desk.panels.needs', ['size' => 'L'])
        ->assertDontSee($plan->proposal->name);

    app(ReviewActivityPlan::class)->approve($this->meal, $plan);

    Livewire::actingAs($this->meal)->test('desk.panels.needs', ['size' => 'L'])
        ->assertDontSee($plan->proposal->name);
});

test('a plan MEAL sent back waits on its program manager from the desk, and on nobody else', function () {
    $plan = sentPlan();
    app(ReviewActivityPlan::class)->sendBack($this->meal, $plan, 'Add Homs to English');

    Livewire::actingAs($this->pm)->test('desk.panels.needs', ['size' => 'L'])
        ->assertSee($plan->proposal->name)
        ->assertSee(__('desk.decision.plan_fix'))
        ->assertSee($this->meal->name)
        ->assertSeeHtml('data-decision="plan_fix"');

    // MEAL is done with it until it comes back, and another manager never sees it.
    Livewire::actingAs($this->meal)->test('desk.panels.needs', ['size' => 'L'])->assertDontSee($plan->proposal->name);
    Livewire::actingAs($this->otherManager)->test('desk.panels.needs', ['size' => 'L'])->assertDontSee($plan->proposal->name);

    app(SubmitActivityPlan::class)->handle($this->pm, $plan->fresh());

    Livewire::actingAs($this->pm)->test('desk.panels.needs', ['size' => 'L'])->assertDontSee($plan->proposal->name);
});

test('a send-back reason written over several lines keeps its shape wherever it is read', function () {
    $plan = sentPlan();
    $reason = "English runs in Aleppo only.\n\nAdd a Homs batch of the same size, and move the second batch two weeks later.";
    app(ReviewActivityPlan::class)->sendBack($this->meal, $plan, $reason);

    // The reason is one block of text on the plan page and in the register's next step,
    // and the stylesheet is what keeps its line breaks.
    Livewire::actingAs($this->pm)->test('pages::annual-plans.plan', ['activityPlan' => $plan->fresh()])
        ->assertSeeHtml('<span class="ap-lines" dir="auto">'.e($reason).'</span>');

    Livewire::actingAs($this->pm)->test('pages::annual-plans.plans')
        ->assertSeeHtml('ap-lines')
        ->assertSee($reason);

    expect(file_get_contents(resource_path('css/annual-plan.css')))->toContain('.ap-lines { white-space: pre-line; }');
});
