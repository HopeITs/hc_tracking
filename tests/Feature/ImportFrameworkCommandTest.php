<?php

use App\Enums\ActivityKind;
use App\Enums\IndicatorMeasure;
use App\Enums\ResultType;
use App\Models\Activity;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\Program;
use App\Models\Result;
use Illuminate\Support\Facades\DB;

const MicroUrbanFile = 'database/seeders/data/logframes/micro-urban.json';
const MicroAgriFile = 'database/seeders/data/logframes/micro-agri.json';

/**
 * A tracking program whose HR row carries the given code, and its id.
 */
function frameworkProgram(string $code = '002'): int
{
    $programId = Program::factory()->create()->id;
    DB::connection('hr')->table('programs')->where('id', $programId)->update(['code' => $code]);

    return $programId;
}

/**
 * One logframe indicator as the JSON files write it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function logframeIndicator(string $ref, string $name, string $targetRaw, string $measure = 'percentage', array $overrides = []): array
{
    return array_merge([
        'ref' => $ref,
        'name_ar' => $name,
        'measure' => $measure,
        'unit' => null,
        'target_raw' => $targetRaw,
        'definition' => 'تعريف',
        'calculation_method' => 'طريقة الحساب',
        'frequency' => 'quarterly',
        'follow_up_months' => null,
        'frequency_note' => 'ربع سنوي',
        'collected_by' => 'project_team',
        'activities' => [],
    ], $overrides);
}

/**
 * Write a small logframe file and return its path.
 *
 * @param  list<array<string, mixed>>  $outputIndicators
 * @param  list<array<string, mixed>>  $goalIndicators
 */
function logframeFile(string $track, array $outputIndicators, array $goalIndicators = [], string $code = '002'): string
{
    $path = tempnam(sys_get_temp_dir(), 'logframe').'.json';

    file_put_contents($path, json_encode([
        'program_code' => $code,
        'hr_program_id' => 999,
        'track' => $track,
        'goal' => ['statement' => "هدف {$track}", 'indicators' => $goalIndicators],
        'outcomes' => [[
            'ref' => 'outcome1',
            'statement' => "نتيجة {$track}",
            'indicators' => [],
            'outputs' => [['ref' => 'output1.1', 'statement' => "مخرج {$track}", 'indicators' => $outputIndicators]],
        ]],
    ], JSON_UNESCAPED_UNICODE));

    return $path;
}

/**
 * Row counts of every table the import writes.
 *
 * @return array<string, int>
 */
function frameworkCounts(): array
{
    return [
        'results' => DB::table('results')->count(),
        'indicators' => DB::table('indicators')->count(),
        'library' => DB::table('library_indicators')->count(),
        'activities' => DB::table('activities')->count(),
    ];
}

test('the urban file builds its track with 16 library entries and activity nodes', function () {
    $programId = frameworkProgram();

    $this->artisan('meal:import-framework', ['file' => MicroUrbanFile, '--program' => $programId])->assertSuccessful();

    $root = Result::query()->where('program_id', $programId)->whereNull('parent_id')->sole();
    expect($root->type)->toBe(ResultType::Impact)
        ->and($root->proposal_id)->toBeNull()
        ->and(LibraryIndicator::query()->where('program_id', $programId)->count())->toBe(16)
        ->and(Indicator::query()->whereNotNull('library_indicator_id')->count())->toBe(16);

    $output = Result::query()->where('type', ResultType::Output)->where('statement', 'تمويل مشاريع مدرة للدخل للأسر المستهدفة')->sole();
    $activities = Result::query()->where('parent_id', $output->id)->get();
    expect($activities->pluck('statement')->all())->toBe(['تسجيل استمارة', 'زيارات ميدانية', 'دراسة الالستمارات', 'لجنة لاخذ القرار', 'توقيع عقود', 'مشتريات', 'افتتاح مشروع'])
        ->and($activities->every(fn (Result $activity): bool => $activity->type === ResultType::Activity && $activity->activity_kind === ActivityKind::Continuous))->toBeTrue()
        ->and(Indicator::query()->whereIn('result_id', $activities->modelKeys())->exists())->toBeFalse();
});

test('both tracks share program code 002 and their codes never collide', function () {
    $programId = frameworkProgram();

    $this->artisan('meal:import-framework', ['file' => MicroUrbanFile, '--program' => $programId])->assertSuccessful();
    $this->artisan('meal:import-framework', ['file' => MicroAgriFile, '--program' => $programId])->assertSuccessful();

    $codes = LibraryIndicator::query()->pluck('code');
    expect($codes)->toHaveCount(37)
        ->and($codes->unique())->toHaveCount(37)
        ->and($codes->every(fn (string $code): bool => preg_match('/^002\.(G|CC|OC\d+|OP\d+\.\d+)\.\d+$/', $code) === 1))->toBeTrue()
        ->and(Result::query()->where('program_id', $programId)->where('type', ResultType::Impact)->count())->toBe(2)
        ->and(LibraryIndicator::query()->where('level_ref', 'OP1.1')->orderBy('serial')->pluck('code')->all())
        ->toBe(['002.OP1.1.1', '002.OP1.1.2', '002.OP1.1.3', '002.OP1.1.4', '002.OP1.1.5', '002.OP1.1.6']);

    $crossCutting = Result::query()->where('type', ResultType::CrossCutting)->sole();
    expect($crossCutting->parent_id)->toBeNull()
        ->and(Result::query()->where('parent_id', $crossCutting->id)->exists())->toBeFalse()
        ->and(Indicator::query()->where('result_id', $crossCutting->id)->count())->toBe(3)
        ->and(LibraryIndicator::query()->where('level_ref', 'CC')->count())->toBe(3);
});

test('an activity listed on a cross-cutting indicator is skipped and reported', function () {
    $this->artisan('meal:import-framework', ['file' => MicroAgriFile, '--program' => frameworkProgram()])
        ->expectsOutputToContain('استبيانات')
        ->assertSuccessful();

    expect(Result::query()->where('statement', 'استبيانات')->exists())->toBeFalse();
});

test('a second run creates nothing', function () {
    $programId = frameworkProgram();
    $this->artisan('meal:import-framework', ['file' => MicroUrbanFile, '--program' => $programId])->assertSuccessful();
    $before = frameworkCounts();

    $this->artisan('meal:import-framework', ['file' => MicroUrbanFile, '--program' => $programId])->assertSuccessful();

    expect(frameworkCounts())->toBe($before);
});

test('a dry run writes nothing', function () {
    $programId = frameworkProgram();
    $before = frameworkCounts();

    $this->artisan('meal:import-framework', ['file' => MicroUrbanFile, '--program' => $programId, '--dry-run' => true])
        ->expectsOutputToContain('002.G.1')
        ->assertSuccessful();

    expect(frameworkCounts())->toBe($before);
});

test('the import records no activity', function () {
    $programId = frameworkProgram();
    $before = Activity::query()->count();

    $this->artisan('meal:import-framework', ['file' => MicroUrbanFile, '--program' => $programId])->assertSuccessful();

    expect(Activity::query()->count())->toBe($before);
});

test('default targets map per D-37', function (string $targetRaw, string $measure, ?string $target, IndicatorMeasure $expectedMeasure, bool $isTrackingOnly) {
    $file = logframeFile('urban', [logframeIndicator('OP1.1a', 'مؤشر الاختبار', $targetRaw, $measure)]);

    $this->artisan('meal:import-framework', ['file' => $file, '--program' => frameworkProgram()])->assertSuccessful();

    $indicator = Indicator::query()->sole();
    expect($indicator->target)->toBe($target)
        ->and($indicator->measure)->toBe($expectedMeasure)
        ->and($indicator->is_tracking_only)->toBe($isTrackingOnly)
        ->and(LibraryIndicator::query()->sole()->measure)->toBe($expectedMeasure);
})->with([
    'a ratio' => ['0.8', 'percentage', '80.00', IndicatorMeasure::Percentage, false],
    'a ratio on a count' => ['0.3', 'numeric', '30.00', IndicatorMeasure::Percentage, false],
    'one whole' => ['1', 'numeric', '100.00', IndicatorMeasure::Percentage, false],
    'a percent with a note' => ['80% ( مشاريع جديدة و زراعية)', 'numeric', '80.00', IndicatorMeasure::Percentage, false],
    'a dash' => ['-', 'average', null, IndicatorMeasure::Average, true],
    'two stars' => ['**', 'numeric', null, IndicatorMeasure::Numeric, false],
    'a rise from baseline' => ['زيادة 20%', 'percentage', '20.00', IndicatorMeasure::Percentage, false],
]);

test('a target relative to the baseline is noted on the library entry', function () {
    $file = logframeFile('urban', [logframeIndicator('OP1.1a', 'نسبة التغير في الدخل', 'زيادة 20%')]);

    $this->artisan('meal:import-framework', ['file' => $file, '--program' => frameworkProgram()])->assertSuccessful();

    expect(LibraryIndicator::query()->sole()->calculation_method)->toContain('زيادة 20%');
});

test('the same indicator in both tracks is one library entry', function () {
    $programId = frameworkProgram();
    $shared = logframeIndicator('OP1.1a', 'عدد المشاريع الممولة', '-', 'numeric');

    $this->artisan('meal:import-framework', ['file' => logframeFile('urban', [$shared]), '--program' => $programId])->assertSuccessful();
    $this->artisan('meal:import-framework', ['file' => logframeFile('agri', [$shared]), '--program' => $programId])->assertSuccessful();

    $entry = LibraryIndicator::query()->sole();
    expect(Indicator::query()->where('library_indicator_id', $entry->id)->count())->toBe(2);
});

test('re-import updates an entry nobody edited', function () {
    $programId = frameworkProgram();
    $this->artisan('meal:import-framework', ['file' => logframeFile('urban', [logframeIndicator('OP1.1a', 'مؤشر', '0.8')]), '--program' => $programId])->assertSuccessful();
    $this->travel(1)->day();

    $file = logframeFile('urban', [logframeIndicator('OP1.1a', 'مؤشر', '0.8', overrides: ['definition' => 'تعريف جديد'])]);
    $this->artisan('meal:import-framework', ['file' => $file, '--program' => $programId])->assertSuccessful();

    expect(LibraryIndicator::query()->sole()->definition)->toBe('تعريف جديد');
});

test('an entry edited by hand after the import is reported as a conflict and left alone', function () {
    $programId = frameworkProgram();
    $this->artisan('meal:import-framework', ['file' => logframeFile('urban', [logframeIndicator('OP1.1a', 'مؤشر', '0.8')]), '--program' => $programId])->assertSuccessful();
    $this->travel(1)->day();
    LibraryIndicator::query()->sole()->update(['definition' => 'عدّله فريق MEAL']);
    $this->travel(1)->day();

    $file = logframeFile('urban', [logframeIndicator('OP1.1a', 'مؤشر', '0.8', overrides: ['definition' => 'تعريف جديد'])]);
    $this->artisan('meal:import-framework', ['file' => $file, '--program' => $programId])
        ->expectsOutputToContain('Conflict: 002.OP1.1.1')
        ->assertSuccessful();

    expect(LibraryIndicator::query()->sole()->definition)->toBe('عدّله فريق MEAL');
});

test('results already in the program framework are neither touched nor deleted', function () {
    $programId = frameworkProgram();
    $outcomeId = insertResult(null, $programId, ResultType::Outcome, 'Improved micro project outcomes.');
    $indicatorId = insertIndicator(insertResult($outcomeId, $programId, ResultType::Output), 'Placeholder indicator');
    $before = DB::table('results')->where('program_id', $programId)->orderBy('id')->get()->toArray();

    $this->artisan('meal:import-framework', ['file' => MicroUrbanFile, '--program' => $programId])
        ->expectsOutputToContain((string) $outcomeId)
        ->assertSuccessful();

    expect(DB::table('results')->whereIn('id', array_column($before, 'id'))->orderBy('id')->get()->toArray())->toEqual($before)
        ->and(DB::table('indicators')->where('id', $indicatorId)->value('library_indicator_id'))->toBeNull();
});

test('a program whose HR code is shared or different stops the run before anything is written', function (string $hrCode, bool $shared) {
    $programId = frameworkProgram($hrCode);

    if ($shared) {
        insertHrProgram('Other program', code: $hrCode);
    }

    $before = frameworkCounts();

    $this->artisan('meal:import-framework', ['file' => MicroUrbanFile, '--program' => $programId])->assertFailed();

    expect(frameworkCounts())->toBe($before);
})->with([
    'shared in HR' => ['002', true],
    'not the file\'s code' => ['005', false],
]);
