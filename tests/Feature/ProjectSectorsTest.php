<?php

use App\Actions\Projects\SaveProject;
use App\Actions\Projects\SyncProjectSectors;
use App\Models\Activity;
use App\Models\Program;
use App\Models\Project;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * The sector ids linked to a project, read straight from the pivot.
 *
 * @return list<int>
 */
function projectSectorIds(int $projectId): array
{
    return DB::table('project_sector')->where('project_id', $projectId)->orderBy('sector_id')->pluck('sector_id')->map(fn (mixed $id): int => (int) $id)->all();
}

describe('SyncProjectSectors', function () {
    beforeEach(function () {
        $this->actingAs(userWithRole('General Manager'));
        $this->project = Project::factory()->create();
        [$this->health, $this->wash, $this->shelter] = [
            Sector::factory()->create(['name_ar' => 'الصحة', 'name_en' => 'Health']),
            Sector::factory()->create(['name_ar' => 'المياه', 'name_en' => 'WASH']),
            Sector::factory()->create(['name_ar' => 'المأوى', 'name_en' => 'Shelter']),
        ];
    });

    it('sets the sectors, then replaces them, ignoring a repeated id', function () {
        app(SyncProjectSectors::class)->handle($this->project, [$this->health->id, $this->wash->id, $this->health->id]);

        expect(projectSectorIds($this->project->id))->toBe([$this->health->id, $this->wash->id]);

        app(SyncProjectSectors::class)->handle($this->project, [$this->shelter->id]);

        expect(projectSectorIds($this->project->id))->toBe([$this->shelter->id]);
    });

    it('requires one sector when asked to, and writes nothing without it', function () {
        app(SyncProjectSectors::class)->handle($this->project, [$this->health->id]);

        expect(fn () => app(SyncProjectSectors::class)->handle($this->project, [], requireOne: true))
            ->toThrow(ValidationException::class, 'Choose at least one sector.');

        expect(projectSectorIds($this->project->id))->toBe([$this->health->id]);
    });

    it('clears the sectors of an existing project when one is not required', function () {
        app(SyncProjectSectors::class)->handle($this->project, [$this->health->id]);
        app(SyncProjectSectors::class)->handle($this->project, []);

        expect(projectSectorIds($this->project->id))->toBe([]);
    });

    it('refuses an inactive sector unless the project already has it', function () {
        $retired = Sector::factory()->inactive()->create();

        expect(fn () => app(SyncProjectSectors::class)->handle($this->project, [$this->health->id, $retired->id]))
            ->toThrow(ValidationException::class, 'That sector is no longer available. Choose another one.');
        expect(projectSectorIds($this->project->id))->toBe([]);

        DB::table('project_sector')->insert(['project_id' => $this->project->id, 'sector_id' => $retired->id, 'created_at' => now(), 'updated_at' => now()]);

        app(SyncProjectSectors::class)->handle($this->project, [$retired->id, $this->wash->id]);

        expect(projectSectorIds($this->project->id))->toBe(collect([$retired->id, $this->wash->id])->sort()->values()->all());
    });

    it('refuses an id that is not a sector, and the whole batch with it', function (mixed $bogus) {
        expect(fn () => app(SyncProjectSectors::class)->handle($this->project, [$this->health->id, $bogus]))
            ->toThrow(ValidationException::class);

        expect(projectSectorIds($this->project->id))->toBe([]);
    })->with([
        'missing id' => 999999,
        'zero' => 0,
        'text' => 'health',
    ]);

    it('is refused for a user who cannot update the project', function () {
        $this->actingAs(userWithRole('Team Member'));

        expect(fn () => app(SyncProjectSectors::class)->handle($this->project, [$this->health->id]))
            ->toThrow(AuthorizationException::class);

        expect(projectSectorIds($this->project->id))->toBe([]);
    });

    it('records the change with names only, and nothing when nothing changed', function () {
        app(SyncProjectSectors::class)->handle($this->project, [$this->health->id, $this->wash->id]);
        app(SyncProjectSectors::class)->handle($this->project, [$this->wash->id, $this->health->id]);
        app(SyncProjectSectors::class)->handle($this->project, [$this->wash->id, $this->shelter->id]);

        $activities = Activity::query()->where('event', 'project.sectors_changed')->orderBy('id')->get();

        expect($activities)->toHaveCount(2)
            ->and($activities->every(fn (Activity $activity): bool => $activity->subject_type === 'project' && $activity->subject_id === $this->project->id))->toBeTrue()
            ->and(collect($activities[1]->properties)->only(['added', 'removed', 'added_sectors', 'removed_sectors'])->all())->toEqual([
                'added' => 'المأوى',
                'removed' => 'الصحة',
                'added_sectors' => ['المأوى'],
                'removed_sectors' => ['الصحة'],
            ]);
    });
});

describe('the project form', function () {
    beforeEach(function () {
        $this->user = User::factory()->role('General Manager')->create();
        $this->cityId = insertHrCity('Idlib', 'إدلب');
        $this->centerId = insertHrCenter($this->cityId, 'Hope Center Idlib', 'مركز الأمل إدلب');
        $this->owner = insertHrEmployee();
        $this->program = Program::factory()->create(['manager_id' => $this->owner]);
        $this->health = Sector::factory()->create(['name_ar' => 'الصحة', 'name_en' => 'Health']);
        $this->form = fn (array $fields = []): array => [
            'name' => 'Idlib Micro',
            'centerId' => (string) $this->centerId,
            'programId' => (string) $this->program->id,
            'userId' => (string) $this->owner,
            ...$fields,
        ];
    });

    it('creates a project with its sectors', function () {
        Livewire::actingAs($this->user)->test('pages::projects.index')
            ->call('openCreateModal')
            ->assertSeeHtml('data-test="sector-picker"')
            ->set(($this->form)(['sectorIds' => [(string) $this->health->id]]))
            ->call('saveProject')
            ->assertHasNoErrors();

        expect(projectSectorIds(Project::query()->sole()->id))->toBe([$this->health->id]);
    });

    it('lets a Public Relations Manager, who may create but not update projects, create one with its sectors', function () {
        $prManager = userWithRole('Public Relations Manager');

        expect($prManager->can('projects.update'))->toBeFalse();

        Livewire::actingAs($prManager)->test('pages::projects.index')
            ->call('openCreateModal')
            ->set(($this->form)(['sectorIds' => [(string) $this->health->id]]))
            ->call('saveProject')
            ->assertHasNoErrors();

        expect(projectSectorIds(Project::query()->sole()->id))->toBe([$this->health->id]);
    });

    it('refuses a new project without a sector and creates nothing', function () {
        Livewire::actingAs($this->user)->test('pages::projects.index')
            ->call('openCreateModal')
            ->set(($this->form)())
            ->call('saveProject')
            ->assertHasErrors(['sectorIds'])
            ->assertSee('Choose at least one sector.');

        expect(Project::query()->count())->toBe(0)
            ->and(DB::table('project_sector')->count())->toBe(0);
    });

    it('reports a missing sector together with the other field errors', function () {
        Livewire::actingAs($this->user)->test('pages::projects.index')
            ->call('openCreateModal')
            ->set(($this->form)(['programId' => '']))
            ->call('saveProject')
            ->assertHasErrors(['programId', 'sectorIds']);

        expect(Project::query()->count())->toBe(0);
    });

    it('requires the sector on the action too, whoever calls it', function () {
        $this->actingAs($this->user);

        expect(fn () => app(SaveProject::class)->handle(null, ($this->form)(), $this->user))
            ->toThrow(ValidationException::class, 'Choose at least one sector.');

        expect(Project::query()->count())->toBe(0);
    });

    it('loads the linked sectors on edit and lets an older project without sectors be saved as it is', function () {
        $legacy = Project::factory()->create(['center_id' => $this->centerId, 'city_id' => $this->cityId, 'program_id' => $this->program->id, 'user_id' => $this->owner]);
        $withSector = Project::factory()->create(['program_id' => Program::factory()->create(['manager_id' => $this->owner])->id, 'user_id' => $this->owner]);
        $withSector->sectors()->attach($this->health->id);

        Livewire::actingAs($this->user)->test('pages::projects.index')
            ->call('openEditModal', $withSector->id)
            ->assertSet('sectorIds', [(string) $this->health->id])
            ->call('openEditModal', $legacy->id)
            ->assertSet('sectorIds', [])
            ->set('name', 'Renamed legacy')
            ->call('saveProject')
            ->assertHasNoErrors();

        expect($legacy->fresh()->name)->toBe('Renamed legacy')
            ->and(projectSectorIds($legacy->id))->toBe([]);
    });

    it('offers active sectors only, plus an inactive one the project already has, marked inactive', function () {
        $retired = Sector::factory()->inactive()->create(['name_en' => 'Retired sector']);
        $hidden = Sector::factory()->inactive()->create(['name_en' => 'Never offered']);
        $project = Project::factory()->create(['center_id' => $this->centerId, 'city_id' => $this->cityId, 'program_id' => $this->program->id, 'user_id' => $this->owner]);
        $project->sectors()->attach($retired->id);

        Livewire::actingAs($this->user)->test('pages::projects.index')
            ->call('openEditModal', $project->id)
            ->assertSeeHtml('data-picker-sector="'.$this->health->id.'"')
            ->assertSeeHtml('data-picker-sector="'.$retired->id.'"')
            ->assertDontSeeHtml('data-picker-sector="'.$hidden->id.'"')
            ->assertSee('Retired sector')
            ->assertSee(__('sectors.inactive'));
    });
});

describe('display', function () {
    it('shows the sector chips on the project page, with the inactive badge', function () {
        $project = Project::factory()->create();
        $project->sectors()->attach([
            Sector::factory()->create(['name_en' => 'Protection', 'sort_order' => 1])->id,
            Sector::factory()->inactive()->create(['name_en' => 'Old sector', 'sort_order' => 2])->id,
        ]);

        $this->actingAs(userWithRole('Team Member'))->get(route('projects.show', $project))
            ->assertOk()
            ->assertSeeInOrder(['data-test="project-sectors"', 'Protection', 'Old sector', __('sectors.inactive')], false);
    });

    it('shows a sectors column on the list and narrows it by sector', function () {
        $health = Sector::factory()->create(['name_en' => 'Health']);
        $wash = Sector::factory()->create(['name_en' => 'WASH']);
        $clinic = Project::factory()->create(['name' => 'Clinic project']);
        $wells = Project::factory()->create(['name' => 'Wells project']);
        $clinic->sectors()->attach($health->id);
        $wells->sectors()->attach($wash->id);

        Livewire::actingAs(userWithRole('General Manager'))->test('pages::projects.index')
            ->set('view', 'table')
            ->assertSee('Clinic project')
            ->assertSee('Wells project')
            ->assertSeeHtml('data-test="project-sectors-cell"')
            ->set('sector', (string) $health->id)
            ->assertSee('Clinic project')
            ->assertDontSee('Wells project');
    });

    it('keeps the list query count flat however many projects and sectors there are', function () {
        $user = userWithRole('General Manager');

        $seed = function (int $projects): void {
            $sectors = Sector::factory()->count(3)->create();

            Project::factory()->count($projects)->create()->each(fn (Project $project) => $project->sectors()->attach($sectors->pluck('id')->all()));
        };

        $countQueries = function () use ($user): int {
            $queries = 0;
            DB::listen(function () use (&$queries): void {
                $queries++;
            });

            Livewire::actingAs($user)->test('pages::projects.index')->set('view', 'table');

            return $queries;
        };

        $seed(2);
        $small = $countQueries();

        $seed(8);
        $large = $countQueries();

        expect($large)->toBeLessThanOrEqual($small + 2);
    });
});
