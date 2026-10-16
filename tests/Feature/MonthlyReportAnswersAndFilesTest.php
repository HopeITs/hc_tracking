<?php

use App\Actions\MonthlyReports\DeleteMonthlyReport;
use App\Actions\MonthlyReports\DeleteMonthlyReportFile;
use App\Actions\MonthlyReports\SaveMonthlyReportAnswers;
use App\Actions\MonthlyReports\SaveMonthlyReportFile;
use App\Actions\MonthlyReports\SubmitMonthlyReport;
use App\Enums\IndicatorCollector;
use App\Enums\MonthlyReportStatus;
use App\Models\Activity;
use App\Models\EvidenceType;
use App\Models\LibraryIndicator;
use App\Models\MealCoverageAssignment;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportAnswer;
use App\Models\MonthlyReportFile;
use App\Models\MonthlyReportLine;
use App\Models\MonthlyReportLineShare;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateLine;
use App\Models\ReportTemplateQuestion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| Narrative answers and typed evidence files of a monthly report (contracts
| §13, §13.1 item 3, §16; D-42, D-43a).
*/

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-12-31 10:00:00');
    $this->city = insertHrCity('Aleppo');
    $this->ownerEmployee = insertHrEmployee(firstNameEn: 'Omar', lastNameEn: 'Owner');
    $this->owner = userWithRole('Public Relations', $this->ownerEmployee);
    $this->officerEmployee = insertHrEmployee(firstNameEn: 'Nour', lastNameEn: 'Officer');
    Activity::withoutRecording(fn () => MealCoverageAssignment::factory()->forCity($this->city)->create(['employee_id' => $this->officerEmployee]));
    $this->officer = userWithRole('MEAL', $this->officerEmployee);

    $this->project = Activity::withoutRecording(fn (): Project => Project::factory()->active()->create([
        'program_id' => Program::factory()->create()->id,
        'city_id' => $this->city,
        'center_id' => null,
        'user_id' => $this->ownerEmployee,
        'start_date' => '2026-10-01',
        'end_date' => '2026-12-31',
    ]));
    $this->template = Activity::withoutRecording(fn (): ReportTemplate => ReportTemplate::factory()->confirmed()->create(['project_id' => $this->project->id]));
    $this->evidenceType = EvidenceType::factory()->create();
});

function mafReport(string $month = '2026-12-01', ?Project $project = null): MonthlyReport
{
    return Activity::withoutRecording(fn (): MonthlyReport => MonthlyReport::factory()->create(['project_id' => ($project ?? test()->project)->id, 'period' => $month]));
}

/**
 * A filled count line whose template line requires the given evidence types.
 *
 * @param  list<int>  $requiredTypeIds
 */
function mafLine(MonthlyReport $report, array $requiredTypeIds = [], IndicatorCollector $enteredBy = IndicatorCollector::ProjectTeam): MonthlyReportLine
{
    return Activity::withoutRecording(function () use ($report, $requiredTypeIds, $enteredBy): MonthlyReportLine {
        $library = LibraryIndicator::factory()->create();
        $target = PlanIndicator::factory()->forProposal()->create(['project_id' => $report->project_id]);
        $templateLine = ReportTemplateLine::factory()->confirmed()->create(['report_template_id' => test()->template->id, 'plan_indicator_id' => $target->id]);
        $templateLine->evidenceTypes()->attach($requiredTypeIds);
        $line = MonthlyReportLine::factory()->create([
            'monthly_report_id' => $report->id,
            'library_indicator_id' => $library->id,
            'entered_by' => $enteredBy,
            'value' => 5,
        ]);
        MonthlyReportLineShare::factory()->create(['monthly_report_line_id' => $line->id, 'plan_indicator_id' => $target->id, 'value' => 5]);

        return $line;
    });
}

function mafUpload(MonthlyReport $report, ?int $lineId = null, ?int $entryId = null, ?int $typeId = null, MonthlyReportStatus $expected = MonthlyReportStatus::Draft): MonthlyReportFile
{
    test()->actingAs($expected === MonthlyReportStatus::Draft ? test()->owner : test()->officer);

    return app(SaveMonthlyReportFile::class)->handle($report->fresh(), $expected, $typeId ?? test()->evidenceType->id, validUpload('evidence.pdf'), $lineId, $entryId);
}

/**
 * @return array<string, list<string>>
 */
function mafErrors(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('Expected a validation error.');
}

it('copies the question text into the answer', function () {
    $question = ReportTemplateQuestion::factory()->create(['report_template_id' => $this->template->id, 'text_ar' => 'ما أبرز الإنجازات؟']);
    $report = mafReport();
    $this->actingAs($this->owner);

    app(SaveMonthlyReportAnswers::class)->handle($report, MonthlyReportStatus::Draft, [['question_id' => $question->id, 'answer' => '  Three sessions held.  ']]);
    $question->update(['text_ar' => 'سؤال معدَّل']);

    $answer = MonthlyReportAnswer::query()->sole();
    expect($answer->question_text)->toBe('ما أبرز الإنجازات؟')
        ->and($answer->answer)->toBe('Three sessions held.');
});

it('asks quarter-end questions only in quarter-end months', function () {
    $monthly = ReportTemplateQuestion::factory()->create(['report_template_id' => $this->template->id]);
    $quarterly = ReportTemplateQuestion::factory()->quarterEnd()->create(['report_template_id' => $this->template->id]);
    ReportTemplateQuestion::factory()->inactive()->create(['report_template_id' => $this->template->id]);

    expect(SaveMonthlyReportAnswers::questions(mafReport('2026-11-01'))->pluck('id')->all())->toBe([$monthly->id])
        ->and(SaveMonthlyReportAnswers::questions(mafReport('2026-12-01'))->pluck('id')->all())->toBe([$monthly->id, $quarterly->id]);

    $this->actingAs($this->owner);
    expect(fn () => app(SaveMonthlyReportAnswers::class)->handle(MonthlyReport::query()->where('period', '2026-11-01')->sole(), MonthlyReportStatus::Draft, [['question_id' => $quarterly->id, 'answer' => 'x']]))
        ->toThrow(ModelNotFoundException::class);
});

it('rejects a question of another project and refuses a user outside the preparer side', function () {
    $otherProject = Activity::withoutRecording(fn (): Project => Project::factory()->active()->create());
    $foreign = ReportTemplateQuestion::factory()->create(['report_template_id' => ReportTemplate::factory()->create(['project_id' => $otherProject->id])->id]);
    $report = mafReport();

    $this->actingAs($this->owner);
    expect(fn () => app(SaveMonthlyReportAnswers::class)->handle($report, MonthlyReportStatus::Draft, [['question_id' => $foreign->id, 'answer' => 'x']]))
        ->toThrow(ModelNotFoundException::class);

    $this->actingAs($this->officer);
    expect(fn () => app(SaveMonthlyReportAnswers::class)->handle($report, MonthlyReportStatus::Draft, []))->toThrow(AuthorizationException::class);
});

it('stores an evidence file on a line', function () {
    $report = mafReport();
    $line = mafLine($report);

    $file = mafUpload($report, lineId: $line->id);

    expect($file->monthly_report_line_id)->toBe($line->id)
        ->and($file->uploaded_by)->toBe($this->owner->id)
        ->and($file->encryption_key_id)->not->toBeNull();
    Storage::disk('local')->assertExists($file->path);
});

it('rejects a file on another report\'s line, an inactive type and a line plus an entry', function () {
    $report = mafReport();
    $otherLine = mafLine(mafReport('2026-11-01'));
    $line = mafLine($report);

    expect(fn () => mafUpload($report, lineId: $otherLine->id))->toThrow(ModelNotFoundException::class);

    $inactive = EvidenceType::factory()->inactive()->create();
    expect(mafErrors(fn () => mafUpload($report, lineId: $line->id, typeId: $inactive->id))['evidence_type_id'])->toBe([__('monthly_reports.evidence_type_inactive')])
        ->and(mafErrors(fn () => mafUpload($report, lineId: $line->id, entryId: 1))['file'])->toBe([__('monthly_reports.file_scope')])
        ->and(MonthlyReportFile::query()->count())->toBe(0);
});

it('lets the manager attach to manager lines only and the validator to meal lines only', function () {
    $report = mafReport();
    $mealLine = mafLine($report, enteredBy: IndicatorCollector::Meal);
    $managerLine = mafLine($report);

    expect(fn () => mafUpload($report, lineId: $mealLine->id))->toThrow(AuthorizationException::class);

    Activity::withoutRecording(fn () => $report->forceFill(['status' => MonthlyReportStatus::Submitted, 'submitted_at' => now(), 'submitted_by' => $this->owner->id, 'prepared_by' => $this->owner->id])->save());

    expect(fn () => mafUpload($report, lineId: $managerLine->id, expected: MonthlyReportStatus::Submitted))->toThrow(AuthorizationException::class)
        ->and(mafUpload($report, lineId: $mealLine->id, expected: MonthlyReportStatus::Submitted)->monthly_report_line_id)->toBe($mealLine->id);
});

it('blocks submit until every required evidence type is attached', function () {
    $second = EvidenceType::factory()->create();
    $report = mafReport();
    $line = mafLine($report, [$this->evidenceType->id, $second->id]);
    mafLine($report, [$second->id], IndicatorCollector::Meal);
    mafUpload($report, lineId: $line->id);

    $this->actingAs($this->owner);
    $errors = mafErrors(fn () => app(SubmitMonthlyReport::class)->handle($report->fresh(), MonthlyReportStatus::Draft));
    expect($errors['lines'])->toBe([__('monthly_reports.evidence_required')]);

    mafUpload($report, lineId: $line->id, typeId: $second->id);
    $this->actingAs($this->owner);
    expect(app(SubmitMonthlyReport::class)->handle($report->fresh(), MonthlyReportStatus::Draft)->status)->toBe(MonthlyReportStatus::Submitted);
});

it('deletes a file from disk only after commit', function () {
    $report = mafReport();
    $file = mafUpload($report, lineId: mafLine($report)->id);
    $this->actingAs($this->owner);

    DB::beginTransaction();
    app(DeleteMonthlyReportFile::class)->handle($report->fresh(), MonthlyReportStatus::Draft, $file->id);
    Storage::disk('local')->assertExists($file->path);
    DB::commit();

    Storage::disk('local')->assertMissing($file->path);
    expect(MonthlyReportFile::query()->count())->toBe(0);
});

it('refuses to delete another report\'s file and removes the files of a deleted draft', function () {
    $report = mafReport();
    $other = mafReport('2026-11-01');
    $foreign = mafUpload($other, lineId: mafLine($other)->id);
    $this->actingAs($this->owner);

    expect(fn () => app(DeleteMonthlyReportFile::class)->handle($report->fresh(), MonthlyReportStatus::Draft, $foreign->id))->toThrow(ModelNotFoundException::class);

    app(DeleteMonthlyReport::class)->handle($other->fresh(), MonthlyReportStatus::Draft);

    Storage::disk('local')->assertMissing($foreign->path);
    expect(MonthlyReportFile::query()->count())->toBe(0);
});
