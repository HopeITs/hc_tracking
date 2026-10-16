<?php

use App\Actions\NarrativeQuestions\DeactivateNarrativeQuestion;
use App\Actions\NarrativeQuestions\DeleteNarrativeQuestion;
use App\Actions\NarrativeQuestions\MoveNarrativeQuestion;
use App\Actions\NarrativeQuestions\ReactivateNarrativeQuestion;
use App\Actions\NarrativeQuestions\SaveNarrativeQuestion;
use App\Enums\NarrativeQuestionFrequency;
use App\Models\NarrativeQuestion;
use App\Support\DefaultNarrativeQuestions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

describe('the page', function () {
    it('sends a guest to the login page', function () {
        $this->get(route('admin.narrative-questions.index'))->assertRedirect(route('login'));
    });

    it('is open to MEAL and the Super Admin only', function (string $role, int $status) {
        NarrativeQuestion::factory()->create(['text_ar' => 'ما أبرز الإنجازات؟', 'text_en' => 'Main achievements?']);

        $response = $this->actingAs(userWithRole($role))->get(route('admin.narrative-questions.index'))->assertStatus($status);

        if ($status === 200) {
            $response->assertSee('Main achievements?')->assertSee('data-test="create-narrative-question"', false);
        }
    })->with([
        'MEAL' => ['MEAL', 200],
        'Super Admin' => ['Super Admin', 200],
        'General Manager' => ['General Manager', 403],
        'Team Member' => ['Team Member', 403],
    ]);

    it('explains an empty bank and offers to add the first question', function () {
        Livewire::actingAs(userWithRole('MEAL'))->test('pages::admin.narrative-questions')
            ->assertSee(__('narrative_questions.empty_heading'))
            ->assertSeeHtml('data-test="empty-create-narrative-question"');
    });

    it('creates a question and edits it into the other frequency', function () {
        $component = Livewire::actingAs(userWithRole('MEAL'))->test('pages::admin.narrative-questions')
            ->call('openCreateModal')
            ->assertSet('showQuestionModal', true)
            ->assertSet('questionFrequency', 'monthly')
            ->set('textAr', 'ما أبرز الإنجازات هذا الشهر؟')
            ->set('textEn', 'Main achievements this month?')
            ->call('saveQuestion')
            ->assertHasNoErrors()
            ->assertSet('showQuestionModal', false);

        $question = NarrativeQuestion::query()->sole();
        expect($question->only(['text_ar', 'text_en', 'frequency', 'is_active']))->toBe([
            'text_ar' => 'ما أبرز الإنجازات هذا الشهر؟',
            'text_en' => 'Main achievements this month?',
            'frequency' => NarrativeQuestionFrequency::Monthly,
            'is_active' => true,
        ]);

        $component->call('openEditModal', $question->id)
            ->assertSet('textAr', 'ما أبرز الإنجازات هذا الشهر؟')
            ->set('textEn', '')
            ->set('questionFrequency', 'quarter_end')
            ->call('saveQuestion')
            ->assertHasNoErrors();

        expect($question->fresh()->only(['text_en', 'frequency']))->toBe(['text_en' => null, 'frequency' => NarrativeQuestionFrequency::QuarterEnd]);
    });

    it('shows the field errors next to their fields', function () {
        Livewire::actingAs(userWithRole('MEAL'))->test('pages::admin.narrative-questions')
            ->call('openCreateModal')
            ->set('textAr', '   ')
            ->set('textEn', str_repeat('q', 1001))
            ->set('questionFrequency', 'weekly')
            ->call('saveQuestion')
            ->assertHasErrors(['textAr', 'textEn', 'questionFrequency'])
            ->assertSet('showQuestionModal', true);

        expect(NarrativeQuestion::query()->count())->toBe(0);
    });

    it('filters the bank by frequency', function () {
        NarrativeQuestion::factory()->monthly()->create(['text_en' => 'Asked every month']);
        NarrativeQuestion::factory()->quarterEnd()->create(['text_en' => 'Asked at quarter end']);

        Livewire::actingAs(userWithRole('MEAL'))->test('pages::admin.narrative-questions')
            ->assertSee('Asked every month')
            ->assertSee('Asked at quarter end')
            ->set('frequency', 'quarter_end')
            ->assertDontSee('Asked every month')
            ->assertSee('Asked at quarter end')
            ->assertSeeHtml('<span class="truncate">'.__('enums.narrative_question_frequency.quarter_end').'</span>');

        Livewire::withQueryParams(['frequency' => 'monthly'])->actingAs(userWithRole('MEAL'))->test('pages::admin.narrative-questions')
            ->assertSee('Asked every month')
            ->assertDontSee('Asked at quarter end');
    });

    it('labels each question with its frequency in words', function () {
        NarrativeQuestion::factory()->quarterEnd()->create();

        Livewire::actingAs(userWithRole('MEAL'))->test('pages::admin.narrative-questions')
            ->assertSeeHtml('data-test="narrative-question-frequency"')
            ->assertSee(__('enums.narrative_question_frequency.quarter_end'));
    });

    it('refuses to mount for a user without narrative-questions.manage', function () {
        Livewire::actingAs(userWithRole('General Manager'))->test('pages::admin.narrative-questions')->assertForbidden();
    });

    /*
     * The page refuses to mount for a non-holder, so each call is made on a
     * page MEAL opened, by a user who lost the permission since (a fresh
     * instance per refused call, .ai/rules/tests.md).
     */
    it('refuses every page action for a user without narrative-questions.manage', function (string $method, Closure $arguments) {
        $question = NarrativeQuestion::factory()->create(['text_ar' => 'سؤال']);
        $meal = userWithRole('MEAL');
        $generalManager = userWithRole('General Manager');

        $fresh = function () use ($meal, $generalManager) {
            $component = Livewire::actingAs($meal)->test('pages::admin.narrative-questions');
            $this->actingAs($generalManager);

            return $component;
        };

        $fresh()->call($method, ...$arguments($question))->assertForbidden();

        expect($question->fresh()->only(['text_ar', 'is_active']))->toBe(['text_ar' => 'سؤال', 'is_active' => true])
            ->and(NarrativeQuestion::query()->count())->toBe(1);
    })->with([
        'open create' => ['openCreateModal', fn (NarrativeQuestion $question) => []],
        'open edit' => ['openEditModal', fn (NarrativeQuestion $question) => [$question->id]],
        'confirm deactivate' => ['confirmDeactivate', fn (NarrativeQuestion $question) => [$question->id]],
        'reactivate' => ['reactivateQuestion', fn (NarrativeQuestion $question) => [$question->id]],
        'confirm delete' => ['confirmDelete', fn (NarrativeQuestion $question) => [$question->id]],
        'move' => ['moveQuestion', fn (NarrativeQuestion $question) => [$question->id, 'down']],
    ]);

    it('deactivates, reactivates and deletes through the dialogs', function () {
        $question = NarrativeQuestion::factory()->create();

        $component = Livewire::actingAs(userWithRole('MEAL'))->test('pages::admin.narrative-questions')
            ->call('confirmDeactivate', $question->id)
            ->assertSet('showDeactivateModal', true)
            ->call('deactivateQuestion')
            ->assertSet('showDeactivateModal', false)
            ->assertSee(__('narrative_questions.inactive'));

        expect($question->fresh()->is_active)->toBeFalse();

        $component->call('reactivateQuestion', $question->id);

        expect($question->fresh()->is_active)->toBeTrue();

        $component->call('confirmDelete', $question->id)
            ->assertSet('showDeleteModal', true)
            ->assertSet('deletingQuestionInUse', false)
            ->call('deleteQuestion')
            ->assertSet('showDeleteModal', false);

        expect(NarrativeQuestion::query()->count())->toBe(0);
    });

    it('moves a question within its frequency and announces its new position', function () {
        $first = NarrativeQuestion::factory()->monthly()->create(['text_en' => 'First', 'sort_order' => 1]);
        $second = NarrativeQuestion::factory()->monthly()->create(['text_en' => 'Second', 'sort_order' => 2]);
        NarrativeQuestion::factory()->quarterEnd()->create(['sort_order' => 1]);

        Livewire::actingAs(userWithRole('MEAL'))->test('pages::admin.narrative-questions')
            ->call('moveQuestion', $second->id, 'up')
            ->assertSet('announcement', __('narrative_questions.moved_to', [
                'text' => 'Second',
                'position' => 1,
                'total' => 2,
                'frequency' => __('enums.narrative_question_frequency.monthly'),
            ]))
            ->assertSeeHtml('aria-live="polite"')
            ->assertDispatched('narrative-question-moved', questionId: $second->id, direction: 'up');

        expect(NarrativeQuestion::query()->where('frequency', 'monthly')->ordered()->pluck('id')->all())->toBe([$second->id, $first->id]);
    });

    it('renders in Arabic', function () {
        NarrativeQuestion::factory()->quarterEnd()->create(['text_ar' => 'شاركوا قصة نجاح', 'text_en' => 'Share a success story']);
        $user = userWithRole('MEAL');
        $user->forceFill(['locale' => 'ar'])->save();

        $this->actingAs($user)->get(route('admin.narrative-questions.index'))
            ->assertOk()
            ->assertSee('بنك الأسئلة السردية')
            ->assertSee('شاركوا قصة نجاح')
            ->assertSee('نهاية الربع')
            ->assertSee(__('narrative_questions.add_question', locale: 'ar'));
    });

    it('runs the same number of queries however many questions there are', function () {
        $user = userWithRole('MEAL');

        $countQueries = function () use ($user): int {
            $queries = 0;
            DB::listen(function () use (&$queries): void {
                $queries++;
            });

            Livewire::actingAs($user)->test('pages::admin.narrative-questions');

            return $queries;
        };

        NarrativeQuestion::factory()->count(2)->create();
        $countQueries();
        $small = $countQueries();

        NarrativeQuestion::factory()->count(6)->quarterEnd()->create();
        NarrativeQuestion::factory()->count(4)->inactive()->create();
        $large = $countQueries();

        expect($large)->toBe($small);
    });
});

describe('the actions', function () {
    it('lets MEAL and the Super Admin create, edit, deactivate, reactivate and delete an unused question', function (string $role) {
        $this->actingAs(userWithRole($role));

        $question = app(SaveNarrativeQuestion::class)->handle(null, ['text_ar' => 'ما التحديات؟', 'text_en' => 'Challenges?', 'frequency' => 'monthly']);
        app(SaveNarrativeQuestion::class)->handle($question->id, ['text_ar' => 'ما التحديات وكيف عولجت؟', 'text_en' => null, 'frequency' => 'quarter_end']);
        app(DeactivateNarrativeQuestion::class)->handle($question->id);
        app(ReactivateNarrativeQuestion::class)->handle($question->id);

        expect($question->fresh()->only(['text_ar', 'text_en', 'frequency', 'is_active']))->toBe([
            'text_ar' => 'ما التحديات وكيف عولجت؟',
            'text_en' => null,
            'frequency' => NarrativeQuestionFrequency::QuarterEnd,
            'is_active' => true,
        ]);

        app(DeleteNarrativeQuestion::class)->handle($question->id);

        expect(NarrativeQuestion::query()->count())->toBe(0);
    })->with(['MEAL', 'Super Admin']);

    it('forbids every other role from writing to the bank', function (string $role, Closure $write) {
        $question = NarrativeQuestion::factory()->create(['text_ar' => 'سؤال']);
        $this->actingAs(userWithRole($role));

        expect(fn () => $write($question))->toThrow(AuthorizationException::class);

        expect(NarrativeQuestion::query()->count())->toBe(1)
            ->and($question->fresh()->only(['text_ar', 'is_active']))->toBe(['text_ar' => 'سؤال', 'is_active' => true]);
    })->with(['General Manager', 'Team Member'])->with([
        'create' => fn (NarrativeQuestion $question) => app(SaveNarrativeQuestion::class)->handle(null, ['text_ar' => 'جديد', 'frequency' => 'monthly']),
        'update' => fn (NarrativeQuestion $question) => app(SaveNarrativeQuestion::class)->handle($question->id, ['text_ar' => 'معدل', 'frequency' => 'monthly']),
        'deactivate' => fn (NarrativeQuestion $question) => app(DeactivateNarrativeQuestion::class)->handle($question->id),
        'reactivate' => fn (NarrativeQuestion $question) => app(ReactivateNarrativeQuestion::class)->handle($question->id),
        'delete' => fn (NarrativeQuestion $question) => app(DeleteNarrativeQuestion::class)->handle($question->id),
        'move' => fn (NarrativeQuestion $question) => app(MoveNarrativeQuestion::class)->handle($question->id, towardsStart: false),
    ]);

    it('rejects a question that breaks a form rule with its message', function (array $input, string $message) {
        $this->actingAs(userWithRole('MEAL'));

        expect(fn () => app(SaveNarrativeQuestion::class)->handle(null, $input))->toThrow(ValidationException::class, $message);

        expect(NarrativeQuestion::query()->count())->toBe(0);
    })->with([
        'no arabic text' => [['text_ar' => '', 'text_en' => 'Challenges?', 'frequency' => 'monthly'], 'Enter the question in Arabic.'],
        'an arabic text too long' => [['text_ar' => str_repeat('س', 1001), 'frequency' => 'monthly'], 'The Arabic question may not be longer than 1000 characters.'],
        'an english text too long' => [['text_ar' => 'سؤال', 'text_en' => str_repeat('q', 1001), 'frequency' => 'monthly'], 'The English question may not be longer than 1000 characters.'],
        'no frequency' => [['text_ar' => 'سؤال'], 'Choose when the question is asked.'],
        'an unknown frequency' => [['text_ar' => 'سؤال', 'frequency' => 'weekly'], 'Choose when the question is asked.'],
    ]);

    it('accepts texts of exactly 1000 characters', function () {
        $this->actingAs(userWithRole('MEAL'));

        $question = app(SaveNarrativeQuestion::class)->handle(null, ['text_ar' => str_repeat('س', 1000), 'text_en' => str_repeat('q', 1000), 'frequency' => 'monthly']);

        expect(mb_strlen($question->fresh()->text_ar))->toBe(1000);
    });

    it('never takes the active flag or the position from the form, and puts a new question last in its frequency', function () {
        $this->actingAs(userWithRole('MEAL'));
        NarrativeQuestion::factory()->monthly()->create(['sort_order' => 4]);
        NarrativeQuestion::factory()->quarterEnd()->create(['sort_order' => 9]);

        $question = app(SaveNarrativeQuestion::class)->handle(null, ['text_ar' => 'سؤال', 'frequency' => 'monthly', 'is_active' => false, 'sort_order' => 1]);

        expect($question->fresh()->only(['is_active', 'sort_order']))->toBe(['is_active' => true, 'sort_order' => 5]);
    });

    it('moves a question to the end of its new frequency when the frequency changes', function () {
        $this->actingAs(userWithRole('MEAL'));
        $question = NarrativeQuestion::factory()->monthly()->create(['sort_order' => 1]);
        NarrativeQuestion::factory()->quarterEnd()->create(['sort_order' => 3]);

        app(SaveNarrativeQuestion::class)->handle($question->id, ['text_ar' => $question->text_ar, 'frequency' => 'quarter_end']);
        expect($question->fresh()->sort_order)->toBe(4);

        app(SaveNarrativeQuestion::class)->handle($question->id, ['text_ar' => 'نص جديد', 'frequency' => 'quarter_end']);
        expect($question->fresh()->sort_order)->toBe(4);
    });

    it('refuses a second deactivate or reactivate as stale', function () {
        $this->actingAs(userWithRole('MEAL'));
        $active = NarrativeQuestion::factory()->create();
        $inactive = NarrativeQuestion::factory()->inactive()->create();

        expect(fn () => app(DeactivateNarrativeQuestion::class)->handle($inactive->id))->toThrow(ValidationException::class, __('narrative_questions.stale'))
            ->and(fn () => app(ReactivateNarrativeQuestion::class)->handle($active->id))->toThrow(ValidationException::class, __('narrative_questions.stale'));
    });

    it('keeps a dense order within a frequency and leaves the other frequency alone', function () {
        $this->actingAs(userWithRole('MEAL'));
        $monthly = collect([1, 2, 3])->map(fn (int $position): NarrativeQuestion => NarrativeQuestion::factory()->monthly()->create(['sort_order' => $position * 10]));
        $quarterly = NarrativeQuestion::factory()->quarterEnd()->create(['sort_order' => 7]);

        expect(app(MoveNarrativeQuestion::class)->handle($monthly[2]->id, towardsStart: true))->toBe(2);
        expect(app(MoveNarrativeQuestion::class)->handle($monthly[0]->id, towardsStart: true))->toBe(1);
        expect(app(MoveNarrativeQuestion::class)->handle($quarterly->id, towardsStart: false))->toBe(1);

        expect(NarrativeQuestion::query()->where('frequency', 'monthly')->ordered()->get(['id', 'sort_order'])->map->only(['id', 'sort_order'])->all())->toBe([
            ['id' => $monthly[0]->id, 'sort_order' => 1],
            ['id' => $monthly[2]->id, 'sort_order' => 2],
            ['id' => $monthly[1]->id, 'sort_order' => 3],
        ])->and($quarterly->fresh()->sort_order)->toBe(1);
    });

    it('refuses a used question and offers to deactivate it instead', function () {
        // T33 slice 2: create a report_template_questions row pointing at the question here.
    })->skip('T33 slice 2: no consumer table yet');
});

describe('the frequency enum', function () {
    it('labels both frequencies in English and Arabic', function () {
        expect(NarrativeQuestionFrequency::Monthly->label())->toBe('Monthly')
            ->and(NarrativeQuestionFrequency::QuarterEnd->label())->toBe('Quarter end');

        app()->setLocale('ar');

        expect(NarrativeQuestionFrequency::Monthly->label())->toBe('شهري')
            ->and(NarrativeQuestionFrequency::QuarterEnd->label())->toBe('نهاية الربع');
    });
});

describe('the default questions', function () {
    it('seeds four monthly and two quarter-end questions once, however often it runs', function () {
        expect(DefaultNarrativeQuestions::ensure())->toBe(6)
            ->and(DefaultNarrativeQuestions::ensure())->toBe(0);

        $byFrequency = NarrativeQuestion::query()->ordered()->get()->groupBy(fn (NarrativeQuestion $question): string => $question->frequency->value);

        expect($byFrequency['monthly']->pluck('sort_order')->all())->toBe([1, 2, 3, 4])
            ->and($byFrequency['quarter_end']->pluck('sort_order')->all())->toBe([1, 2])
            ->and(NarrativeQuestion::query()->ordered()->pluck('text_en')->all())->toBe(array_column(DefaultNarrativeQuestions::all(), 'text_en'))
            ->and(NarrativeQuestion::query()->where('is_active', false)->count())->toBe(0);
    });

    it('does not re-create a default edited in one language, or one already deactivated', function () {
        DefaultNarrativeQuestions::ensure();
        $defaults = DefaultNarrativeQuestions::all();
        NarrativeQuestion::query()->where('text_en', $defaults[0]['text_en'])->update(['text_ar' => 'نص معدل']);
        NarrativeQuestion::query()->where('text_en', $defaults[1]['text_en'])->update(['is_active' => false]);

        expect(DefaultNarrativeQuestions::ensure())->toBe(0)
            ->and(NarrativeQuestion::query()->count())->toBe(6);
    });
});
