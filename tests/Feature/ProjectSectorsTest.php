<?php

use App\Actions\Locations\SyncLocationSectors;
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
 * The sector ids of a place, read straight from the pivot (Y-17: the row
 * carries its own sectors, `proposal_location_sector`).
 *
 * @return list<int>
 */
function placeSectorIds(int $rowId): array
{
    return DB::table('proposal_location_sector')->where('proposal_location_id', $rowId)->orderBy('sector_id')->pluck('sector_id')->map(fn (mixed $id): int => (int) $id)->all();
}

describe('SyncLocationSectors', function () {
    beforeEach(function () {
        $this->actingAs(userWithRole('General Manager'));
        // Y-17: sectors belong to the place, and the place IS a row.
        $this->project = rowFor(projectWithRow());
        [$this->health, $this->wash, $this->shelter] = [
            Sector::factory()->create(['name_ar' => 'الصحة', 'name_en' => 'Health']),
            Sector::factory()->create(['name_ar' => 'المياه', 'name_en' => 'WASH']),
            Sector::factory()->create(['name_ar' => 'المأوى', 'name_en' => 'Shelter']),
        ];
    });

    it('sets the sectors, then replaces them, ignoring a repeated id', function () {
        app(SyncLocationSectors::class)->handle($this->project, [$this->health->id, $this->wash->id, $this->health->id]);

        expect(placeSectorIds($this->project->id))->toBe([$this->health->id, $this->wash->id]);

        app(SyncLocationSectors::class)->handle($this->project, [$this->shelter->id]);

        expect(placeSectorIds($this->project->id))->toBe([$this->shelter->id]);
    });

    it('requires one sector when asked to, and writes nothing without it', function () {
        app(SyncLocationSectors::class)->handle($this->project, [$this->health->id]);

        expect(fn () => app(SyncLocationSectors::class)->handle($this->project, [], requireOne: true))
            ->toThrow(ValidationException::class, 'Choose at least one sector.');

        expect(placeSectorIds($this->project->id))->toBe([$this->health->id]);
    });

    it('clears the sectors of an existing place when one is not required', function () {
        app(SyncLocationSectors::class)->handle($this->project, [$this->health->id]);
        app(SyncLocationSectors::class)->handle($this->project, []);

        expect(placeSectorIds($this->project->id))->toBe([]);
    });

    it('refuses an inactive sector unless the place already has it', function () {
        $retired = Sector::factory()->inactive()->create();

        expect(fn () => app(SyncLocationSectors::class)->handle($this->project, [$this->health->id, $retired->id]))
            ->toThrow(ValidationException::class, 'That sector is no longer available. Choose another one.');
        expect(placeSectorIds($this->project->id))->toBe([]);

        DB::table('proposal_location_sector')->insert(['proposal_location_id' => $this->project->id, 'sector_id' => $retired->id, 'created_at' => now(), 'updated_at' => now()]);

        app(SyncLocationSectors::class)->handle($this->project, [$retired->id, $this->wash->id]);

        expect(placeSectorIds($this->project->id))->toBe(collect([$retired->id, $this->wash->id])->sort()->values()->all());
    });

    it('refuses an id that is not a sector, and the whole batch with it', function (mixed $bogus) {
        expect(fn () => app(SyncLocationSectors::class)->handle($this->project, [$this->health->id, $bogus]))
            ->toThrow(ValidationException::class);

        expect(placeSectorIds($this->project->id))->toBe([]);
    })->with([
        'missing id' => 999999,
        'zero' => 0,
        'text' => 'health',
    ]);

    it('is refused for a user who cannot update the place', function () {
        $this->actingAs(userWithRole('Team Member'));

        expect(fn () => app(SyncLocationSectors::class)->handle($this->project, [$this->health->id]))
            ->toThrow(AuthorizationException::class);

        expect(placeSectorIds($this->project->id))->toBe([]);
    });

    it('records the change with names only, and nothing when nothing changed', function () {
        app(SyncLocationSectors::class)->handle($this->project, [$this->health->id, $this->wash->id]);
        app(SyncLocationSectors::class)->handle($this->project, [$this->wash->id, $this->health->id]);
        app(SyncLocationSectors::class)->handle($this->project, [$this->wash->id, $this->shelter->id]);

        $activities = Activity::query()->where('event', 'project.sectors_changed')->orderBy('id')->get();

        expect($activities)->toHaveCount(2)
            ->and($activities->every(fn (Activity $activity): bool => $activity->subject_type === 'proposal_location' && $activity->subject_id === $this->project->id))->toBeTrue()
            ->and(collect($activities[1]->properties)->only(['added', 'removed', 'added_sectors', 'removed_sectors'])->all())->toEqual([
                'added' => 'المأوى',
                'removed' => 'الصحة',
                'added_sectors' => ['المأوى'],
                'removed_sectors' => ['الصحة'],
            ]);
    });
});

describe('the place form', function () {
    /*
    | Y-17: six cases went with the create form, which the [contract] removes
    | in §8 (nothing creates a project by hand): creating with sectors, a PR
    | Manager creating one, a new project refused without a sector, the
    | missing sector reported with the other errors, the action requiring one,
    | and the materialiser creating one without sectors. They are named in the
    | Y-17 handoff. Editing a place's sectors is what is left, and it is a
    | place — a location row — that is edited.
    */
    beforeEach(function () {
        $this->user = User::factory()->role('General Manager')->create();
        $this->cityId = insertHrCity('Idlib', 'إدلب');
        $this->centerId = insertHrCenter($this->cityId, 'Hope Center Idlib', 'مركز الأمل إدلب');
        $this->owner = insertHrEmployee();
        $this->program = Program::factory()->create(['manager_id' => $this->owner]);
        $this->health = Sector::factory()->create(['name_ar' => 'الصحة', 'name_en' => 'Health']);
    });

    it('loads the place sectors on edit, and saves a place that has none as it is', function () {
        $legacy = rowFor(projectWithRow(['center_id' => $this->centerId, 'city_id' => $this->cityId, 'program_id' => $this->program->id, 'user_id' => $this->owner]));
        $withSector = rowFor(projectWithRow(['program_id' => Program::factory()->create(['manager_id' => $this->owner])->id, 'user_id' => $this->owner]));
        $withSector->sectors()->attach($this->health->id);

        Livewire::actingAs($this->user)->test('pages::projects.index')
            ->call('openEditModal', $withSector->id)
            ->assertSet('sectorIds', [(string) $this->health->id])
            ->call('openEditModal', $legacy->id)
            ->assertSet('sectorIds', [])
            ->set('description', 'Renamed legacy')
            ->call('saveProject')
            ->assertHasNoErrors();

        expect($legacy->fresh()->description)->toBe('Renamed legacy')
            ->and(placeSectorIds($legacy->id))->toBe([]);
    });

    it('sets the sectors of a place from the form', function () {
        $row = rowFor(projectWithRow(['center_id' => $this->centerId, 'city_id' => $this->cityId, 'program_id' => $this->program->id, 'user_id' => $this->owner]));

        Livewire::actingAs($this->user)->test('pages::projects.index')
            ->call('openEditModal', $row->id)
            ->assertSeeHtml('data-test="sector-picker"')
            ->set('sectorIds', [(string) $this->health->id])
            ->call('saveProject')
            ->assertHasNoErrors();

        expect(placeSectorIds($row->id))->toBe([$this->health->id]);
    });

    it('offers active sectors only, plus an inactive one the place already has, marked inactive', function () {
        $retired = Sector::factory()->inactive()->create(['name_en' => 'Retired sector']);
        $hidden = Sector::factory()->inactive()->create(['name_en' => 'Never offered']);
        $row = rowFor(projectWithRow(['center_id' => $this->centerId, 'city_id' => $this->cityId, 'program_id' => $this->program->id, 'user_id' => $this->owner]));
        $row->sectors()->attach($retired->id);

        Livewire::actingAs($this->user)->test('pages::projects.index')
            ->call('openEditModal', $row->id)
            ->assertSeeHtml('data-picker-sector="'.$this->health->id.'"')
            ->assertSeeHtml('data-picker-sector="'.$retired->id.'"')
            ->assertDontSeeHtml('data-picker-sector="'.$hidden->id.'"')
            ->assertSee('Retired sector')
            ->assertSee(__('sectors.inactive'));
    });
});

describe('display', function () {
    it('shows the sector chips on the project page, with the inactive badge', function () {
        // Y-11, Y-16: sectors live on the row (proposal_location_sector), and
        // the page reads them there; the project keeps its own until Y-18.
        $project = projectWithRow();
        $sectorIds = [
            Sector::factory()->create(['name_en' => 'Protection', 'sort_order' => 1])->id,
            Sector::factory()->inactive()->create(['name_en' => 'Old sector', 'sort_order' => 2])->id,
        ];
        $project->sectors()->attach($sectorIds);
        rowFor($project)->sectors()->attach($sectorIds);

        $this->actingAs(userWithRole('Team Member'))->get(route('projects.show', rowFor($project)))
            ->assertOk()
            ->assertSeeInOrder(['data-test="project-sectors"', 'Protection', 'Old sector', __('sectors.inactive')], false);
    });

    it('shows a sectors column on the list and narrows it by sector', function () {
        $health = Sector::factory()->create(['name_en' => 'Health']);
        $wash = Sector::factory()->create(['name_en' => 'WASH']);
        $clinic = projectWithRow(['name' => 'Clinic project']);
        $wells = projectWithRow(['name' => 'Wells project']);
        // Y-17 (D-93): the register filters places, and a place carries its
        // own sectors — SyncLocationSectors is their one writer.
        rowFor($clinic)->sectors()->attach($health->id);
        rowFor($wells)->sectors()->attach($wash->id);

        Livewire::actingAs(userWithRole('General Manager'))->test('pages::projects.index')
            ->set('view', 'list')
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

            Livewire::actingAs($user)->test('pages::projects.index')->set('view', 'list');

            return $queries;
        };

        $seed(2);
        $small = $countQueries();

        $seed(8);
        $large = $countQueries();

        expect($large)->toBeLessThanOrEqual($small + 2);
    });
});
