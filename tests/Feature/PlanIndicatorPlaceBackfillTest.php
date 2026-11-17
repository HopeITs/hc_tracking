<?php

declare(strict_types=1);

use App\Console\Commands\PlaceBackfillPlanIndicators;
use App\Models\AnnualPlan;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->cityId = insertHrCity('Idlib '.Str::random(4), 'إدلب');
    $this->centerA = insertHrCenter($this->cityId, 'A '.Str::random(4));
    $this->centerB = insertHrCenter($this->cityId, 'B '.Str::random(4));
    $this->backfill = new PlaceBackfillPlanIndicators;
});

/**
 * A proposal target on a project, with the place columns cleared like legacy data.
 *
 * @param  array<string, mixed>  $project
 * @return array{0: PlanIndicator, 1: Proposal, 2: Project}
 */
function legacyTarget(int $cityId, array $project = []): array
{
    $project = Project::factory()->approved()->create(['city_id' => $cityId, 'center_id' => null, ...$project]);
    $proposal = Proposal::factory()->create();
    $target = PlanIndicator::factory()->forProposal($proposal)->create(['project_id' => $project->id]);
    clearTargetPlace($target);

    return [$target, $proposal, $project];
}

function clearTargetPlace(PlanIndicator $target): void
{
    DB::table('plan_indicators')->where('id', $target->id)->update(['city_id' => null, 'program_id' => null, 'center_id' => null, 'city_wide' => false, 'all_centers' => false]);
}

/**
 * @param  list<int>  $centers
 */
function targetRow(Proposal $proposal, Project $project, array $centers = [], bool $cityWide = false): void
{
    $row = ProposalLocation::factory()->create([
        'proposal_id' => $proposal->id,
        'city_id' => $project->city_id,
        'program_id' => $project->program_id,
        'project_id' => $project->id,
        'city_wide' => $cityWide,
    ]);

    foreach ($centers as $center) {
        $row->centers()->create(['center_id' => $center]);
    }
}

/**
 * @return array<string, mixed>
 */
function targetPlaceColumns(PlanIndicator $target): array
{
    return (array) DB::table('plan_indicators')->where('id', $target->id)->first(['city_id', 'program_id', 'center_id', 'city_wide', 'all_centers']);
}

it('gives a target the city, program and center of its project', function (): void {
    [$target, , $project] = legacyTarget($this->cityId, ['center_id' => $this->centerA]);

    $this->backfill->apply($this->backfill->plan());

    expect(targetPlaceColumns($target))->toBe([
        'city_id' => $this->cityId,
        'program_id' => $project->program_id,
        'center_id' => $this->centerA,
        'city_wide' => 0,
        'all_centers' => 0,
    ]);
});

it('takes the single center of the location row when the project has none', function (): void {
    [$target, $proposal, $project] = legacyTarget($this->cityId);
    targetRow($proposal, $project, [$this->centerB]);

    $this->backfill->apply($this->backfill->plan());

    expect(targetPlaceColumns($target))->toMatchArray(['center_id' => $this->centerB, 'city_wide' => 0, 'all_centers' => 0]);
});

it('makes a shared target when the row has several centers', function (): void {
    [$target, $proposal, $project] = legacyTarget($this->cityId);
    targetRow($proposal, $project, [$this->centerA, $this->centerB]);

    $plan = $this->backfill->plan();
    $this->backfill->apply($plan);

    expect(targetPlaceColumns($target))->toMatchArray(['center_id' => null, 'city_wide' => 0, 'all_centers' => 1, 'city_id' => $this->cityId])
        ->and($plan[$target->id]['center_ids'])->toEqualCanonicalizing([$this->centerA, $this->centerB]);
});

it('makes a city-wide target when the row is city-wide', function (): void {
    [$target, $proposal, $project] = legacyTarget($this->cityId);
    targetRow($proposal, $project, [], true);

    $this->backfill->apply($this->backfill->plan());

    expect(targetPlaceColumns($target))->toMatchArray(['center_id' => null, 'city_wide' => 1, 'all_centers' => 0]);
});

it('leaves a target without a project placeless', function (): void {
    $target = PlanIndicator::factory()->forProposal()->create(['project_id' => null]);

    $plan = $this->backfill->plan();
    $this->backfill->apply($plan);

    expect($plan)->not->toHaveKey($target->id)
        ->and(targetPlaceColumns($target))->toMatchArray(['city_id' => null, 'program_id' => null, 'center_id' => null, 'city_wide' => 0, 'all_centers' => 0]);
});

it('places an annual plan target from its project only', function (): void {
    $project = Project::factory()->approved()->create(['city_id' => $this->cityId, 'center_id' => null]);
    $target = PlanIndicator::factory()->forAnnualPlan(AnnualPlan::factory()->create())->create(['project_id' => $project->id]);
    clearTargetPlace($target);
    $proposal = Proposal::factory()->create();
    targetRow($proposal, $project, [$this->centerA]);

    $this->backfill->apply($this->backfill->plan());

    expect(targetPlaceColumns($target))->toMatchArray(['city_id' => $this->cityId, 'program_id' => $project->program_id, 'center_id' => null, 'all_centers' => 0]);
});

it('leaves the center null and lists the target when project and row disagree', function (): void {
    [$target, $proposal, $project] = legacyTarget($this->cityId, ['center_id' => $this->centerA]);
    targetRow($proposal, $project, [$this->centerB]);

    $plan = $this->backfill->plan();
    $this->backfill->apply($plan);

    expect($plan[$target->id]['disagree'])->toBeTrue()
        ->and(targetPlaceColumns($target))->toMatchArray(['center_id' => null, 'city_wide' => 0, 'all_centers' => 0, 'city_id' => $this->cityId]);
});

it('leaves the center null and lists the target when its project matches no row', function (): void {
    [$target] = legacyTarget($this->cityId);

    $plan = $this->backfill->plan();
    $this->backfill->apply($plan);

    expect($plan[$target->id]['no_row'])->toBeTrue()
        ->and(targetPlaceColumns($target))->toMatchArray(['center_id' => null, 'city_wide' => 0, 'all_centers' => 0, 'city_id' => $this->cityId]);
});

it('prints the same counts on a dry run and writes nothing', function (): void {
    legacyTarget($this->cityId, ['center_id' => $this->centerA]);
    [, $proposalB, $projectB] = legacyTarget($this->cityId);
    targetRow($proposalB, $projectB, [$this->centerA, $this->centerB]);
    [$unset] = legacyTarget($this->cityId);
    PlanIndicator::factory()->forProposal()->create(['project_id' => null]);

    $this->artisan('targets:place-backfill', ['--dry-run' => true])
        ->expectsOutput(__('targets.console.backfill_targets', ['count' => 4]))
        ->expectsOutput(__('targets.console.backfill_with_place', ['count' => 3]))
        ->expectsOutput(__('targets.console.backfill_with_center', ['count' => 1]))
        ->expectsOutput(__('targets.console.backfill_without_center', ['count' => 1]))
        ->expectsOutput(__('targets.console.backfill_shared', ['count' => 1]))
        ->expectsOutput(__('targets.console.backfill_without_project', ['count' => 1]))
        ->expectsOutput(__('targets.console.backfill_without_center_ids', ['ids' => (string) $unset->id]))
        ->assertSuccessful();

    expect(DB::table('plan_indicators')->whereNotNull('city_id')->count())->toBe(0);

    $this->artisan('targets:place-backfill')->assertSuccessful();

    expect(DB::table('plan_indicators')->whereNotNull('city_id')->count())->toBe(3)
        ->and(DB::table('plan_indicators')->where('all_centers', true)->count())->toBe(1);
});

it('drops the place columns on down and applies again', function (): void {
    [$target] = legacyTarget($this->cityId, ['center_id' => $this->centerA]);
    $this->backfill->apply($this->backfill->plan());

    $index = require database_path('migrations/2026_11_17_100002_add_place_index_to_plan_indicators_table.php');
    $add = require database_path('migrations/2026_11_17_100001_add_place_to_plan_indicators_table.php');

    try {
        $index->down();
        $add->down();

        expect(Schema::hasColumn('plan_indicators', 'city_id'))->toBeFalse()
            ->and(Schema::hasColumn('plan_indicators', 'all_centers'))->toBeFalse()
            ->and(DB::table('plan_indicators')->where('id', $target->id)->exists())->toBeTrue();
    } finally {
        if (! Schema::hasColumn('plan_indicators', 'city_id')) {
            $add->up();
            $index->up();
        }
    }

    expect(Schema::hasColumn('plan_indicators', 'all_centers'))->toBeTrue()
        ->and(DB::table('plan_indicators')->where('id', $target->id)->value('center_id'))->toBe($this->centerA)
        ->and(collect(Schema::getIndexes('plan_indicators'))->firstWhere('name', 'plan_indicators_place_index'))->not->toBeNull();
});
