<?php

use App\Actions\EvidenceTypes\DeactivateEvidenceType;
use App\Actions\EvidenceTypes\DeleteEvidenceType;
use App\Actions\EvidenceTypes\MoveEvidenceType;
use App\Actions\EvidenceTypes\ReactivateEvidenceType;
use App\Actions\EvidenceTypes\SaveEvidenceType;
use App\Models\EvidenceType;
use App\Models\ReportTemplateLine;
use App\Support\DefaultEvidenceTypes;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

describe('the page', function () {
    it('sends a guest to the login page', function () {
        $this->get(route('admin.evidence-types.index'))->assertRedirect(route('login'));
    });

    it('is open only to the roles that manage evidence types', function (string $role, bool $manages) {
        EvidenceType::factory()->create(['name_ar' => 'كشوف الحضور', 'name_en' => 'Attendance sheets']);

        $response = $this->actingAs(userWithRole($role))->get(route('admin.evidence-types.index'));

        $manages
            ? $response->assertOk()->assertSee('Attendance sheets')->assertSee('data-test="create-evidence-type"', false)
            : $response->assertForbidden();
    })->with([
        'MEAL' => ['MEAL', true],
        'Super Admin' => ['Super Admin', true],
        'General Manager' => ['General Manager', false],
        'Team Member' => ['Team Member', false],
    ]);

    it('explains an empty list and offers the create button', function () {
        Livewire::actingAs(userWithRole('MEAL'))->test('pages::admin.evidence-types')
            ->assertSee(__('evidence_types.empty_heading'))
            ->assertSeeHtml('data-test="empty-create-evidence-type"');
    });

    it('creates and edits an evidence type from the dialog', function () {
        $component = Livewire::actingAs(userWithRole('MEAL'))->test('pages::admin.evidence-types')
            ->call('openCreateModal')
            ->assertSet('showEvidenceTypeModal', true)
            ->set('nameAr', 'صور النشاط')
            ->set('nameEn', 'Activity photos')
            ->call('saveEvidenceType')
            ->assertHasNoErrors()
            ->assertSet('showEvidenceTypeModal', false);

        $evidenceType = EvidenceType::query()->sole();
        expect($evidenceType->only(['name_ar', 'name_en', 'is_active']))->toBe(['name_ar' => 'صور النشاط', 'name_en' => 'Activity photos', 'is_active' => true]);

        $component->call('openEditModal', $evidenceType->id)
            ->assertSet('nameAr', 'صور النشاط')
            ->set('nameEn', '')
            ->call('saveEvidenceType')
            ->assertHasNoErrors();

        expect($evidenceType->fresh()->name_en)->toBeNull();
    });

    it('shows the field error next to the Arabic name', function () {
        Livewire::actingAs(userWithRole('MEAL'))->test('pages::admin.evidence-types')
            ->call('openCreateModal')
            ->set('nameAr', '   ')
            ->call('saveEvidenceType')
            ->assertHasErrors(['nameAr'])
            ->assertSet('showEvidenceTypeModal', true);

        expect(EvidenceType::query()->count())->toBe(0);
    });

    it('deactivates, reactivates and deletes an unused type through its dialogs', function () {
        $evidenceType = EvidenceType::factory()->create();

        $component = Livewire::actingAs(userWithRole('MEAL'))->test('pages::admin.evidence-types')
            ->call('confirmDeactivate', $evidenceType->id)
            ->assertSet('showDeactivateModal', true)
            ->call('deactivateEvidenceType')
            ->assertSet('showDeactivateModal', false)
            ->assertSee(__('evidence_types.inactive'));

        expect($evidenceType->fresh()->is_active)->toBeFalse();

        $component->call('reactivateEvidenceType', $evidenceType->id);

        expect($evidenceType->fresh()->is_active)->toBeTrue();

        $component->call('confirmDelete', $evidenceType->id)
            ->assertSet('showDeleteModal', true)
            ->assertSet('deletingEvidenceTypeInUse', false)
            ->call('deleteEvidenceType')
            ->assertSet('showDeleteModal', false);

        expect(EvidenceType::query()->count())->toBe(0);
    });

    it('refuses every page call once the user no longer manages evidence types', function (Closure $call) {
        $evidenceType = EvidenceType::factory()->create(['name_ar' => 'قوائم المستفيدين']);
        $user = userWithRole('MEAL');

        $component = Livewire::actingAs($user)->test('pages::admin.evidence-types');
        $user->syncRoles(['General Manager']);

        $call($component, $evidenceType)->assertForbidden();

        expect($evidenceType->fresh()->only(['name_ar', 'is_active']))->toBe(['name_ar' => 'قوائم المستفيدين', 'is_active' => true])
            ->and(EvidenceType::query()->count())->toBe(1);
    })->with([
        'open create' => fn (Testable $component, EvidenceType $evidenceType) => $component->call('openCreateModal'),
        'save new' => fn (Testable $component, EvidenceType $evidenceType) => $component->set('nameAr', 'جديد')->call('saveEvidenceType'),
        'open edit' => fn (Testable $component, EvidenceType $evidenceType) => $component->call('openEditModal', $evidenceType->id),
        'save edit' => fn (Testable $component, EvidenceType $evidenceType) => $component->set('editingEvidenceTypeId', $evidenceType->id)->set('nameAr', 'معدل')->call('saveEvidenceType'),
        'confirm deactivate' => fn (Testable $component, EvidenceType $evidenceType) => $component->call('confirmDeactivate', $evidenceType->id),
        'deactivate' => fn (Testable $component, EvidenceType $evidenceType) => $component->set('deactivatingEvidenceTypeId', $evidenceType->id)->call('deactivateEvidenceType'),
        'reactivate' => fn (Testable $component, EvidenceType $evidenceType) => $component->call('reactivateEvidenceType', $evidenceType->id),
        'confirm delete' => fn (Testable $component, EvidenceType $evidenceType) => $component->call('confirmDelete', $evidenceType->id),
        'delete' => fn (Testable $component, EvidenceType $evidenceType) => $component->set('deletingEvidenceTypeId', $evidenceType->id)->call('deleteEvidenceType'),
        'move' => fn (Testable $component, EvidenceType $evidenceType) => $component->call('moveEvidenceType', $evidenceType->id, 'down'),
    ]);

    it('moves a type with the keyboard-reachable buttons and announces its new position', function () {
        [$first, $second] = [EvidenceType::factory()->create(['name_en' => 'First', 'sort_order' => 1]), EvidenceType::factory()->create(['name_en' => 'Second', 'sort_order' => 2])];

        Livewire::actingAs(userWithRole('MEAL'))->test('pages::admin.evidence-types')
            ->call('moveEvidenceType', $second->id, 'up')
            ->assertSet('announcement', __('evidence_types.moved_to', ['name' => 'Second', 'position' => 1, 'total' => 2]))
            ->assertSeeHtml('aria-live="polite"')
            ->assertDispatched('evidence-type-moved', evidenceTypeId: $second->id, direction: 'up');

        expect(EvidenceType::query()->ordered()->pluck('id')->all())->toBe([$second->id, $first->id]);
    });

    it('renders the list in Arabic with the English name under the Arabic one', function () {
        app()->setLocale('ar');
        EvidenceType::factory()->create(['name_ar' => 'كشوف الحضور', 'name_en' => 'Attendance sheets']);
        EvidenceType::factory()->inactive()->create(['name_ar' => 'موافقات التصوير']);

        Livewire::actingAs(userWithRole('MEAL'))->test('pages::admin.evidence-types')
            ->assertSeeInOrder(['كشوف الحضور', 'Attendance sheets', 'موافقات التصوير', 'غير فعّال'])
            ->assertSee('إضافة نوع شاهد');
    });

    it('runs the same number of queries however many types there are', function () {
        $user = userWithRole('MEAL');

        $countQueries = function () use ($user): int {
            $queries = 0;
            DB::listen(function () use (&$queries): void {
                $queries++;
            });

            Livewire::actingAs($user)->test('pages::admin.evidence-types');

            return $queries;
        };

        // A first render loads and caches the user's permissions.
        Livewire::actingAs($user)->test('pages::admin.evidence-types');

        EvidenceType::factory()->count(2)->create();
        $small = $countQueries();

        EvidenceType::factory()->count(10)->create();
        $large = $countQueries();

        expect($large)->toBe($small);
    });
});

describe('the actions', function () {
    it('lets MEAL create, edit, deactivate, reactivate and delete an unused type', function () {
        $this->actingAs(userWithRole('MEAL'));

        $evidenceType = app(SaveEvidenceType::class)->handle(null, ['name_ar' => 'صور النشاط', 'name_en' => 'Activity photos']);
        app(SaveEvidenceType::class)->handle($evidenceType->id, ['name_ar' => 'صور الأنشطة', 'name_en' => null]);
        app(DeactivateEvidenceType::class)->handle($evidenceType->id);
        app(ReactivateEvidenceType::class)->handle($evidenceType->id);

        expect($evidenceType->fresh()->only(['name_ar', 'name_en', 'is_active']))->toBe(['name_ar' => 'صور الأنشطة', 'name_en' => null, 'is_active' => true]);

        app(DeleteEvidenceType::class)->handle($evidenceType->id);

        expect(EvidenceType::query()->count())->toBe(0);
    });

    it('forbids every other role from writing to the evidence type list', function (string $role, Closure $write) {
        $evidenceType = EvidenceType::factory()->create(['name_ar' => 'كشوف الحضور']);
        $this->actingAs(userWithRole($role));

        expect(fn () => $write($evidenceType))->toThrow(AuthorizationException::class);

        expect(EvidenceType::query()->count())->toBe(1)
            ->and($evidenceType->fresh()->only(['name_ar', 'is_active']))->toBe(['name_ar' => 'كشوف الحضور', 'is_active' => true]);
    })->with(['General Manager', 'Team Member'])->with([
        'create' => fn (EvidenceType $evidenceType) => app(SaveEvidenceType::class)->handle(null, ['name_ar' => 'جديد']),
        'update' => fn (EvidenceType $evidenceType) => app(SaveEvidenceType::class)->handle($evidenceType->id, ['name_ar' => 'معدل']),
        'deactivate' => fn (EvidenceType $evidenceType) => app(DeactivateEvidenceType::class)->handle($evidenceType->id),
        'reactivate' => fn (EvidenceType $evidenceType) => app(ReactivateEvidenceType::class)->handle($evidenceType->id),
        'delete' => fn (EvidenceType $evidenceType) => app(DeleteEvidenceType::class)->handle($evidenceType->id),
        'move' => fn (EvidenceType $evidenceType) => app(MoveEvidenceType::class)->handle($evidenceType->id, towardsStart: false),
    ]);

    it('rejects a type that breaks a form rule with its message', function (array $input, string $message) {
        $this->actingAs(userWithRole('MEAL'));

        expect(fn () => app(SaveEvidenceType::class)->handle(null, $input))->toThrow(ValidationException::class, $message);

        expect(EvidenceType::query()->count())->toBe(0);
    })->with([
        'no arabic name' => [['name_ar' => '', 'name_en' => 'Activity photos'], 'Enter the evidence type name in Arabic.'],
        'an arabic name too long' => [['name_ar' => str_repeat('ص', 256)], 'The Arabic name may not be longer than 255 characters.'],
        'an english name too long' => [['name_ar' => 'صور النشاط', 'name_en' => str_repeat('h', 256)], 'The English name may not be longer than 255 characters.'],
    ]);

    it('never takes the active flag or the position from the form, and puts a new type last', function () {
        $this->actingAs(userWithRole('MEAL'));
        EvidenceType::factory()->create(['sort_order' => 4]);

        $evidenceType = app(SaveEvidenceType::class)->handle(null, ['name_ar' => 'صور النشاط', 'is_active' => false, 'sort_order' => 1]);

        expect($evidenceType->fresh()->only(['is_active', 'sort_order']))->toBe(['is_active' => true, 'sort_order' => 5]);
    });

    it('refuses a second deactivate or reactivate as stale', function () {
        $this->actingAs(userWithRole('MEAL'));
        $active = EvidenceType::factory()->create();
        $inactive = EvidenceType::factory()->inactive()->create();

        expect(fn () => app(DeactivateEvidenceType::class)->handle($inactive->id))->toThrow(ValidationException::class, __('evidence_types.stale'))
            ->and(fn () => app(ReactivateEvidenceType::class)->handle($active->id))->toThrow(ValidationException::class, __('evidence_types.stale'));
    });

    it('turns a stale deactivate on the page into a toast and leaves the type as it is', function () {
        $evidenceType = EvidenceType::factory()->inactive()->create();

        Livewire::actingAs(userWithRole('MEAL'))->test('pages::admin.evidence-types')
            ->set('deactivatingEvidenceTypeId', $evidenceType->id)
            ->call('deactivateEvidenceType')
            ->assertHasNoErrors()
            ->assertDispatched('toast-show', fn (string $event, array $params) => ($params['slots']['text'] ?? null) === __('evidence_types.stale'));

        expect($evidenceType->fresh()->is_active)->toBeFalse();
    });

    it('keeps a dense order when types move, and a move past either end changes nothing', function () {
        $this->actingAs(userWithRole('MEAL'));
        $evidenceTypes = collect([1, 2, 3])->map(fn (int $position): EvidenceType => EvidenceType::factory()->create(['sort_order' => $position * 10]));

        app(MoveEvidenceType::class)->handle($evidenceTypes[2]->id, towardsStart: true);
        app(MoveEvidenceType::class)->handle($evidenceTypes[0]->id, towardsStart: true);

        expect(EvidenceType::query()->ordered()->get(['id', 'sort_order'])->map->only(['id', 'sort_order'])->all())->toBe([
            ['id' => $evidenceTypes[0]->id, 'sort_order' => 1],
            ['id' => $evidenceTypes[2]->id, 'sort_order' => 2],
            ['id' => $evidenceTypes[1]->id, 'sort_order' => 3],
        ]);
    });

    it('refuses to delete a used type, and deactivates it instead', function () {
        $this->actingAs(userWithRole('MEAL'));
        $evidenceType = EvidenceType::factory()->create();
        ReportTemplateLine::factory()->create()->evidenceTypes()->attach($evidenceType->id);

        expect(fn () => app(DeleteEvidenceType::class)->handle($evidenceType->id))
            ->toThrow(ValidationException::class, 'This evidence type is in use. Deactivate it instead.');

        Livewire::test('pages::admin.evidence-types')
            ->call('confirmDelete', $evidenceType->id)
            ->assertSet('deletingEvidenceTypeInUse', true)
            ->assertSee(__('evidence_types.in_use'))
            ->call('deactivateInsteadOfDelete');

        expect($evidenceType->fresh()->is_active)->toBeFalse();
    });
});

describe('the active scope', function () {
    it('offers only active types, in the managed order', function () {
        $second = EvidenceType::factory()->create(['sort_order' => 2]);
        $first = EvidenceType::factory()->create(['sort_order' => 1]);
        EvidenceType::factory()->inactive()->create(['sort_order' => 0]);

        expect(EvidenceType::query()->active()->ordered()->pluck('id')->all())->toBe([$first->id, $second->id]);
    });
});

describe('the default evidence types', function () {
    it('seeds the five starting types once, in order, however often it runs', function () {
        expect(DefaultEvidenceTypes::ensure())->toBe(5)
            ->and(DefaultEvidenceTypes::ensure())->toBe(0);

        expect(EvidenceType::query()->ordered()->pluck('name_ar')->all())->toBe([
            'كشوف الحضور',
            'موافقات التصوير',
            'قوائم المستفيدين',
            'صور النشاط',
            'اختبارات قبلية وبعدية',
        ])->and(EvidenceType::query()->ordered()->pluck('sort_order')->all())->toBe([1, 2, 3, 4, 5]);
    });

    it('does not re-create a default an admin renamed in one language, or one already deactivated', function () {
        DefaultEvidenceTypes::ensure();
        EvidenceType::query()->where('name_en', 'Attendance sheets')->update(['name_ar' => 'سجلات الحضور']);
        EvidenceType::query()->where('name_ar', 'صور النشاط')->update(['name_en' => 'Field photos', 'is_active' => false]);

        expect(DefaultEvidenceTypes::ensure())->toBe(0)
            ->and(EvidenceType::query()->count())->toBe(5);
    });
});
