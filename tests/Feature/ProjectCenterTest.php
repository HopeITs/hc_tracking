<?php

use App\Enums\ProposalStatus;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| Y-17: the create and placement half of this file went with the feature it
| guarded. The [contract] drops `projects.create` in §8 (nothing creates a
| place by hand: a place exists because a proposal has it, D-84/D-87) and
| withdraws rule L94, the one-open-project rule (D-77). The city, the
| program, the centers and the responsible are decided on the proposal now,
| so the form no longer offers them. The twelve deleted cases are named in
| the Y-17 handoff, and the hosted-project block (W-01, D-55) went with
| them: a place with no centre of its own is field work across the city, and
| the row says so itself (D-88, Y-16).
|
| What is left reads: the register's "no centre yet" group and the
| «Center inactive» badge (W-02, D-66).
*/

beforeEach(function () {
    $this->user = User::factory()->role('General Manager')->create();
    $this->cityId = insertHrCity('Idlib', 'إدلب');
    $this->centerId = insertHrCenter($this->cityId, 'Hope Center Idlib', 'مركز الأمل إدلب');
    $this->owner = insertHrEmployee();
    $this->program = Program::factory()->create(['manager_id' => $this->owner]);
});

test('managers see the projects that have no center yet', function () {
    projectWithRow(['name' => 'Unmapped Project', 'center_id' => null]);
    projectWithRow(['name' => 'Mapped Project', 'center_id' => $this->centerId]);

    Livewire::actingAs($this->user)
        ->test('pages::projects.index')
        ->assertSeeText('Projects without a center (1)')
        ->set('showProjectsWithoutCenter', true)
        ->assertSeeInOrder(['Projects without a center', 'Unmapped Project']);

    Livewire::actingAs(User::factory()->role('Team Member')->create())
        ->test('pages::projects.index')
        ->assertDontSeeText('Projects without a center');
});

/*
|--------------------------------------------------------------------------
| The «Center inactive» badge (W-02, D-66)
|--------------------------------------------------------------------------
*/

test('a project whose center HR has deactivated says so on the directory, the project page and the proposal picker', function () {
    $closedId = insertHrCenter($this->cityId, 'Closed Center', 'مركز مغلق', isActive: false);
    $project = projectWithRow(['name' => 'Closed Center Water', 'center_id' => $closedId, 'city_id' => $this->cityId]);

    Livewire::actingAs($this->user)->test('pages::projects.index')
        ->assertSeeHtml('data-test="center-inactive-badge"')
        ->assertSeeText(__('projects.center_inactive_badge'));

    $this->actingAs($this->user)
        ->get(route('projects.show', rowFor($project)))
        ->assertOk()
        ->assertSee('data-test="center-inactive-badge"', false);

    // A new place cannot pick a closed center; a row that already holds one
    // says it is closed. Its own closed centre, because the row the project
    // above carries holds $closedId and the picker rightly offers a centre a
    // row already holds (Y-16: every project has a row now).
    $pickerClosedId = insertHrCenter($this->cityId, 'Retired Center', 'مركز متقاعد', isActive: false);
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::ProposalDraft]);
    ProposalLocation::factory()->complete()->create(['proposal_id' => $proposal->id, 'city_id' => $this->cityId])
        ->centers()->create(['center_id' => $pickerClosedId]);

    Livewire::actingAs($this->user)->test('pages::proposals.index')
        ->call('openCreateModal')
        ->assertDontSeeText('Retired Center')
        ->call('openEditModal', $proposal->id)
        ->assertSeeText('Retired Center · '.__('projects.center_inactive_badge'));
});

test('an active center, a hosted project and a project with no center carry no badge', function () {
    Project::factory()->create(['name' => 'Active Water', 'center_id' => $this->centerId, 'city_id' => $this->cityId]);
    Project::factory()->hostedBy(insertHrCenter(insertHrCity('Aleppo', 'حلب'), 'Head Office Aleppo', 'المكتب الرئيسي حلب', isActive: false))->create(['name' => 'Hosted Water']);
    Project::factory()->create(['name' => 'Unmapped Water', 'center_id' => null]);

    Livewire::actingAs($this->user)->test('pages::projects.index')
        ->assertDontSeeHtml('data-test="center-inactive-badge"');
});

test('the inactive badge adds no query per row', function () {
    $closedId = insertHrCenter($this->cityId, 'Closed Center', 'مركز مغلق', isActive: false);
    Project::factory()->create(['center_id' => $closedId, 'city_id' => $this->cityId]);

    $countQueries = function (): int {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        Livewire::actingAs($this->user)->test('pages::projects.index');

        return $queries;
    };

    $withOne = $countQueries();
    Project::factory()->count(4)->create(['center_id' => $closedId, 'city_id' => $this->cityId]);

    expect($countQueries())->toBeLessThanOrEqual($withOne);
});
