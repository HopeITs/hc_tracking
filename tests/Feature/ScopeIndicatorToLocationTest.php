<?php

declare(strict_types=1);

use App\Actions\Targets\ScopeIndicatorToLocation;
use App\Enums\ResultType;
use App\Models\Activity;
use App\Models\AnnualPlan;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\Result;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Y-16 slice 1 (D-93): the row-keyed twin of ScopeIndicatorToProject. Scoping
 * a program-framework indicator to one place of a proposal writes
 * `indicators.proposal_location_id`, with the same guards and the same
 * messages as its project twin.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->meal = mealManager();
    $this->actingAs($this->meal);

    $this->program = Program::factory()->create();
    $this->row = ProposalLocation::factory()->create(['program_id' => $this->program->id]);
    $this->programNode = Result::factory()->create(['type' => ResultType::Output, 'program_id' => $this->program->id]);
    $this->indicator = Indicator::factory()->create(['result_id' => $this->programNode->id]);
});

test('it scopes a program-framework indicator to the row', function () {
    $scoped = app(ScopeIndicatorToLocation::class)->handle($this->row, $this->indicator->id);

    expect($scoped->proposal_location_id)->toBe($this->row->id)
        ->and(DB::table('indicators')->where('id', $this->indicator->id)->value('proposal_location_id'))->toBe($this->row->id);
});

test('scoping an indicator twice to the same row changes nothing', function () {
    app(ScopeIndicatorToLocation::class)->handle($this->row, $this->indicator->id);
    $before = Activity::query()->count();

    app(ScopeIndicatorToLocation::class)->handle($this->row, $this->indicator->id);

    expect(Activity::query()->count())->toBe($before);
});

test('a row outside the result program is refused', function () {
    $elsewhere = ProposalLocation::factory()->create(['program_id' => Program::factory()->create()->id]);

    expect(fn () => app(ScopeIndicatorToLocation::class)->handle($elsewhere, $this->indicator->id))
        ->toThrow(ValidationException::class, __('indicators.this_project_does_not_deliver_the_result_program'))
        ->and(DB::table('indicators')->where('id', $this->indicator->id)->value('proposal_location_id'))->toBeNull();
});

test('a row still waiting for its program is refused', function () {
    $waiting = ProposalLocation::factory()->create(['program_id' => null]);

    expect(fn () => app(ScopeIndicatorToLocation::class)->handle($waiting, $this->indicator->id))
        ->toThrow(ValidationException::class, __('indicators.this_project_does_not_deliver_the_result_program'));
});

test('an indicator of a proposal framework is not found (BOLA)', function () {
    $proposalNode = Result::factory()->create([
        'type' => ResultType::Output,
        'program_id' => $this->program->id,
        'proposal_id' => Proposal::factory()->create()->id,
    ]);
    $instance = Indicator::factory()->create(['result_id' => $proposalNode->id]);

    expect(fn () => app(ScopeIndicatorToLocation::class)->handle($this->row, $instance->id))
        ->toThrow(ModelNotFoundException::class);
});

test('a change that would break a committed target is refused', function () {
    // The target sits on an annual plan of another program, so moving the
    // indicator onto this row introduces issue_project_outside_plan_program.
    $plan = AnnualPlan::factory()->create(['program_id' => Program::factory()->create()->id]);
    PlanIndicator::factory()->forAnnualPlan($plan)->create(['indicator_id' => $this->indicator->id]);

    expect(fn () => app(ScopeIndicatorToLocation::class)->handle($this->row, $this->indicator->id))
        ->toThrow(ValidationException::class)
        ->and(DB::table('indicators')->where('id', $this->indicator->id)->value('proposal_location_id'))->toBeNull();
});

test('a user without indicators.update gets a 403', function () {
    $this->actingAs(userWithRole('Team Member'));

    expect(fn () => app(ScopeIndicatorToLocation::class)->handle($this->row, $this->indicator->id))
        ->toThrow(AuthorizationException::class)
        ->and(DB::table('indicators')->where('id', $this->indicator->id)->value('proposal_location_id'))->toBeNull();
});
