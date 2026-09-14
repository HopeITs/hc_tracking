<?php

use App\Enums\ProjectStatus;
use App\Models\City;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected from the project directory', function () {
    $this->get(route('projects.index'))->assertRedirect(route('login'));
});

test('authenticated users see the project directory', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('projects.index'))->assertOk()->assertSeeText('Projects');
});

test('projects can be filtered by search city program owner and status', function () {
    $user = User::factory()->create();
    $firstOwner = User::factory()->create(['name' => 'Layla Haddad']);
    $secondOwner = User::factory()->create(['name' => 'Omar Khalil']);
    $aleppo = City::factory()->create(['name' => 'Aleppo']);
    $homs = City::factory()->create(['name' => 'Homs']);
    $water = Program::factory()->create(['name' => 'Water Security']);
    $shelter = Program::factory()->create(['name' => 'Safe Shelter']);

    Project::factory()->active()->create(['name' => 'Aleppo Wells', 'city_id' => $aleppo->id, 'program_id' => $water->id, 'user_id' => $firstOwner->id]);
    Project::factory()->closed()->create(['name' => 'Homs Shelter', 'city_id' => $homs->id, 'program_id' => $shelter->id, 'user_id' => $secondOwner->id]);

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->assertSee('Aleppo Wells')->assertSee('Homs Shelter')
        ->set('search', 'Wells')->assertSee('Aleppo Wells')->assertDontSee('Homs Shelter')
        ->call('clearFilters')->set('city', (string) $homs->id)->assertDontSee('Aleppo Wells')->assertSee('Homs Shelter')
        ->call('clearFilters')->set('program', (string) $water->id)->assertSee('Aleppo Wells')->assertDontSee('Homs Shelter')
        ->call('clearFilters')->set('owner', (string) $secondOwner->id)->assertDontSee('Aleppo Wells')->assertSee('Homs Shelter')
        ->call('clearFilters')->set('status', ProjectStatus::Active->value)->assertSee('Aleppo Wells')->assertDontSee('Homs Shelter')
        ->call('clearFilters')->assertSet('status', '')->assertSee('Aleppo Wells')->assertSee('Homs Shelter');
});

test('the status URL filter selects projects by lifecycle state', function () {
    $user = User::factory()->create();
    Project::factory()->active()->create(['name' => 'Active Water Network']);
    Project::factory()->closed()->create(['name' => 'Closed Shelter Project']);

    $this->actingAs($user)
        ->get(route('projects.index', ['status' => ProjectStatus::Active->value]))
        ->assertOk()
        ->assertSeeText('Active Water Network')
        ->assertDontSeeText('Closed Shelter Project');
});

test('a project can be created with lifecycle dates and independent beneficiary targets', function () {
    $user = User::factory()->create();
    $city = City::factory()->create();
    $program = Program::factory()->create();
    $owner = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->call('openCreateModal')
        ->set('name', '  Idlib Water Network  ')
        ->set('description', '  Restores reliable household water access.  ')
        ->set('projectStatus', ProjectStatus::Approved->value)
        ->set('startDate', '2026-09-01')
        ->set('endDate', '2027-08-31')
        ->set('targetBeneficiaries', '1000')
        ->set('targetMen', '180')
        ->set('targetWomen', '220')
        ->set('targetBoys', '140')
        ->set('targetGirls', '160')
        ->set('cityId', (string) $city->id)
        ->set('programId', (string) $program->id)
        ->set('userId', (string) $owner->id)
        ->call('saveProject')
        ->assertHasNoErrors()
        ->assertSet('showProjectModal', false);

    $project = Project::query()->where('name', 'Idlib Water Network')->sole();

    expect($project->description)->toBe('Restores reliable household water access.')
        ->and($project->status)->toBe(ProjectStatus::Approved)
        ->and($project->start_date?->toDateString())->toBe('2026-09-01')
        ->and($project->end_date?->toDateString())->toBe('2027-08-31')
        ->and($project->target_beneficiaries)->toBe(1000)
        ->and($project->targetDemographicTotal())->toBe(700)
        ->and($project->city_id)->toBe($city->id)
        ->and($project->program_id)->toBe($program->id)
        ->and($project->user_id)->toBe($owner->id);
});

test('nullable project details are normalized before saving', function () {
    $user = User::factory()->create();
    $city = City::factory()->create();
    $program = Program::factory()->create();
    $owner = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->call('openCreateModal')
        ->set('name', 'Project without targets')
        ->set('description', '   ')
        ->set('cityId', (string) $city->id)
        ->set('programId', (string) $program->id)
        ->set('userId', (string) $owner->id)
        ->call('saveProject')
        ->assertHasNoErrors();

    $project = Project::query()->where('name', 'Project without targets')->sole();

    expect($project->description)->toBeNull()
        ->and($project->start_date)->toBeNull()
        ->and($project->end_date)->toBeNull()
        ->and($project->target_beneficiaries)->toBeNull()
        ->and($project->targetDemographicTotal())->toBeNull();
});

test('creating a project requires a name a city a program and an owner', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->call('openCreateModal')
        ->set('name', '')
        ->set('cityId', '')
        ->set('programId', '')
        ->set('userId', '')
        ->call('saveProject')
        ->assertHasErrors(['name', 'cityId', 'programId', 'userId']);

    expect(Project::query()->count())->toBe(0);
});

test('project lifecycle dates and targets reject invalid values', function () {
    $user = User::factory()->create();
    $city = City::factory()->create();
    $program = Program::factory()->create();
    $owner = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->call('openCreateModal')
        ->set('name', 'Invalid Project')
        ->set('cityId', (string) $city->id)
        ->set('programId', (string) $program->id)
        ->set('userId', (string) $owner->id)
        ->set('projectStatus', 'ACTIVE')
        ->set('startDate', '2026-09-10')
        ->set('endDate', '2026-09-09')
        ->set('targetBeneficiaries', '-1')
        ->set('targetMen', '1.5')
        ->set('targetWomen', '-2')
        ->set('targetBoys', 'not-a-number')
        ->set('targetGirls', '-3')
        ->call('saveProject')
        ->assertHasErrors(['projectStatus', 'endDate', 'targetBeneficiaries', 'targetMen', 'targetWomen', 'targetBoys', 'targetGirls']);

    expect(Project::query()->count())->toBe(0);
});

test('a project can be renamed relocated and moved through its lifecycle', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create([
        'name' => 'Old Name',
        'description' => 'Original scope',
        'status' => ProjectStatus::Approved,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'target_beneficiaries' => 500,
        'target_men' => 100,
        'target_women' => 120,
        'target_boys' => 80,
        'target_girls' => 90,
    ]);
    $newCity = City::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->call('openEditModal', $project->id)
        ->assertSet('name', 'Old Name')
        ->assertSet('description', 'Original scope')
        ->assertSet('projectStatus', ProjectStatus::Approved->value)
        ->assertSet('startDate', '2026-01-01')
        ->assertSet('endDate', '2026-12-31')
        ->assertSet('targetBeneficiaries', '500')
        ->assertSet('targetMen', '100')
        ->assertSet('targetWomen', '120')
        ->assertSet('targetBoys', '80')
        ->assertSet('targetGirls', '90')
        ->set('name', 'New Name')
        ->set('description', '  Updated scope  ')
        ->set('projectStatus', ProjectStatus::Active->value)
        ->set('startDate', '2026-02-01')
        ->set('endDate', '2027-01-31')
        ->set('cityId', (string) $newCity->id)
        ->set('targetBeneficiaries', '450')
        ->set('targetMen', '100')
        ->set('targetWomen', '120')
        ->set('targetBoys', '80')
        ->set('targetGirls', '90')
        ->call('saveProject')
        ->assertHasNoErrors();

    $project->refresh();

    expect($project->name)->toBe('New Name')
        ->and($project->description)->toBe('Updated scope')
        ->and($project->status)->toBe(ProjectStatus::Active)
        ->and($project->start_date?->toDateString())->toBe('2026-02-01')
        ->and($project->end_date?->toDateString())->toBe('2027-01-31')
        ->and($project->city_id)->toBe($newCity->id)
        ->and($project->target_beneficiaries)->toBe(450)
        ->and($project->targetDemographicTotal())->toBe(390);
});

test('the project directory offers no delete action', function () {
    $user = User::factory()->create();
    Project::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->assertDontSee('wire:click="deleteProject', false)
        ->assertDontSee('Delete project', false);
});

test('the project directory renders with no projects', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::projects.index')->assertSee('No projects yet');
});
