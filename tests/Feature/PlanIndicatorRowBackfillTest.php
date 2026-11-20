<?php

declare(strict_types=1);

use App\Console\Commands\BackfillPlanIndicatorRows;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->cityId = insertHrCity('Hama '.Str::random(4), 'حماة');
    $this->centerA = insertHrCenter($this->cityId, 'A '.Str::random(4));
    $this->centerB = insertHrCenter($this->cityId, 'B '.Str::random(4));
    $this->backfill = new BackfillPlanIndicatorRows;
});

/**
 * A proposal target on a project with no row link, like data from before Y-10.
 *
 * @return array{0: PlanIndicator, 1: Proposal, 2: Project}
 */
function unlinkedTarget(int $cityId, ?Proposal $proposal = null, ?Project $project = null, ?Indicator $indicator = null): array
{
    $project ??= Project::factory()->approved()->create(['city_id' => $cityId, 'center_id' => null]);
    $proposal ??= Proposal::factory()->create();
    $target = PlanIndicator::factory()->forProposal($proposal)->create([
        'project_id' => $project->id,
        ...($indicator === null ? [] : ['indicator_id' => $indicator->id]),
    ]);
    DB::table('plan_indicators')->where('id', $target->id)->update(['proposal_location_id' => null]);

    return [$target, $proposal, $project];
}

/**
 * @param  list<int>  $centers
 */
function rowOf(Proposal $proposal, int $cityId, int $programId, ?int $projectId, array $centers = [], bool $cityWide = false): ProposalLocation
{
    $row = ProposalLocation::factory()->create([
        'proposal_id' => $proposal->id,
        'city_id' => $cityId,
        'program_id' => $programId,
        'project_id' => $projectId,
        'city_wide' => $cityWide,
    ]);

    foreach ($centers as $center) {
        $row->centers()->create(['center_id' => $center]);
    }

    return $row;
}

it('links a target to the row that carries its project and places it from the row', function (): void {
    [$target, $proposal, $project] = unlinkedTarget($this->cityId);
    $row = rowOf($proposal, $this->cityId, $project->program_id, $project->id, [$this->centerA, $this->centerB]);

    $this->backfill->apply($this->backfill->plan());

    expect((array) DB::table('plan_indicators')->where('id', $target->id)->first(['proposal_location_id', 'center_id', 'city_wide', 'all_centers', 'project_id']))->toBe([
        'proposal_location_id' => $row->id,
        'center_id' => null,
        'city_wide' => 0,
        'all_centers' => 1,
        'project_id' => $project->id,
    ]);
});

it('falls back to the row with the same city and program', function (): void {
    [$target, $proposal, $project] = unlinkedTarget($this->cityId);
    $other = Project::factory()->approved()->create(['city_id' => $this->cityId, 'program_id' => $project->program_id]);
    $row = rowOf($proposal, $this->cityId, $project->program_id, $other->id, [$this->centerA]);

    $this->backfill->apply($this->backfill->plan());

    expect(DB::table('plan_indicators')->where('id', $target->id)->value('proposal_location_id'))->toBe($row->id)
        ->and(DB::table('plan_indicators')->where('id', $target->id)->value('center_id'))->toBe($this->centerA);
});

it('leaves a duplicate pair unlinked, lists both, and the unique step refuses', function (): void {
    $proposal = Proposal::factory()->create();
    $indicator = Indicator::factory()->create();
    [$first, , $projectA] = unlinkedTarget($this->cityId, $proposal, indicator: $indicator);
    $projectB = Project::factory()->approved()->create(['city_id' => $this->cityId, 'program_id' => $projectA->program_id]);
    [$second] = unlinkedTarget($this->cityId, $proposal, $projectB, $indicator);
    $row = rowOf($proposal, $this->cityId, $projectA->program_id, $projectA->id, [$this->centerA, $this->centerB]);

    $plan = $this->backfill->plan();
    $this->backfill->apply($plan);

    expect($plan['duplicates'])->toBe([$row->id => [$first->id, $second->id]])
        ->and(DB::table('plan_indicators')->whereIn('id', [$first->id, $second->id])->whereNotNull('proposal_location_id')->count())->toBe(0);

    $unique = require database_path('migrations/2026_11_17_152344_add_indicator_location_unique_to_plan_indicators_table.php');
    $unique->down();

    try {
        expect(fn () => $unique->up())->toThrow(RuntimeException::class, (string) $first->id);
        expect(collect(Schema::getIndexes('plan_indicators'))->firstWhere('name', 'plan_indicators_indicator_location_unique'))->toBeNull();
    } finally {
        DB::table('plan_indicators')->whereIn('id', [$first->id, $second->id])->delete();
        $unique->up();
    }
});

it('leaves annual-plan targets and targets with no row alone', function (): void {
    $annual = PlanIndicator::factory()->create();
    [$orphan] = unlinkedTarget($this->cityId);

    $plan = $this->backfill->plan();
    $this->backfill->apply($plan);

    expect($plan['unlinked'])->toBe([$orphan->id])
        ->and(DB::table('plan_indicators')->whereIn('id', [$annual->id, $orphan->id])->whereNotNull('proposal_location_id')->count())->toBe(0);
});

it('prints the same counts on a dry run and writes nothing', function (): void {
    [, $proposal, $project] = unlinkedTarget($this->cityId);
    rowOf($proposal, $this->cityId, $project->program_id, $project->id, [$this->centerA, $this->centerB]);
    [$orphan] = unlinkedTarget($this->cityId);

    $this->artisan('targets:row-backfill', ['--dry-run' => true])
        ->expectsOutput(__('targets.console.row_backfill_targets', ['count' => 2]))
        ->expectsOutput(__('targets.console.row_backfill_linked', ['count' => 1]))
        ->expectsOutput(__('targets.console.row_backfill_places_changed', ['count' => 1]))
        ->expectsOutput(__('targets.console.row_backfill_unlinked', ['count' => 1]))
        ->expectsOutput(__('targets.console.row_backfill_duplicates', ['count' => 0]))
        ->expectsOutput(__('targets.console.row_backfill_unlinked_ids', ['ids' => (string) $orphan->id]))
        ->assertSuccessful();

    expect(DB::table('plan_indicators')->whereNotNull('proposal_location_id')->count())->toBe(0);

    $this->artisan('targets:row-backfill')->assertSuccessful();

    expect(DB::table('plan_indicators')->whereNotNull('proposal_location_id')->count())->toBe(1);
});

it('drops the row link on down and applies again', function (): void {
    [$target, $proposal, $project] = unlinkedTarget($this->cityId);
    $row = rowOf($proposal, $this->cityId, $project->program_id, $project->id, [$this->centerA]);
    $this->backfill->apply($this->backfill->plan());

    $unique = require database_path('migrations/2026_11_17_152344_add_indicator_location_unique_to_plan_indicators_table.php');
    $add = require database_path('migrations/2026_11_17_152342_add_proposal_location_id_to_plan_indicators_table.php');

    try {
        $unique->down();
        $add->down();

        expect(Schema::hasColumn('plan_indicators', 'proposal_location_id'))->toBeFalse()
            ->and(DB::table('plan_indicators')->where('id', $target->id)->exists())->toBeTrue();
    } finally {
        if (! Schema::hasColumn('plan_indicators', 'proposal_location_id')) {
            $add->up();
            $unique->up();
        }
    }

    expect(DB::table('plan_indicators')->where('id', $target->id)->value('proposal_location_id'))->toBe($row->id)
        ->and(collect(Schema::getIndexes('plan_indicators'))->firstWhere('name', 'plan_indicators_indicator_location_unique'))->not->toBeNull();
});
