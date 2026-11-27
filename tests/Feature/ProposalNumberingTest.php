<?php

use App\Actions\Proposals\SaveProposal;
use App\Models\Donor;
use App\Models\Project;
use App\Models\Proposal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function numberingInput(Project $project, string $start): array
{
    return [
        'name' => 'Proposal',
        'donorId' => Donor::factory()->create()->id,
        'locations' => [placePayload()],
        'plannedDateBegin' => $start,
        'plannedDateEnd' => $start,
    ];
}

test('proposal numbers follow the planned year and remain fixed after edits', function () {
    $user = userWithRole('Financial');
    $this->actingAs($user);
    $project = Project::factory()->create();
    $input = numberingInput($project, '2026-01-01');

    $first = app(SaveProposal::class)->handle(null, $input, $user);
    $second = app(SaveProposal::class)->handle(null, $input, $user);
    $nextYear = app(SaveProposal::class)->handle(null, [...$input, 'plannedDateBegin' => '2027-01-01', 'plannedDateEnd' => '2027-01-01'], $user);
    $edited = app(SaveProposal::class)->handle($first, [...$input, 'plannedDateBegin' => '2028-01-01', 'plannedDateEnd' => '2028-01-01', 'internalProjectNumber' => 'HC-2099-999'], $user);

    expect($first->internal_project_number)->toBe('HC-2026-001')
        ->and($second->internal_project_number)->toBe('HC-2026-002')
        ->and($nextYear->internal_project_number)->toBe('HC-2027-001')
        ->and($edited->internal_project_number)->toBe('HC-2026-001');
});

test('a year with 999 assigned numbers rejects another proposal', function () {
    $user = userWithRole('Financial');
    $this->actingAs($user);
    $project = Project::factory()->create();
    DB::table('proposal_number_sequences')->insert(['year' => 2026, 'last_number' => 999]);

    expect(fn () => app(SaveProposal::class)->handle(null, numberingInput($project, '2026-01-01'), $user))
        ->toThrow(ValidationException::class);

    expect(Proposal::query()->count())->toBe(0);
});

test('numbering continues after an existing number in the same year', function () {
    $user = userWithRole('Financial');
    $this->actingAs($user);
    Proposal::factory()->create(['internal_project_number' => 'HC-2026-041']);
    $project = Project::factory()->create();

    $proposal = app(SaveProposal::class)->handle(null, numberingInput($project, '2026-01-01'), $user);

    expect($proposal->internal_project_number)->toBe('HC-2026-042');
});

test('a rolled back creation does not consume its number', function () {
    $user = userWithRole('Financial');
    $this->actingAs($user);
    $project = Project::factory()->create();
    $input = numberingInput($project, '2026-01-01');

    expect(fn () => DB::transaction(function () use ($input, $user): void {
        app(SaveProposal::class)->handle(null, $input, $user);

        throw new RuntimeException('Rollback');
    }))->toThrow(RuntimeException::class);

    $proposal = app(SaveProposal::class)->handle(null, $input, $user);

    expect($proposal->internal_project_number)->toBe('HC-2026-001');
});
