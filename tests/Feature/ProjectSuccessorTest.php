<?php

declare(strict_types=1);

use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Support\Locations\ProjectSuccessor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A proposal carrying the project through one location row, dated by its
 * actual period.
 */
function successorRow(Project $project, string $begin, string $end, array $overrides = []): ProposalLocation
{
    $proposal = Proposal::factory()->create([
        'actual_date_begin' => $begin,
        'actual_date_end' => $end,
        ...$overrides,
    ]);

    return proposalRowForProject($proposal, $project, false);
}

test('one row carrying the project answers for every date', function () {
    $project = Project::factory()->create();
    $row = successorRow($project, '2026-01-01', '2026-12-31');

    $successor = app(ProjectSuccessor::class);

    expect($successor->rowFor($project->id, CarbonImmutable::parse('2026-06-01'))?->id)->toBe($row->id)
        ->and($successor->rowFor($project->id, CarbonImmutable::parse('2030-06-01'))?->id)->toBe($row->id)
        ->and($successor->rowFor($project->id)?->id)->toBe($row->id);
});

test('a date inside the first proposal picks the first row, inside the second picks the second', function () {
    $project = Project::factory()->create();
    $first = successorRow($project, '2025-01-01', '2025-12-31');
    $second = successorRow($project, '2026-01-01', '2026-12-31');

    $successor = app(ProjectSuccessor::class);

    expect($successor->rowFor($project->id, CarbonImmutable::parse('2025-06-01'))?->id)->toBe($first->id)
        ->and($successor->rowFor($project->id, CarbonImmutable::parse('2026-06-01'))?->id)->toBe($second->id);
});

test('a date covered by no proposal falls to the row of the most recent proposal', function () {
    $project = Project::factory()->create();
    successorRow($project, '2025-01-01', '2025-12-31');
    $second = successorRow($project, '2026-01-01', '2026-12-31');

    expect(app(ProjectSuccessor::class)->rowFor($project->id, CarbonImmutable::parse('2024-03-01'))?->id)->toBe($second->id);
});

test('a date covered by two overlapping proposals falls to the row of the most recent one', function () {
    $project = Project::factory()->create();
    successorRow($project, '2026-01-01', '2026-12-31');
    $later = successorRow($project, '2026-06-01', '2027-05-31');

    expect(app(ProjectSuccessor::class)->rowFor($project->id, CarbonImmutable::parse('2026-08-01'))?->id)->toBe($later->id);
});

test('a null date takes the row of the most recent proposal', function () {
    $project = Project::factory()->create();
    successorRow($project, '2025-01-01', '2025-12-31');
    $second = successorRow($project, '2026-01-01', '2026-12-31');

    expect(app(ProjectSuccessor::class)->rowFor($project->id)?->id)->toBe($second->id);
});

test('proposals tied on their begin date fall to the highest id', function () {
    $project = Project::factory()->create();
    successorRow($project, '2026-01-01', '2026-12-31');
    $highest = successorRow($project, '2026-01-01', '2026-06-30');

    expect(app(ProjectSuccessor::class)->rowFor($project->id)?->id)->toBe($highest->id);
});

test('a proposal with no actual dates is read through its planned period', function () {
    $project = Project::factory()->create();
    $row = successorRow($project, begin: '2026-01-01', end: '2026-12-31', overrides: [
        'actual_date_begin' => null,
        'actual_date_end' => null,
        'planned_date_begin' => '2026-01-01',
        'planned_date_end' => '2026-12-31',
    ]);

    expect(app(ProjectSuccessor::class)->rowFor($project->id, CarbonImmutable::parse('2026-06-01'))?->id)->toBe($row->id);
});

test('an extension widens the period the row answers for', function () {
    $project = Project::factory()->create();
    $row = successorRow($project, '2026-01-01', '2026-06-30', ['extended_date_end' => '2026-12-31']);

    expect(app(ProjectSuccessor::class)->rowFor($project->id, CarbonImmutable::parse('2026-09-01'))?->id)->toBe($row->id);
});

test('a project no row carries has no successor', function () {
    $project = Project::factory()->create();

    expect(app(ProjectSuccessor::class)->rowFor($project->id, CarbonImmutable::parse('2026-06-01')))->toBeNull()
        ->and(app(ProjectSuccessor::class)->rowFor($project->id))->toBeNull()
        ->and(app(ProjectSuccessor::class)->rowsFor($project->id))->toBeEmpty();
});

test('rowsFor lists every row carrying the project, most recent proposal first', function () {
    $project = Project::factory()->create();
    $first = successorRow($project, '2025-01-01', '2025-12-31');
    $second = successorRow($project, '2026-01-01', '2026-12-31');

    expect(app(ProjectSuccessor::class)->rowsFor($project->id)->pluck('id')->all())->toBe([$second->id, $first->id]);
});

test('labelling thirty rows costs a fixed number of queries', function () {
    $proposal = Proposal::factory()->create();
    $cityId = insertHrCity('Label city', 'مدينة');

    for ($i = 0; $i < 30; $i++) {
        ProposalLocation::factory()->create([
            'proposal_id' => $proposal->id,
            'city_id' => $cityId,
            'program_id' => Program::factory()->create()->id,
            'city_wide' => $i % 2 === 0,
        ]);
    }

    $rows = ProposalLocation::query()->with(['city', 'program', 'centers'])->get();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $labels = $rows->map(fn (ProposalLocation $row): string => $row->label())->all();

    $queries = count(DB::getRawQueryLog());
    DB::disableQueryLog();

    expect($labels)->toHaveCount(30)
        ->and($queries)->toBe(0);
});

test('a row of another project is never returned', function () {
    $project = Project::factory()->create();
    $other = Project::factory()->create();
    successorRow($other, '2026-01-01', '2026-12-31');

    expect(app(ProjectSuccessor::class)->rowFor($project->id))->toBeNull();
});
