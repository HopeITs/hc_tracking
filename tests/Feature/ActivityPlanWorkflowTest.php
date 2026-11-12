<?php

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
use Illuminate\Auth\Access\AuthorizationException;
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
        ->and($plan->items->first()->batches->first()->start_week->toDateString())->toBe('2026-12-28');
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

    expect($plan->fresh()->status)->toBe(ActivityPlanStatus::Draft);
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
