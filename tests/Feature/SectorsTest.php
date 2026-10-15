<?php

use App\Actions\Sectors\DeactivateSector;
use App\Actions\Sectors\DeleteSector;
use App\Actions\Sectors\MoveSector;
use App\Actions\Sectors\ReactivateSector;
use App\Actions\Sectors\SaveSector;
use App\Models\Project;
use App\Models\Sector;
use App\Support\DefaultSectors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * A sector some project already uses.
 */
function sectorInUse(array $attributes = []): Sector
{
    $sector = Sector::factory()->create($attributes);
    Project::factory()->create()->sectors()->attach($sector->id);

    return $sector;
}

describe('the page', function () {
    it('sends a guest to the login page', function () {
        $this->get(route('admin.sectors.index'))->assertRedirect(route('login'));
    });

    it('is open to every role, read-only for those who cannot manage it', function (string $role, bool $manages) {
        Sector::factory()->create(['name_ar' => 'الصحة', 'name_en' => 'Health']);

        $response = $this->actingAs(userWithRole($role))->get(route('admin.sectors.index'))
            ->assertOk()
            ->assertSee('Health');

        $manages
            ? $response->assertSee('data-test="create-sector"', false)
            : $response->assertDontSee('data-test="create-sector"', false);
    })->with([
        'MEAL' => ['MEAL', true],
        'General Manager' => ['General Manager', true],
        'Team Member' => ['Team Member', false],
        'Financial' => ['Financial', false],
    ]);

    it('explains an empty list and offers the create button only to managers', function () {
        Livewire::actingAs(userWithRole('MEAL'))->test('pages::admin.sectors')
            ->assertSee(__('sectors.empty_heading'))
            ->assertSeeHtml('data-test="empty-create-sector"');

        Livewire::actingAs(userWithRole('Team Member'))->test('pages::admin.sectors')
            ->assertSee(__('sectors.empty_heading'))
            ->assertDontSeeHtml('data-test="empty-create-sector"');
    });

    it('creates and edits a sector from the dialog', function () {
        $component = Livewire::actingAs(userWithRole('MEAL'))->test('pages::admin.sectors')
            ->call('openCreateModal')
            ->assertSet('showSectorModal', true)
            ->set('nameAr', 'الحماية')
            ->set('nameEn', 'Protection')
            ->call('saveSector')
            ->assertHasNoErrors()
            ->assertSet('showSectorModal', false);

        $sector = Sector::query()->sole();
        expect($sector->only(['name_ar', 'name_en', 'is_active']))->toBe(['name_ar' => 'الحماية', 'name_en' => 'Protection', 'is_active' => true]);

        $component->call('openEditModal', $sector->id)
            ->assertSet('nameAr', 'الحماية')
            ->set('nameEn', '')
            ->call('saveSector')
            ->assertHasNoErrors();

        expect($sector->fresh()->name_en)->toBeNull();
    });

    it('shows the field error next to the Arabic name', function () {
        Livewire::actingAs(userWithRole('MEAL'))->test('pages::admin.sectors')
            ->call('openCreateModal')
            ->set('nameAr', '   ')
            ->call('saveSector')
            ->assertHasErrors(['nameAr'])
            ->assertSet('showSectorModal', true);

        expect(Sector::query()->count())->toBe(0);
    });

    it('refuses every write for a role that cannot manage sectors', function (string $method, Closure $arguments) {
        $sector = Sector::factory()->create(['name_ar' => 'التعليم']);
        $user = userWithRole('Team Member');

        $fresh = fn () => Livewire::actingAs($user)->test('pages::admin.sectors');

        $fresh()->call($method, ...$arguments($sector))->assertForbidden();

        expect($sector->fresh()->only(['name_ar', 'is_active']))->toBe(['name_ar' => 'التعليم', 'is_active' => true])
            ->and(Sector::query()->count())->toBe(1);
    })->with([
        'open create' => ['openCreateModal', fn (Sector $sector) => []],
        'open edit' => ['openEditModal', fn (Sector $sector) => [$sector->id]],
        'confirm deactivate' => ['confirmDeactivate', fn (Sector $sector) => [$sector->id]],
        'reactivate' => ['reactivateSector', fn (Sector $sector) => [$sector->id]],
        'confirm delete' => ['confirmDelete', fn (Sector $sector) => [$sector->id]],
        'move' => ['moveSector', fn (Sector $sector) => [$sector->id, 'down']],
    ]);

    it('explains in the delete dialog that a used sector is deactivated instead', function () {
        $sector = sectorInUse(['name_en' => 'Shelter']);

        Livewire::actingAs(userWithRole('General Manager'))->test('pages::admin.sectors')
            ->call('confirmDelete', $sector->id)
            ->assertSet('showDeleteModal', true)
            ->assertSet('deletingSectorInUse', true)
            ->assertSee(__('sectors.in_use'))
            ->call('deactivateInsteadOfDelete')
            ->assertSet('showDeleteModal', false);

        expect($sector->fresh()->is_active)->toBeFalse();
    });

    it('moves a sector with the keyboard-reachable buttons and announces its new position', function () {
        [$first, $second] = [Sector::factory()->create(['name_en' => 'First', 'sort_order' => 1]), Sector::factory()->create(['name_en' => 'Second', 'sort_order' => 2])];

        Livewire::actingAs(userWithRole('MEAL'))->test('pages::admin.sectors')
            ->call('moveSector', $second->id, 'up')
            ->assertSet('announcement', __('sectors.moved_to', ['name' => 'Second', 'position' => 1, 'total' => 2]))
            ->assertSeeHtml('aria-live="polite"')
            ->assertDispatched('sector-moved', sectorId: $second->id, direction: 'up');

        expect(Sector::query()->ordered()->pluck('id')->all())->toBe([$second->id, $first->id]);
    });
});

describe('the actions', function () {
    it('lets MEAL and the General Manager create, edit, deactivate, reactivate and delete an unused sector', function (string $role) {
        $this->actingAs(userWithRole($role));

        $sector = app(SaveSector::class)->handle(null, ['name_ar' => 'الصحة', 'name_en' => 'Health']);
        app(SaveSector::class)->handle($sector->id, ['name_ar' => 'الصحة العامة', 'name_en' => null]);
        app(DeactivateSector::class)->handle($sector->id);
        app(ReactivateSector::class)->handle($sector->id);

        expect($sector->fresh()->only(['name_ar', 'name_en', 'is_active']))->toBe(['name_ar' => 'الصحة العامة', 'name_en' => null, 'is_active' => true]);

        app(DeleteSector::class)->handle($sector->id);

        expect(Sector::query()->count())->toBe(0);
    })->with(['MEAL', 'General Manager']);

    it('forbids every other role from writing to the sector list', function (string $role, Closure $write) {
        $sector = Sector::factory()->create(['name_ar' => 'التعليم']);
        $this->actingAs(userWithRole($role));

        expect(fn () => $write($sector))->toThrow(AuthorizationException::class);

        expect(Sector::query()->count())->toBe(1)
            ->and($sector->fresh()->only(['name_ar', 'is_active']))->toBe(['name_ar' => 'التعليم', 'is_active' => true]);
    })->with(['Team Member', 'Managers', 'Financial', 'Public Relations Manager'])->with([
        'create' => fn (Sector $sector) => app(SaveSector::class)->handle(null, ['name_ar' => 'جديد']),
        'update' => fn (Sector $sector) => app(SaveSector::class)->handle($sector->id, ['name_ar' => 'معدل']),
        'deactivate' => fn (Sector $sector) => app(DeactivateSector::class)->handle($sector->id),
        'delete' => fn (Sector $sector) => app(DeleteSector::class)->handle($sector->id),
        'move' => fn (Sector $sector) => app(MoveSector::class)->handle($sector->id, towardsStart: false),
    ]);

    it('lets every role view the sector list', function (string $role) {
        expect(userWithRole($role)->can('viewAny', Sector::class))->toBeTrue();
    })->with(['Team Member', 'Managers', 'Financial', 'Media Team', 'MEAL']);

    it('rejects a sector that breaks a form rule with its message', function (array $input, string $message) {
        $this->actingAs(userWithRole('MEAL'));

        expect(fn () => app(SaveSector::class)->handle(null, $input))->toThrow(ValidationException::class, $message);

        expect(Sector::query()->count())->toBe(0);
    })->with([
        'no arabic name' => [['name_ar' => '', 'name_en' => 'Health'], 'Enter the sector name in Arabic.'],
        'an arabic name too long' => [['name_ar' => str_repeat('ص', 256)], 'The Arabic name may not be longer than 255 characters.'],
        'an english name too long' => [['name_ar' => 'الصحة', 'name_en' => str_repeat('h', 256)], 'The English name may not be longer than 255 characters.'],
    ]);

    it('never takes the active flag or the position from the form, and puts a new sector last', function () {
        $this->actingAs(userWithRole('MEAL'));
        Sector::factory()->create(['sort_order' => 4]);

        $sector = app(SaveSector::class)->handle(null, ['name_ar' => 'الحماية', 'is_active' => false, 'sort_order' => 1]);

        expect($sector->fresh()->only(['is_active', 'sort_order']))->toBe(['is_active' => true, 'sort_order' => 5]);
    });

    it('refuses to delete a sector a project uses, and leaves it in place', function () {
        $this->actingAs(userWithRole('MEAL'));
        $sector = sectorInUse();

        expect(fn () => app(DeleteSector::class)->handle($sector->id))
            ->toThrow(ValidationException::class, 'This sector is in use. Deactivate it instead.');

        expect(Sector::query()->whereKey($sector->id)->exists())->toBeTrue()
            ->and(DB::table('project_sector')->where('sector_id', $sector->id)->count())->toBe(1);
    });

    it('deactivates a used sector and keeps it on its project', function () {
        $this->actingAs(userWithRole('MEAL'));
        $sector = sectorInUse();

        app(DeactivateSector::class)->handle($sector->id);

        expect($sector->fresh()->is_active)->toBeFalse()
            ->and(DB::table('project_sector')->where('sector_id', $sector->id)->count())->toBe(1);
    });

    it('refuses a second deactivate or reactivate as stale', function () {
        $this->actingAs(userWithRole('MEAL'));
        $active = Sector::factory()->create();
        $inactive = Sector::factory()->inactive()->create();

        expect(fn () => app(DeactivateSector::class)->handle($inactive->id))->toThrow(ValidationException::class, __('sectors.stale'))
            ->and(fn () => app(ReactivateSector::class)->handle($active->id))->toThrow(ValidationException::class, __('sectors.stale'));
    });

    it('keeps a dense order when sectors move, and a move past either end changes nothing', function () {
        $this->actingAs(userWithRole('MEAL'));
        $sectors = collect([1, 2, 3])->map(fn (int $position): Sector => Sector::factory()->create(['sort_order' => $position * 10]));

        app(MoveSector::class)->handle($sectors[2]->id, towardsStart: true);
        app(MoveSector::class)->handle($sectors[0]->id, towardsStart: true);

        expect(Sector::query()->ordered()->get(['id', 'sort_order'])->map->only(['id', 'sort_order'])->all())->toBe([
            ['id' => $sectors[0]->id, 'sort_order' => 1],
            ['id' => $sectors[2]->id, 'sort_order' => 2],
            ['id' => $sectors[1]->id, 'sort_order' => 3],
        ]);
    });
});

describe('active and inactive sectors', function () {
    it('drops an inactive sector from what can be chosen but keeps it on the project that has it', function () {
        $active = Sector::factory()->create(['name_en' => 'Still offered']);
        $inactive = sectorInUse(['name_en' => 'Withdrawn', 'is_active' => false]);
        $project = $inactive->projects()->sole();

        expect(Sector::query()->active()->pluck('id')->all())->toBe([$active->id])
            ->and(Sector::pickerOptions([])->pluck('id')->all())->toBe([$active->id])
            ->and(Sector::pickerOptions([$inactive->id])->pluck('id')->sort()->values()->all())->toBe(collect([$active->id, $inactive->id])->sort()->values()->all());

        $this->actingAs(userWithRole('Team Member'))->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Withdrawn')
            ->assertSee(__('sectors.inactive'));
    });
});

describe('the default sectors', function () {
    it('seeds the six D-47 sectors once, in order, however often it runs', function () {
        expect(DefaultSectors::ensure())->toBe(6)
            ->and(DefaultSectors::ensure())->toBe(0);

        expect(Sector::query()->ordered()->pluck('name_en')->all())->toBe([
            'Health',
            'Food security & livelihoods',
            'WASH',
            'Shelter & NFIs',
            'Protection',
            'Education',
        ])->and(Sector::query()->ordered()->pluck('name_ar')->all())->toBe([
            'الصحة',
            'الأمن الغذائي وسبل العيش',
            'المياه والصرف الصحي والنظافة',
            'المأوى والمواد غير الغذائية',
            'الحماية',
            'التعليم',
        ]);
    });

    it('does not re-create a default an admin renamed in one language, or one already deactivated', function () {
        DefaultSectors::ensure();
        Sector::query()->where('name_en', 'Health')->update(['name_ar' => 'الرعاية الصحية']);
        Sector::query()->where('name_en', 'WASH')->update(['is_active' => false]);

        expect(DefaultSectors::ensure())->toBe(0)
            ->and(Sector::query()->count())->toBe(6);
    });
});
