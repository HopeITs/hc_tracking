<?php

use App\Actions\FundingAgreements\SaveFundingAgreement;
use App\Enums\ProposalStatus;
use App\Models\Activity;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use Database\Seeders\CoreDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

test('a proposal carries its donor and the lifecycle columns', function () {
    $donorId = insertDonor();
    $id = insertProposal(donorId: $donorId);

    $proposal = DB::table('proposals')->find($id);

    expect($proposal->donor_id)->toBe($donorId)
        ->and($proposal->requested_budget)->toBeNull()
        ->and($proposal->requested_currency)->toBeNull()
        ->and($proposal->status_remind_on)->toBeNull()
        ->and($proposal->approval_note)->toBeNull()
        ->and($proposal->final_report_accepted_on)->toBeNull()
        ->and($proposal->final_report_accepted_by)->toBeNull()
        ->and($proposal->previous_proposal_id)->toBeNull();
});

test('a proposal cannot exist without a donor', function () {
    expect(fn () => DB::table('proposals')->insert([
        'name' => 'No donor',
        'slug' => 'no-donor',
        'internal_project_number' => 'HC-2026-777',
        'planned_date_begin' => '2026-01-01',
        'planned_date_end' => '2026-12-31',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('a donor with a proposal cannot be deleted', function () {
    $donorId = insertDonor();
    insertProposal(donorId: $donorId);

    expect(fn () => DB::table('donors')->where('id', $donorId)->delete())->toThrow(QueryException::class);
});

test('a proposal accepts only one funding agreement', function () {
    $donorId = insertDonor();
    $proposalId = insertProposal(donorId: $donorId);
    insertDonorProposal($donorId, $proposalId);

    expect(fn () => insertDonorProposal($donorId, $proposalId))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('an agreement cannot name a donor other than its proposal\'s', function () {
    $proposalId = insertProposal(donorId: insertDonor('UNICEF'));

    expect(fn () => DB::table('donor_proposal')->insert([
        'donor_id' => insertDonor('GIZ'),
        'proposal_id' => $proposalId,
        'budget' => '1000.00',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('the donor of a proposal with an agreement cannot change', function () {
    $donorId = insertDonor();
    $proposalId = insertProposal(donorId: $donorId);
    insertDonorProposal($donorId, $proposalId);

    expect(fn () => DB::table('proposals')->where('id', $proposalId)->update(['donor_id' => insertDonor('GIZ')]))
        ->toThrow(QueryException::class);

    expect(DB::table('proposals')->find($proposalId)->donor_id)->toBe($donorId);
});

test('the unique index and the composite foreign key exist under their names', function () {
    $database = DB::connection()->getDatabaseName();

    $indexes = DB::table('information_schema.statistics')
        ->where('table_schema', $database)
        ->whereIn('index_name', ['proposals_id_donor_id_unique', 'donor_proposal_proposal_id_unique'])
        ->select('index_name as name')
        ->pluck('name')
        ->unique()
        ->all();

    $foreignKey = DB::table('information_schema.referential_constraints')
        ->where('constraint_schema', $database)
        ->where('constraint_name', 'donor_proposal_proposal_donor_foreign')
        ->first();

    expect($indexes)->toEqualCanonicalizing(['proposals_id_donor_id_unique', 'donor_proposal_proposal_id_unique'])
        ->and($foreignKey)->not->toBeNull()
        ->and(strtoupper($foreignKey->delete_rule ?? $foreignKey->DELETE_RULE))->toBe('RESTRICT')
        ->and(strtoupper($foreignKey->update_rule ?? $foreignKey->UPDATE_RULE))->toBe('RESTRICT');
});

test('target beneficiaries on the project link is nullable and readable through the pivot', function () {
    $project = Project::factory()->create();
    $proposal = Proposal::factory()->create();

    $project->proposals()->attach($proposal);

    expect($project->proposals()->first()->pivot->target_beneficiaries)->toBeNull();

    $project->proposals()->updateExistingPivot($proposal->id, ['target_beneficiaries' => 120]);

    expect($project->proposals()->first()->pivot->target_beneficiaries)->toBe(120);
});

test('donors default to not internal and the internal donor is seeded once', function () {
    expect(Donor::factory()->create()->refresh()->is_internal)->toBeFalse();

    $this->seed(CoreDataSeeder::class);
    $this->seed(CoreDataSeeder::class);

    $internal = Donor::query()->where('is_internal', true)->get();

    expect($internal)->toHaveCount(1)
        ->and($internal->first()->name)->toBe('تمويل ذاتي — Hope')
        ->and($internal->first()->isInternal())->toBeTrue();
});

test('milestones carry a nullable proposal and a final report flag', function () {
    expect(Schema::hasColumns('milestones', ['proposal_id', 'is_final_report']))->toBeTrue();

    $milestone = DB::table('milestones')->find(Milestone::factory()->create()->id);

    expect($milestone->proposal_id)->toBeNull()
        ->and((int) $milestone->is_final_report)->toBe(0);
});

test('working days is a nullable JSON list that casts to an array', function () {
    $untouched = Project::factory()->create();

    expect($untouched->fresh()->working_days)->toBeNull();

    $project = Project::factory()->create(['working_days' => [7, 1, 2, 3, 4]]);

    expect($project->fresh()->working_days)->toBe([7, 1, 2, 3, 4]);
});

test('projects gained only the working days column', function () {
    expect(Schema::hasColumn('projects', 'working_days'))->toBeTrue();
});

test('a requested budget never reaches the activity log', function () {
    $proposal = Proposal::factory()->create();

    $this->actingAs(User::factory()->role('Financial')->create());

    $proposal->update(['name' => 'Renamed proposal', 'requested_budget' => '987654.32', 'requested_currency' => 'USD']);

    $logged = Activity::query()->where('subject_type', 'proposal')->get()->toJson();

    expect($logged)->toContain('Renamed proposal')
        ->not->toContain('requested_budget')
        ->not->toContain('987654');
});

test('funding sources are the donor-approved proposals with their donors in a fixed number of queries', function () {
    $project = Project::factory()->create();
    $approved = Proposal::factory()->create(['name' => 'Approved one']);
    $active = Proposal::factory()->create(['name' => 'Active one']);
    $draft = Proposal::factory()->create(['name' => 'Draft one']);
    $project->proposals()->attach([$approved->id, $active->id, $draft->id]);

    DB::table('proposals')->where('id', $approved->id)->update(['status' => ProposalStatus::Approved->value]);
    DB::table('proposals')->where('id', $active->id)->update(['status' => ProposalStatus::Active->value]);

    DB::enableQueryLog();
    $sources = $project->fundingSources();
    $names = $sources->map(fn (Proposal $proposal): string => $proposal->donor->name)->all();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($sources->pluck('name')->all())->toBe(['Active one', 'Approved one'])
        ->and($names)->toHaveCount(2)
        ->and($queries)->toBe(2)
        ->and($sources->first()->getAttributes())->not->toHaveKey('requested_budget')
        ->and($sources->first()->relationLoaded('donorProposals'))->toBeFalse();
});

test('the funding sources panel shows donor names to everyone but amounts only to money holders', function () {
    $project = Project::factory()->create();
    $proposal = Proposal::factory()->create(['name' => 'Harbour Clean Water', 'donor_id' => Donor::factory()->create(['name' => 'Aurora Foundation'])]);
    $project->proposals()->attach($proposal);
    DB::table('proposals')->where('id', $proposal->id)->update(['status' => ProposalStatus::Approved->value]);
    DonorProposal::factory()->create(['proposal_id' => $proposal->id, 'budget' => '731942.00']);

    $managers = Livewire::actingAs(userWithRole('Managers'))
        ->test('pages::projects.show', ['project' => $project])
        ->assertSee('Funding sources')
        ->assertSee('Aurora Foundation')
        ->assertDontSee('731,942');

    expect($managers->instance()->fundingSources->first()->relationLoaded('donorProposals'))->toBeFalse();

    Livewire::actingAs(userWithRole('Financial'))
        ->test('pages::projects.show', ['project' => $project])
        ->assertSee('Aurora Foundation')
        ->assertSee('731,942');
});

test('creating a proposal requires a donor', function () {
    $user = User::factory()->role('Financial')->create();
    $donor = Donor::factory()->create();

    $form = Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('openCreateModal')
        ->set('name', 'Donorless')
        ->set('internalProjectNumber', 'HC-2026-044')
        ->set('proposalStatus', ProposalStatus::ConceptNote->value)
        ->set('plannedDateBegin', '2026-01-01')
        ->set('plannedDateEnd', '2026-12-31')
        ->call('saveProposal')
        ->assertHasErrors(['proposalDonorId'])
        ->assertSee('Choose the donor.');

    expect(Proposal::query()->where('name', 'Donorless')->exists())->toBeFalse();

    $form->set('proposalDonorId', (string) $donor->id)->call('saveProposal')->assertHasNoErrors();

    expect(Proposal::query()->where('name', 'Donorless')->sole()->donor_id)->toBe($donor->id);
});

test('an agreement takes its donor from the proposal, never from the request', function () {
    $proposal = Proposal::factory()->create();
    $other = Donor::factory()->create();

    $this->actingAs(User::factory()->role('Financial')->create());

    $agreement = app(SaveFundingAgreement::class)->handle(null, [
        'agreementDonorId' => (string) $other->id,
        'agreementProposalId' => (string) $proposal->id,
        'budget' => '1000.00',
        'agreementCurrency' => 'USD',
        'externalProjectNumber' => '',
    ]);

    expect($agreement->donor_id)->toBe($proposal->donor_id);
});
