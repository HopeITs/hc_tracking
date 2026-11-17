<?php

declare(strict_types=1);

use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\ReportTemplate;
use App\Support\Reporting\PlaceBridge;
use App\Support\Reporting\ReportPlace;

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
 * A proposal target of a project carried by the row.
 */
function bridgeTarget(Proposal $proposal, Project $project): PlanIndicator
{
    return PlanIndicator::factory()->forProposal($proposal)->create(['project_id' => $project->id]);
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

it('takes the single center of the target row when its project has none', function (): void {
    $project = Project::factory()->approved()->create(['program_id' => $this->program->id, 'city_id' => $this->cityId, 'center_id' => null]);
    bridgeRow($this->proposal, $this->cityId, $this->program, [$this->centerA], ['project_id' => $project->id]);

    $place = $this->bridge->placeOfTarget(bridgeTarget($this->proposal, $project));

    expect($place?->key())->toBe($this->cityId.':'.$this->program->id.':c'.$this->centerA);
});

it('takes the project center when it has one', function (): void {
    $project = Project::factory()->approved()->create(['program_id' => $this->program->id, 'city_id' => $this->cityId, 'center_id' => $this->centerB]);

    $place = $this->bridge->placeOfTarget(bridgeTarget($this->proposal, $project));

    expect($place?->centerId)->toBe($this->centerB);
});

it('is city-wide for the target of a city-wide row', function (): void {
    $project = Project::factory()->approved()->create(['program_id' => $this->program->id, 'city_id' => $this->cityId, 'center_id' => null]);
    bridgeRow($this->proposal, $this->cityId, $this->program, [], ['project_id' => $project->id, 'city_wide' => true]);

    $place = $this->bridge->placeOfTarget(bridgeTarget($this->proposal, $project));

    expect($place?->cityWide)->toBeTrue()->and($place?->centerId)->toBeNull();
});

it('has no single place for a shared target but covers each center of its row', function (): void {
    $project = Project::factory()->approved()->create(['program_id' => $this->program->id, 'city_id' => $this->cityId, 'center_id' => null]);
    bridgeRow($this->proposal, $this->cityId, $this->program, [$this->centerA, $this->centerB], ['project_id' => $project->id]);
    $target = bridgeTarget($this->proposal, $project);

    expect($this->bridge->placeOfTarget($target))->toBeNull()
        ->and($this->bridge->targetCoversPlace($target, new ReportPlace($this->cityId, $this->program->id, $this->centerA, false)))->toBeTrue()
        ->and($this->bridge->targetCoversPlace($target, new ReportPlace($this->cityId, $this->program->id, $this->centerB, false)))->toBeTrue()
        ->and($this->bridge->targetCoversPlace($target, new ReportPlace($this->cityId, $this->program->id, $this->centerC, false)))->toBeFalse();
});

it('has no place for a target whose row has no center', function (): void {
    $project = Project::factory()->approved()->create(['program_id' => $this->program->id, 'city_id' => $this->cityId, 'center_id' => null]);
    bridgeRow($this->proposal, $this->cityId, $this->program, [], ['project_id' => $project->id]);
    $target = bridgeTarget($this->proposal, $project);

    expect($this->bridge->placeOfTarget($target))->toBeNull()
        ->and($this->bridge->targetCoversPlace($target, new ReportPlace($this->cityId, $this->program->id, $this->centerA, false)))->toBeFalse();
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
