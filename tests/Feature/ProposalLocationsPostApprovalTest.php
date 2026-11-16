<?php

use App\Actions\Proposals\SaveProposal;
use App\Enums\ProposalStatus;
use App\Models\Activity;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
| Y-01 slice 2 (D-83, D-88): once the donor has approved, `locations[]` is a
| fill only. A row with no center and not city-wide may gain centers or be
| marked city-wide; everything else must come back unchanged.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->user = User::factory()->role('Financial')->create();
    $this->actingAs($this->user);

    $this->aleppo = insertHrCity('Aleppo', 'حلب');
    $this->homs = insertHrCity('Homs', 'حمص');
    $this->aleppoCenter = insertHrCenter($this->aleppo, 'Hope Center Aleppo', 'مركز الأمل حلب');
    $this->aleppoCenterTwo = insertHrCenter($this->aleppo, 'Hope Center Aleppo Two', 'مركز الأمل حلب ٢');
    $this->employee = insertHrEmployee($this->aleppo);
    $this->otherEmployee = insertHrEmployee($this->aleppo, 'Rami', 'Nasser', 'رامي', 'ناصر');
    $this->program = Program::factory()->create();
    $this->otherProgram = Program::factory()->create();

    $this->proposal = Proposal::factory()->running()->create();
    $this->empty = ProposalLocation::factory()->create([
        'proposal_id' => $this->proposal->id,
        'city_id' => $this->aleppo,
        'program_id' => $this->program->id,
        'responsible_employee_id' => $this->employee,
        'sort' => 0,
    ]);
    $this->withCenter = ProposalLocation::factory()->create([
        'proposal_id' => $this->proposal->id,
        'city_id' => $this->homs,
        'program_id' => $this->program->id,
        'responsible_employee_id' => $this->employee,
        'sort' => 1,
    ]);
    $this->withCenter->centers()->create(['center_id' => insertHrCenter($this->homs, 'Hope Center Homs', 'مركز الأمل حمص')]);
    $this->homsCenter = (int) $this->withCenter->centers()->value('center_id');
});

/**
 * The stored rows as the form would send them back.
 *
 * @param  array<int, array<string, mixed>>  $overrides  keyed by row index
 * @return list<array<string, mixed>>
 */
function storedAsSent(Proposal $proposal, array $overrides = []): array
{
    $rows = [];

    foreach ($proposal->locations()->with('centers')->orderBy('sort')->orderBy('id')->get() as $index => $row) {
        $rows[] = [
            'city_id' => $row->city_id,
            'program_id' => $row->program_id,
            'responsible_employee_id' => $row->responsible_employee_id,
            'center_ids' => $row->centerIds(),
            'is_new' => $row->is_new,
            'city_wide' => $row->city_wide,
            ...($overrides[$index] ?? []),
        ];
    }

    return $rows;
}

/**
 * @param  list<array<string, mixed>>  $locations
 */
function saveApproved(Proposal $proposal, array $locations, User $actor): Proposal
{
    return app(SaveProposal::class)->handle($proposal, [
        'name' => $proposal->name,
        'donorId' => $proposal->donor_id,
        'plannedDateBegin' => $proposal->planned_date_begin->toDateString(),
        'plannedDateEnd' => $proposal->planned_date_end->toDateString(),
        'notes' => $proposal->notes,
        'locations' => $locations,
    ], $actor);
}

function refusedAfterApproval(Proposal $proposal, array $locations, User $actor): array
{
    try {
        saveApproved($proposal, $locations, $actor);
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('The save was not refused.');
}

test('an approved row with no center gains centers and records the fill, creating no project', function () {
    $projects = Project::query()->count();

    saveApproved($this->proposal, storedAsSent($this->proposal, [0 => ['center_ids' => [$this->aleppoCenterTwo, $this->aleppoCenter]]]), $this->user);

    $row = $this->empty->fresh();

    expect($row->centerIds())->toBe([$this->aleppoCenter, $this->aleppoCenterTwo])
        ->and($row->city_wide)->toBeFalse()
        ->and($row->project_id)->toBeNull()
        ->and(Project::query()->count())->toBe($projects)
        ->and(DB::table('proposal_locations')->where('proposal_id', $this->proposal->id)->whereNotNull('project_id')->count())->toBe(0)
        ->and($this->withCenter->fresh()->centerIds())->toBe([$this->homsCenter]);

    $activity = Activity::query()->where('event', 'proposal.locations_changed')->sole();

    expect($activity->properties)->toMatchArray(['added' => 0, 'removed' => 0, 'changed' => 1]);
});

test('an approved row with no center can be marked city-wide', function () {
    saveApproved($this->proposal, storedAsSent($this->proposal, [0 => ['city_wide' => true]]), $this->user);

    expect($this->empty->fresh()->city_wide)->toBeTrue()
        ->and($this->empty->fresh()->centerIds())->toBe([])
        ->and(Activity::query()->where('event', 'proposal.locations_changed')->sole()->properties)->toMatchArray(['added' => 0, 'removed' => 0, 'changed' => 1]);
});

test('a row cannot be both city-wide and given centers in one fill', function () {
    $errors = refusedAfterApproval($this->proposal, storedAsSent($this->proposal, [0 => ['city_wide' => true, 'center_ids' => [$this->aleppoCenter]]]), $this->user);

    expect($errors)->toHaveKey('locations.0.center_ids')
        ->and($this->empty->fresh()->city_wide)->toBeFalse();
});

test('a fill with nothing changed writes and records nothing', function () {
    saveApproved($this->proposal, storedAsSent($this->proposal), $this->user);

    expect(Activity::query()->where('event', 'proposal.locations_changed')->count())->toBe(0);
});

test('a city-wide row cannot gain a center afterwards', function () {
    $this->empty->forceFill(['city_wide' => true])->save();

    $errors = refusedAfterApproval($this->proposal, storedAsSent($this->proposal, [0 => ['city_wide' => false, 'center_ids' => [$this->aleppoCenter]]]), $this->user);

    expect($errors)->toHaveKey('locations')
        ->and($errors['locations'][0])->toBe(__('proposals.location_locked_after_approval'))
        ->and($this->empty->fresh()->centerIds())->toBe([])
        ->and($this->empty->fresh()->city_wide)->toBeTrue();
});

test('an existing center cannot be changed, added to or removed', function (array $override) {
    $errors = refusedAfterApproval($this->proposal, storedAsSent($this->proposal, [1 => $override]), $this->user);

    expect($errors)->toHaveKey('locations')
        ->and($errors['locations'][0])->toBe(__('proposals.location_locked_after_approval'))
        ->and($this->withCenter->fresh()->centerIds())->toBe([$this->homsCenter]);
})->with([
    'removed' => fn () => ['center_ids' => []],
    'swapped' => fn () => ['center_ids' => [insertHrCenter(test()->homs, 'Other Homs', 'حمص أخرى')]],
    'extended' => fn () => ['center_ids' => [test()->homsCenter, insertHrCenter(test()->homs, 'Third Homs', 'حمص ثالث')]],
    'made city-wide' => fn () => ['center_ids' => [], 'city_wide' => true],
]);

test('city, program, responsible and new flag cannot change', function (int $index, string $field, string $value) {
    $override = [$field => match ($value) {
        'homs' => $this->homs,
        'aleppo' => $this->aleppo,
        'otherProgram' => $this->otherProgram->id,
        'otherEmployee' => $this->otherEmployee,
        'new' => true,
    }];

    $errors = refusedAfterApproval($this->proposal, storedAsSent($this->proposal, [$index => $override]), $this->user);

    expect($errors['locations'][0])->toBe(__('proposals.location_locked_after_approval'));
})->with([
    'city' => [0, 'city_id', 'homs'],
    'program' => [0, 'program_id', 'otherProgram'],
    'responsible' => [0, 'responsible_employee_id', 'otherEmployee'],
    'responsible of a row with a center' => [1, 'responsible_employee_id', 'otherEmployee'],
    'is_new' => [0, 'is_new', 'new'],
]);

test('rows cannot be added or dropped after approval', function () {
    $sent = storedAsSent($this->proposal);
    $added = [...$sent, ['city_id' => $this->aleppo, 'program_id' => $this->otherProgram->id, 'responsible_employee_id' => $this->employee, 'center_ids' => [$this->aleppoCenter], 'is_new' => false, 'city_wide' => false]];

    expect(refusedAfterApproval($this->proposal, $added, $this->user)['locations'][0])->toBe(__('proposals.location_locked_after_approval'))
        ->and(refusedAfterApproval($this->proposal, [$sent[0]], $this->user)['locations'][0])->toBe(__('proposals.location_locked_after_approval'))
        ->and(refusedAfterApproval($this->proposal, [], $this->user)['locations'][0] ?? null)->not->toBeNull()
        ->and($this->proposal->locations()->count())->toBe(2);
});

test('a user without proposals.update is refused', function () {
    $stranger = User::factory()->create();

    expect(fn () => saveApproved($this->proposal, storedAsSent($this->proposal, [0 => ['city_wide' => true]]), $stranger))
        ->toThrow(AuthorizationException::class)
        ->and($this->empty->fresh()->city_wide)->toBeFalse();
});

test('a second fill of the same row from stale state never overwrites the first', function () {
    $stale = storedAsSent($this->proposal);

    saveApproved($this->proposal, storedAsSent($this->proposal, [0 => ['center_ids' => [$this->aleppoCenter]]]), $this->user);

    // The second editor still sees a row with no center and picks another.
    $errors = refusedAfterApproval($this->proposal->fresh(), [
        [...$stale[0], 'center_ids' => [$this->aleppoCenterTwo]],
        $stale[1],
    ], $this->user);

    expect($errors['locations'][0])->toBe(__('proposals.location_locked_after_approval'))
        ->and($this->empty->fresh()->centerIds())->toBe([$this->aleppoCenter])
        ->and(Activity::query()->where('event', 'proposal.locations_changed')->count())->toBe(1);
});

test('before approval the rows are still replaced as a set', function () {
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::ConceptNote]);
    ProposalLocation::factory()->create(['proposal_id' => $proposal->id, 'city_id' => $this->aleppo, 'program_id' => $this->program->id, 'responsible_employee_id' => $this->employee]);

    saveApproved($proposal, [['city_id' => $this->homs, 'program_id' => $this->program->id, 'responsible_employee_id' => $this->employee, 'center_ids' => [], 'city_wide' => true, 'is_new' => false]], $this->user);

    expect($proposal->locations()->count())->toBe(1)
        ->and($proposal->locations()->value('city_id'))->toBe($this->homs);
});
