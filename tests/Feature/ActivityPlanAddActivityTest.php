<?php

use App\Actions\AnnualPlans\CreateActivityPlan;
use App\Enums\ActivityKind;
use App\Enums\Period;
use App\Enums\ResultType;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Result;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
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

test('a fit segmented row sizes each choice to its label', function () {
    View::share('errors', new ViewErrorBag);
    $options = [['value' => 'a', 'label' => 'Impact'], ['value' => 'b', 'label' => 'Cross-cutting']];

    expect(Blade::render('<x-ui.form-segmented wire:model="kind" label="Type" value="a" fit :options="$options" />', ['options' => $options]))
        ->toContain('form-segmented-fit')
        ->and(Blade::render('<x-ui.form-segmented wire:model="kind" label="Type" value="a" :options="$options" />', ['options' => $options]))
        ->not->toContain('form-segmented-fit');
});
