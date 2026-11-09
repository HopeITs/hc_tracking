<?php

use App\Actions\Proposals\TransitionProposal;
use App\Enums\Currency;
use App\Enums\ProposalStatus;
use App\Models\Donor;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\Role;
use Livewire\Livewire;

/*
| The fundraising officer (user story §1) works the proposals but neither
| records donors nor sees the money: the Public Relations Manager records a
| donor and the General Manager approves it.
*/

beforeEach(function () {
    $this->fundraiser = userWithRole('Public Relations');
});

test('the fundraiser opens the proposals pages and the donor list', function () {
    $proposal = Proposal::factory()->create();

    $this->actingAs($this->fundraiser)
        ->get(route('proposals.index'))->assertOk();
    $this->get(route('donors.index'))->assertOk();

    Livewire::actingAs($this->fundraiser)->test('pages::proposals.show', ['proposal' => $proposal])->assertOk();
});

test('the fundraiser moves a concept note to review, which needs proposals.update', function () {
    $proposal = Proposal::factory()->create([
        'status' => ProposalStatus::ConceptNote,
        'requested_budget' => '120000.00',
        'requested_currency' => Currency::Usd,
    ]);
    $proposal->projects()->attach(Project::factory()->create()->id, ['target_beneficiaries' => 400]);
    ProposalLocation::factory()->for($proposal)->complete()->create();

    $this->actingAs($this->fundraiser);
    app(TransitionProposal::class)->handle($proposal, ProposalStatus::ConceptNoteReview, ProposalStatus::ConceptNote, []);

    expect($proposal->fresh()->status)->toBe(ProposalStatus::ConceptNoteReview);
});

test('the fundraiser cannot record a donor, approve a proposal or see the money pages', function () {
    expect($this->fundraiser->can('create', Donor::class))->toBeFalse()
        ->and($this->fundraiser->can('approve', Proposal::factory()->create(['status' => ProposalStatus::Submitted])))->toBeFalse()
        ->and($this->fundraiser->can('funding-agreements.view'))->toBeFalse()
        ->and($this->fundraiser->can('transfers.view'))->toBeFalse();
});

test('the migration gives an existing Public Relations role its proposal access and takes back exactly that', function () {
    $granted = ['proposals.view', 'proposals.create', 'proposals.update', 'donors.view'];
    $role = Role::findByName('Public Relations', 'web');
    $role->revokePermissionTo($granted);
    $migration = require glob(database_path('migrations/*_grant_public_relations_proposal_permissions.php'))[0];

    $migration->up();
    $migration->up();

    expect($role->fresh()->hasAllPermissions($granted))->toBeTrue()
        ->and($role->fresh()->hasPermissionTo('donors.create'))->toBeFalse();

    $migration->down();

    expect($role->fresh()->hasAnyPermission($granted))->toBeFalse()
        ->and($role->fresh()->hasPermissionTo('tasks.create'))->toBeTrue();
});
