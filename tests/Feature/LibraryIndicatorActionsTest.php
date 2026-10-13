<?php

use App\Actions\IndicatorLibrary\DeactivateLibraryIndicator;
use App\Actions\IndicatorLibrary\DeleteLibraryIndicator;
use App\Actions\IndicatorLibrary\ReactivateLibraryIndicator;
use App\Actions\IndicatorLibrary\SaveLibraryIndicator;
use App\Enums\DenominatorBasis;
use App\Enums\IndicatorMeasure;
use App\Models\Activity;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\PlanIndicator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

describe('authorization', function () {
    it('lets MEAL and the General Manager create, edit, deactivate, reactivate and delete an unused entry', function (string $role) {
        $this->actingAs(userWithRole($role));

        $entry = app(SaveLibraryIndicator::class)->handle(null, libraryIndicatorInput());
        app(SaveLibraryIndicator::class)->handle($entry->id, libraryIndicatorInput(['name_en' => 'Households reached']));
        app(DeactivateLibraryIndicator::class)->handle($entry->id);
        app(ReactivateLibraryIndicator::class)->handle($entry->id);
        app(DeleteLibraryIndicator::class)->handle($entry->id);

        expect(LibraryIndicator::query()->count())->toBe(0)
            ->and(Activity::query()->where('subject_type', 'library_indicator')->orderBy('id')->pluck('event')->all())->toBe([
                'library_indicator.created',
                'library_indicator.updated',
                'library_indicator.deactivated',
                'library_indicator.reactivated',
                'library_indicator.deleted',
            ]);
    })->with(['MEAL', 'General Manager']);

    it('forbids every other role from writing to the library', function (string $role, Closure $write) {
        $entry = LibraryIndicator::factory()->create(['name_en' => 'Untouched']);
        $this->actingAs(userWithRole($role));

        expect(fn () => $write($entry))->toThrow(AuthorizationException::class);

        expect(LibraryIndicator::query()->count())->toBe(1)
            ->and($entry->fresh()->only(['name_en', 'is_active']))->toBe(['name_en' => 'Untouched', 'is_active' => true]);
    })->with(['Team Member', 'Managers', 'Financial'])->with([
        'create' => fn (LibraryIndicator $entry) => app(SaveLibraryIndicator::class)->handle(null, libraryIndicatorInput()),
        'update' => fn (LibraryIndicator $entry) => app(SaveLibraryIndicator::class)->handle($entry->id, libraryIndicatorInput(['name_en' => 'Changed'])),
        'deactivate' => fn (LibraryIndicator $entry) => app(DeactivateLibraryIndicator::class)->handle($entry->id),
        'delete' => fn (LibraryIndicator $entry) => app(DeleteLibraryIndicator::class)->handle($entry->id),
    ]);

    it('lets every role view the library', function (string $role) {
        expect(userWithRole($role)->can('viewAny', LibraryIndicator::class))->toBeTrue();
    })->with(['Team Member', 'Managers', 'Financial', 'MEAL']);
});

describe('saving', function () {
    beforeEach(fn () => $this->actingAs(userWithRole('MEAL')));

    it('rejects an entry that breaks a form rule with its message', function (array $overrides, string $message) {
        expect(fn () => app(SaveLibraryIndicator::class)->handle(null, libraryIndicatorInput($overrides)))
            ->toThrow(ValidationException::class, $message);

        expect(LibraryIndicator::query()->count())->toBe(0);
    })->with([
        'no arabic name' => [['name_ar' => ''], 'Enter the indicator name in Arabic.'],
        'no program for a program indicator' => [['institutional' => false, 'program_id' => null], 'Choose the program.'],
        'a level outside the framework' => [['level_ref' => 'Output 1'], 'Enter the level and its number as in the program framework, e.g. OP1.2.'],
        'a follow-up without its months' => [['frequency' => 'follow-up', 'follow_up_months' => null], 'Enter after how many months the follow-up is measured.'],
    ]);

    it('never takes the code, serial or active flag from the form', function () {
        $entry = app(SaveLibraryIndicator::class)->handle(null, libraryIndicatorInput(['code' => 'TYPED.1', 'serial' => 9, 'is_active' => false]));

        expect($entry->fresh()->only(['code', 'serial', 'is_active']))->toBe(['code' => 'HC.OP1.1', 'serial' => 1, 'is_active' => true]);
    });

    it('clears the unit of a percentage and defaults its denominator from who collects it', function (string $collector, DenominatorBasis $basis) {
        $entry = app(SaveLibraryIndicator::class)->handle(null, libraryIndicatorInput(['measure' => 'percentage', 'collected_by' => $collector]));

        expect($entry->fresh()->only(['unit', 'denominator_basis']))->toBe(['unit' => null, 'denominator_basis' => $basis]);
    })->with([
        'project team' => ['project_team', DenominatorBasis::Fixed],
        'MEAL' => ['meal', DenominatorBasis::Entered],
    ]);

    it('keeps the denominator MEAL chose for a percentage', function () {
        $entry = app(SaveLibraryIndicator::class)->handle(null, libraryIndicatorInput(['measure' => 'percentage', 'denominator_basis' => 'entered']));

        expect($entry->fresh()->denominator_basis)->toBe(DenominatorBasis::Entered);
    });

    it('drops the denominator of a count and the months of a non follow-up frequency', function () {
        $entry = app(SaveLibraryIndicator::class)->handle(null, libraryIndicatorInput(['denominator_basis' => 'entered', 'follow_up_months' => 6]));

        expect($entry->fresh()->only(['denominator_basis', 'follow_up_months']))->toBe(['denominator_basis' => null, 'follow_up_months' => null]);
    });

    it('refuses to change the measure once a linked indicator has targets', function () {
        $entry = LibraryIndicator::factory()->create();
        $indicator = Indicator::factory()->create(['library_indicator_id' => $entry->id]);
        PlanIndicator::factory()->for($indicator)->create();

        expect(fn () => app(SaveLibraryIndicator::class)->handle($entry->id, libraryIndicatorInput(['level_ref' => $entry->level_ref, 'measure' => 'average'])))
            ->toThrow(ValidationException::class, 'The measure cannot change because this indicator has targets.');

        expect($entry->fresh()->measure)->toBe(IndicatorMeasure::Numeric)
            ->and($indicator->fresh()->measure)->toBe(IndicatorMeasure::Numeric);
    });

    it('carries a measure change to every linked indicator while none has targets', function () {
        $entry = LibraryIndicator::factory()->create();
        $linked = Indicator::factory()->count(2)->create(['library_indicator_id' => $entry->id]);
        $unlinked = Indicator::factory()->create();

        app(SaveLibraryIndicator::class)->handle($entry->id, libraryIndicatorInput(['level_ref' => $entry->level_ref, 'measure' => 'average']));

        expect($linked->map(fn (Indicator $indicator): IndicatorMeasure => $indicator->fresh()->measure)->all())->toBe([IndicatorMeasure::Average, IndicatorMeasure::Average])
            ->and($unlinked->fresh()->measure)->toBe(IndicatorMeasure::Numeric);
    });
});

describe('deactivating, reactivating and deleting', function () {
    beforeEach(fn () => $this->actingAs(userWithRole('MEAL')));

    it('takes a deactivated entry off new selection and brings it back on reactivation', function () {
        $entry = LibraryIndicator::factory()->create();

        app(DeactivateLibraryIndicator::class)->handle($entry->id);
        $afterDeactivation = LibraryIndicator::query()->active()->count();
        app(ReactivateLibraryIndicator::class)->handle($entry->id);

        expect($afterDeactivation)->toBe(0)
            ->and(LibraryIndicator::query()->active()->count())->toBe(1);
    });

    it('keeps a deactivated entry on the indicators that already use it', function () {
        $entry = LibraryIndicator::factory()->create();
        $indicator = Indicator::factory()->create(['library_indicator_id' => $entry->id]);

        app(DeactivateLibraryIndicator::class)->handle($entry->id);

        expect($indicator->fresh()->library_indicator_id)->toBe($entry->id);
    });

    it('refuses a deactivation or reactivation someone else already made', function (bool $isActive, string $action) {
        $entry = LibraryIndicator::factory()->create(['is_active' => $isActive]);

        expect(fn () => app($action)->handle($entry->id))
            ->toThrow(ValidationException::class, 'Someone else changed this indicator. Reload the page and try again.');

        expect(Activity::query()->where('subject_type', 'library_indicator')->whereIn('event', ['library_indicator.deactivated', 'library_indicator.reactivated'])->exists())->toBeFalse();
    })->with([
        'deactivate an inactive entry' => [false, DeactivateLibraryIndicator::class],
        'reactivate an active entry' => [true, ReactivateLibraryIndicator::class],
    ]);

    it('refuses to delete an entry in use and keeps it', function () {
        $entry = LibraryIndicator::factory()->create();
        Indicator::factory()->create(['library_indicator_id' => $entry->id]);

        expect(fn () => app(DeleteLibraryIndicator::class)->handle($entry->id))
            ->toThrow(ValidationException::class, 'This indicator is in use. Deactivate it instead.');

        $this->assertModelExists($entry);
    });
});
