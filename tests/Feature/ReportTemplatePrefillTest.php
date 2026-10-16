<?php

use App\Enums\IndicatorCollector;
use App\Enums\LineSchedule;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Enums\ReportTemplateLineStatus;
use App\Enums\ReportTemplateStatus;
use App\Enums\ResultType;
use App\Events\ProposalStatusChanged;
use App\Listeners\PrefillReportTemplates;
use App\Models\Activity;
use App\Models\AnnualPlan;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\MealCoverageAssignment;
use App\Models\NarrativeQuestion;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateLine;
use App\Models\Result;
use App\Models\UserNotification;
use App\Support\ReportTemplatePrefiller;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo('2026-10-16 10:00:00');

    $this->program = Program::factory()->create();
    $this->centerA = Project::factory()->approved()->create(['program_id' => $this->program->id, 'start_date' => '2026-11-01', 'end_date' => '2027-10-31']);
    $this->centerB = Project::factory()->approved()->create(['program_id' => $this->program->id, 'start_date' => '2026-11-01', 'end_date' => '2027-10-31']);
    $this->proposal = prefillProposal([$this->centerA->id => 180, $this->centerB->id => null]);
    $this->output = Result::factory()->create(['type' => ResultType::Output, 'program_id' => $this->program->id, 'proposal_id' => $this->proposal->id]);
});

/**
 * An approved proposal over the given centers (project id => target_beneficiaries).
 *
 * @param  array<int, int|null>  $centers
 */
function prefillProposal(array $centers, string $begin = '2026-11-15', string $end = '2027-10-20'): Proposal
{
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Approved, 'actual_date_begin' => $begin, 'actual_date_end' => $end]);

    foreach ($centers as $projectId => $beneficiaries) {
        $proposal->projects()->attach($projectId, ['target_beneficiaries' => $beneficiaries]);
    }

    return $proposal;
}

/**
 * A proposal target of the given center on an indicator under the result.
 *
 * @param  array<string, mixed>  $library  library entry attributes, or null for an unlinked indicator
 */
function prefillTarget(Proposal $proposal, Result $result, Project $project, ?array $library = []): PlanIndicator
{
    $entry = $library === null ? null : LibraryIndicator::factory()->create($library);
    $indicator = Indicator::factory()->create(['result_id' => $result->id, 'library_indicator_id' => $entry?->id]);

    return PlanIndicator::factory()->forProposal($proposal)->create(['indicator_id' => $indicator->id, 'project_id' => $project->id]);
}

function approveForPrefill(Proposal $proposal): void
{
    ProposalStatusChanged::dispatch($proposal->id, ProposalStatus::Submitted, ProposalStatus::Approved, null);
}

function templateOf(Project $project): ?ReportTemplate
{
    return ReportTemplate::query()->where('project_id', $project->id)->first();
}

test('approving a two-center proposal pre-fills a pending template per center with one pending line per library target', function () {
    $fixedA = prefillTarget($this->proposal, $this->output, $this->centerA, ['denominator_basis' => 'fixed', 'measure' => 'percentage', 'unit' => null, 'collected_by' => IndicatorCollector::Meal]);
    $countA = prefillTarget($this->proposal, $this->output, $this->centerA, ['collected_by' => IndicatorCollector::ProjectTeam]);
    $fixedB = prefillTarget($this->proposal, $this->output, $this->centerB, ['denominator_basis' => 'fixed', 'measure' => 'percentage', 'unit' => null]);

    approveForPrefill($this->proposal);

    foreach ([$this->centerA, $this->centerB] as $project) {
        expect(templateOf($project)?->status)->toBe(ReportTemplateStatus::Pending);
    }

    $lines = ReportTemplateLine::query()->get()->keyBy('plan_indicator_id');
    expect($lines->keys()->sort()->values()->all())->toBe(collect([$fixedA->id, $countA->id, $fixedB->id])->sort()->values()->all());

    $line = $lines[$fixedA->id];
    expect($line->report_template_id)->toBe(templateOf($this->centerA)->id)
        ->and($line->status)->toBe(ReportTemplateLineStatus::Pending)
        ->and($line->schedule)->toBe(LineSchedule::LibraryDefault)
        ->and($line->entered_by)->toBe(IndicatorCollector::Meal)
        ->and($line->fixed_denominator)->toBe(180)
        ->and($line->effective_from->toDateString())->toBe('2026-11-01')
        ->and($line->effective_to->toDateString())->toBe('2027-10-01')
        ->and($line->evidenceTypes()->count())->toBe(0);

    expect($lines[$countA->id]->entered_by)->toBe(IndicatorCollector::ProjectTeam)
        ->and($lines[$countA->id]->fixed_denominator)->toBeNull()
        ->and($lines[$fixedB->id]->report_template_id)->toBe(templateOf($this->centerB)->id)
        ->and($lines[$fixedB->id]->fixed_denominator)->toBeNull();
});

test('the result lists unlinked targets and fixed-basis lines with no beneficiaries, and creates no line for the unlinked one', function () {
    $unlinked = prefillTarget($this->proposal, $this->output, $this->centerA, null);
    prefillTarget($this->proposal, $this->output, $this->centerA, ['denominator_basis' => 'fixed', 'measure' => 'percentage', 'unit' => null]);
    $fixedB = prefillTarget($this->proposal, $this->output, $this->centerB, ['denominator_basis' => 'fixed', 'measure' => 'percentage', 'unit' => null]);

    $result = app(ReportTemplatePrefiller::class)->prefillForProposal($this->proposal);

    $missingLine = ReportTemplateLine::query()->where('plan_indicator_id', $fixedB->id)->sole();

    expect($result->unlinkedPlanIndicatorIds)->toBe([$unlinked->id])
        ->and($result->missingFixedDenominatorLineIds)->toBe([$missingLine->id])
        ->and($result->createdTemplateIds)->toHaveCount(2)
        ->and($result->createdLineIds)->toHaveCount(2)
        ->and(ReportTemplateLine::query()->where('plan_indicator_id', $unlinked->id)->exists())->toBeFalse();
});

test('handling the approval twice creates no duplicate template, line or question', function () {
    prefillTarget($this->proposal, $this->output, $this->centerA);
    $listener = app(PrefillReportTemplates::class);
    $event = new ProposalStatusChanged($this->proposal->id, ProposalStatus::Submitted, ProposalStatus::Approved, null);

    $listener->handle($event);
    $counts = [ReportTemplate::query()->count(), ReportTemplateLine::query()->count(), DB::table('report_template_questions')->count()];

    $listener->handle($event);
    $second = app(ReportTemplatePrefiller::class)->prefillForProposal($this->proposal);

    expect([ReportTemplate::query()->count(), ReportTemplateLine::query()->count(), DB::table('report_template_questions')->count()])->toBe($counts)
        ->and($counts[0])->toBe(2)
        ->and($counts[1])->toBe(1)
        ->and($second->createdTemplateIds)->toBe([])
        ->and($second->createdLineIds)->toBe([]);
});

test('a new approved proposal on an active project adds pending lines and leaves the confirmed template and lines alone', function () {
    $project = Project::factory()->active()->create(['program_id' => $this->program->id]);
    $first = prefillProposal([$project->id => 50], '2025-01-01', '2026-12-31');
    $template = ReportTemplate::factory()->confirmed()->create(['project_id' => $project->id]);
    $existing = ReportTemplateLine::factory()->confirmed()->create([
        'report_template_id' => $template->id,
        'plan_indicator_id' => prefillTarget($first, $this->output, $project)->id,
    ]);
    $before = $existing->fresh()->toArray();

    $second = prefillProposal([$project->id => 70], '2026-12-01', '2027-11-30');
    $output = Result::factory()->create(['type' => ResultType::Output, 'program_id' => $this->program->id, 'proposal_id' => $second->id]);
    $newTarget = prefillTarget($second, $output, $project);

    approveForPrefill($second);

    $added = ReportTemplateLine::query()->where('plan_indicator_id', $newTarget->id)->sole();
    expect($template->fresh()->status)->toBe(ReportTemplateStatus::Confirmed)
        ->and($added->report_template_id)->toBe($template->id)
        ->and($added->status)->toBe(ReportTemplateLineStatus::Pending)
        ->and($added->effective_from->toDateString())->toBe('2026-12-01')
        ->and($existing->fresh()->toArray())->toBe($before)
        ->and(DB::table('report_template_questions')->where('report_template_id', $template->id)->count())->toBe(0)
        ->and($project->fresh()->status)->toBe(ProjectStatus::Active);
});

test('the active bank questions are copied once, when the template is created', function () {
    $monthly = NarrativeQuestion::factory()->monthly()->create(['text_ar' => 'ما أبرز الإنجازات؟', 'text_en' => 'Main achievements?', 'sort_order' => 3]);
    $retired = NarrativeQuestion::factory()->inactive()->create();
    $expected = NarrativeQuestion::query()->active()->ordered()->get();

    approveForPrefill($this->proposal);

    $copies = DB::table('report_template_questions')->where('report_template_id', templateOf($this->centerA)->id)->orderBy('id')->get();
    expect($copies->pluck('narrative_question_id')->all())->toBe($expected->pluck('id')->all())
        ->and($copies->pluck('narrative_question_id')->all())->not->toContain($retired->id);

    $copy = $copies->firstWhere('narrative_question_id', $monthly->id);
    expect([$copy->text_ar, $copy->text_en, $copy->frequency, $copy->sort_order, (bool) $copy->is_active])
        ->toBe(['ما أبرز الإنجازات؟', 'Main achievements?', 'monthly', 3, true]);

    NarrativeQuestion::factory()->create();
    app(ReportTemplatePrefiller::class)->prefillProject($this->centerA);

    expect(DB::table('report_template_questions')->where('report_template_id', templateOf($this->centerA)->id)->count())->toBe($copies->count());
});

test('the project\'s HC annual-plan targets get pending lines over the plan year within the project dates', function () {
    $plan = AnnualPlan::factory()->create(['program_id' => $this->program->id, 'year' => 2027]);
    $planResult = Result::factory()->create(['type' => ResultType::Output, 'program_id' => $this->program->id]);
    $hcEntry = LibraryIndicator::factory()->create(['code' => 'HC.OP90.1', 'level_ref' => 'OP90']);
    $programEntry = LibraryIndicator::factory()->create(['code' => 'WASH.OP91.1', 'level_ref' => 'OP91', 'program_id' => $this->program->id]);
    $hcTarget = PlanIndicator::factory()->forAnnualPlan($plan)->create([
        'indicator_id' => Indicator::factory()->create(['result_id' => $planResult->id, 'library_indicator_id' => $hcEntry->id])->id,
        'project_id' => $this->centerA->id,
    ]);
    $programTarget = PlanIndicator::factory()->forAnnualPlan($plan)->create([
        'indicator_id' => Indicator::factory()->create(['result_id' => $planResult->id, 'library_indicator_id' => $programEntry->id])->id,
        'project_id' => $this->centerA->id,
    ]);

    approveForPrefill($this->proposal);

    $line = ReportTemplateLine::query()->where('plan_indicator_id', $hcTarget->id)->sole();
    expect($line->status)->toBe(ReportTemplateLineStatus::Pending)
        ->and($line->effective_from->toDateString())->toBe('2027-01-01')
        ->and($line->effective_to->toDateString())->toBe('2027-10-01')
        ->and(ReportTemplateLine::query()->where('plan_indicator_id', $programTarget->id)->exists())->toBeFalse();
});

test('the responsible MEAL officer and the MEAL Manager are told once, only when lines were created', function () {
    $managerId = holdMealManagerSlot();
    $manager = userWithRole('MEAL', $managerId);
    $officerId = insertHrEmployee(firstNameEn: 'Omar');
    $officer = userWithRole('MEAL', $officerId);
    Activity::withoutRecording(fn () => MealCoverageAssignment::factory()->forProject($this->centerA->id)->create(['employee_id' => $officerId]));
    prefillTarget($this->proposal, $this->output, $this->centerA);

    approveForPrefill($this->proposal);
    approveForPrefill($this->proposal);

    $pendingFor = fn ($user) => UserNotification::query()->forUser($user)->get()
        ->filter(fn (UserNotification $row): bool => $row->data['key'] === 'notifications.events.report_template.pending');

    expect($pendingFor($officer)->sole()->data['count'])->toBe(1)
        ->and($pendingFor($officer)->sole()->subject_id)->toBe($this->centerA->id)
        ->and($pendingFor($manager)->sole()->data['count'])->toBe(1);
});

test('the MEAL Manager responsible by fallback is told once', function () {
    $manager = userWithRole('MEAL', holdMealManagerSlot());
    prefillTarget($this->proposal, $this->output, $this->centerA);

    approveForPrefill($this->proposal);

    expect(UserNotification::query()->forUser($manager)->get()
        ->filter(fn (UserNotification $row): bool => $row->data['key'] === 'notifications.events.report_template.pending')
        ->sole()->data['count'])->toBe(1);
});

test('a move to any status but approved pre-fills nothing', function () {
    prefillTarget($this->proposal, $this->output, $this->centerA);

    ProposalStatusChanged::dispatch($this->proposal->id, ProposalStatus::ProposalReview, ProposalStatus::Submitted, null);

    expect(app(PrefillReportTemplates::class)->shouldQueue(new ProposalStatusChanged($this->proposal->id, ProposalStatus::Approved, ProposalStatus::Active, null)))->toBeFalse()
        ->and(ReportTemplate::query()->count())->toBe(0);
});
