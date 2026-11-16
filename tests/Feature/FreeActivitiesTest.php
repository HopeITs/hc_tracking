<?php

use App\Enums\ProposalStatus;
use App\Enums\ResultType;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Result;
use Livewire\Livewire;

test('the MEAL manager adds an activity linked to no statement on the results framework', function () {
    $program = Program::factory()->create();
    Result::factory()->type(ResultType::Impact)->create(['program_id' => $program->id, 'statement' => 'Families live safely.']);

    Livewire::actingAs(mealManager())
        ->test('pages::results.index')
        ->assertSeeHtml('data-test="add-free-activity-'.$program->id.'"')
        ->call('openCreateActivity', $program->id)
        ->assertSet('showResultModal', true)
        ->assertSet('resultType', ResultType::Activity->value)
        ->assertSet('parentId', '')
        ->assertSet('resultProgramId', (string) $program->id)
        ->set('statement', 'Hold a community meeting each quarter.')
        ->call('saveResult')
        ->assertHasNoErrors()
        ->assertSee(__('results_framework.free_activities'))
        ->assertSee('Hold a community meeting each quarter.');

    $activity = Result::query()->where('statement', 'Hold a community meeting each quarter.')->sole();

    expect($activity->type)->toBe(ResultType::Activity)
        ->and($activity->parent_id)->toBeNull()
        ->and($activity->program_id)->toBe($program->id);
});

test('free activities follow the chain, before cross-cutting results', function () {
    $program = Program::factory()->create();
    Result::factory()->type(ResultType::CrossCutting)->create(['program_id' => $program->id, 'statement' => 'Gender is considered throughout.']);
    Result::factory()->type(ResultType::Activity)->create(['program_id' => $program->id, 'parent_id' => null, 'statement' => 'Train the volunteers.']);
    Result::factory()->type(ResultType::Impact)->create(['program_id' => $program->id, 'statement' => 'Families live safely.']);

    Livewire::actingAs(userWithRole('General Manager'))
        ->test('pages::results.index')
        ->assertSeeInOrder(['Families live safely.', __('results_framework.free_activities'), 'Train the volunteers.', 'Gender is considered throughout.']);
});

test('a MEAL officer sees free activities but no button to add one', function () {
    $program = Program::factory()->create();
    Result::factory()->type(ResultType::Activity)->create(['program_id' => $program->id, 'parent_id' => null, 'statement' => 'Train the volunteers.']);

    Livewire::actingAs(userWithRole('MEAL'))
        ->test('pages::results.index')
        ->assertSee('Train the volunteers.')
        ->assertDontSeeHtml('data-test="add-free-activity-');
});

test('a proposal framework has its own section for activities linked to no statement', function () {
    $program = Program::factory()->create();
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::ConceptNote]);
    $proposal->projects()->attach(Project::factory()->create(['program_id' => $program->id]));

    $tab = Livewire::actingAs(mealManager())->test('proposals.logframe', ['proposalId' => $proposal->id]);

    $tab->assertSeeHtml('data-test="add-free-activity"')
        ->call('openCreateActivity')
        ->assertSet('resultType', ResultType::Activity->value)
        ->set('statement', 'Run a launch event.')
        ->call('saveResult')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="logframe-free-activities"')
        ->assertSee('Run a launch event.');

    $activity = Result::query()->where('statement', 'Run a launch event.')->sole();

    expect($activity->proposal_id)->toBe($proposal->id)
        ->and($activity->parent_id)->toBeNull();
});
