<?php

use App\Enums\Currency;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Models\Activity;
use App\Models\Donor;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-10-14 10:00:00');
    $this->user = User::factory()->role('General Manager')->create();
    $this->project = Project::factory()->status(ProjectStatus::Active)->create([
        'program_id' => Program::factory()->create()->id,
        'center_id' => insertHrCenter(insertHrCity()),
    ]);
    $this->previous = Activity::withoutRecording(function (): Proposal {
        $proposal = Proposal::factory()->running()->create([
            'name' => 'Winter Shelter 2026',
            'internal_project_number' => 'HC-2026-410',
            'planned_date_begin' => '2026-01-01',
            'planned_date_end' => '2026-12-31',
            'requested_budget' => '987654.00',
            'requested_currency' => Currency::Usd,
            'donor_id' => Donor::factory()->create(['name' => 'Hope Donor']),
        ]);
        $proposal->projects()->attach($this->project->id, ['target_beneficiaries' => 300]);

        return $proposal;
    });
});

test('the create form offers the renewable proposals and previews what is copied', function () {
    Proposal::factory()->create(['internal_project_number' => 'HC-2026-411']);

    $form = Livewire::actingAs($this->user)->test('pages::proposals.index')->call('openCreateModal');

    expect(collect($form->get('renewableProposals'))->pluck('id')->all())->toBe([$this->previous->id]);

    $form->set('previousProposalId', (string) $this->previous->id)
        ->assertSet('name', 'Winter Shelter 2026')
        ->assertSet('plannedDateBegin', '2027-01-01')
        ->assertSet('plannedDateEnd', '2027-12-31')
        ->assertSet('internalProjectNumber', '')
        ->assertSee('Will be copied')
        ->assertSee('Will not be copied')
        ->assertDontSee('987654');
});

test('saving a renewal goes through the clone and lands a linked concept note', function () {
    $form = Livewire::actingAs($this->user)->test('pages::proposals.index')
        ->call('openCreateModal')
        ->set('previousProposalId', (string) $this->previous->id)
        ->set('internalProjectNumber', 'HC-2027-100')
        ->call('saveProposal')
        ->assertHasNoErrors();

    $renewal = Proposal::query()->where('internal_project_number', 'HC-2027-100')->sole();

    expect($renewal->previous_proposal_id)->toBe($this->previous->id)
        ->and($renewal->status)->toBe(ProposalStatus::ConceptNote)
        ->and($renewal->projects()->pluck('projects.id')->all())->toBe([$this->project->id])
        ->and($renewal->donor_id)->toBe($this->previous->donor_id);

    $form->assertSet('showProposalModal', false);
});

test('a proposal that cannot be renewed is refused', function () {
    $concept = Proposal::factory()->create();

    Livewire::actingAs($this->user)->test('pages::proposals.index')
        ->call('openCreateModal')
        ->set('previousProposalId', (string) $concept->id)
        ->assertSet('previousProposalId', '')
        ->set('internalProjectNumber', 'HC-2027-101')
        ->assertHasNoErrors();

    expect(DB::table('proposals')->whereNotNull('previous_proposal_id')->count())->toBe(0);
});

test('the proposal page shows the renewal chain in both directions', function () {
    $renewal = Proposal::factory()->create(['previous_proposal_id' => $this->previous->id, 'internal_project_number' => 'HC-2027-102']);

    Livewire::actingAs($this->user)->test('pages::proposals.show', ['proposal' => $this->previous])
        ->assertSeeHtml('data-test="renewal-chain-next"')
        ->assertSee('HC-2027-102')
        ->assertDontSeeHtml('data-test="renewal-chain-previous"');

    Livewire::actingAs($this->user)->test('pages::proposals.show', ['proposal' => $renewal])
        ->assertSeeHtml('data-test="renewal-chain-previous"')
        ->assertSee('HC-2026-410');
});

test('a proposal outside any chain shows no chain', function () {
    Livewire::actingAs($this->user)->test('pages::proposals.show', ['proposal' => $this->previous])
        ->assertDontSeeHtml('data-test="renewal-chain"');
});

test('a removed predecessor shows as removed and the renewal survives', function () {
    $renewal = Proposal::factory()->create(['previous_proposal_id' => $this->previous->id]);
    Activity::query()->create([
        'actor_id' => $this->user->id,
        'subject_type' => 'proposal',
        'subject_id' => $renewal->id,
        'module' => 'proposals',
        'event' => 'proposal.created',
        'changes' => [],
        'properties' => ['renewed_from' => $this->previous->id],
    ]);

    DB::table('project_proposal')->where('proposal_id', $this->previous->id)->delete();
    DB::table('proposals')->where('id', $this->previous->id)->delete();

    Livewire::actingAs($this->user)->test('pages::proposals.show', ['proposal' => $renewal])
        ->assertSeeHtml('data-test="renewal-chain-removed"');

    expect(DB::table('proposals')->where('id', $renewal->id)->value('previous_proposal_id'))->toBeNull();
});

test('the project page lists its funding history oldest first without amounts', function () {
    $renewal = Activity::withoutRecording(function (): Proposal {
        $proposal = Proposal::factory()->create([
            'name' => 'Winter Shelter 2027',
            'internal_project_number' => 'HC-2027-103',
            'planned_date_begin' => '2027-01-01',
            'planned_date_end' => '2027-12-31',
            'previous_proposal_id' => $this->previous->id,
            'requested_budget' => '555555.00',
            'requested_currency' => Currency::Usd,
        ]);
        $proposal->projects()->attach($this->project->id);

        return $proposal;
    });

    $page = Livewire::actingAs($this->user)->test('pages::projects.show', ['project' => $this->project])->set('tab', 'proposals');

    expect($page->get('fundingHistory')->pluck('id')->all())->toBe([$this->previous->id, $renewal->id]);

    $page->assertSee('Funding history')
        ->assertSee('Hope Donor')
        ->assertSee('Renews HC-2026-410')
        ->assertDontSee('987654')
        ->assertDontSee('555555');
});

test('the funding history is a fixed number of queries', function () {
    $count = function (): int {
        $page = Livewire::actingAs($this->user)->test('pages::projects.show', ['project' => $this->project]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $page->get('fundingHistory');

        return count(DB::getQueryLog());
    };

    $proposal = Proposal::factory()->create(['previous_proposal_id' => $this->previous->id]);
    $proposal->projects()->attach($this->project->id);
    $before = $count();

    foreach (range(1, 4) as $index) {
        $proposal = Proposal::factory()->create(['previous_proposal_id' => $this->previous->id]);
        $proposal->projects()->attach($this->project->id);
    }

    expect($count())->toBe($before);
});
