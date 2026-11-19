<?php

use App\Actions\AnnualPlans\ChooseActivityPlanActivities;
use App\Actions\AnnualPlans\CreateActivityPlan;
use App\Enums\ActivityKind;
use App\Enums\Period;
use App\Enums\ResultType;
use App\Models\ActivityPlan;
use App\Models\ActivityPlanItem;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Result;
use App\Support\ActivityPlanScope;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $manager = insertHrEmployee(firstNameEn: 'Meghety', lastNameEn: 'Nakkashian');
    $this->pm = userWithRole('Managers', $manager);
    $this->program = Program::factory()->create(['manager_id' => $manager]);
    $this->proposal = Proposal::factory()->create(['planned_date_begin' => '2027-01-01', 'planned_date_end' => '2027-12-31']);
    $project = Project::factory()->create(['program_id' => $this->program->id, 'city_id' => insertHrCity('Aleppo', 'حلب')]);
    DB::table('project_proposal')->insert(['project_id' => $project->id, 'proposal_id' => $this->proposal->id]);
    $this->outcome = Result::factory()->type(ResultType::Outcome)->create(['proposal_id' => $this->proposal->id, 'program_id' => $this->program->id, 'checkout_period' => 'monthly']);
    $this->plan = app(CreateActivityPlan::class)->handle($this->pm, $this->proposal->id, $this->program->id);
});

test('the plan asks a new activity its kind and period like the Logical Framework does', function () {
    Livewire::actingAs($this->pm)->test('pages::annual-plans.plan', ['activityPlan' => $this->plan])
        ->set('addingActivity', true)
        ->assertSeeHtml('wire:model="newActivityKind"')
        ->assertSeeHtml('wire:model="newActivityPeriod"')
        ->assertSee(__('results_framework.activity_kind'))
        ->assertSee(__('results_framework.checked'))
        ->set('newActivityName', 'Final exam')
        ->set('newActivityParent', (string) $this->outcome->id)
        ->set('newActivityKind', ActivityKind::Event->value)
        ->set('newActivityPeriod', Period::Annual->value)
        ->call('addActivity')
        ->assertHasNoErrors();

    $activity = Result::query()->where('statement', 'Final exam')->firstOrFail();

    expect($activity->activity_kind)->toBe(ActivityKind::Event)
        ->and($activity->checkout_period)->toBe(Period::Annual);
});

test('the dates step shows the plan calendar unpinned and lists copy targets as chips', function () {
    $output = Result::factory()->type(ResultType::Output)->childOf($this->outcome)->create(['proposal_id' => $this->proposal->id, 'program_id' => $this->program->id]);
    $basic = Result::factory()->activity($output)->create(['statement' => 'Basic sessions']);
    $english = Result::factory()->activity($output)->create(['statement' => 'English sessions']);
    $plan = app(ChooseActivityPlanActivities::class)->handle($this->pm, $this->plan, [$basic->id, $english->id]);
    $source = $plan->items()->where('result_id', $basic->id)->firstOrFail();
    $target = $plan->items()->where('result_id', $english->id)->firstOrFail();

    $page = Livewire::actingAs($this->pm)->test('pages::annual-plans.plan', ['activityPlan' => $plan])
        ->assertSeeHtml('class="ap-cal" data-dates-calendar');

    // The one-page plan draws the whole-plan gantt in the send section too, so
    // hiding the dates calendar takes exactly one gantt off the page.
    $gantts = fn (): int => substr_count($page->html(), 'data-plan-gantt');
    $shown = $gantts();
    $page->toggle('showPlanCalendar')->assertSee(__('annual_plans.wf.show_calendar'));
    expect($shown)->toBeGreaterThan(0)->and($gantts())->toBe($shown - 1);
    $page->toggle('showPlanCalendar');
    expect($gantts())->toBe($shown);

    $page->call('toggleItem', $target->id)
        ->assertSet('openItemId', $target->id)
        ->assertSeeHtml('class="ap-line"')
        ->assertSeeHtml('class="ap-row ap-act-foot"')
        ->assertDontSee(__('annual_plans.wf.needs_sessions_hours', ['each' => __('annual_plans.wf.every_batch')]))
        ->assertSeeHtml('class="ap-copy-list"')
        ->assertSeeHtml('data-test="copy-target-'.$source->id.'"');
});

test('a fit segmented row sizes each choice to its label', function () {
    View::share('errors', new ViewErrorBag);
    $options = [['value' => 'a', 'label' => 'Impact'], ['value' => 'b', 'label' => 'Cross-cutting']];

    expect(Blade::render('<x-ui.form-segmented wire:model="kind" label="Type" value="a" fit :options="$options" />', ['options' => $options]))
        ->toContain('form-segmented-fit')
        ->and(Blade::render('<x-ui.form-segmented wire:model="kind" label="Type" value="a" :options="$options" />', ['options' => $options]))
        ->not->toContain('form-segmented-fit');
});

/**
 * The plan with one activity chosen, and that activity's item.
 *
 * @return array{0: ActivityPlan, 1: ActivityPlanItem}
 */
function planWithOneActivity(): array
{
    $output = Result::factory()->type(ResultType::Output)->childOf(test()->outcome)->create(['proposal_id' => test()->proposal->id, 'program_id' => test()->program->id]);
    $basic = Result::factory()->activity($output)->create(['statement' => 'Basic sessions']);
    $plan = app(ChooseActivityPlanActivities::class)->handle(test()->pm, test()->plan, [$basic->id]);

    return [$plan, $plan->items()->firstOrFail()];
}

/**
 * The plan page with the item's details open.
 */
function openPlanItem(ActivityPlan $plan, ActivityPlanItem $item): Testable
{
    $page = Livewire::actingAs(test()->pm)->test('pages::annual-plans.plan', ['activityPlan' => $plan]);

    if ($page->get('openItemId') !== $item->id) {
        $page->call('toggleItem', $item->id);
    }

    return $page;
}

test('opening an activity drops a governorate the plan no longer has, and says so', function () {
    [$plan, $item] = planWithOneActivity();
    $planCity = ActivityPlanScope::cityIds($plan)[0];
    $item->cities()->create(['city_id' => insertHrCity('Homs', 'حمص')]);

    openPlanItem($plan, $item)
        ->assertSet('form.cities', [(string) $planCity])
        ->assertSeeHtml('data-removed-cities')
        ->assertSee(__('annual_plans.wf.removed_cities', ['cities' => 'Homs']));
});

test('a centre without a known governorate is named with a way to fix it', function () {
    $broken = Project::factory()->create(['program_id' => $this->program->id, 'city_id' => 999999, 'center_id' => null, 'name' => 'Al-Wadi Training Center']);
    DB::table('project_proposal')->insert(['project_id' => $broken->id, 'proposal_id' => $this->proposal->id]);
    [$plan, $item] = planWithOneActivity();

    openPlanItem($plan, $item)
        ->assertSeeHtml('data-centre-without-governorate')
        ->assertSee(__('annual_plans.wf.centre_no_governorate', ['centre' => 'Al-Wadi Training Center']))
        ->assertSeeHtml('href="'.route('projects.show', $broken).'"');
});
