<?php

use App\Actions\Targets\ScopeIndicatorToProject;
use App\Enums\ResultType;
use App\Models\Activity;
use App\Models\AnnualPlan;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Result;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->meal = User::factory()->role('MEAL')->create();
    $this->actingAs($this->meal);

    $this->program = Program::factory()->create();
    $this->project = Project::factory()->create(['program_id' => $this->program->id]);
    $this->proposal = Proposal::factory()->create(['name' => 'Winter WASH Response']);
    DB::table('project_proposal')->insert(['project_id' => $this->project->id, 'proposal_id' => $this->proposal->id]);

    $proposalNode = Result::factory()->create(['type' => ResultType::Output, 'program_id' => $this->program->id, 'proposal_id' => $this->proposal->id]);
    $this->proposalIndicator = Indicator::factory()->create(['result_id' => $proposalNode->id, 'name' => 'Latrines rehabilitated']);

    $this->programNode = Result::factory()->create(['type' => ResultType::Output, 'program_id' => $this->program->id]);
    $this->programIndicator = Indicator::factory()->create(['result_id' => $this->programNode->id, 'name' => 'Hygiene sessions held']);
});

function projectTargetsTab(User $user, Project $project): Testable
{
    return Livewire::actingAs($user)->test('pages::projects.show', ['project' => $project])->set('tab', 'targets');
}

function scopeIndicator(Project $project, Indicator $indicator): Indicator
{
    return app(ScopeIndicatorToProject::class)->handle($project, $indicator->id);
}

test('the targets tab lists the indicators of its proposals and of its program framework', function () {
    $otherProgramIndicator = Indicator::factory()->create([
        'result_id' => Result::factory()->create(['type' => ResultType::Output, 'program_id' => Program::factory()->create()->id])->id,
        'name' => 'Seeds distributed',
    ]);
    $elsewhere = Indicator::factory()->create([
        'result_id' => $this->programNode->id,
        'project_id' => Project::factory()->create(['program_id' => $this->program->id])->id,
        'name' => 'Pumps repaired elsewhere',
    ]);

    projectTargetsTab($this->meal, $this->project)
        ->assertSee(__('projects.project_indicators'))
        ->assertSeeInOrder(['Winter WASH Response', 'Latrines rehabilitated'])
        ->assertSee([__('projects.program_framework'), 'Hygiene sessions held'])
        ->assertDontSee(['Seeds distributed', 'Pumps repaired elsewhere'])
        ->assertSeeHtml('data-scope-indicator="'.$this->programIndicator->id.'"')
        ->assertDontSeeHtml('data-scope-indicator="'.$this->proposalIndicator->id.'"');

    expect($otherProgramIndicator->exists && $elsewhere->exists)->toBeTrue();
});

test('MEAL scopes a program-framework indicator to the project and it is logged', function () {
    projectTargetsTab($this->meal, $this->project)
        ->call('scopeIndicator', $this->programIndicator->id)
        ->assertHasNoErrors()
        ->assertSee(__('projects.indicator_scoped_here'));

    expect(DB::table('indicators')->where('id', $this->programIndicator->id)->value('project_id'))->toBe($this->project->id)
        ->and(Activity::query()->where('subject_type', 'indicator')->where('subject_id', $this->programIndicator->id)->exists())->toBeTrue();
});

test('a project outside the result program is refused', function () {
    $outsider = Project::factory()->create(['program_id' => Program::factory()->create()->id]);

    expect(fn () => scopeIndicator($outsider, $this->programIndicator))->toThrow(ValidationException::class);
    expect(DB::table('indicators')->where('id', $this->programIndicator->id)->value('project_id'))->toBeNull();
});

test('a proposal-framework indicator cannot be scoped from the project page', function () {
    expect(fn () => scopeIndicator($this->project, $this->proposalIndicator))->toThrow(ModelNotFoundException::class);
});

test('scoping that would strand a target on another project is refused', function () {
    $sibling = Project::factory()->create(['program_id' => $this->program->id]);
    PlanIndicator::factory()->create(['indicator_id' => $this->programIndicator->id, 'project_id' => $sibling->id, 'source_type' => 'annual_plan', 'source_id' => insertAnnualPlanFor($this->program)]);

    try {
        scopeIndicator($this->project, $this->programIndicator);
        $this->fail('The scope was not refused.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('indicator');
    }

    expect(DB::table('indicators')->where('id', $this->programIndicator->id)->value('project_id'))->toBeNull();
});

test('users without indicator rights see no scope button and are refused', function (string $role) {
    $user = User::factory()->role($role)->create();

    projectTargetsTab($user, $this->project)
        ->assertSee('Hygiene sessions held')
        ->assertDontSeeHtml('data-scope-indicator=');

    projectTargetsTab($user, $this->project)
        ->call('scopeIndicator', $this->programIndicator->id)
        ->assertForbidden();

    expect(DB::table('indicators')->where('id', $this->programIndicator->id)->value('project_id'))->toBeNull();
})->with(['Public Relations Manager', 'Financial']);

function insertAnnualPlanFor(Program $program): int
{
    return AnnualPlan::factory()->create(['program_id' => $program->id])->id;
}
