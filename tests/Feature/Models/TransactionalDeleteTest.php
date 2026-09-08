<?php

use App\Models\DonorProposal;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Proposal;
use App\Models\TransfersTracking;
use App\Models\YearlyPlan;
use Illuminate\Database\QueryException;

test('a proposal delete that is blocked leaves its targets untouched', function () {
    $proposal = Proposal::factory()->create();
    $target = PlanIndicator::factory()->forProposal($proposal)->create();
    DonorProposal::factory()->create(['proposal_id' => $proposal->id]);

    expect(fn () => $proposal->delete())->toThrow(QueryException::class);

    $this->assertDatabaseHas('proposals', ['id' => $proposal->id]);
    $this->assertDatabaseHas('plan_indicators', ['id' => $target->id]);
});

test('money already received survives an attempt to delete the proposal', function () {
    $proposal = Proposal::factory()->create();
    $agreement = DonorProposal::factory()->create(['proposal_id' => $proposal->id]);
    $tranche = TransfersTracking::factory()->received()->create(['donor_proposal_id' => $agreement->id]);

    expect(fn () => $proposal->delete())->toThrow(QueryException::class);

    $this->assertDatabaseHas('transfers_tracking', ['id' => $tranche->id]);
    $this->assertDatabaseHas('donor_proposal', ['id' => $agreement->id]);
});

test('a proposal with no funding still deletes and cleans its targets', function () {
    $proposal = Proposal::factory()->create();
    $target = PlanIndicator::factory()->forProposal($proposal)->create();

    expect($proposal->delete())->toBeTrue();

    $this->assertDatabaseMissing('proposals', ['id' => $proposal->id]);
    $this->assertDatabaseMissing('plan_indicators', ['id' => $target->id]);
});

test('a program delete that fails rolls back its yearly plan cleanup', function () {
    $program = Program::factory()->create();
    $plan = YearlyPlan::factory()->create(['program_id' => $program->id, 'year' => 2026]);
    $target = PlanIndicator::factory()->forYearlyPlan($plan)->create();

    // Force the delete of the program row itself to fail after the observer has
    // already removed the yearly plan and its target.
    Program::deleting(function () use ($plan, $target): void {
        // Prove the observer has already run, so the rollback below is real.
        expect(YearlyPlan::query()->whereKey($plan->id)->exists())->toBeFalse()
            ->and(PlanIndicator::query()->whereKey($target->id)->exists())->toBeFalse();

        throw new RuntimeException('simulated failure after cleanup');
    });

    expect(fn () => $program->delete())->toThrow(RuntimeException::class);

    $this->assertDatabaseHas('programs', ['id' => $program->id]);
    $this->assertDatabaseHas('yearly_plans', ['id' => $plan->id]);
    $this->assertDatabaseHas('plan_indicators', ['id' => $target->id]);
});
