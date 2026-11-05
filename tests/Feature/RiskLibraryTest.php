<?php

use App\Actions\RiskLibrary\DeactivateRiskLibraryEntry;
use App\Actions\RiskLibrary\DeleteRiskLibraryEntry;
use App\Actions\RiskLibrary\MoveRiskLibraryEntry;
use App\Actions\RiskLibrary\ReactivateRiskLibraryEntry;
use App\Actions\RiskLibrary\SaveRiskLibraryEntry;
use App\Models\Proposal;
use App\Models\Result;
use App\Models\Risk;
use App\Models\RiskLibrary;
use App\Models\User;
use App\Support\DefaultRiskLibrary;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * A library entry some proposal's risk was picked from.
 */
function usedRiskLibraryEntry(array $attributes = []): RiskLibrary
{
    $entry = RiskLibrary::factory()->create($attributes);
    $proposal = Proposal::factory()->create();
    $result = Result::factory()->create(['proposal_id' => $proposal->id]);
    Risk::factory()->create([
        'project_id' => null,
        'library_risk_id' => $entry->id,
        'proposal_id' => $proposal->id,
        'result_id' => $result->id,
    ]);

    return $entry;
}

/**
 * A user of the given role who also holds the MEAL Manager slot, the one
 * person who manages the library (contracts.md §8, D-61).
 */
function libraryManager(string $role): User
{
    $employeeId = insertHrEmployee(firstNameEn: 'Maha', lastNameEn: 'Manager');
    holdMealManagerSlot($employeeId);

    return userWithRole($role, $employeeId);
}

describe('the page', function () {
    it('sends a guest to the login page', function () {
        $this->get(route('admin.risk-library.index'))->assertRedirect(route('login'));
    });

    it('is open to the MEAL Manager slot holder only', function (string $who, int $status) {
        RiskLibrary::factory()->create(['title_en' => 'Funding delay']);

        $user = match ($who) {
            'slot MEAL' => mealManager(),
            'slot General Manager' => libraryManager('General Manager'),
            'MEAL without the slot' => userWithRole('MEAL', insertHrEmployee(firstNameEn: 'Omar', lastNameEn: 'Officer')),
            'Team Member' => userWithRole('Team Member'),
            'Financial' => userWithRole('Financial'),
        };

        $this->actingAs($user)->get(route('admin.risk-library.index'))->assertStatus($status);
    })->with([
        'slot MEAL' => ['slot MEAL', 200],
        'slot General Manager' => ['slot General Manager', 200],
        'MEAL without the slot' => ['MEAL without the slot', 403],
        'Team Member' => ['Team Member', 403],
        'Financial' => ['Financial', 403],
    ]);

    it('renders in Arabic with the Arabic title and button', function () {
        RiskLibrary::factory()->create(['title_ar' => 'تأخر التمويل']);
        app()->setLocale('ar');

        $this->actingAs(mealManager())->get(route('admin.risk-library.index'))
            ->assertOk()
            ->assertSee(__('titles.risk_library'))
            ->assertSee('تأخر التمويل')
            ->assertSee(__('risk_library.add_entry'));
    });

    it('explains an empty library and offers the create button', function () {
        Livewire::actingAs(mealManager())->test('pages::admin.risk-library')
            ->assertSee(__('risk_library.empty_heading'))
            ->assertSeeHtml('data-test="empty-create-risk-library-entry"');
    });

    it('creates and edits an entry from the dialog', function () {
        $component = Livewire::actingAs(mealManager())->test('pages::admin.risk-library')
            ->call('openCreateModal')
            ->assertSet('showEntryModal', true)
            ->set('titleEn', 'Funding delay')
            ->set('titleAr', 'تأخر التمويل')
            ->set('category', 'financial')
            ->set('defaultLikelihood', '2')
            ->set('defaultSeverity', '3')
            ->call('saveEntry')
            ->assertHasNoErrors()
            ->assertSet('showEntryModal', false);

        $entry = RiskLibrary::query()->sole();
        expect($entry->only(['title_en', 'title_ar', 'category', 'default_likelihood', 'default_severity', 'is_active']))
            ->toBe(['title_en' => 'Funding delay', 'title_ar' => 'تأخر التمويل', 'category' => 'financial', 'default_likelihood' => 2, 'default_severity' => 3, 'is_active' => true]);

        $component->call('openEditModal', $entry->id)
            ->assertSet('titleEn', 'Funding delay')
            ->set('category', '')
            ->set('defaultLikelihood', '')
            ->call('saveEntry')
            ->assertHasNoErrors();

        expect($entry->fresh()->only(['category', 'default_likelihood']))->toBe(['category' => null, 'default_likelihood' => null]);
    });

    it('shows field errors for a missing title, a duplicate title and an out-of-range rating', function () {
        RiskLibrary::factory()->create(['title_en' => 'Funding delay']);

        Livewire::actingAs(mealManager())->test('pages::admin.risk-library')
            ->call('openCreateModal')
            ->set('titleEn', '  ')
            ->set('titleAr', '')
            ->call('saveEntry')
            ->assertHasErrors(['titleEn', 'titleAr'])
            ->set('titleEn', 'Funding delay')
            ->set('titleAr', 'تأخر')
            ->call('saveEntry')
            ->assertHasErrors(['titleEn'])
            ->set('titleEn', 'Other')
            ->set('defaultSeverity', '4')
            ->call('saveEntry')
            ->assertHasErrors(['defaultSeverity']);

        expect(RiskLibrary::query()->count())->toBe(1);
    });

    it('does not even mount for a role that cannot manage the library or a MEAL user without the slot', function (string $role) {
        Livewire::actingAs(userWithRole($role, insertHrEmployee(firstNameEn: 'Omar', lastNameEn: 'Officer')))->test('pages::admin.risk-library')->assertForbidden();
    })->with(['Team Member', 'Financial', 'Public Relations Manager', 'MEAL']);

    it('explains in the delete dialog that a used entry is deactivated instead', function () {
        $entry = usedRiskLibraryEntry();

        Livewire::actingAs(libraryManager('General Manager'))->test('pages::admin.risk-library')
            ->call('confirmDelete', $entry->id)
            ->assertSet('showDeleteModal', true)
            ->assertSet('deletingEntryInUse', true)
            ->assertSee(__('risk_library.in_use'))
            ->call('deactivateInsteadOfDelete')
            ->assertSet('showDeleteModal', false);

        expect($entry->fresh()->is_active)->toBeFalse();
    });

    it('moves an entry and announces its new position', function () {
        $first = RiskLibrary::factory()->create(['title_en' => 'First', 'sort_order' => 1]);
        $second = RiskLibrary::factory()->create(['title_en' => 'Second', 'sort_order' => 2]);

        Livewire::actingAs(mealManager())->test('pages::admin.risk-library')
            ->call('moveEntry', $second->id, 'up')
            ->assertSet('announcement', __('risk_library.moved_to', ['name' => 'Second', 'position' => 1, 'total' => 2]));

        expect(RiskLibrary::query()->ordered()->pluck('id')->all())->toBe([$second->id, $first->id]);
    });
});

describe('the actions', function () {
    it('lets MEAL and the General Manager create, edit, deactivate, reactivate and delete an unused entry', function (string $role) {
        $this->actingAs(libraryManager($role));

        $entry = app(SaveRiskLibraryEntry::class)->handle(null, ['title_en' => 'Access denied', 'title_ar' => 'منع الوصول']);
        expect($entry->is_active)->toBeTrue()->and($entry->sort_order)->toBe(1);

        app(SaveRiskLibraryEntry::class)->handle($entry->id, ['title_en' => 'Access blocked', 'title_ar' => 'منع الوصول']);
        app(DeactivateRiskLibraryEntry::class)->handle($entry->id);
        expect($entry->fresh()->is_active)->toBeFalse();

        app(ReactivateRiskLibraryEntry::class)->handle($entry->id);
        app(DeleteRiskLibraryEntry::class)->handle($entry->id);
        expect(RiskLibrary::query()->count())->toBe(0);
    })->with(['MEAL', 'General Manager']);

    it('refuses a role without risk-library.manage on every action', function (Closure $call) {
        $this->actingAs(userWithRole('Team Member'));
        $entry = RiskLibrary::factory()->create();

        expect(fn () => $call($entry))->toThrow(AuthorizationException::class);
    })->with([
        'save' => [fn (RiskLibrary $e) => app(SaveRiskLibraryEntry::class)->handle(null, ['title_en' => 'X', 'title_ar' => 'س'])],
        'update' => [fn (RiskLibrary $e) => app(SaveRiskLibraryEntry::class)->handle($e->id, ['title_en' => 'X', 'title_ar' => 'س'])],
        'deactivate' => [fn (RiskLibrary $e) => app(DeactivateRiskLibraryEntry::class)->handle($e->id)],
        'reactivate' => [fn (RiskLibrary $e) => app(ReactivateRiskLibraryEntry::class)->handle($e->id)],
        'delete' => [fn (RiskLibrary $e) => app(DeleteRiskLibraryEntry::class)->handle($e->id)],
        'move' => [fn (RiskLibrary $e) => app(MoveRiskLibraryEntry::class)->handle($e->id, true)],
    ]);

    it('refuses to delete an entry a risk was picked from, and the database agrees', function () {
        $this->actingAs(mealManager());
        $entry = usedRiskLibraryEntry();

        expect(fn () => app(DeleteRiskLibraryEntry::class)->handle($entry->id))->toThrow(ValidationException::class);
        expect(RiskLibrary::query()->whereKey($entry->id)->exists())->toBeTrue();

        expect(fn () => DB::table('risk_library')->where('id', $entry->id)->delete())->toThrow(QueryException::class);
    });

    it('refuses a stale deactivate and a stale reactivate', function () {
        $this->actingAs(mealManager());
        $active = RiskLibrary::factory()->create();
        $inactive = RiskLibrary::factory()->inactive()->create();

        expect(fn () => app(DeactivateRiskLibraryEntry::class)->handle($inactive->id))->toThrow(ValidationException::class)
            ->and(fn () => app(ReactivateRiskLibraryEntry::class)->handle($active->id))->toThrow(ValidationException::class);
    });
});

describe('the starting list', function () {
    it('seeds idempotently, by English title, and never resurrects a renamed or deactivated entry', function () {
        $list = [['title_en' => 'Funding delay', 'title_ar' => 'تأخر التمويل', 'category' => 'financial']];

        expect(DefaultRiskLibrary::ensure($list))->toBe(1)
            ->and(DefaultRiskLibrary::ensure($list))->toBe(0);

        RiskLibrary::query()->update(['is_active' => false]);

        expect(DefaultRiskLibrary::ensure($list))->toBe(0)
            ->and(RiskLibrary::query()->count())->toBe(1);
    });

    it('ships the MEAL risk matrix as its starting list, well formed and in matrix order', function () {
        $list = DefaultRiskLibrary::all();

        expect($list)->toHaveCount(18)
            ->and(collect($list)->pluck('title_en')->duplicates())->toBeEmpty()
            ->and(collect($list)->pluck('title_ar')->duplicates())->toBeEmpty()
            ->and(collect($list)->pluck('category')->unique()->values()->all())
            ->toBe(['Context', 'Economic empowerment', 'Youth and students', 'Children and parenting']);

        foreach ($list as $entry) {
            expect($entry['title_en'])->not->toBe('')
                ->and(mb_strlen($entry['title_en']))->toBeLessThanOrEqual(255)
                ->and($entry['title_ar'])->not->toBe('')
                ->and(mb_strlen($entry['title_ar']))->toBeLessThanOrEqual(255)
                ->and(mb_strlen((string) $entry['category']))->toBeLessThanOrEqual(40);
        }
    });

    it('seeds the starting list once, active and in order, both languages stored', function () {
        $list = DefaultRiskLibrary::all();

        expect(DefaultRiskLibrary::ensure())->toBe(18)
            ->and(DefaultRiskLibrary::ensure())->toBe(0)
            ->and(RiskLibrary::query()->count())->toBe(18);

        $seeded = RiskLibrary::query()->active()->ordered()->get();

        expect($seeded->pluck('title_en')->all())->toBe(array_column($list, 'title_en'))
            ->and($seeded->pluck('title_ar')->all())->toBe(array_column($list, 'title_ar'))
            ->and($seeded->pluck('category')->all())->toBe(array_column($list, 'category'))
            ->and($seeded->pluck('default_likelihood')->unique()->all())->toBe([null])
            ->and($seeded->pluck('default_severity')->unique()->all())->toBe([null]);
    });
});
