<?php

declare(strict_types=1);

use App\Models\PlanIndicator;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\Report;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->proposal = Proposal::factory()->create(['name' => 'Winter Response']);
    $this->targetsUrl = route('proposals.show', ['proposal' => $this->proposal, 'tab' => 'targets']);
});

/**
 * Two targets on a location row of the proposal, the first optionally reported.
 */
function ltdTargets(Proposal $proposal, ProposalLocation $row, bool $reported = false): void
{
    $targets = PlanIndicator::factory()->forProposal($proposal)->count(2)->create(['proposal_location_id' => $row->id]);

    if ($reported) {
        Report::factory()->create(['plan_indicator_id' => $targets->first()->id]);
    }
}

/**
 * The index of a row in the open proposal form.
 */
function ltdFormIndex(array $locations, ProposalLocation $row): int
{
    return (int) collect($locations)->search(fn (array $location): bool => (int) $location['location_id'] === $row->id);
}

describe('removing a location row in the proposal form', function (): void {
    beforeEach(function (): void {
        $this->withTargets = ProposalLocation::factory()->create(['proposal_id' => $this->proposal->id, 'project_id' => null, 'sort' => 0]);
        $this->bare = ProposalLocation::factory()->create(['proposal_id' => $this->proposal->id, 'project_id' => null, 'sort' => 1]);
        ltdTargets($this->proposal, $this->withTargets);
    });

    it('asks first when the row has targets, and offers to delete them or open them', function (): void {
        $this->actingAs(User::factory()->role('General Manager')->create());
        $form = Livewire::test('pages::proposals.index')->call('openEditModal', $this->proposal->id);
        $index = ltdFormIndex($form->get('locations'), $this->withTargets);

        $form->call('removeLocationRow', $index)
            ->assertSet('showLocationTargetsModal', true)
            ->assertCount('locations', 2)
            ->assertSeeText(trans_choice('proposals.location_targets_body', 2, ['count' => 2]))
            ->assertSeeHtml('href="'.$this->targetsUrl.'"')
            ->assertSee(__('proposals.delete_targets_and_remove'))
            ->call('clearRemovingLocationTargets')
            ->assertSet('showLocationTargetsModal', false)
            ->assertCount('locations', 1);

        expect(PlanIndicator::query()->where('proposal_location_id', $this->withTargets->id)->count())->toBe(0);
    });

    it('removes a row without targets straight away', function (): void {
        $this->actingAs(User::factory()->role('General Manager')->create());
        $form = Livewire::test('pages::proposals.index')->call('openEditModal', $this->proposal->id);

        $form->call('removeLocationRow', ltdFormIndex($form->get('locations'), $this->bare))
            ->assertSet('showLocationTargetsModal', false)
            ->assertCount('locations', 1);
    });

    it('tells a user who cannot delete targets to ask MEAL, and refuses the delete', function (): void {
        $this->actingAs(User::factory()->role('Financial')->create());
        $form = Livewire::test('pages::proposals.index')->call('openEditModal', $this->proposal->id);

        $form->call('removeLocationRow', ltdFormIndex($form->get('locations'), $this->withTargets))
            ->assertSet('showLocationTargetsModal', true)
            ->assertSeeText(__('proposals.location_targets_no_right'))
            ->assertDontSee(__('proposals.delete_targets_and_remove'))
            ->call('clearRemovingLocationTargets')
            ->assertForbidden();

        expect(PlanIndicator::query()->where('proposal_location_id', $this->withTargets->id)->count())->toBe(2);
    });

    it('says which targets have reports and keeps them', function (): void {
        $this->actingAs(User::factory()->role('General Manager')->create());
        PlanIndicator::query()->where('proposal_location_id', $this->withTargets->id)->delete();
        ltdTargets($this->proposal, $this->withTargets, reported: true);
        $form = Livewire::test('pages::proposals.index')->call('openEditModal', $this->proposal->id);

        $form->call('removeLocationRow', ltdFormIndex($form->get('locations'), $this->withTargets))
            ->assertSeeText(trans_choice('proposals.location_targets_reported', 1, ['count' => 1]))
            ->assertDontSee(__('proposals.delete_targets_and_remove'));
    });
});

describe('deleting a proposal that has targets', function (): void {
    it('says how many targets go with it and links to them', function (): void {
        $this->actingAs(User::factory()->role('Financial')->create());
        $row = ProposalLocation::factory()->create(['proposal_id' => $this->proposal->id]);
        ltdTargets($this->proposal, $row);

        Livewire::test('pages::proposals.index')
            ->call('confirmDelete', $this->proposal->id)
            ->assertSeeText(trans_choice('proposals.proposal_delete_targets', 2, ['count' => 2]))
            ->assertSeeHtml('href="'.$this->targetsUrl.'"');
    });

    it('says when reported targets keep the proposal from being deleted', function (): void {
        $this->actingAs(User::factory()->role('Financial')->create());
        $row = ProposalLocation::factory()->create(['proposal_id' => $this->proposal->id]);
        ltdTargets($this->proposal, $row, reported: true);

        Livewire::test('pages::proposals.index')
            ->call('confirmDelete', $this->proposal->id)
            ->assertSeeText(trans_choice('proposals.proposal_delete_reported_targets', 1, ['count' => 1]));
    });
});
