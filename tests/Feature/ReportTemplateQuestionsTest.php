<?php

use App\Actions\ReportTemplates\DeleteReportTemplateQuestion;
use App\Actions\ReportTemplates\MoveReportTemplateQuestion;
use App\Actions\ReportTemplates\SaveReportTemplateQuestion;
use App\Actions\ReportTemplates\SetReportTemplateQuestionActive;
use App\Enums\NarrativeQuestionFrequency;
use App\Models\Activity;
use App\Models\MonthlyReportAnswer;
use App\Models\NarrativeQuestion;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateQuestion;
use App\Notifications\ActivityPresenter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
| The template question actions (contracts.md §12.4, Y-07, D-78): MEAL adds,
| edits, reorders, switches off/on and deletes the narrative questions of one
| report template. The bank (`narrative_questions`) is never written.
*/

beforeEach(fn () => $this->travelTo('2026-10-14 10:00:00'));

function rtqTemplate(): ReportTemplate
{
    return Activity::withoutRecording(fn (): ReportTemplate => ReportTemplate::factory()->create());
}

/**
 * @param  array<string, mixed>  $attributes
 */
function rtqQuestion(ReportTemplate $template, array $attributes = []): ReportTemplateQuestion
{
    return ReportTemplateQuestion::factory()->create(['report_template_id' => $template->id, ...$attributes]);
}

/**
 * The ids of the template's questions of one frequency, in the order reports ask them.
 *
 * @return list<int>
 */
function rtqOrder(ReportTemplate $template, NarrativeQuestionFrequency $frequency = NarrativeQuestionFrequency::Monthly): array
{
    return array_values($template->questions()->where('frequency', $frequency)->ordered()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
}

/**
 * The first validation message, or null when the call went through.
 */
function rtqRefusal(callable $call): ?string
{
    try {
        $call();
    } catch (ValidationException $exception) {
        return collect($exception->errors())->flatten()->first();
    }

    return null;
}

function rtqAnswer(ReportTemplateQuestion $question): void
{
    Activity::withoutRecording(fn () => MonthlyReportAnswer::factory()->create(['report_template_question_id' => $question->id]));
}

test('adding a question appends it last in its frequency as the template\'s own question', function () {
    $this->actingAs(mealManager());
    $template = rtqTemplate();
    $first = rtqQuestion($template, ['sort_order' => 1]);
    $second = rtqQuestion($template, ['sort_order' => 2]);
    rtqQuestion($template, ['sort_order' => 5, 'frequency' => NarrativeQuestionFrequency::QuarterEnd]);

    $added = app(SaveReportTemplateQuestion::class)->handle($template, null, [
        'text_ar' => '  ما الذي تغيّر في المركز؟  ',
        'text_en' => '',
        'frequency' => 'monthly',
    ]);

    $fresh = $added->fresh();

    expect($fresh->report_template_id)->toBe($template->id)
        ->and($fresh->narrative_question_id)->toBeNull()
        ->and($fresh->text_ar)->toBe('ما الذي تغيّر في المركز؟')
        ->and($fresh->text_en)->toBeNull()
        ->and($fresh->is_active)->toBeTrue()
        ->and($fresh->sort_order)->toBe(3)
        ->and(rtqOrder($template))->toBe([$first->id, $second->id, $added->id]);

    $activity = Activity::query()->where('event', 'report_template.question_added')->sole();

    expect($activity->subject_id)->toBe($template->id)
        ->and($activity->properties['question_id'])->toBe($added->id)
        ->and($activity->shouldNotify())->toBeFalse();
});

test('adding a question needs its Arabic text and a known frequency', function () {
    $this->actingAs(mealManager());
    $template = rtqTemplate();

    expect(rtqRefusal(fn () => app(SaveReportTemplateQuestion::class)->handle($template, null, ['text_ar' => ' ', 'frequency' => 'monthly'])))->toBe(__('narrative_questions.enter_arabic_text'))
        ->and(rtqRefusal(fn () => app(SaveReportTemplateQuestion::class)->handle($template, null, ['text_ar' => 'سؤال', 'frequency' => 'weekly'])))->toBe(__('narrative_questions.choose_frequency'))
        ->and($template->questions()->count())->toBe(0);
});

test('editing a question saves its texts and records the change', function () {
    $this->actingAs(mealManager());
    $template = rtqTemplate();
    $question = rtqQuestion($template, ['sort_order' => 1]);

    app(SaveReportTemplateQuestion::class)->handle($template, $question->id, [
        'text_ar' => 'سؤال معدّل',
        'text_en' => 'An edited question',
        'frequency' => 'monthly',
    ], $question->updated_at?->toDateTimeString());

    $fresh = $question->fresh();

    expect($fresh->text_ar)->toBe('سؤال معدّل')
        ->and($fresh->text_en)->toBe('An edited question')
        ->and($fresh->sort_order)->toBe(1)
        ->and(Activity::query()->where('event', 'report_template.question_changed')->sole()->properties['question_id'])->toBe($question->id);
});

test('a stale edit writes nothing', function () {
    $this->actingAs(mealManager());
    $template = rtqTemplate();
    $question = rtqQuestion($template, ['text_ar' => 'السؤال الأصلي']);

    expect(rtqRefusal(fn () => app(SaveReportTemplateQuestion::class)->handle($template, $question->id, [
        'text_ar' => 'سؤال معدّل',
        'frequency' => 'monthly',
    ], '2020-01-01 00:00:00')))->toBe(__('report_templates.stale'))
        ->and($question->fresh()->text_ar)->toBe('السؤال الأصلي')
        ->and(Activity::query()->where('event', 'report_template.question_changed')->exists())->toBeFalse();
});

test('a question with answers keeps its frequency; one without moves last into the new frequency', function () {
    $this->actingAs(mealManager());
    $template = rtqTemplate();
    $answered = rtqQuestion($template, ['sort_order' => 1]);
    $fresh = rtqQuestion($template, ['sort_order' => 2]);
    $quarterly = rtqQuestion($template, ['sort_order' => 4, 'frequency' => NarrativeQuestionFrequency::QuarterEnd]);
    rtqAnswer($answered);

    expect(rtqRefusal(fn () => app(SaveReportTemplateQuestion::class)->handle($template, $answered->id, [
        'text_ar' => $answered->text_ar,
        'frequency' => 'quarter_end',
    ])))->toBe(__('report_templates.question_frequency_locked'))
        ->and($answered->fresh()->frequency)->toBe(NarrativeQuestionFrequency::Monthly);

    app(SaveReportTemplateQuestion::class)->handle($template, $fresh->id, ['text_ar' => $fresh->text_ar, 'frequency' => 'quarter_end']);

    expect($fresh->fresh()->frequency)->toBe(NarrativeQuestionFrequency::QuarterEnd)
        ->and($fresh->fresh()->sort_order)->toBe(5)
        ->and(rtqOrder($template, NarrativeQuestionFrequency::QuarterEnd))->toBe([$quarterly->id, $fresh->id]);
});

test('a question moves up and down inside its frequency only, and not past either end', function () {
    $this->actingAs(mealManager());
    $template = rtqTemplate();
    $a = rtqQuestion($template, ['sort_order' => 1]);
    $b = rtqQuestion($template, ['sort_order' => 2]);
    $c = rtqQuestion($template, ['sort_order' => 3]);
    $quarterly = rtqQuestion($template, ['sort_order' => 1, 'frequency' => NarrativeQuestionFrequency::QuarterEnd]);
    $move = app(MoveReportTemplateQuestion::class);

    $move->handle($template, $c->id, 'up');
    expect(rtqOrder($template))->toBe([$a->id, $c->id, $b->id]);

    $move->handle($template, $a->id, 'down');
    expect(rtqOrder($template))->toBe([$c->id, $a->id, $b->id]);

    $move->handle($template, $c->id, 'up');
    $move->handle($template, $b->id, 'down');
    $move->handle($template, $quarterly->id, 'up');

    expect(rtqOrder($template))->toBe([$c->id, $a->id, $b->id])
        ->and(rtqOrder($template, NarrativeQuestionFrequency::QuarterEnd))->toBe([$quarterly->id])
        ->and($quarterly->fresh()->sort_order)->toBe(1)
        ->and(fn () => $move->handle($template, $a->id, 'sideways'))->toThrow(InvalidArgumentException::class);
});

test('a question switches off and on, and switching to the state it has is stale', function () {
    $this->actingAs(mealManager());
    $template = rtqTemplate();
    $question = rtqQuestion($template);
    $action = app(SetReportTemplateQuestionActive::class);

    $action->handle($template, $question->id, false);
    expect($question->fresh()->is_active)->toBeFalse()
        ->and(rtqRefusal(fn () => $action->handle($template, $question->id, false)))->toBe(__('report_templates.stale'));

    $action->handle($template, $question->id, true);
    expect($question->fresh()->is_active)->toBeTrue();

    $switches = Activity::query()->where('event', 'report_template.question_switched')->orderBy('id')->get();

    expect($switches)->toHaveCount(2)
        ->and($switches->map(fn (Activity $activity): array => [$activity->properties['question_id'], $activity->properties['active']])->all())
        ->toBe([[$question->id, false], [$question->id, true]]);
});

test('a question without answers is deleted', function () {
    $this->actingAs(mealManager());
    $template = rtqTemplate();
    $question = rtqQuestion($template);

    app(DeleteReportTemplateQuestion::class)->handle($template, $question->id);

    expect(ReportTemplateQuestion::query()->whereKey($question->id)->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'report_template.question_removed')->sole()->properties['question_id'])->toBe($question->id);
});

test('a question with answers cannot be deleted', function () {
    $this->actingAs(mealManager());
    $template = rtqTemplate();
    $question = rtqQuestion($template);
    rtqAnswer($question);

    expect(rtqRefusal(fn () => app(DeleteReportTemplateQuestion::class)->handle($template, $question->id)))->toBe(__('report_templates.question_has_answers'))
        ->and(ReportTemplateQuestion::query()->whereKey($question->id)->exists())->toBeTrue()
        ->and(Activity::query()->where('event', 'report_template.question_removed')->exists())->toBeFalse();
});

test('every action is refused to a user without report-templates.manage and to a MEAL officer not responsible for the place', function (string $role) {
    holdMealManagerSlot();
    $template = rtqTemplate();
    $question = rtqQuestion($template, ['text_ar' => 'السؤال الأصلي']);
    $this->actingAs(userWithRole($role, insertHrEmployee(firstNameEn: 'Rami')));

    $calls = [
        fn () => app(SaveReportTemplateQuestion::class)->handle($template, null, ['text_ar' => 'سؤال جديد', 'frequency' => 'monthly']),
        fn () => app(SaveReportTemplateQuestion::class)->handle($template, $question->id, ['text_ar' => 'سؤال معدّل', 'frequency' => 'monthly']),
        fn () => app(MoveReportTemplateQuestion::class)->handle($template, $question->id, 'down'),
        fn () => app(SetReportTemplateQuestionActive::class)->handle($template, $question->id, false),
        fn () => app(DeleteReportTemplateQuestion::class)->handle($template, $question->id),
    ];

    foreach ($calls as $call) {
        expect($call)->toThrow(AuthorizationException::class);
    }

    expect($template->questions()->count())->toBe(1)
        ->and($question->fresh()->text_ar)->toBe('السؤال الأصلي')
        ->and($question->fresh()->is_active)->toBeTrue()
        ->and(Activity::query()->where('event', 'like', 'report_template.question_%')->exists())->toBeFalse();
})->with(['no permission' => 'Team Member', 'another place\'s MEAL officer' => 'MEAL']);

test('a question of another template is not found and nothing is written', function () {
    $this->actingAs(mealManager());
    $template = rtqTemplate();
    $foreign = rtqQuestion(rtqTemplate(), ['text_ar' => 'سؤال قالب آخر', 'sort_order' => 1]);
    $before = (array) DB::table('report_template_questions')->find($foreign->id);

    $calls = [
        fn () => app(SaveReportTemplateQuestion::class)->handle($template, $foreign->id, ['text_ar' => 'سؤال معدّل', 'frequency' => 'monthly']),
        fn () => app(MoveReportTemplateQuestion::class)->handle($template, $foreign->id, 'down'),
        fn () => app(SetReportTemplateQuestionActive::class)->handle($template, $foreign->id, false),
        fn () => app(DeleteReportTemplateQuestion::class)->handle($template, $foreign->id),
    ];

    foreach ($calls as $call) {
        expect($call)->toThrow(ModelNotFoundException::class);
    }

    expect((array) DB::table('report_template_questions')->find($foreign->id))->toBe($before)
        ->and(Activity::query()->where('event', 'like', 'report_template.question_%')->exists())->toBeFalse();
});

test('no action writes the bank question a template question was copied from', function () {
    $this->actingAs(mealManager());
    $template = rtqTemplate();
    $bank = NarrativeQuestion::factory()->create(['text_ar' => 'سؤال البنك', 'sort_order' => 1]);
    $copy = rtqQuestion($template, ['narrative_question_id' => $bank->id, 'text_ar' => 'سؤال البنك', 'sort_order' => 1]);
    rtqQuestion($template, ['sort_order' => 2]);
    $before = (array) DB::table('narrative_questions')->find($bank->id);
    $this->travel(1)->minutes();

    app(SaveReportTemplateQuestion::class)->handle($template, $copy->id, ['text_ar' => 'نسخة القالب', 'text_en' => 'Template copy', 'frequency' => 'monthly']);
    app(MoveReportTemplateQuestion::class)->handle($template, $copy->id, 'down');
    app(SetReportTemplateQuestionActive::class)->handle($template, $copy->id, false);
    app(SetReportTemplateQuestionActive::class)->handle($template, $copy->id, true);
    app(DeleteReportTemplateQuestion::class)->handle($template, $copy->id);

    expect((array) DB::table('narrative_questions')->find($bank->id))->toBe($before)
        ->and(NarrativeQuestion::query()->count())->toBe(1);
});

test('the recorded question activities carry the question id and no text', function () {
    $this->actingAs(mealManager());
    $template = rtqTemplate();
    $question = app(SaveReportTemplateQuestion::class)->handle($template, null, ['text_ar' => 'نص سري للسؤال', 'frequency' => 'monthly']);
    app(SaveReportTemplateQuestion::class)->handle($template, $question->id, ['text_ar' => 'نص سري معدّل', 'frequency' => 'monthly']);
    app(SetReportTemplateQuestionActive::class)->handle($template, $question->id, false);
    app(DeleteReportTemplateQuestion::class)->handle($template, $question->id);

    $activities = Activity::query()->where('event', 'like', 'report_template.question_%')->orderBy('id')->get();

    expect($activities->pluck('event')->all())->toBe([
        'report_template.question_added',
        'report_template.question_changed',
        'report_template.question_switched',
        'report_template.question_removed',
    ]);

    foreach ($activities as $activity) {
        expect($activity->properties['question_id'])->toBe($question->id)
            ->and(json_encode($activity->properties, JSON_UNESCAPED_UNICODE))->not->toContain('نص سري')
            ->and(app(ActivityPresenter::class)->sentence($activity, 'en'))->not->toStartWith('notifications.')
            ->and(app(ActivityPresenter::class)->sentence($activity, 'ar'))->not->toStartWith('notifications.');
    }
});
