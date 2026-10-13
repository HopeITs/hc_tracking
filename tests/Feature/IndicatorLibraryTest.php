<?php

use App\Enums\IndicatorMeasure;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\PlanIndicator;
use App\Models\Program;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * An entry linked to one indicator, optionally with a target on it.
 */
function libraryEntryInUse(bool $withTargets = false, array $attributes = []): LibraryIndicator
{
    $entry = LibraryIndicator::factory()->create($attributes);
    $indicator = Indicator::factory()->create(['library_indicator_id' => $entry->id, 'code' => $entry->code, 'measure' => $entry->measure]);

    if ($withTargets) {
        PlanIndicator::factory()->create(['indicator_id' => $indicator->id]);
    }

    return $entry;
}

describe('access', function () {
    it('redirects guests to the login page', function () {
        $this->get(route('indicator-library.index'))->assertRedirect(route('login'));
    });

    it('shows the library to every role, and the write controls only to MEAL and the General Manager', function (string $role, bool $writes) {
        LibraryIndicator::factory()->create(['name_en' => 'Households reached']);

        $response = $this->actingAs(userWithRole($role))->get(route('indicator-library.index'))->assertOk()->assertSee('Households reached');

        $writes
            ? $response->assertSee('data-test="create-library-entry"', false)
            : $response->assertDontSee('data-test="create-library-entry"', false);
    })->with([
        'MEAL' => ['MEAL', true],
        'General Manager' => ['General Manager', true],
        'Team Member' => ['Team Member', false],
        'Managers' => ['Managers', false],
        'Financial' => ['Financial', false],
    ]);

    it('refuses a user without the view permission', function () {
        $user = userWithRole('Team Member');
        $user->syncRoles([]);

        $this->actingAs($user)->get(route('indicator-library.index'))->assertForbidden();
    });
});

describe('writing through the page', function () {
    it('lets MEAL and the General Manager create, edit, deactivate, reactivate and delete an unused entry', function (string $role) {
        $user = userWithRole($role);

        $component = Livewire::actingAs($user)->test('pages::indicator-library.index')
            ->call('openCreateModal')
            ->assertSet('showEntryModal', true)
            ->set('entry.institutional', true)
            ->set('entry.level_ref', 'OP1')
            ->set('entry.name_ar', 'عدد الأسر المستفيدة')
            ->set('entry.name_en', 'Households reached')
            ->set('entry.frequency', 'monthly')
            ->call('saveEntry')
            ->assertHasNoErrors()
            ->assertSet('showEntryModal', false)
            ->assertSee('HC.OP1.1');

        $entry = LibraryIndicator::query()->sole();

        $component
            ->call('openEditModal', $entry->id)
            ->assertSet('showEntryModal', true)
            ->assertSet('entry.name_en', 'Households reached')
            ->set('entry.name_en', 'Families reached')
            ->call('saveEntry')
            ->assertHasNoErrors()
            ->assertSee('Families reached')
            ->call('confirmDeactivate', $entry->id)
            ->assertSet('showDeactivateModal', true)
            ->call('deactivateEntry')
            ->assertSet('showDeactivateModal', false);

        expect($entry->fresh()->is_active)->toBeFalse();

        $component->call('reactivateEntry', $entry->id);

        expect($entry->fresh()->is_active)->toBeTrue();

        $component
            ->call('confirmDelete', $entry->id)
            ->assertSet('showDeleteModal', true)
            ->call('deleteEntry')
            ->assertSet('showDeleteModal', false)
            ->assertDontSee('Families reached');

        expect(LibraryIndicator::query()->exists())->toBeFalse();
    })->with(['MEAL', 'General Manager']);

    it('forbids every other role from each write', function (string $role, string $method, bool $passesId) {
        $entry = LibraryIndicator::factory()->create(['name_en' => 'Untouched']);
        $user = userWithRole($role);

        $component = Livewire::actingAs($user)->test('pages::indicator-library.index');

        $passesId ? $component->call($method, $entry->id) : $component->call($method);

        $component->assertForbidden();

        expect($entry->fresh()->only(['name_en', 'is_active']))->toBe(['name_en' => 'Untouched', 'is_active' => true])
            ->and(LibraryIndicator::query()->count())->toBe(1);
    })->with(['Team Member', 'Managers', 'Financial'])->with([
        'open create' => ['openCreateModal', false],
        'save a new entry' => ['saveEntry', false],
        'open edit' => ['openEditModal', true],
        'ask to deactivate' => ['confirmDeactivate', true],
        'reactivate' => ['reactivateEntry', true],
        'ask to delete' => ['confirmDelete', true],
    ]);

    it('shows the Arabic-name message under its field when the Arabic name is missing', function () {
        Livewire::actingAs(userWithRole('MEAL'))->test('pages::indicator-library.index')
            ->call('openCreateModal')
            ->set('entry.institutional', true)
            ->set('entry.level_ref', 'OP1')
            ->set('entry.frequency', 'monthly')
            ->call('saveEntry')
            ->assertHasErrors(['entry.name_ar'])
            ->assertSee(__('indicator_library.name_ar_required'))
            ->assertSet('showEntryModal', true);

        expect(LibraryIndicator::query()->exists())->toBeFalse();
    });
});

describe('measure', function () {
    it('refuses a measure change once a linked indicator has targets', function () {
        $entry = libraryEntryInUse(withTargets: true);

        Livewire::actingAs(userWithRole('MEAL'))->test('pages::indicator-library.index')
            ->call('openEditModal', $entry->id)
            ->assertSet('measureIsLocked', true)
            ->set('entry.measure', 'percentage')
            ->call('saveEntry')
            ->assertHasErrors(['entry.measure'])
            ->assertSee(__('indicator_library.measure_locked'));

        expect($entry->fresh()->measure)->toBe(IndicatorMeasure::Numeric);
    });

    it('carries a measure change to every linked indicator while none has targets', function () {
        $entry = libraryEntryInUse();

        Livewire::actingAs(userWithRole('MEAL'))->test('pages::indicator-library.index')
            ->call('openEditModal', $entry->id)
            ->assertSet('measureIsLocked', false)
            ->set('entry.measure', 'average')
            ->call('saveEntry')
            ->assertHasNoErrors();

        expect($entry->fresh()->measure)->toBe(IndicatorMeasure::Average)
            ->and(DB::table('indicators')->where('library_indicator_id', $entry->id)->value('measure'))->toBe('average');
    });
});

describe('deleting and deactivating', function () {
    it('refuses to delete an entry in use and says to deactivate it instead', function () {
        $entry = libraryEntryInUse();

        Livewire::actingAs(userWithRole('MEAL'))->test('pages::indicator-library.index')
            ->call('confirmDelete', $entry->id)
            ->call('deleteEntry')
            ->assertSet('showDeleteModal', false)
            ->assertDispatched('toast-show', fn (string $event, array $params) => ($params['slots']['text'] ?? null) === __('indicator_library.in_use'));

        expect(LibraryIndicator::query()->whereKey($entry->id)->exists())->toBeTrue();
    });

    it('drops a deactivated entry from the selectable list but keeps it on the page', function () {
        $active = LibraryIndicator::factory()->create(['name_en' => 'Still offered']);
        $inactive = LibraryIndicator::factory()->inactive()->create(['name_en' => 'Withdrawn']);

        expect(LibraryIndicator::query()->active()->pluck('id')->all())->toBe([$active->id]);

        Livewire::actingAs(userWithRole('Team Member'))->test('pages::indicator-library.index')
            ->assertSee('Withdrawn')
            ->assertSee(__('indicator_library.inactive'))
            ->set('status', 'active')
            ->assertSee('Still offered')
            ->assertDontSee('Withdrawn');

        expect($inactive->fresh()->is_active)->toBeFalse();
    });
});

describe('search and filters', function () {
    it('finds an Arabic name typed with or without tashkeel, tatweel or hamza', function (string $term) {
        LibraryIndicator::factory()->create(['name_ar' => 'نسبة الأطفال المُلَقَّحين', 'name_en' => 'Vaccinated children']);
        LibraryIndicator::factory()->create(['name_ar' => 'عدد الآبار', 'name_en' => 'Wells built']);

        Livewire::actingAs(userWithRole('Team Member'))->test('pages::indicator-library.index')
            ->set('search', $term)
            ->assertSee('Vaccinated children')
            ->assertDontSee('Wells built');
    })->with([
        'plain' => 'الملقحين',
        'with tashkeel' => 'المُلَقَّحين',
        'with tatweel' => 'الملـقحين',
        'bare alef for hamza' => 'الاطفال',
    ]);

    it('finds an entry by its code', function () {
        LibraryIndicator::factory()->create(['code' => 'HC.G.1', 'level_ref' => 'G', 'name_en' => 'Goal entry']);
        LibraryIndicator::factory()->create(['name_en' => 'Other entry']);

        Livewire::actingAs(userWithRole('Team Member'))->test('pages::indicator-library.index')
            ->set('search', 'HC.G')
            ->assertSee('Goal entry')
            ->assertDontSee('Other entry');
    });

    it('narrows the list by program, level, measure, collector and a missing English name', function (string $filter, string $value, string $expected) {
        $program = Program::factory()->create();

        LibraryIndicator::factory()->create(['name_en' => 'Institutional output', 'name_ar' => 'مخرج مؤسسي']);
        LibraryIndicator::factory()->percentage()->create([
            'program_id' => $program->id, 'code' => 'P.OC1.1', 'level_ref' => 'OC1',
            'name_en' => 'Program outcome', 'name_ar' => 'نتيجة البرنامج', 'collected_by' => 'meal',
        ]);
        LibraryIndicator::factory()->create(['name_en' => null, 'name_ar' => 'مؤشر بلا اسم إنجليزي', 'code' => 'HC.AC1.1', 'level_ref' => 'AC1']);

        $component = Livewire::actingAs(userWithRole('Team Member'))->test('pages::indicator-library.index')
            ->set($filter, $value === 'program' ? (string) $program->id : $value);

        expect($component->get('filteredEntries')->map->localizedName()->values()->all())->toBe([$expected]);
    })->with([
        'program' => ['program', 'program', 'Program outcome'],
        'level' => ['level', 'OC', 'Program outcome'],
        'measure' => ['measure', 'percentage', 'Program outcome'],
        'collector' => ['collector', 'meal', 'Program outcome'],
        'missing English' => ['english', 'missing', 'مؤشر بلا اسم إنجليزي'],
    ]);

    it('caps a long search term instead of matching against all of it', function () {
        LibraryIndicator::factory()->create(['name_en' => 'Households reached']);

        Livewire::actingAs(userWithRole('Team Member'))->test('pages::indicator-library.index')
            ->set('search', 'Households'.str_repeat(' ', 200).'x')
            ->assertSee('Households reached');
    });
});

describe('presentation', function () {
    it('shows an Arabic-only entry by its Arabic name with the missing-English badge', function () {
        LibraryIndicator::factory()->create(['name_en' => null, 'name_ar' => 'عدد الجلسات المنفذة']);

        $this->actingAs(userWithRole('Team Member'))->get(route('indicator-library.index'))
            ->assertOk()
            ->assertSee('عدد الجلسات المنفذة')
            ->assertSee(__('indicator_library.missing_english'));
    });

    it('renders its labels in Arabic for an Arabic reader', function () {
        LibraryIndicator::factory()->create();
        $user = userWithRole('MEAL');
        $user->forceFill(['locale' => 'ar'])->save();

        $this->actingAs($user)->get(route('indicator-library.index'))
            ->assertOk()
            ->assertSee('مكتبة المؤشرات')
            ->assertSee('إضافة مؤشر إلى المكتبة');
    });

    it('opens one entry from a notification link', function () {
        $entry = LibraryIndicator::factory()->create(['definition' => 'Count each household once.']);

        Livewire::withQueryParams(['entry' => $entry->id])
            ->actingAs(userWithRole('Team Member'))
            ->test('pages::indicator-library.index')
            ->assertSet('expandedEntryId', $entry->id)
            ->assertSee('Count each household once.');
    });

    it('reads a flat number of queries however many entries there are', function () {
        $user = userWithRole('MEAL');

        $countQueries = function () use ($user): int {
            $queries = 0;
            DB::listen(function () use (&$queries): void {
                $queries++;
            });

            Livewire::actingAs($user)->test('pages::indicator-library.index');

            return $queries;
        };

        foreach (range(1, 5) as $ignored) {
            libraryEntryInUse(withTargets: true, attributes: ['program_id' => Program::factory()->create()->id]);
        }
        // Warm the permission and HR-name caches so both counts start equal.
        $countQueries();
        $small = $countQueries();

        foreach (range(1, 45) as $ignored) {
            libraryEntryInUse(withTargets: true, attributes: ['program_id' => Program::factory()->create()->id]);
        }
        $large = $countQueries();

        expect($large)->toBe($small);
    });
});
