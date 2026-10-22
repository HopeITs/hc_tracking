<?php

use App\Enums\ResultType;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Result;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->user = User::factory()->role('MEAL')->create();
    $this->program = Program::factory()->create();
    $this->proposal = Proposal::factory()->create();
    $this->project = Project::factory()->create(['program_id' => $this->program->id]);
    DB::table('project_proposal')->insert(['project_id' => $this->project->id, 'proposal_id' => $this->proposal->id, 'target_beneficiaries' => 10]);
});

test('the empty targets checklist switches the proposal to the logframe tab in place', function () {
    Livewire::actingAs($this->user)->test('pages::proposals.show', ['proposal' => $this->proposal, 'embedded' => true])
        ->set('tab', 'targets')
        ->assertSeeHtml('data-test="open-logframe-tab"')
        ->assertDontSeeHtml('tab=logframe')
        ->set('tab', 'logframe')
        ->assertSet('tab', 'logframe');
});

test('the results framework links a proposal group straight to its Story block', function () {
    Result::factory()->create(['type' => ResultType::Impact, 'program_id' => $this->program->id, 'proposal_id' => $this->proposal->id]);

    Livewire::actingAs($this->user)->test('pages::results.index')
        ->assertSeeHtml('href="'.e(route('projects.show', ['project' => $this->project, 'proposal' => $this->proposal->id, 'ptab' => 'logframe']).'#proposal').'"')
        ->assertDontSeeHtml(route('proposals.show', $this->proposal));
});
