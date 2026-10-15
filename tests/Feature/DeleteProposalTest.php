<?php

use App\Actions\Proposals\DeleteProposal;
use App\Enums\ResultType;
use App\Models\Activity;
use App\Models\DonorProposal;
use App\Models\Indicator;
use App\Models\Milestone;
use App\Models\PlanIndicator;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Result;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->role('Financial')->create());
    $this->proposal = Proposal::factory()->create(['name' => 'Winter Response']);
});

/**
 * A four-level framework on the proposal, stored as data already on file.
 *
 * @return array{impact: Result, outcome: Result, output: Result, activity: Result}
 */
function proposalFrameworkOnFile(Proposal $proposal): array
{
    $impact = Result::factory()->type(ResultType::Impact)->create(['proposal_id' => $proposal->id]);
    $outcome = Result::factory()->type(ResultType::Outcome)->childOf($impact)->create(['proposal_id' => $proposal->id]);
    $output = Result::factory()->type(ResultType::Output)->childOf($outcome)->create(['proposal_id' => $proposal->id]);
    $activity = Result::factory()->activity($output)->create();

    return compact('impact', 'outcome', 'output', 'activity');
}

/**
 * The validation messages a refused delete raised, keyed by field.
 *
 * @return array<string, list<string>>
 */
function proposalDeleteRefusal(Closure $call): array
{
    try {
        $call();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('The call was not refused.');
}

test('deleting a proposal removes its framework, targets and milestone links, with one activity', function () {
    $framework = proposalFrameworkOnFile($this->proposal);
    $indicator = Indicator::factory()->create(['result_id' => $framework['output']->id]);
    $target = PlanIndicator::factory()->forProposal($this->proposal)->create(['indicator_id' => $indicator->id]);
    $milestone = Milestone::factory()->create();
    $milestone->forceFill(['result_id' => $framework['activity']->id])->save();
    $activitiesBefore = Activity::query()->count();

    app(DeleteProposal::class)->handle($this->proposal);

    $this->assertDatabaseMissing('proposals', ['id' => $this->proposal->id]);
    expect(Result::query()->where('proposal_id', $this->proposal->id)->count())->toBe(0)
        ->and(Indicator::query()->whereKey($indicator->id)->exists())->toBeFalse()
        ->and(PlanIndicator::query()->whereKey($target->id)->exists())->toBeFalse()
        ->and($milestone->refresh()->result_id)->toBeNull()
        ->and(Activity::query()->count() - $activitiesBefore)->toBe(1)
        ->and(Activity::query()->latest('id')->value('event'))->toBe('proposal.deleted');
});

test('a proposal whose framework has reported targets is not deleted', function () {
    $framework = proposalFrameworkOnFile($this->proposal);
    $indicator = Indicator::factory()->create(['result_id' => $framework['outcome']->id]);
    $target = PlanIndicator::factory()->forProposal($this->proposal)->create(['indicator_id' => $indicator->id]);
    Report::factory()->create(['plan_indicator_id' => $target->id]);

    expect(proposalDeleteRefusal(fn () => app(DeleteProposal::class)->handle($this->proposal)))
        ->toBe(['proposal' => [__('results_framework.proposal_delete_reported')]]);

    $this->assertDatabaseHas('proposals', ['id' => $this->proposal->id]);
    expect(Result::query()->where('proposal_id', $this->proposal->id)->count())->toBe(4);
});

test('a proposal with a funding agreement is still not deleted', function () {
    DonorProposal::factory()->create(['proposal_id' => $this->proposal->id]);

    expect(proposalDeleteRefusal(fn () => app(DeleteProposal::class)->handle($this->proposal)))
        ->toBe(['proposal' => [__('proposals.this_proposal_has_a_funding_agreement_remove_the_agreement_f')]]);

    $this->assertDatabaseHas('proposals', ['id' => $this->proposal->id]);
});

test('a second delete of the same proposal returns quietly', function () {
    proposalFrameworkOnFile($this->proposal);

    app(DeleteProposal::class)->handle($this->proposal);
    app(DeleteProposal::class)->handle($this->proposal);

    expect(Activity::query()->where('event', 'proposal.deleted')->count())->toBe(1);
});

test('deleting a proposal needs proposals.delete', function () {
    $this->actingAs(User::factory()->role('MEAL')->create());

    expect(fn () => app(DeleteProposal::class)->handle($this->proposal))->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('proposals', ['id' => $this->proposal->id]);
});

test('the register deletes through the action and explains a refusal', function () {
    $framework = proposalFrameworkOnFile($this->proposal);
    $target = PlanIndicator::factory()->forProposal($this->proposal)->create([
        'indicator_id' => Indicator::factory()->create(['result_id' => $framework['output']->id])->id,
    ]);
    Report::factory()->create(['plan_indicator_id' => $target->id]);

    Livewire::test('pages::proposals.index')
        ->call('confirmDelete', $this->proposal->id)
        ->call('deleteProposal')
        ->assertSet('showDeleteModal', false);

    $this->assertDatabaseHas('proposals', ['id' => $this->proposal->id]);

    $clean = Proposal::factory()->create(['name' => 'Spring Response']);
    proposalFrameworkOnFile($clean);

    Livewire::test('pages::proposals.index')
        ->call('confirmDelete', $clean->id)
        ->call('deleteProposal');

    $this->assertDatabaseMissing('proposals', ['id' => $clean->id]);
});
