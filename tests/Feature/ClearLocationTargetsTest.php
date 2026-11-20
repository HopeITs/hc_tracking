<?php

declare(strict_types=1);

use App\Actions\Targets\ClearLocationTargets;
use App\Models\PlanIndicator;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->proposal = Proposal::factory()->create();
    $this->row = ProposalLocation::factory()->create(['proposal_id' => $this->proposal->id]);
    $this->targets = PlanIndicator::factory()->forProposal($this->proposal)->count(2)->create(['proposal_location_id' => $this->row->id]);
});

it('deletes the targets of a location row and records one summary', function (): void {
    $this->actingAs(User::factory()->role('General Manager')->create());
    $other = ProposalLocation::factory()->create(['proposal_id' => $this->proposal->id]);
    $kept = PlanIndicator::factory()->forProposal($this->proposal)->create(['proposal_location_id' => $other->id]);

    expect(app(ClearLocationTargets::class)->handle($this->proposal, $this->row->id))->toBe(2)
        ->and(PlanIndicator::query()->where('proposal_location_id', $this->row->id)->count())->toBe(0)
        ->and(PlanIndicator::query()->whereKey($kept->id)->exists())->toBeTrue()
        ->and(DB::table('activities')->where('event', 'proposal.targets_saved')->count())->toBe(1);
});

it('refuses when a target of the row has reports, and deletes nothing', function (): void {
    $user = User::factory()->role('General Manager')->create();
    $this->actingAs($user);
    insertReport($this->targets->first()->id, $user->id);

    expect(fn () => app(ClearLocationTargets::class)->handle($this->proposal, $this->row->id))
        ->toThrow(ValidationException::class, __('targets.location_targets_reported'));
    expect(PlanIndicator::query()->where('proposal_location_id', $this->row->id)->count())->toBe(2);
});

it('is refused to a role that cannot delete targets', function (): void {
    $this->actingAs(User::factory()->role('Public Relations Manager')->create());

    app(ClearLocationTargets::class)->handle($this->proposal, $this->row->id);
})->throws(AuthorizationException::class);

it('never reaches a row of another proposal', function (): void {
    $this->actingAs(User::factory()->role('General Manager')->create());
    $foreign = ProposalLocation::factory()->create();

    app(ClearLocationTargets::class)->handle($this->proposal, $foreign->id);
})->throws(ModelNotFoundException::class);
