<?php

use App\Enums\ProjectStatus;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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

test('the form takes no city of its own, so a tampered city never reaches the project', function () {
    expect(fn () => ($this->create)(['name' => 'Water', 'cityId' => (string) insertHrCity('Hama')]))
        ->toThrow(Exception::class, 'Public property [$cityId] not found');

    expect(Project::query()->count())->toBe(0);
});

test('choosing the program and center proposes «Program — Center» as an editable name', function () {
    $form = Livewire::actingAs($this->user)
        ->test('pages::projects.index')
        ->call('openCreateModal')
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

test('the center is required, must exist and must be active', function (string|int $centerId) {
    ($this->create)(['name' => 'No center', 'centerId' => (string) $centerId])
        ->assertHasErrors(['centerId'])
        ->assertSee('Choose the center.');

    expect(Project::query()->count())->toBe(0);
})->with([
    'missing' => '',
    'unknown' => 999999,
    'inactive' => fn () => insertHrCenter(insertHrCity(), 'Closed', isActive: false),
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
        ->assertSee('Inactive center');
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
