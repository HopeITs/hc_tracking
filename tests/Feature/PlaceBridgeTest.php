<?php

declare(strict_types=1);

use App\Models\AnnualPlan;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\ReportTemplate;
use App\Support\ProposalLocationRows;
use App\Support\Reporting\PlaceBridge;
use App\Support\Reporting\ReportPlace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->cityId = insertHrCity('Homs '.Str::random(4), 'حمص');
    $this->centerA = insertHrCenter($this->cityId, 'A '.Str::random(4));
    $this->centerB = insertHrCenter($this->cityId, 'B '.Str::random(4));
    $this->centerC = insertHrCenter($this->cityId, 'C '.Str::random(4));
    $this->program = Program::factory()->create();
    $this->proposal = Proposal::factory()->create();
    $this->bridge = new PlaceBridge;
});

/**
 * A location row of the proposal with the given centers.
 *
 * @param  list<int>  $centers
 * @param  array<string, mixed>  $state
 */
function bridgeRow(Proposal $proposal, int $cityId, ?Program $program, array $centers = [], array $state = []): ProposalLocation
{
    $row = ProposalLocation::factory()->create([
        'proposal_id' => $proposal->id,
        'city_id' => $cityId,
        'program_id' => $program?->id,
        ...$state,
    ]);

    foreach ($centers as $center) {
        $row->centers()->create(['center_id' => $center]);
    }

    return $row;
}

/**
 * A proposal target of a project, carrying the place columns it is given
 * (the bridge reads them, never the project).
 *
 * @param  array<string, mixed>  $place  city_id, program_id, center_id, city_wide, all_centers
 */
function bridgeTarget(Proposal $proposal, Project $project, array $place = []): PlanIndicator
{
    return PlanIndicator::factory()->forProposal($proposal)->create(['project_id' => $project->id, ...$place]);
}

it('yields one place per row and center', function (): void {
    bridgeRow($this->proposal, $this->cityId, $this->program, [$this->centerA, $this->centerB]);

    $places = $this->bridge->placesOfProposal($this->proposal);

    expect(collect($places)->map->key()->sort()->values()->all())->toBe(collect([
        $this->cityId.':'.$this->program->id.':c'.$this->centerA,
        $this->cityId.':'.$this->program->id.':c'.$this->centerB,
    ])->sort()->values()->all());
});

it('yields one city-wide place for a city-wide row and one unset place for a row with neither', function (): void {
    $other = Program::factory()->create();
    bridgeRow($this->proposal, $this->cityId, $this->program, [], ['city_wide' => true]);
    bridgeRow($this->proposal, $this->cityId, $other);

    $places = collect($this->bridge->placesOfProposal($this->proposal))->keyBy(fn (ReportPlace $place): string => $place->key());

    expect($places)->toHaveCount(2)
        ->and($places[$this->cityId.':'.$this->program->id.':city-wide']->cityWide)->toBeTrue()
        ->and($places[$this->cityId.':'.$other->id.':unset']->isUnset())->toBeTrue();
});

it('reads the place from the target columns', function (): void {
    $project = Project::factory()->approved()->create(['program_id' => $this->program->id, 'city_id' => $this->cityId, 'center_id' => $this->centerB]);
    $target = bridgeTarget($this->proposal, $project, ['city_id' => $this->cityId, 'program_id' => $this->program->id, 'center_id' => $this->centerB]);

    expect($this->bridge->placeOfTarget($target)?->key())->toBe($this->cityId.':'.$this->program->id.':c'.$this->centerB);
});

it('does not move the place when the project center changes after the target is saved', function (): void {
    $project = Project::factory()->approved()->create(['program_id' => $this->program->id, 'city_id' => $this->cityId, 'center_id' => $this->centerA]);
    $target = bridgeTarget($this->proposal, $project, ['city_id' => $this->cityId, 'program_id' => $this->program->id, 'center_id' => $this->centerA]);

    $project->update(['center_id' => $this->centerC]);

    expect($this->bridge->placeOfTarget($target->fresh())?->centerId)->toBe($this->centerA)
        ->and($this->bridge->targetCoversPlace($target->fresh(), new ReportPlace($this->cityId, $this->program->id, $this->centerC, false)))->toBeFalse();
});

it('is city-wide for a city-wide target', function (): void {
    $project = Project::factory()->approved()->create(['program_id' => $this->program->id, 'city_id' => $this->cityId, 'center_id' => null]);
    $target = bridgeTarget($this->proposal, $project, ['city_id' => $this->cityId, 'program_id' => $this->program->id, 'center_id' => null, 'city_wide' => true]);

    $place = $this->bridge->placeOfTarget($target);

    expect($place?->cityWide)->toBeTrue()->and($place?->centerId)->toBeNull()
        ->and($this->bridge->targetCoversPlace($target, new ReportPlace($this->cityId, $this->program->id, null, true)))->toBeTrue();
});

it('has no single place for a shared target but covers each center of its row', function (): void {
    $project = Project::factory()->approved()->create(['program_id' => $this->program->id, 'city_id' => $this->cityId, 'center_id' => null]);
    bridgeRow($this->proposal, $this->cityId, $this->program, [$this->centerA, $this->centerB], ['project_id' => $project->id]);
    $target = bridgeTarget($this->proposal, $project, ['city_id' => $this->cityId, 'program_id' => $this->program->id, 'center_id' => null, 'all_centers' => true]);

    expect($this->bridge->placeOfTarget($target))->toBeNull()
        ->and($this->bridge->isSharedTarget($target))->toBeTrue()
        ->and($this->bridge->targetCoversPlace($target, new ReportPlace($this->cityId, $this->program->id, $this->centerA, false)))->toBeTrue()
        ->and($this->bridge->targetCoversPlace($target, new ReportPlace($this->cityId, $this->program->id, $this->centerB, false)))->toBeTrue()
        ->and($this->bridge->targetCoversPlace($target, new ReportPlace($this->cityId, $this->program->id, $this->centerC, false)))->toBeFalse();
});

it('covers nothing for a target whose center is not set, and is not shared', function (): void {
    $project = Project::factory()->approved()->create(['program_id' => $this->program->id, 'city_id' => $this->cityId, 'center_id' => null]);
    bridgeRow($this->proposal, $this->cityId, $this->program, [], ['project_id' => $project->id]);
    $target = bridgeTarget($this->proposal, $project, ['city_id' => $this->cityId, 'program_id' => $this->program->id, 'center_id' => null]);

    expect($this->bridge->isSharedTarget($target))->toBeFalse()
        ->and($this->bridge->targetCoversPlace($target, new ReportPlace($this->cityId, $this->program->id, $this->centerA, false)))->toBeFalse()
        ->and($this->bridge->targetCoversPlace($target, new ReportPlace($this->cityId, $this->program->id, null, false)))->toBeFalse();
});

it('has no place for a target without a project', function (): void {
    $target = PlanIndicator::factory()->forProposal($this->proposal)->create(['project_id' => null]);

    expect($this->bridge->placeOfTarget($target))->toBeNull();
});

it('finds the legacy project of a template from its own column or its place row', function (): void {
    $project = Project::factory()->approved()->create(['program_id' => $this->program->id, 'city_id' => $this->cityId, 'center_id' => null]);
    bridgeRow($this->proposal, $this->cityId, $this->program, [$this->centerA], ['project_id' => $project->id]);

    $old = ReportTemplate::factory()->forPlace(new ReportPlace($this->cityId, $this->program->id, $this->centerA, false), $this->proposal)->create(['project_id' => $project->id]);
    $new = ReportTemplate::factory()->forPlace(new ReportPlace($this->cityId, $this->program->id, $this->centerA, false), $this->proposal)->create();
    $unlinked = ReportTemplate::factory()->forPlace(new ReportPlace($this->cityId, $this->program->id, $this->centerB, false), $this->proposal)->create();

    expect($this->bridge->legacyProjectIdFor($old))->toBe($project->id)
        ->and($this->bridge->legacyProjectIdFor($new))->toBe($project->id)
        ->and($this->bridge->legacyProjectIdFor($unlinked))->toBeNull();
});

it('gives a new annual-plan target the place of its project center, by the backfill rule', function (): void {
    $project = Project::factory()->create(['city_id' => $this->cityId, 'program_id' => $this->program->id, 'center_id' => $this->centerA]);
    $plan = AnnualPlan::factory()->create(['program_id' => $this->program->id]);

    expect($this->bridge->placeForTarget($plan, $project->id))->toEqual(new ReportPlace($this->cityId, $this->program->id, $this->centerA, false))
        ->and($this->bridge->placeForTarget($plan, null))->toBeNull();
});

it('gives a proposal target no place when no row of the proposal carries its project (Y-10)', function (): void {
    $project = Project::factory()->create(['city_id' => $this->cityId, 'program_id' => $this->program->id, 'center_id' => $this->centerA]);

    expect($this->bridge->placeForTarget($this->proposal, $project->id))->toBeNull();
});

it('gives a new target the single center, city-wide or shared answer of its proposal row', function (): void {
    $single = Project::factory()->create(['city_id' => $this->cityId, 'program_id' => $this->program->id, 'center_id' => null]);
    $field = Project::factory()->create(['city_id' => $this->cityId, 'program_id' => $this->program->id, 'center_id' => null]);
    $shared = Project::factory()->create(['city_id' => $this->cityId, 'program_id' => $this->program->id, 'center_id' => null]);
    bridgeRow($this->proposal, $this->cityId, $this->program, [$this->centerB], ['project_id' => $single->id]);
    bridgeRow($this->proposal, $this->cityId + 1000, $this->program, [], ['project_id' => $field->id, 'city_wide' => true]);
    bridgeRow($this->proposal, $this->cityId + 2000, $this->program, [$this->centerA, $this->centerC], ['project_id' => $shared->id]);

    $columns = $this->bridge->placeColumnsForTargets($this->proposal, [$single->id, $field->id, $shared->id]);

    expect($this->bridge->placeForTarget($this->proposal, $single->id))->toEqual(new ReportPlace($this->cityId, $this->program->id, $this->centerB, false))
        ->and($this->bridge->placeForTarget($this->proposal, $field->id))->toEqual(new ReportPlace($this->cityId + 1000, $this->program->id, null, true))
        ->and($this->bridge->placeForTarget($this->proposal, $shared->id))->toBeNull()
        ->and($columns[$shared->id])->toMatchArray(['center_id' => null, 'city_wide' => false, 'all_centers' => true]);
});

it('takes the row center over the project center for a proposal target (Y-10)', function (): void {
    $project = Project::factory()->create(['city_id' => $this->cityId, 'program_id' => $this->program->id, 'center_id' => $this->centerA]);
    bridgeRow($this->proposal, $this->cityId, $this->program, [$this->centerB], ['project_id' => $project->id]);

    expect($this->bridge->placeForTarget($this->proposal, $project->id))->toEqual(new ReportPlace($this->cityId, $this->program->id, $this->centerB, false));
});

it('turns a row target shared in the same save when a second center is added (Y-10)', function (): void {
    // Y-17: a second centre reaches the row through the proposal form
    // (ProposalLocationRows::replace), not by linking a second project.
    $row = bridgeRow($this->proposal, $this->cityId, $this->program, [$this->centerA]);
    $target = PlanIndicator::factory()->forProposal($this->proposal)->create([
        'proposal_location_id' => $row->id,
        ...PlanIndicator::placeColumnsForRow($row),
    ]);

    expect($target->refresh()->only(['proposal_location_id', 'center_id', 'all_centers']))->toBe(['proposal_location_id' => $row->id, 'center_id' => $this->centerA, 'all_centers' => false]);

    DB::transaction(fn () => app(ProposalLocationRows::class)->replace($this->proposal, [[
        'city_id' => $this->cityId,
        'program_id' => $this->program->id,
        'responsible_employee_id' => null,
        'center_ids' => [$this->centerA, $this->centerB],
        'is_new' => false,
    ]]));

    expect($target->refresh()->only(['proposal_location_id', 'center_id', 'all_centers']))->toBe([
        'proposal_location_id' => $row->id,
        'center_id' => null,
        'all_centers' => true,
    ])
        ->and($this->bridge->targetCoversPlace($target, new ReportPlace($this->cityId, $this->program->id, $this->centerB, false)))->toBeTrue();
});

it('refuses to remove a row that still has targets (Y-10)', function (): void {
    // Y-17: detachProject went with the link, so the refusal is reached
    // through replace() — the only writer of the set that is left.
    $row = bridgeRow($this->proposal, $this->cityId, $this->program, [$this->centerA]);
    PlanIndicator::factory()->forProposal($this->proposal)->create([
        'proposal_location_id' => $row->id,
        ...PlanIndicator::placeColumnsForRow($row),
    ]);

    expect(fn () => DB::transaction(fn () => app(ProposalLocationRows::class)->replace($this->proposal, [])))
        ->toThrow(ValidationException::class, __('proposals.location_has_targets'));
    expect(ProposalLocation::query()->whereKey($row->id)->exists())->toBeTrue();
});
