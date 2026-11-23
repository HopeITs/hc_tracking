<?php

use App\Models\MealCoverageAssignment;
use App\Models\Program;
use App\Models\Project;
use App\Models\ProposalLocation;
use App\Support\Locations\MealCoveragePlaceBackfill;
use Illuminate\Support\Facades\DB;

test('backfill deduplicates places and reports unset rows without writing on preview', function () {
    $city = insertHrCity('Aleppo');
    $program = Program::factory()->create();
    $project = Project::factory()->create(['city_id' => $city, 'program_id' => $program->id]);
    $officer = insertHrEmployee();
    MealCoverageAssignment::factory()->forProject($project->id)->create(['employee_id' => $officer]);
    $centers = [insertHrCenter($city), insertHrCenter($city)];
    foreach (range(1, 2) as $ignored) {
        $row = ProposalLocation::factory()->forProject($project)->create();
        foreach ($centers as $center) {
            $row->centers()->create(['center_id' => $center]);
        }
    }
    $unset = ProposalLocation::factory()->forProject($project)->create();
    $backfill = app(MealCoveragePlaceBackfill::class);

    expect($backfill->run())->toMatchArray(['written' => 2, 'unset_rows' => [$unset->id], 'conflicts' => []])
        ->and(DB::table('meal_coverages')->where('scope_type', 'place')->count())->toBe(0);
    $backfill->run(apply: true);

    expect(DB::table('meal_coverages')->where('scope_type', 'place')->pluck('center_id')->all())->toEqualCanonicalizing($centers)
        ->and($backfill->run()['already_covered'])->toBe(2);
});

test('backfill refuses different officers claiming the same field-work place', function () {
    $city = insertHrCity('Aleppo');
    $program = Program::factory()->create();
    foreach (range(1, 2) as $ignored) {
        $project = Project::factory()->create(['city_id' => $city, 'program_id' => $program->id]);
        ProposalLocation::factory()->forProject($project)->cityWide()->create();
        MealCoverageAssignment::factory()->forProject($project->id)->create(['employee_id' => insertHrEmployee()]);
    }
    $backfill = app(MealCoveragePlaceBackfill::class);

    expect($backfill->run()['conflicts'])->toHaveCount(1)
        ->and(fn () => $backfill->run(apply: true))->toThrow(RuntimeException::class)
        ->and(DB::table('meal_coverages')->where('scope_type', 'place')->count())->toBe(0);
});
