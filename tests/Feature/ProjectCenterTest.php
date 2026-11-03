<?php

use App\Actions\Projects\SaveProject;
use App\Enums\ProjectStatus;
use App\Models\MealCoverageAssignment;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use App\Support\MealCoverage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->role('General Manager')->create();
    $this->cityId = insertHrCity('Idlib', 'إدلب');
    $this->centerId = insertHrCenter($this->cityId, 'Hope Center Idlib', 'مركز الأمل إدلب');
    $this->owner = insertHrEmployee();
    $this->program = Program::factory()->create(['manager_id' => $this->owner]);
    $this->create = fn (array $fields = []) => Livewire::actingAs($this->user)
        ->test('pages::projects.index')
        ->call('openCreateModal')
        ->set('sectorIds', newProjectSectorIds())
        ->set([
            'centerId' => (string) $this->centerId,
            'programId' => (string) $this->program->id,
            'userId' => (string) $this->owner,
            ...$fields,
        ])
        ->call('saveProject');
});

test('creating a project stores its center and takes the city from the center', function () {
    ($this->create)(['name' => 'Water'])->assertHasNoErrors();

    expect(Project::query()->sole())
        ->center_id->toBe($this->centerId)
        ->city_id->toBe($this->cityId);
});

test('a city sent with a center is ignored: the center\'s city always wins', function () {
    // Tampered input: the form itself drops a center outside the chosen city (W-02), so the rule is checked on the action.
    $this->actingAs($this->user);
    app(SaveProject::class)->handle(null, [
        'name' => 'Water',
        'centerId' => $this->centerId,
        'cityId' => insertHrCity('Hama'),
        'programId' => $this->program->id,
        'userId' => $this->owner,
        'sectorIds' => newProjectSectorIds(),
    ], $this->user);

    expect(Project::query()->sole())
        ->center_id->toBe($this->centerId)
        ->city_id->toBe($this->cityId)
        ->host_center_id->toBeNull();
});

test('choosing the program and center proposes «Program — Center» as an editable name', function () {
    $form = Livewire::actingAs($this->user)
        ->test('pages::projects.index')
        ->call('openCreateModal')
        ->set('sectorIds', newProjectSectorIds())
        ->set('programId', (string) $this->program->id)
        ->set('centerId', (string) $this->centerId)
        ->assertSet('name', $this->program->name.' — Hope Center Idlib');

    $form->set('name', 'Idlib Water')->call('saveProject')->assertHasNoErrors();

    expect(Project::query()->sole()->name)->toBe('Idlib Water');
});

test('a second open project for the same center and program is refused', function (ProjectStatus $status) {
    $existing = Project::factory()->create([
        'name' => 'Idlib Micro',
        'status' => $status,
        'center_id' => $this->centerId,
        'city_id' => $this->cityId,
        'program_id' => $this->program->id,
    ]);

    ($this->create)(['name' => 'Duplicate'])
        ->assertHasErrors(['centerId'])
        ->assertSee('This center already has an open project for this program: Idlib Micro. Add a new proposal to it instead.');

    expect(Project::query()->pluck('id')->all())->toBe([$existing->id]);
})->with([ProjectStatus::Draft, ProjectStatus::Approved, ProjectStatus::Active]);

test('closed and archived projects do not block a new one at the same center and program', function (ProjectStatus $status) {
    Project::factory()->create([
        'status' => $status,
        'center_id' => $this->centerId,
        'city_id' => $this->cityId,
        'program_id' => $this->program->id,
    ]);

    ($this->create)(['name' => 'Fresh start'])->assertHasNoErrors();

    expect(Project::query()->count())->toBe(2);
})->with([ProjectStatus::Closed, ProjectStatus::Archived]);

test('the same center may run a project for another program', function () {
    Project::factory()->create([
        'center_id' => $this->centerId,
        'city_id' => $this->cityId,
        'program_id' => Program::factory()->create()->id,
    ]);

    ($this->create)(['name' => 'Second program'])->assertHasNoErrors();

    expect(Project::query()->count())->toBe(2);
});

test('the center is required, must exist and must be active', function (string|int $centerId, string $message) {
    ($this->create)(['name' => 'No center', 'centerId' => (string) $centerId])
        ->assertHasErrors(['centerId'])
        ->assertSee($message);

    expect(Project::query()->count())->toBe(0);
})->with([
    'missing' => ['', 'Choose a physical center or a host center.'],
    'unknown' => [999999, 'Choose the center.'],
    'inactive' => [fn () => insertHrCenter(insertHrCity(), 'Closed', isActive: false), 'Choose the center.'],
]);

test('a second create waits on the program lock and is refused once the first commits', function () {
    DB::enableQueryLog();

    ($this->create)(['name' => 'First'])->assertHasNoErrors();
    $lockedPrograms = collect(DB::getQueryLog())->pluck('query')
        ->filter(fn (string $query): bool => str_contains($query, 'from `programs`') && str_contains($query, 'for update'));

    ($this->create)(['name' => 'Second'])->assertHasErrors(['centerId']);

    expect($lockedPrograms)->not->toBeEmpty()
        ->and(Project::query()->pluck('name')->all())->toBe(['First']);
});

test('moving a project onto a center and program that already has an open project is refused', function () {
    Project::factory()->create(['center_id' => $this->centerId, 'city_id' => $this->cityId, 'program_id' => $this->program->id]);
    $otherCenter = insertHrCenter($this->cityId, 'Second Center');
    $moving = Project::factory()->create(['center_id' => $otherCenter, 'city_id' => $this->cityId, 'program_id' => $this->program->id]);

    Livewire::actingAs($this->user)
        ->test('pages::projects.index')
        ->call('openEditModal', $moving->id)
        ->set('centerId', (string) $this->centerId)
        ->call('saveProject')
        ->assertHasErrors(['centerId']);

    expect($moving->fresh()->center_id)->toBe($otherCenter);
});

test('a project that is not yet mapped to a center can still be edited and keeps its city', function () {
    $project = Project::factory()->create(['name' => 'Legacy', 'center_id' => null, 'city_id' => $this->cityId, 'program_id' => $this->program->id, 'user_id' => $this->owner]);

    Livewire::actingAs($this->user)
        ->test('pages::projects.index')
        ->call('openEditModal', $project->id)
        ->set('name', 'Legacy renamed')
        ->call('saveProject')
        ->assertHasNoErrors();

    expect($project->fresh())
        ->name->toBe('Legacy renamed')
        ->center_id->toBeNull()
        ->city_id->toBe($this->cityId);
});

test('a project keeps its inactive center on edit and shows it as inactive', function () {
    $closedCenter = insertHrCenter($this->cityId, 'Old Center', isActive: false);
    $project = Project::factory()->create(['center_id' => $closedCenter, 'city_id' => $this->cityId, 'program_id' => $this->program->id, 'user_id' => $this->owner]);

    Livewire::actingAs($this->user)
        ->test('pages::projects.index')
        ->call('openEditModal', $project->id)
        ->assertSet('centerId', (string) $closedCenter)
        ->set('name', 'Still here')
        ->call('saveProject')
        ->assertHasNoErrors();

    Livewire::actingAs($this->user)
        ->test('pages::projects.show', ['project' => $project->fresh()])
        ->assertSee('Old Center')
        ->assertSee('Center inactive');
});

test('someone without project create rights still cannot create one', function () {
    Livewire::actingAs(User::factory()->role('Team Member')->create())
        ->test('pages::projects.index')
        ->call('openCreateModal')
        ->assertForbidden();

    expect(Project::query()->count())->toBe(0);
});

test('managers see the projects that have no center yet', function () {
    Project::factory()->create(['name' => 'Unmapped Project', 'center_id' => null]);
    Project::factory()->create(['name' => 'Mapped Project', 'center_id' => $this->centerId]);

    Livewire::actingAs($this->user)
        ->test('pages::projects.index')
        ->assertSeeText('Projects without a center (1)')
        ->set('showProjectsWithoutCenter', true)
        ->assertSeeInOrder(['Projects without a center', 'Unmapped Project']);

    Livewire::actingAs(User::factory()->role('Team Member')->create())
        ->test('pages::projects.index')
        ->assertDontSeeText('Projects without a center');
});

describe('hosted projects (W-01, D-55)', function () {
    beforeEach(function () {
        $this->idlebId = insertHrCity('Idleb', 'إدلب');
        $this->aleppoId = insertHrCity('Aleppo', 'حلب');
        $this->hostId = insertHrCenter($this->aleppoId, 'Head Office Aleppo', 'المكتب الرئيسي حلب');
        $this->hosted = fn (array $fields = []) => ($this->create)([
            'centerId' => '',
            'cityId' => (string) $this->idlebId,
            'hostCenterId' => (string) $this->hostId,
            ...$fields,
        ]);
    });

    test('a hosted draft is created with a city and a host center and no physical center', function () {
        ($this->hosted)(['name' => 'Wells Idleb'])->assertHasNoErrors();

        expect(Project::query()->sole())
            ->name->toBe('Wells Idleb')
            ->status->toBe(ProjectStatus::Draft)
            ->center_id->toBeNull()
            ->host_center_id->toBe($this->hostId)
            ->city_id->toBe($this->idlebId);
    });

    test('choosing the program and host center proposes «Program — City» as the name', function () {
        Livewire::actingAs($this->user)
            ->test('pages::projects.index')
            ->call('openCreateModal')
            ->set('programId', (string) $this->program->id)
            ->set('cityId', (string) $this->idlebId)
            ->set('hostCenterId', (string) $this->hostId)
            ->assertSet('name', $this->program->name.' — Idleb');
    });

    test('a project needs a physical center or a host center', function () {
        ($this->hosted)(['name' => 'Nowhere', 'hostCenterId' => ''])
            ->assertHasErrors(['centerId'])
            ->assertSee('Choose a physical center or a host center.');

        expect(Project::query()->count())->toBe(0);
    });

    // Tampered input (the form never offers a host beside a center, W-02): the action refuses it.
    test('a physical center and a host center cannot both be set', function () {
        $refused = fn () => app(SaveProject::class)->handle(null, [
            'name' => 'Both',
            'centerId' => $this->centerId,
            'cityId' => $this->idlebId,
            'hostCenterId' => $this->hostId,
            'programId' => $this->program->id,
            'userId' => $this->owner,
            'sectorIds' => newProjectSectorIds(),
        ], $this->user);

        expect($refused)->toThrow(fn (ValidationException $e) => expect($e->errors()['hostCenterId'][0])->toBe('Choose a physical center or a host center.'))
            ->and(Project::query()->count())->toBe(0);
    });

    test('a project cannot be hosted by its own center', function () {
        $refused = fn () => app(SaveProject::class)->handle(null, [
            'name' => 'Self',
            'centerId' => $this->centerId,
            'cityId' => $this->cityId,
            'hostCenterId' => $this->centerId,
            'programId' => $this->program->id,
            'userId' => $this->owner,
            'sectorIds' => newProjectSectorIds(),
        ], $this->user);

        expect($refused)->toThrow(fn (ValidationException $e) => expect($e->errors()['hostCenterId'][0])->toBe('A project cannot be hosted by its own center.'))
            ->and(Project::query()->count())->toBe(0);
    });

    test('the host center must exist and be active', function (string|int $hostCenterId) {
        ($this->hosted)(['name' => 'Bad host', 'hostCenterId' => (string) $hostCenterId])
            ->assertHasErrors(['hostCenterId'])
            ->assertSee('Choose the center.');

        expect(Project::query()->count())->toBe(0);
    })->with([
        'unknown' => 999999,
        'inactive' => fn () => insertHrCenter($this->aleppoId, 'Closed', isActive: false),
    ]);

    test('a hosted project needs its city', function () {
        ($this->hosted)(['name' => 'No city', 'cityId' => ''])
            ->assertHasErrors(['cityId']);

        expect(Project::query()->count())->toBe(0);
    });

    test('a second open hosted project in the same city and program is refused', function (ProjectStatus $status) {
        $existing = Project::factory()->create([
            'name' => 'Idleb Micro',
            'status' => $status,
            'center_id' => null,
            'host_center_id' => $this->hostId,
            'city_id' => $this->idlebId,
            'program_id' => $this->program->id,
        ]);

        ($this->hosted)(['name' => 'Duplicate', 'hostCenterId' => (string) $this->centerId])
            ->assertHasErrors(['hostCenterId'])
            ->assertSee('Idleb Micro');

        expect(Project::query()->pluck('id')->all())->toBe([$existing->id]);
    })->with([ProjectStatus::Draft, ProjectStatus::Approved, ProjectStatus::Active]);

    test('a project at a center and a hosted project in the same city and program may coexist', function () {
        Project::factory()->create([
            'center_id' => $this->centerId,
            'city_id' => $this->cityId,
            'program_id' => $this->program->id,
        ]);

        ($this->hosted)(['name' => 'Hosted twin', 'cityId' => (string) $this->cityId])->assertHasNoErrors();

        expect(Project::query()->count())->toBe(2);
    });

    test('moving a hosted project onto a physical center clears its host center and takes the center\'s city', function () {
        $project = Project::factory()->create([
            'center_id' => null,
            'host_center_id' => $this->hostId,
            'city_id' => $this->idlebId,
            'program_id' => $this->program->id,
            'user_id' => $this->owner,
        ]);

        Livewire::actingAs($this->user)
            ->test('pages::projects.index')
            ->call('openEditModal', $project->id)
            ->assertSet('hostCenterId', (string) $this->hostId)
            ->assertSet('cityId', (string) $this->idlebId)
            ->set('centerId', (string) $this->centerId)
            ->assertSet('hostCenterId', '')
            ->call('saveProject')
            ->assertHasNoErrors();

        expect($project->fresh())
            ->center_id->toBe($this->centerId)
            ->host_center_id->toBeNull()
            ->city_id->toBe($this->cityId);
    });

    test('a hosted project resolves its MEAL officer by its own city', function () {
        $officer = insertHrEmployee();
        MealCoverageAssignment::factory()->forCity($this->idlebId)->create(['employee_id' => $officer]);
        $project = Project::factory()->create(['center_id' => null, 'host_center_id' => $this->hostId, 'city_id' => $this->idlebId]);

        expect(MealCoverage::responsibleFor($project))->toBe($officer);
    });
});

describe('inactive center badge (W-02, D-66)', function () {
    beforeEach(function () {
        $this->closedCenterId = insertHrCenter($this->cityId, 'Closed Center', 'مركز مغلق', isActive: false);
        $this->onClosed = Project::factory()->create([
            'name' => 'Closed Center Project',
            'center_id' => $this->closedCenterId,
            'city_id' => $this->cityId,
            'program_id' => $this->program->id,
            'user_id' => $this->owner,
        ]);
        $this->onActive = Project::factory()->create([
            'name' => 'Active Center Project',
            'center_id' => $this->centerId,
            'city_id' => $this->cityId,
            'program_id' => $this->program->id,
            'user_id' => $this->owner,
        ]);
    });

    test('the project index flags only the project whose center is inactive', function () {
        $page = Livewire::actingAs($this->user)->test('pages::projects.index')
            ->assertSeeText('Closed Center Project')
            ->assertSeeText('Active Center Project')
            ->assertSeeHtml('data-test="center-inactive-badge"');

        expect(substr_count($page->html(), 'data-test="center-inactive-badge"'))->toBe(1);
    });

    test('the project story header flags an inactive center and stays quiet for an active one', function () {
        $this->actingAs($this->user)
            ->get(route('projects.show', $this->onClosed))
            ->assertOk()
            ->assertSee('data-test="center-inactive-badge"', false);

        $this->actingAs($this->user)
            ->get(route('projects.show', $this->onActive))
            ->assertOk()
            ->assertDontSee('data-test="center-inactive-badge"', false);
    });

    test('the project index reads the centers once for every badge, never once per row', function () {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        Livewire::actingAs($this->user)->test('pages::projects.index')
            ->assertSeeHtml('data-test="center-inactive-badge"');

        $centerLookups = array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from "hchr_centers"'));

        // ProjectDirectoryTest's 24 + the unread-dot subjects query + one shared load of the HR centers lookup.
        expect($centerLookups)->toHaveCount(1)
            ->and(count($queries))->toBeLessThanOrEqual(26);
    });
});
