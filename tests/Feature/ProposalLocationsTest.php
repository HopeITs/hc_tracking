<?php

use App\Actions\Proposals\LinkProposalProject;
use App\Actions\Proposals\SaveProposal;
use App\Actions\Proposals\UnlinkProposalProject;
use App\Events\ProposalLocationsChanged;
use App\Models\Activity;
use App\Models\Donor;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\User;
use App\Support\ProposalLocationRows;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/*
| W-34a slice 1 (D-62): every proposal carries location rows. SaveProposal
| validates the rows as a set and replaces them under the proposal lock;
| LinkProposalProject / UnlinkProposalProject keep a row per linked project;
| ProposalLocationsChanged fires after commit only; the backfill command gives
| every existing link its row and is safe to re-run.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->user = User::factory()->role('Financial')->create();
    $this->actingAs($this->user);

    $this->aleppo = insertHrCity('Aleppo', 'حلب');
    $this->homs = insertHrCity('Homs', 'حمص');
    $this->aleppoCenter = insertHrCenter($this->aleppo, 'Hope Center Aleppo', 'مركز الأمل حلب');
    $this->homsCenter = insertHrCenter($this->homs, 'Hope Center Homs', 'مركز الأمل حمص');
    $this->employee = insertHrEmployee($this->aleppo);
    $this->program = Program::factory()->create();
});

/**
 * Create data with location rows instead of projects.
 *
 * @param  list<array<string, mixed>>  $locations
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function locationFormData(array $locations, array $overrides = []): array
{
    return [
        'name' => 'Winter Shelter Response',
        'internalProjectNumber' => 'HC-2026-952',
        'donorId' => Donor::factory()->create()->id,
        'locations' => $locations,
        'beneficiaries' => [],
        'plannedDateBegin' => '2026-11-01',
        'plannedDateEnd' => '2027-10-31',
        'notes' => '',
        'startingStage' => 'concept note',
        'skipReason' => null,
        ...$overrides,
    ];
}

/**
 * One complete row for the test's Aleppo fixtures.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function aleppoRow(array $overrides = []): array
{
    return [
        'city_id' => test()->aleppo,
        'program_id' => test()->program->id,
        'responsible_employee_id' => test()->employee,
        'center_ids' => [test()->aleppoCenter],
        'is_new' => true,
        ...$overrides,
    ];
}

/**
 * Edit data for an existing proposal, starting from its stored values.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function locationEditData(Proposal $proposal, array $overrides = []): array
{
    return [
        'name' => $proposal->name,
        'internalProjectNumber' => $proposal->internal_project_number,
        'donorId' => $proposal->donor_id,
        'plannedDateBegin' => $proposal->planned_date_begin->toDateString(),
        'plannedDateEnd' => $proposal->planned_date_end->toDateString(),
        'notes' => $proposal->notes,
        ...$overrides,
    ];
}

/**
 * The validation errors a refused save reports.
 *
 * @return array<string, list<string>>
 */
function refusedLocations(callable $save): array
{
    try {
        $save();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('The save was not refused.');
}

/**
 * The stored rows of a proposal, in sort order, with their center ids.
 *
 * @return list<array<string, mixed>>
 */
function storedRows(Proposal $proposal): array
{
    return DB::table('proposal_locations')->where('proposal_id', $proposal->id)->orderBy('sort')->orderBy('id')->get()
        ->map(fn (object $row): array => [
            ...(array) $row,
            'center_ids' => DB::table('proposal_location_centers')->where('proposal_location_id', $row->id)->orderBy('center_id')->pluck('center_id')->map(fn ($id): int => (int) $id)->all(),
        ])
        ->all();
}

// ── Create ──────────────────────────────────────────────────────────────

test('a proposal is created with its location rows and their centers', function () {
    $proposal = app(SaveProposal::class)->handle(null, locationFormData([
        aleppoRow(),
        ['city_id' => $this->homs, 'is_new' => false],
    ]), $this->user);

    $rows = storedRows($proposal);

    expect($rows)->toHaveCount(2)
        ->and((int) $rows[0]['city_id'])->toBe($this->aleppo)
        ->and((int) $rows[0]['program_id'])->toBe($this->program->id)
        ->and((int) $rows[0]['responsible_employee_id'])->toBe($this->employee)
        ->and((bool) $rows[0]['is_new'])->toBeTrue()
        ->and($rows[0]['project_id'])->toBeNull()
        ->and((int) $rows[0]['sort'])->toBe(0)
        ->and($rows[0]['center_ids'])->toBe([$this->aleppoCenter])
        ->and((int) $rows[1]['city_id'])->toBe($this->homs)
        ->and($rows[1]['program_id'])->toBeNull()
        ->and($rows[1]['responsible_employee_id'])->toBeNull()
        ->and((bool) $rows[1]['is_new'])->toBeFalse()
        ->and((int) $rows[1]['sort'])->toBe(1)
        ->and($rows[1]['center_ids'])->toBe([])
        ->and($proposal->locations()->count())->toBe(2);
});

test('a proposal needs at least one location row at creation', function () {
    $errors = refusedLocations(fn () => app(SaveProposal::class)->handle(null, locationFormData([]), $this->user));

    expect($errors['locations'])->toBe([__('proposals.locations_required')])
        ->and(Proposal::query()->count())->toBe(0);
});

/*
| Y-17: the cases on linking a project at creation, on a row that carries a
| project, on linking and unlinking, and the two on the W-34a backfill went
| with the features they guarded — a proposal links no project any more, so
| `projectIds`, LinkProposalProject, UnlinkProposalProject and
| ProposalLocationBackfill are all gone. They are named in the Y-17 handoff.
*/

// ── Validation (the set as a whole, every message translated) ───────────

test('a row must name a city that HR knows and has not deactivated', function () {
    $inactive = insertHrCity('Old Town', 'المدينة القديمة', false);

    $errors = refusedLocations(fn () => app(SaveProposal::class)->handle(null, locationFormData([
        aleppoRow(),
        ['city_id' => $inactive],
        ['city_id' => 999999],
        ['city_id' => null],
    ]), $this->user));

    expect($errors['locations.1.city_id'])->toBe([__('proposals.location_choose_the_city')])
        ->and($errors['locations.2.city_id'])->toBe([__('proposals.location_choose_the_city')])
        ->and($errors['locations.3.city_id'])->toBe([__('proposals.location_choose_the_city')])
        ->and($errors)->not->toHaveKey('locations.0.city_id')
        ->and(Proposal::query()->count())->toBe(0);
});

test('cities are distinct per program, and one row per city may wait for a program', function () {
    $errors = refusedLocations(fn () => app(SaveProposal::class)->handle(null, locationFormData([
        aleppoRow(),
        aleppoRow(['center_ids' => []]),
        ['city_id' => $this->homs],
        ['city_id' => $this->homs],
    ]), $this->user));

    expect($errors['locations.1.city_id'])->toBe([__('proposals.location_city_duplicate')])
        ->and($errors['locations.3.city_id'])->toBe([__('proposals.location_city_duplicate')])
        ->and($errors)->not->toHaveKey('locations.0.city_id')
        ->and($errors)->not->toHaveKey('locations.2.city_id');
});

test('a center must belong to the city of its row', function () {
    $errors = refusedLocations(fn () => app(SaveProposal::class)->handle(null, locationFormData([
        aleppoRow(['center_ids' => [$this->aleppoCenter, $this->homsCenter]]),
        aleppoRow(['city_id' => $this->homs, 'center_ids' => [999999]]),
    ]), $this->user));

    expect($errors['locations.0.center_ids'])->toBe([__('proposals.location_center_not_in_city')])
        ->and($errors['locations.1.center_ids'])->toBe([__('proposals.location_center_not_in_city')]);
});

test('a responsible must be an active HR employee', function () {
    $retired = insertHrEmployee($this->aleppo, 'Nour', 'Saad', 'نور', 'سعد', false);

    $errors = refusedLocations(fn () => app(SaveProposal::class)->handle(null, locationFormData([
        aleppoRow(['responsible_employee_id' => $retired]),
        aleppoRow(['city_id' => $this->homs, 'center_ids' => [], 'responsible_employee_id' => 999999]),
    ]), $this->user));

    expect($errors['locations.0.responsible_employee_id'])->toBe([__('proposals.location_responsible_inactive')])
        ->and($errors['locations.1.responsible_employee_id'])->toBe([__('proposals.location_responsible_inactive')]);
});

test('a program must exist in tracking', function () {
    $errors = refusedLocations(fn () => app(SaveProposal::class)->handle(null, locationFormData([
        aleppoRow(['program_id' => 999999]),
    ]), $this->user));

    expect($errors['locations.0.program_id'])->toBe([__('proposals.location_choose_the_program')]);
});

// ── Edit: rows replaced as a set ────────────────────────────────────────

test('an edit that sends no locations leaves the rows alone', function () {
    $proposal = app(SaveProposal::class)->handle(null, locationFormData([aleppoRow()]), $this->user);

    app(SaveProposal::class)->handle($proposal, locationEditData($proposal, ['name' => 'Renamed']), $this->user);

    expect(storedRows($proposal))->toHaveCount(1)
        ->and($proposal->fresh()->name)->toBe('Renamed');
});

test('an edit that sends an empty set is refused', function () {
    $proposal = app(SaveProposal::class)->handle(null, locationFormData([aleppoRow()]), $this->user);

    $errors = refusedLocations(fn () => app(SaveProposal::class)->handle($proposal, [...locationEditData($proposal), 'locations' => []], $this->user));

    expect($errors['locations'])->toBe([__('proposals.locations_required')])
        ->and(storedRows($proposal))->toHaveCount(1);
});

test('a user without proposals.update cannot change the rows', function () {
    $proposal = app(SaveProposal::class)->handle(null, locationFormData([aleppoRow()]), $this->user);
    $viewer = User::factory()->role('Media Team')->create();
    $this->actingAs($viewer);

    expect(fn () => app(SaveProposal::class)->handle($proposal, [...locationEditData($proposal), 'locations' => [['city_id' => $this->homs]]], $viewer))
        ->toThrow(AuthorizationException::class)
        ->and(storedRows($proposal))->toHaveCount(1);
});

// ── Event and activity ──────────────────────────────────────────────────

test('ProposalLocationsChanged carries the diff counts and fires after commit only', function () {
    $seen = [];
    Event::listen(ProposalLocationsChanged::class, function (ProposalLocationsChanged $event) use (&$seen): void {
        $seen[] = $event;
    });

    $proposal = DB::transaction(function () use (&$seen): Proposal {
        $proposal = app(SaveProposal::class)->handle(null, locationFormData([aleppoRow(), ['city_id' => $this->homs]]), $this->user);

        expect($seen)->toBe([]);

        return $proposal;
    });

    expect($seen)->toHaveCount(1)
        ->and($seen[0]->proposalId)->toBe($proposal->id)
        ->and($seen[0]->actorId)->toBe($this->user->id)
        ->and($seen[0]->added)->toBe(2)
        ->and($seen[0]->removed)->toBe(0)
        ->and($seen[0]->changed)->toBe(0);

    app(SaveProposal::class)->handle($proposal, [
        ...locationEditData($proposal),
        'locations' => [aleppoRow(['center_ids' => []]), ['city_id' => $this->homs, 'program_id' => $this->program->id]],
    ], $this->user);

    expect($seen)->toHaveCount(2)
        ->and($seen[1]->added)->toBe(1)
        ->and($seen[1]->removed)->toBe(1)
        ->and($seen[1]->changed)->toBe(1);

    app(SaveProposal::class)->handle($proposal, locationEditData($proposal, ['name' => 'Renamed']), $this->user);

    expect($seen)->toHaveCount(2);
});

test('a refused save dispatches nothing and writes nothing', function () {
    Event::fake([ProposalLocationsChanged::class]);

    refusedLocations(fn () => app(SaveProposal::class)->handle(null, locationFormData([aleppoRow(['center_ids' => [$this->homsCenter]])]), $this->user));

    Event::assertNotDispatched(ProposalLocationsChanged::class);
    expect(DB::table('proposal_locations')->count())->toBe(0)
        ->and(Proposal::query()->count())->toBe(0);
});

test('the change is recorded on the proposal with counts only', function () {
    $proposal = app(SaveProposal::class)->handle(null, locationFormData([aleppoRow(), ['city_id' => $this->homs]]), $this->user);

    $activity = Activity::query()->where('event', 'proposal.locations_changed')->sole();

    expect($activity->subject_type)->toBe('proposal')
        ->and($activity->subject_id)->toBe($proposal->id)
        ->and($activity->properties)->toEqual(['label' => 'Winter Shelter Response', 'notify' => true, 'added' => 2, 'removed' => 0, 'changed' => 0]);
});

// ── Link / unlink wrappers ──────────────────────────────────────────────

// ── Backfill ────────────────────────────────────────────────────────────

// ── Models ──────────────────────────────────────────────────────────────

test('the location models read back through their relations and casts', function () {
    $location = ProposalLocation::factory()->create(['city_id' => $this->aleppo, 'program_id' => $this->program->id, 'is_new' => true]);
    $location->centers()->create(['center_id' => $this->aleppoCenter]);

    $location = $location->fresh(['centers', 'proposal']);

    expect($location->is_new)->toBeTrue()
        ->and($location->centerIds())->toBe([$this->aleppoCenter])
        ->and($location->proposal)->toBeInstanceOf(Proposal::class)
        ->and((new ProposalLocation)->getMorphClass())->toBe('proposal_location');
});

// ── Field work, no center (Y-01, D-88) ──────────────────────────────────

test('a city-wide row is complete without a center, a row with neither is not', function () {
    $proposal = Proposal::factory()->create();
    $cityWide = ProposalLocation::factory()->for($proposal)->complete()->cityWide()->create(['city_id' => $this->aleppo]);
    $neither = ProposalLocation::factory()->for($proposal)->complete()->create(['city_id' => $this->homs]);

    expect(ProposalLocationRows::incompleteRowIds($proposal))->toBe([$neither->id])
        ->and($cityWide->fresh()->city_wide)->toBeTrue();
});

test('a city-wide row is stored with the flag and no centers', function () {
    $proposal = app(SaveProposal::class)->handle(null, locationFormData([
        aleppoRow(['city_wide' => true, 'center_ids' => [], 'is_new' => false]),
        aleppoRow(['city_id' => $this->homs, 'center_ids' => [$this->homsCenter]]),
    ]), $this->user);

    $rows = storedRows($proposal);

    expect((bool) $rows[0]['city_wide'])->toBeTrue()
        ->and($rows[0]['center_ids'])->toBe([])
        ->and((bool) $rows[1]['city_wide'])->toBeFalse()
        ->and($rows[1]['center_ids'])->toBe([$this->homsCenter])
        ->and(ProposalLocationRows::incompleteRowIds($proposal))->toBe([]);
});

test('a row cannot be city-wide and hold centers', function () {
    $errors = refusedLocations(fn () => app(SaveProposal::class)->handle(null, locationFormData([
        aleppoRow(['city_wide' => true]),
    ]), $this->user));

    expect($errors['locations.0.center_ids'])->toBe([__('proposals.location_city_wide_has_centers')])
        ->and(Proposal::query()->count())->toBe(0);
});

test('a center of another city is refused on its own row only', function () {
    $errors = refusedLocations(fn () => app(SaveProposal::class)->handle(null, locationFormData([
        aleppoRow(['city_wide' => true, 'center_ids' => [], 'is_new' => false]),
        aleppoRow(['city_id' => $this->homs, 'center_ids' => [$this->aleppoCenter]]),
    ]), $this->user));

    expect($errors['locations.1.center_ids'])->toBe([__('proposals.location_center_not_in_city')])
        ->and($errors)->not->toHaveKey('locations.0.center_ids');
});

test('editing a row to city-wide clears its centers and counts as a change', function () {
    $proposal = app(SaveProposal::class)->handle(null, locationFormData([aleppoRow()]), $this->user);

    $saved = app(SaveProposal::class)->handle($proposal, locationEditData($proposal, [
        'locations' => [aleppoRow(['city_wide' => true, 'center_ids' => []])],
    ]), $this->user);

    $row = storedRows($saved)[0];

    expect((bool) $row['city_wide'])->toBeTrue()
        ->and($row['center_ids'])->toBe([])
        ->and(Activity::query()->where('event', 'proposal.locations_changed')->latest('id')->first()->properties['changed'])->toBe(1);
});
