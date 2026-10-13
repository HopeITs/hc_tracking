<?php

use App\Enums\ProposalPhase;
use App\Enums\ProposalStatus;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('guests are redirected from the proposal pipeline', function () {
    $this->get(route('proposals.index'))->assertRedirect(route('login'));
});

test('proposals can be filtered by search and status', function () {
    $user = User::factory()->role('Financial')->create();

    Proposal::factory()->create(['name' => 'Emergency WASH Response', 'status' => ProposalStatus::Active]);
    Proposal::factory()->create(['name' => 'Winter Shelter Kit', 'status' => ProposalStatus::Rejected]);

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->assertSee('Emergency WASH Response')
        ->assertSee('Winter Shelter Kit')
        ->set('search', 'WASH')
        ->assertSee('Emergency WASH Response')
        ->assertDontSee('Winter Shelter Kit')
        ->call('clearFilters')
        ->set('status', ProposalStatus::Rejected->value)
        ->assertDontSee('Emergency WASH Response')
        ->assertSee('Winter Shelter Kit');
});

test('the phase filter selects every status belonging to that phase', function () {
    $user = User::factory()->role('Financial')->create();

    // Monitoring holds exactly ProposalStatus::Active.
    Proposal::factory()->create(['name' => 'Monitored proposal', 'status' => ProposalStatus::Active]);
    // Planning holds ConceptNote and ProposalDraft.
    Proposal::factory()->create(['name' => 'Planned proposal', 'status' => ProposalStatus::ConceptNote]);

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->set('phase', ProposalPhase::Monitoring->value)
        ->assertSee('Monitored proposal')
        ->assertDontSee('Planned proposal')
        ->call('clearFilters')
        ->set('phase', ProposalPhase::Planning->value)
        ->assertDontSee('Monitored proposal')
        ->assertSee('Planned proposal');
});

test('a proposal can be created with a trimmed name', function () {
    $user = User::factory()->role('Financial')->create();

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('openCreateModal')
        ->set('name', '  Rapid Response Fund  ')
        ->set('proposalDonorId', (string) insertDonor())->set('internalProjectNumber', 'HC-2026-041')
        ->set('proposalStatus', ProposalStatus::ConceptNote->value)
        ->set('plannedDateBegin', '2026-01-01')
        ->set('plannedDateEnd', '2026-12-31')
        ->call('saveProposal')
        ->assertHasNoErrors()
        ->assertSet('showProposalModal', false);

    $proposal = Proposal::query()->where('name', 'Rapid Response Fund')->sole();

    expect($proposal->status)->toBe(ProposalStatus::ConceptNote);
});

test('a proposal end date cannot fall before its begin date', function () {
    $user = User::factory()->role('Financial')->create();

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('openCreateModal')
        ->set('name', 'Backwards Proposal')
        ->set('proposalDonorId', (string) insertDonor())->set('internalProjectNumber', 'HC-2026-042')
        ->set('proposalStatus', ProposalStatus::ConceptNote->value)
        ->set('plannedDateBegin', '2026-12-31')
        ->set('plannedDateEnd', '2026-01-01')
        ->call('saveProposal')
        ->assertHasErrors(['plannedDateEnd']);
});

test('an unfunded proposal can be deleted', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('confirmDelete', $proposal->id)
        ->call('deleteProposal')
        ->assertHasNoErrors();

    expect(DB::table('proposals')->find($proposal->id))->toBeNull();
});

test('a proposal holding a funding agreement is not deleted and says why', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create();
    $donor = Donor::factory()->create();
    Proposal::query()->whereKey($proposal->id)->update(['donor_id' => $donor->id]);
    $agreement = DonorProposal::factory()->create([
        'donor_id' => $donor->id,
        'proposal_id' => $proposal->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('confirmDelete', $proposal->id)
        ->call('deleteProposal');

    expect(DB::table('proposals')->find($proposal->id))->not->toBeNull()
        ->and(DB::table('donor_proposal')->find($agreement->id))->not->toBeNull();
});

test('the pipeline renders with no proposals', function () {
    $user = User::factory()->role('Financial')->create();

    Livewire::actingAs($user)->test('pages::proposals.index')->assertSee('No proposals yet');
});

test('the delete confirmation opens and closes through its boolean', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('confirmDelete', $proposal->id)
        ->assertSet('showDeleteModal', true)
        ->call('cancelDelete')
        ->assertSet('showDeleteModal', false)
        ->assertSet('deletingProposalId', null);
});

test('moving a proposal to its next stage records the status and the reason together', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Submitted]);

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('openStageModal', $proposal->id)
        ->assertSet('showStageModal', true)
        ->assertSet('newStatus', ProposalStatus::Approved->value)
        ->set('statusContext', '  Donor signed off the budget.  ')
        ->set('statusDate', '2026-09-01')
        ->call('recordStageChange')
        ->assertHasNoErrors()
        ->assertSet('showStageModal', false);

    expect(DB::table('proposals')->find($proposal->id)->status)->toBe('approved');

    $note = DB::table('proposal_status_notes')->where('proposal_id', $proposal->id)->sole();

    expect($note->status)->toBe('approved')
        ->and($note->context)->toBe('Donor signed off the budget.')
        ->and($note->date)->toBe('2026-09-01');
});

test('a stage move without a reason changes nothing', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Active]);

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('openStageModal', $proposal->id)
        ->set('statusContext', '   ')
        ->call('recordStageChange')
        ->assertHasErrors(['statusContext']);

    expect(DB::table('proposals')->find($proposal->id)->status)->toBe('active')
        ->and(DB::table('proposal_status_notes')->where('proposal_id', $proposal->id)->count())->toBe(0);
});

test('a proposal with no next stage opens the stage move on its current status', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Rejected]);

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('openStageModal', $proposal->id)
        ->assertSet('newStatus', ProposalStatus::Rejected->value);
});

test('selecting a donor shows only the proposals it funds, and selecting it again clears the filter', function () {
    $user = User::factory()->role('Financial')->create();
    $echo = Donor::factory()->create(['name' => 'ECHO']);
    $giz = Donor::factory()->create(['name' => 'GIZ']);
    $funded = Proposal::factory()->create(['name' => 'Water Response']);
    $other = Proposal::factory()->create(['name' => 'Shelter Response']);
    Proposal::query()->whereKey($funded->id)->update(['donor_id' => $echo->id]);
    DonorProposal::factory()->create(['donor_id' => $echo->id, 'proposal_id' => $funded->id]);
    Proposal::query()->whereKey($other->id)->update(['donor_id' => $giz->id]);
    DonorProposal::factory()->create(['donor_id' => $giz->id, 'proposal_id' => $other->id]);

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('toggleDonor', $echo->id)
        ->assertSet('donor', (string) $echo->id)
        ->assertSee('Funded by ECHO')
        ->assertSee('Water Response')
        ->assertDontSee('Shelter Response')
        ->call('toggleDonor', $echo->id)
        ->assertSet('donor', '')
        ->assertSee('Shelter Response');
});

test('the pipeline rail toggles the phase filter and still counts the phases it hides', function () {
    $user = User::factory()->role('Financial')->create();
    Proposal::factory()->create(['status' => ProposalStatus::Active]);
    Proposal::factory()->create(['status' => ProposalStatus::Rejected]);

    $component = Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('togglePhase', ProposalPhase::Monitoring->value)
        ->assertSet('phase', ProposalPhase::Monitoring->value);

    $implementation = collect($component->instance()->pipeline)->firstWhere('phase', ProposalPhase::Implementation);

    expect($implementation['count'])->toBe(1)
        ->and($implementation['rejected'])->toBe(1);

    $component->call('togglePhase', ProposalPhase::Monitoring->value)->assertSet('phase', '');
});

test('the funding position splits committed, received and pending per currency for matching proposals only', function () {
    $user = User::factory()->role('Financial')->create();
    $donor = Donor::factory()->create();
    $shown = Proposal::factory()->create(['name' => 'Shown Proposal']);
    $shownInEuro = Proposal::factory()->create(['name' => 'Shown Proposal Two']);
    $hidden = Proposal::factory()->create(['name' => 'Hidden Proposal']);

    Proposal::query()->whereKey($shown->id)->update(['donor_id' => $donor->id]);
    $usd = DonorProposal::factory()->create(['donor_id' => $donor->id, 'proposal_id' => $shown->id, 'budget' => 1000, 'currency' => 'USD']);
    Proposal::query()->whereKey($shownInEuro->id)->update(['donor_id' => $donor->id]);
    DonorProposal::factory()->create(['donor_id' => $donor->id, 'proposal_id' => $shownInEuro->id, 'budget' => 500, 'currency' => 'EUR']);
    Proposal::query()->whereKey($hidden->id)->update(['donor_id' => $donor->id]);
    DonorProposal::factory()->create(['donor_id' => $donor->id, 'proposal_id' => $hidden->id, 'budget' => 9000, 'currency' => 'USD']);

    TransfersTracking::factory()->create(['donor_proposal_id' => $usd->id, 'ar_status' => 'received', 'payment_amount' => 300, 'amount_transfered_currency' => 'USD', 'net_amount_received' => 300, 'amount_received_currency' => 'USD']);
    TransfersTracking::factory()->create(['donor_proposal_id' => $usd->id, 'ar_status' => 'pending', 'payment_amount' => 200, 'amount_transfered_currency' => 'USD']);

    $position = Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->set('search', 'Shown')
        ->instance()
        ->fundingPosition
        ->keyBy('currency');

    expect($position->keys()->all())->toBe(['EUR', 'USD'])
        ->and($position['USD']['committed'])->toBe(1000.0)
        ->and($position['USD']['received'])->toBe(300.0)
        ->and($position['USD']['pending'])->toBe(200.0)
        ->and($position['USD']['unscheduled'])->toBe(500.0)
        ->and($position['EUR']['committed'])->toBe(500.0)
        ->and($position['EUR']['received'])->toBe(0.0);
});

test('the funding flow draws one graph per currency and lists unfunded proposals apart', function () {
    $user = User::factory()->role('Financial')->create();
    $donor = Donor::factory()->create(['name' => 'ECHO']);
    $funded = Proposal::factory()->create(['name' => 'Funded Proposal']);
    $unfunded = Proposal::factory()->create(['name' => 'Unfunded Proposal']);
    $project = Project::factory()->create(['name' => 'Water Network Expansion']);
    $funded->projects()->attach($project);
    Proposal::query()->whereKey($funded->id)->update(['donor_id' => $donor->id]);
    DonorProposal::factory()->create(['donor_id' => $donor->id, 'proposal_id' => $funded->id, 'budget' => 1250, 'currency' => 'USD']);

    $flow = Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->set('view', 'overview')
        ->assertSee('Water Network Expansion')
        ->instance()
        ->flow;

    expect($flow['currencies'])->toHaveCount(1)
        ->and($flow['currencies'][0]['graph']['money'])->toBe([
            ['from' => 'd'.$donor->id, 'to' => 'p'.$funded->id, 'amount' => 1250.0],
        ])
        ->and($flow['currencies'][0]['graph']['links'])->toBe([
            ['from' => 'p'.$funded->id, 'to' => 'j'.$project->id],
        ])
        ->and($flow['unfunded'])->toBe([['id' => $unfunded->id, 'name' => 'Unfunded Proposal']]);
});

test('each proposal row shows its task progress and overdue work', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create();
    Task::factory()->create(['source_type' => 'proposal', 'source_id' => $proposal->id, 'status' => TaskStatus::Done]);
    Task::factory()->create(['source_type' => 'proposal', 'source_id' => $proposal->id, 'status' => TaskStatus::Todo, 'due_date' => now()->subDays(3)->toDateString()]);
    Task::factory()->create(['source_type' => 'proposal', 'source_id' => $proposal->id, 'status' => TaskStatus::InProgress, 'due_date' => now()->addDays(3)->toDateString()]);

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->set('view', 'overview')
        ->assertSee('1 of 3 tasks done')
        ->assertSee('1 overdue');
});

test('a user without proposal permissions sees no edit controls and cannot create, move or delete', function () {
    $viewer = User::factory()->role('Team Member')->create()->givePermissionTo('proposals.view');
    $proposal = Proposal::factory()->create();

    $fresh = fn () => Livewire::actingAs($viewer)->test('pages::proposals.index');

    $fresh()->assertDontSeeHtml('data-test="create-proposal-button"')
        ->assertDontSeeHtml('data-test="advance-proposal-'.$proposal->id.'"');

    $fresh()->call('openCreateModal')->assertForbidden();
    $fresh()->set('name', 'Sneaked in')->set('proposalDonorId', (string) insertDonor())->set('internalProjectNumber', 'HC-2026-043')->set('plannedDateBegin', '2026-01-01')->set('plannedDateEnd', '2026-12-31')->set('proposalStatus', 'concept note')->call('saveProposal')->assertForbidden();
    $fresh()->call('openStageModal', $proposal->id)->assertForbidden();
    $fresh()->call('confirmDelete', $proposal->id)->assertForbidden();
    expect(fn () => $fresh()->set('deletingProposalId', $proposal->id))->toThrow(CannotUpdateLockedPropertyException::class);

    expect(DB::table('proposals')->where('name', 'Sneaked in')->exists())->toBeFalse()
        ->and(DB::table('proposals')->find($proposal->id))->not->toBeNull();
});

test('a proposal cannot be created without an internal project number', function () {
    $user = User::factory()->role('Financial')->create();

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('openCreateModal')
        ->set('name', 'Rapid Response Fund')
        ->set('proposalDonorId', (string) insertDonor())->set('internalProjectNumber', '   ')
        ->set('proposalStatus', ProposalStatus::ConceptNote->value)
        ->set('plannedDateBegin', '2026-01-01')
        ->set('plannedDateEnd', '2026-12-31')
        ->call('saveProposal')
        ->assertHasErrors(['internalProjectNumber' => 'required']);

    expect(DB::table('proposals')->where('name', 'Rapid Response Fund')->exists())->toBeFalse();
});

test('a new proposal cannot reuse another proposal\'s internal number, whatever its case', function () {
    $user = User::factory()->role('Financial')->create();
    Proposal::factory()->create(['internal_project_number' => 'HC-2026-044']);

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('openCreateModal')
        ->set('name', 'Rapid Response Fund')
        ->set('proposalDonorId', (string) insertDonor())->set('internalProjectNumber', 'hc-2026-044')
        ->set('proposalStatus', ProposalStatus::ConceptNote->value)
        ->set('plannedDateBegin', '2026-01-01')
        ->set('plannedDateEnd', '2026-12-31')
        ->call('saveProposal')
        ->assertHasErrors(['internalProjectNumber' => 'unique'])
        ->assertSee('The internal project number has already been taken.');

    expect(DB::table('proposals')->where('name', 'Rapid Response Fund')->exists())->toBeFalse();
});

test('the internal project number is trimmed and limited to 50 characters', function () {
    $user = User::factory()->role('Financial')->create();

    $component = Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('openCreateModal')
        ->set('name', 'Rapid Response Fund')
        ->set('proposalStatus', ProposalStatus::ConceptNote->value)
        ->set('plannedDateBegin', '2026-01-01')
        ->set('plannedDateEnd', '2026-12-31')
        ->set('proposalDonorId', (string) insertDonor())->set('internalProjectNumber', str_repeat('A', 51))
        ->call('saveProposal')
        ->assertHasErrors(['internalProjectNumber' => 'max']);

    $component->set('proposalDonorId', (string) insertDonor())->set('internalProjectNumber', '  HC-2026-045  ')
        ->call('saveProposal')
        ->assertHasNoErrors();

    expect(DB::table('proposals')->where('name', 'Rapid Response Fund')->value('internal_project_number'))->toBe('HC-2026-045');
});

test('a new internal number cannot use the reserved placeholder prefix', function (string $number) {
    $user = User::factory()->role('Financial')->create();

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('openCreateModal')
        ->set('name', 'Rapid Response Fund')
        ->set('proposalDonorId', (string) insertDonor())->set('internalProjectNumber', $number)
        ->set('proposalStatus', ProposalStatus::ConceptNote->value)
        ->set('plannedDateBegin', '2026-01-01')
        ->set('plannedDateEnd', '2026-12-31')
        ->call('saveProposal')
        ->assertHasErrors('internalProjectNumber')
        ->assertSee(__('proposals.internal_project_number_prefix_reserved'));

    expect(DB::table('proposals')->where('name', 'Rapid Response Fund')->exists())->toBeFalse();
})->with(['TEMP-0099', 'temp-0099']);

test('a proposal still holding its placeholder saves while the number is left alone, but not with another placeholder', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create(['internal_project_number' => 'TEMP-0007']);

    $component = Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('openEditModal', $proposal->id)
        ->assertSet('internalProjectNumber', 'TEMP-0007')
        ->set('proposalDonorId', (string) insertDonor())->set('internalProjectNumber', 'TEMP-0008')
        ->call('saveProposal')
        ->assertHasErrors('internalProjectNumber');

    $component->set('proposalDonorId', (string) insertDonor())->set('internalProjectNumber', 'TEMP-0007')
        ->set('name', 'Renamed proposal')
        ->call('saveProposal')
        ->assertHasNoErrors();

    $stored = DB::table('proposals')->find($proposal->id);

    expect($stored->name)->toBe('Renamed proposal')
        ->and($stored->internal_project_number)->toBe('TEMP-0007');
});

test('saving a proposal with its own internal number raises no duplicate error', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create(['internal_project_number' => 'HC-2026-046']);

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('openEditModal', $proposal->id)
        ->set('notes', 'Checked against the donor letter')
        ->call('saveProposal')
        ->assertHasNoErrors();

    expect(DB::table('proposals')->find($proposal->id)->notes)->toBe('Checked against the donor letter');
});

test('changing the internal number is recorded in the activity log and notifies', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Activity::withoutRecording(fn () => Proposal::factory()->create(['internal_project_number' => 'TEMP-0003']));

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('openEditModal', $proposal->id)
        ->set('proposalDonorId', (string) insertDonor())->set('internalProjectNumber', 'HC-2026-047')
        ->call('saveProposal')
        ->assertHasNoErrors();

    $activity = Activity::query()->where('subject_type', 'proposal')->where('subject_id', $proposal->id)->sole();

    expect($activity->event)->toBe('proposal.updated')
        ->and($activity->changes['internal_project_number'])->toBe(['TEMP-0003', 'HC-2026-047'])
        ->and($activity->shouldNotify())->toBeTrue();
});

test('a duplicate that slips past validation shows the duplicate error instead of failing', function () {
    $user = User::factory()->role('Financial')->create();
    Proposal::factory()->create(['internal_project_number' => 'HC-2026-048']);

    // Stands in for a colleague saving the same number between this form's
    // validation and its insert: validation saw a free number, the database
    // does not.
    Proposal::creating(function (Proposal $proposal): void {
        $proposal->internal_project_number = 'HC-2026-048';
    });

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('openCreateModal')
        ->set('name', 'Rapid Response Fund')
        ->set('proposalDonorId', (string) insertDonor())->set('internalProjectNumber', 'HC-2026-049')
        ->set('proposalStatus', ProposalStatus::ConceptNote->value)
        ->set('plannedDateBegin', '2026-01-01')
        ->set('plannedDateEnd', '2026-12-31')
        ->call('saveProposal')
        ->assertHasErrors('internalProjectNumber')
        ->assertSee('The internal project number has already been taken.')
        ->assertSet('showProposalModal', true);

    expect(DB::table('proposals')->where('name', 'Rapid Response Fund')->exists())->toBeFalse();
});

test('the proposals list finds a proposal by its internal number', function () {
    $user = User::factory()->role('Financial')->create();
    Proposal::factory()->create(['name' => 'Winter Shelter Kit', 'internal_project_number' => 'HC-2026-061']);
    Proposal::factory()->create(['name' => 'Cash for Work', 'internal_project_number' => 'HC-2026-062']);

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->set('search', '2026-061')
        ->assertSee('Winter Shelter Kit')
        ->assertDontSee('Cash for Work');
});

test('a placeholder internal number carries the replace chip and a real one does not', function () {
    $user = User::factory()->role('Financial')->create();
    Proposal::factory()->create(['internal_project_number' => 'TEMP-0063']);
    Proposal::factory()->create(['internal_project_number' => 'HC-2026-063']);

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->set('view', 'overview')
        ->assertSeeHtml('<bdi dir="ltr" class="font-semibold tabular-nums">HC-2026-063</bdi>')
        ->assertSeeHtml('data-placeholder-chip="TEMP-0063"')
        ->assertDontSeeHtml('data-placeholder-chip="HC-2026-063"')
        ->assertSee('Placeholder — replace');
});

test('the proposals search hint says it also matches the internal number', function () {
    $user = User::factory()->role('Financial')->create();
    Proposal::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->assertSeeHtml('placeholder="Name, number or notes"');
});
