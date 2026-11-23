<?php

use App\Actions\ReportTemplates\CreateReportTemplateForProposal;
use App\Enums\AssignmentStatus;
use App\Enums\CollectionFrequency;
use App\Enums\DenominatorBasis;
use App\Enums\LineSchedule;
use App\Enums\NarrativeQuestionFrequency;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Enums\ReportTemplateLineStatus;
use App\Enums\ReportTemplateStatus;
use App\Enums\ResultType;
use App\Models\Activity;
use App\Models\ActivitySchedule;
use App\Models\EvidenceType;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\MealCoverageAssignment;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportAnswer;
use App\Models\NarrativeQuestion;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateLine;
use App\Models\ReportTemplateQuestion;
use App\Models\Result;
use App\Models\User;
use App\Support\Reporting\ReportPlace;
use App\Support\ReportTemplatePrefiller;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| The "Report template" tab on projects/show (contracts.md §12.4, T33 slice 3):
| lines grouped by proposal, edited and confirmed by the responsible MEAL
| officer or the MEAL Manager only, read-only for the owner, hidden from
| everyone else. Its narrative questions panel (Y-07 slice 2) is edited the
| same way as the bank's page.
*/

beforeEach(fn () => $this->travelTo('2026-10-14 10:00:00'));

/**
 * An approved project with an acknowledged owner and a pending template.
 *
 * @return array{project: Project, template: ReportTemplate}
 */
function rtpProject(ProjectStatus $status = ProjectStatus::Approved): array
{
    $project = Project::factory()->approved()->create(['status' => $status, 'start_date' => '2026-11-01', 'end_date' => '2027-10-31']);
    $project->currentAssignment('user_id')?->forceFill(['status' => AssignmentStatus::Acknowledged])->save();

    $template = Activity::withoutRecording(fn (): ReportTemplate => ReportTemplate::factory()->create(['project_id' => $project->id]));

    return ['project' => $project, 'template' => $template];
}

/**
 * A donor-approved proposal linked to the project.
 */
function rtpProposal(Project $project, string $name = 'Winter WASH'): Proposal
{
    return Activity::withoutRecording(function () use ($project, $name): Proposal {
        $proposal = Proposal::factory()->create([
            'name' => $name,
            'status' => $project->status === ProjectStatus::Active ? ProposalStatus::Active : ProposalStatus::Approved,
            'actual_date_begin' => '2026-11-01',
            'actual_date_end' => '2027-10-31',
        ]);
        $proposal->projects()->attach($project->id, ['target_beneficiaries' => 100]);

        return $proposal;
    });
}

/**
 * A pending line for a new target of the proposal.
 *
 * @param  array<string, mixed>  $library
 * @param  array<string, mixed>  $attributes
 */
function rtpLine(ReportTemplate $template, Proposal $proposal, array $library = [], bool $linked = true, array $attributes = []): ReportTemplateLine
{
    return Activity::withoutRecording(function () use ($template, $proposal, $library, $linked, $attributes): ReportTemplateLine {
        $entry = $linked ? LibraryIndicator::factory()->create($library) : null;
        $indicator = Indicator::factory()->create(['library_indicator_id' => $entry?->id]);
        $target = PlanIndicator::factory()->forProposal($proposal)->create(['indicator_id' => $indicator->id, 'project_id' => $template->project_id, 'target' => 250]);

        return ReportTemplateLine::factory()->create([
            'report_template_id' => $template->id,
            'plan_indicator_id' => $target->id,
            'effective_from' => '2026-11-01',
            'effective_to' => '2027-10-01',
            ...$attributes,
        ]);
    });
}

function rtpResponsibleOfficer(Project $project): User
{
    $officer = userWithRole('MEAL', insertHrEmployee(insertHrCity('Homs', 'حمص'), 'Nour', 'Ali'));
    Activity::withoutRecording(fn () => MealCoverageAssignment::factory()->forProject($project->id)->create(['employee_id' => $officer->employee_id]));

    return $officer;
}

function rtpPage(User $user, Project $project): Testable
{
    return Livewire::actingAs($user)->withQueryParams(['tab' => 'template'])->test('pages::projects.show', ['project' => $project]);
}

test('the responsible officer and the MEAL Manager see the lines grouped by proposal, with edit and confirm controls', function (string $who) {
    ['project' => $project, 'template' => $template] = rtpProject();
    $proposal = rtpProposal($project);
    $line = rtpLine($template, $proposal, ['name_en' => 'Households reached', 'code' => 'HC.OP1.1'], attributes: ['entered_by' => 'meal']);
    $evidence = EvidenceType::factory()->create(['name_en' => 'Attendance sheets']);
    $line->evidenceTypes()->attach($evidence->id);
    ReportTemplateQuestion::factory()->create(['report_template_id' => $template->id, 'text_en' => 'What changed this month?']);
    $actor = $who === 'officer' ? rtpResponsibleOfficer($project) : mealManager();

    rtpPage($actor, $project)
        ->assertSeeHtml('data-report-template')
        ->assertSee(__('report_templates.tab.title'))
        ->assertSee('Winter WASH')
        ->assertSee('Households reached')
        ->assertSee('HC.OP1.1')
        ->assertSee('250')
        ->assertSee(__('enums.line_schedule.library_default'))
        ->assertSee(__('enums.indicator_collector.meal'))
        ->assertSee('Attendance sheets')
        ->assertSee(__('enums.report_template_line_status.pending'))
        ->assertSee('What changed this month?')
        ->assertSeeHtml('data-test="template-edit-line-'.$line->id.'"')
        ->assertSeeHtml('data-test="template-confirm-button"');
})->with(['officer', 'meal manager']);

test('the owner reads the template without any control', function () {
    ['project' => $project, 'template' => $template] = rtpProject();
    $line = rtpLine($template, rtpProposal($project));
    $owner = userWithRole('Team Member', $project->user_id);

    rtpPage($owner, $project)
        ->assertSeeHtml('data-report-template')
        ->assertSeeHtml('data-template-line="'.$line->id.'"')
        ->assertSee(__('report_templates.tab.read_only'))
        ->assertDontSeeHtml('data-test="template-edit-line-'.$line->id.'"')
        ->assertDontSeeHtml('data-test="template-confirm-button"');

    $page = fn () => rtpPage($owner, $project);
    $page()->call('openTemplateLineModal', $line->id)->assertForbidden();
    $page()->set('selectedTemplateLineIds', [$line->id])->call('confirmReportTemplate')->assertForbidden();

    expect($line->fresh()->status)->toBe(ReportTemplateLineStatus::Pending)
        ->and($project->fresh()->status)->toBe(ProjectStatus::Approved);
});

test('an outsider gets no tab and forcing it shows nothing', function () {
    ['project' => $project, 'template' => $template] = rtpProject();
    rtpLine($template, rtpProposal($project), ['name_en' => 'Secret households']);

    rtpPage(userWithRole('Team Member', insertHrEmployee(firstNameEn: 'Sami')), $project)
        ->assertDontSeeHtml('data-report-template')
        ->assertDontSee(__('report_templates.tab.title'))
        ->assertDontSee('Secret households');
});

test('a MEAL user who is not responsible reads the template but cannot act', function () {
    ['project' => $project, 'template' => $template] = rtpProject();
    $line = rtpLine($template, rtpProposal($project));
    $meal = userWithRole('MEAL', insertHrEmployee(firstNameEn: 'Rami'));

    rtpPage($meal, $project)
        ->assertSeeHtml('data-template-line="'.$line->id.'"')
        ->assertDontSeeHtml('data-test="template-confirm-button"');

    rtpPage($meal, $project)->set('selectedTemplateLineIds', [$line->id])->call('confirmReportTemplate')->assertForbidden();

    expect($line->fresh()->status)->toBe(ReportTemplateLineStatus::Pending);
});

test('confirming selected lines on an approved project activates it', function () {
    ['project' => $project, 'template' => $template] = rtpProject();
    $line = rtpLine($template, rtpProposal($project));

    rtpPage(mealManager(), $project)
        ->set('selectedTemplateLineIds', [$line->id])
        ->call('askConfirmReportTemplate')
        ->assertSet('showTemplateConfirmModal', true)
        ->call('confirmReportTemplate')
        ->assertSet('showTemplateConfirmModal', false)
        ->assertSet('selectedTemplateLineIds', []);

    expect($line->fresh()->status)->toBe(ReportTemplateLineStatus::Confirmed)
        ->and($template->fresh()->status)->toBe(ReportTemplateStatus::Confirmed)
        ->and($project->fresh()->status)->toBe(ProjectStatus::Active);
});

test('a stale confirm clears the selection so the next confirm is not stuck', function () {
    ['project' => $project, 'template' => $template] = rtpProject();
    $first = rtpLine($template, rtpProposal($project));
    $second = rtpLine($template, rtpProposal($project, 'Spring WASH'));

    $page = rtpPage(mealManager(), $project)->set('selectedTemplateLineIds', [$first->id, $second->id]);

    $first->forceFill(['status' => ReportTemplateLineStatus::Confirmed])->save();

    $page->call('confirmReportTemplate')
        ->assertSet('selectedTemplateLineIds', []);

    expect($second->fresh()->status)->toBe(ReportTemplateLineStatus::Pending);
});

test('confirming with nothing selected is refused with the translated message and writes nothing', function () {
    ['project' => $project, 'template' => $template] = rtpProject();
    $line = rtpLine($template, rtpProposal($project));

    rtpPage(mealManager(), $project)
        ->call('confirmReportTemplate')
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => ($params['slots']['text'] ?? null) === __('report_templates.confirm_at_least_one'));

    expect($line->fresh()->status)->toBe(ReportTemplateLineStatus::Pending)
        ->and($project->fresh()->status)->toBe(ProjectStatus::Approved);
});

test('the edit dialog saves the schedule, months and evidence of a line', function () {
    ['project' => $project, 'template' => $template] = rtpProject();
    $line = rtpLine($template, rtpProposal($project));
    $evidence = EvidenceType::factory()->create();

    rtpPage(mealManager(), $project)
        ->call('openTemplateLineModal', $line->id)
        ->assertSet('showTemplateLineModal', true)
        ->assertSet('templateLineSchedule', 'library_default')
        ->set('templateLineSchedule', 'selected_months')
        ->set('templateLineMonths', ['3', '9'])
        ->set('templateLineEnteredBy', 'meal')
        ->set('templateLineEvidenceTypeIds', [(string) $evidence->id])
        ->call('saveTemplateLine')
        ->assertHasNoErrors()
        ->assertSet('showTemplateLineModal', false);

    $line->refresh();

    expect($line->schedule)->toBe(LineSchedule::SelectedMonths)
        ->and($line->schedule_months)->toBe([3, 9])
        ->and($line->entered_by->value)->toBe('meal')
        ->and($line->evidenceTypes()->pluck('evidence_types.id')->all())->toBe([$evidence->id]);
});

test('an invalid edit shows the message on its field and writes nothing', function () {
    ['project' => $project, 'template' => $template] = rtpProject();
    $line = rtpLine($template, rtpProposal($project));

    rtpPage(mealManager(), $project)
        ->call('openTemplateLineModal', $line->id)
        ->set('templateLineSchedule', 'selected_months')
        ->set('templateLineMonths', [])
        ->call('saveTemplateLine')
        ->assertHasErrors(['templateLineMonths'])
        ->assertSet('showTemplateLineModal', true);

    expect($line->fresh()->schedule)->toBe(LineSchedule::LibraryDefault);
});

test('an edit of a line changed meanwhile is refused as stale', function () {
    ['project' => $project, 'template' => $template] = rtpProject();
    $line = rtpLine($template, rtpProposal($project));

    $page = rtpPage(mealManager(), $project)->call('openTemplateLineModal', $line->id);
    $this->travel(5)->seconds();
    $line->forceFill(['fixed_denominator' => 40])->save();

    $page->set('templateLineSchedule', 'monthly')
        ->call('saveTemplateLine')
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => ($params['slots']['text'] ?? null) === __('report_templates.stale'))
        ->assertSet('showTemplateLineModal', false);

    expect($line->fresh()->schedule)->toBe(LineSchedule::LibraryDefault);
});

test('a line of another template is rejected for edit, confirm and retire', function () {
    ['project' => $project, 'template' => $template] = rtpProject();
    $own = rtpLine($template, rtpProposal($project));
    ['project' => $other, 'template' => $otherTemplate] = rtpProject();
    $foreign = rtpLine($otherTemplate, rtpProposal($other, 'Other'));
    $manager = mealManager();
    $notFound = fn (string $event, array $params): bool => ($params['slots']['text'] ?? null) === __('report_templates.tab.line_missing');

    rtpPage($manager, $project)->call('openTemplateLineModal', $foreign->id)
        ->assertDispatched('toast-show', $notFound)
        ->assertSet('showTemplateLineModal', false);
    rtpPage($manager, $project)->set('selectedTemplateLineIds', [$own->id, $foreign->id])->call('confirmReportTemplate')
        ->assertDispatched('toast-show', $notFound);
    rtpPage($manager, $project)->call('askRetireTemplateLine', $foreign->id)
        ->assertDispatched('toast-show', $notFound)
        ->assertSet('showRetireTemplateLineModal', false);

    expect($own->fresh()->status)->toBe(ReportTemplateLineStatus::Pending)
        ->and($foreign->fresh()->status)->toBe(ReportTemplateLineStatus::Pending)
        ->and($project->fresh()->status)->toBe(ProjectStatus::Approved);
});

test('retiring a confirmed line ends it this month', function () {
    ['project' => $project, 'template' => $template] = rtpProject(ProjectStatus::Active);
    $line = rtpLine($template, rtpProposal($project));
    $line->forceFill(['status' => ReportTemplateLineStatus::Confirmed])->save();

    rtpPage(mealManager(), $project)
        ->call('askRetireTemplateLine', $line->id)
        ->assertSet('showRetireTemplateLineModal', true)
        ->call('retireTemplateLine')
        ->assertSet('showRetireTemplateLineModal', false);

    expect($line->fresh()->status)->toBe(ReportTemplateLineStatus::Retired)
        ->and($line->fresh()->effective_to->toDateString())->toBe('2026-10-01');
});

test('unlinked targets, lines needing months and missing fixed denominators are flagged', function () {
    ['project' => $project, 'template' => $template] = rtpProject();
    $proposal = rtpProposal($project);
    $unlinkedLine = rtpLine($template, $proposal, linked: false);
    $seasonal = rtpLine($template, $proposal, ['frequency' => CollectionFrequency::PerSeason]);
    $fixed = rtpLine($template, $proposal, ['denominator_basis' => DenominatorBasis::Fixed, 'measure' => 'percentage'], attributes: ['fixed_denominator' => null]);
    Activity::withoutRecording(fn () => PlanIndicator::factory()->forProposal($proposal)->create([
        'indicator_id' => Indicator::factory()->create(['name' => 'Legacy target', 'library_indicator_id' => null])->id,
        'project_id' => $project->id,
    ]));

    rtpPage(mealManager(), $project)
        ->assertSeeHtml('data-template-flag="unlinked-'.$unlinkedLine->id.'"')
        ->assertSeeHtml('data-template-flag="months-'.$seasonal->id.'"')
        ->assertSeeHtml('data-template-flag="denominator-'.$fixed->id.'"')
        ->assertSeeHtml('data-template-unlinked-target')
        ->assertSee('Legacy target')
        ->assertSee(__('report_templates.link_library_first'))
        ->assertSee(__('report_templates.choose_measurement_months'))
        ->assertDontSeeHtml('value="'.$unlinkedLine->id.'" wire:model="selectedTemplateLineIds"');
});

test('the tab holds a flat query count whatever the number of proposals and lines', function () {
    ['project' => $project, 'template' => $template] = rtpProject();
    $evidence = EvidenceType::factory()->create();
    $manager = mealManager();

    $countQueries = function () use ($manager, $project): int {
        $page = rtpPage($manager, $project);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $page->call('$refresh');

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    rtpLine($template, rtpProposal($project, 'First'))->evidenceTypes()->attach($evidence->id);
    $small = $countQueries();

    foreach (['Second', 'Third'] as $name) {
        $proposal = rtpProposal($project, $name);

        foreach (range(1, 3) as $index) {
            rtpLine($template, $proposal)->evidenceTypes()->attach($evidence->id);
        }
    }

    expect($countQueries())->toBe($small);
});

test('the tab renders in Arabic', function () {
    app()->setLocale('ar');
    ['project' => $project, 'template' => $template] = rtpProject();
    $proposal = rtpProposal($project);
    rtpLine($template, $proposal, ['name_ar' => 'عدد الأسر الواصلة']);
    rtpLine($template, $proposal, linked: false);

    rtpPage(mealManager(), $project)
        ->assertSee('قالب التقرير الشهري')
        ->assertSee('أكّد الأسطر المحددة')
        ->assertSee('عدد الأسر الواصلة')
        ->assertSee('اربطه بالمكتبة أولًا')
        ->assertSee(__('enums.report_template_line_status.pending'));
});

test('a confirmed active template shows when its monthly reports are still being prepared', function () {
    ['project' => $project, 'template' => $template] = rtpProject(ProjectStatus::Active);
    $template->forceFill(['status' => ReportTemplateStatus::Confirmed])->save();
    $manager = mealManager();

    rtpPage($manager, $project)->assertSeeHtml('data-monthly-reports-preparing');

    MonthlyReport::factory()->for($project)->create(['period' => '2026-11-01']);

    rtpPage($manager, $project)->assertDontSeeHtml('data-monthly-reports-preparing');
});

test('the tab finds a template at the place of the project row and names its proposals', function () {
    ['project' => $project, 'template' => $legacy] = rtpProject();
    $legacy->delete();
    $proposal = rtpProposal($project, 'Place proposal');
    $row = proposalRowForProject($proposal, $project);
    $place = new ReportPlace((int) $project->city_id, (int) $project->program_id, $row->centers()->value('center_id'), false);
    $template = Activity::withoutRecording(fn (): ReportTemplate => ReportTemplate::factory()->forPlace($place, $proposal)->create());
    rtpLine($template, $proposal, ['name_en' => 'Place line'], attributes: []);

    rtpPage(mealManager(), $project)
        ->assertSeeHtml('data-test="template-proposals"')
        ->assertSee('Place proposal');
});

/**
 * An activity of the proposal with its calendar rows, the proposal carried by the template.
 *
 * @param  array<string, list<int>|null>  $months  first-of-month date => weeks
 */
function rtpCalendar(ReportTemplate $template, Proposal $proposal, string $statement, array $months, ?int $projectId = null): Result
{
    return Activity::withoutRecording(function () use ($template, $proposal, $statement, $months, $projectId): Result {
        if (! $template->proposals()->whereKey($proposal->id)->exists()) {
            $template->proposals()->attach($proposal->id, ['joined_at' => now()]);
        }

        $output = Result::factory()->type(ResultType::Output)->create(['proposal_id' => $proposal->id]);
        $activity = Result::factory()->activity($output)->create(['statement' => $statement]);

        foreach ($months as $month => $weeks) {
            ActivitySchedule::query()->create(['result_id' => $activity->id, 'project_id' => $projectId, 'month' => $month, 'weeks' => $weeks]);
        }

        return $activity;
    });
}

test('the activity calendar panel shows the scheduled months with an accessible mark and the weeks', function () {
    ['project' => $project, 'template' => $template] = rtpProject();
    // One proposal only: drop the one the factory carries a legacy template with (Y-03).
    $template->proposals()->detach();
    $activity = rtpCalendar($template, rtpProposal($project), 'Run parenting sessions', ['2026-11-01' => [1, 2], '2026-12-01' => null]);

    $page = rtpPage(mealManager(), $project)
        ->assertSeeHtml('data-activity-calendar')
        ->assertSee(__('report_templates.calendar.title'))
        ->assertSee('Run parenting sessions')
        ->assertSeeHtml('data-calendar-cell="'.$activity->id.'-2026-11"')
        ->assertSeeHtml('data-calendar-cell="'.$activity->id.'-2026-12"')
        ->assertDontSeeHtml('data-calendar-cell="'.$activity->id.'-2027-01"')
        ->assertSee(__('report_templates.calendar.scheduled_weeks', ['weeks' => '1–2']))
        ->assertSee(__('report_templates.calendar.scheduled'))
        ->assertDontSeeHtml('data-calendar-group');

    expect($page->html())->toContain('scope="col"')->toContain('scope="row"');
});

test('the activity calendar groups the activities by proposal when the template has more than one', function () {
    ['project' => $project, 'template' => $template] = rtpProject();
    rtpCalendar($template, rtpProposal($project, 'Alpha'), 'Alpha activity', ['2026-11-01' => null]);
    rtpCalendar($template, rtpProposal($project, 'Beta'), 'Beta activity', ['2026-12-01' => null]);

    rtpPage(mealManager(), $project)
        ->assertSeeHtml('data-calendar-group')
        ->assertSeeInOrder(['Alpha activity', 'Beta activity']);
});

test('the activity calendar says where the calendar is set when nothing is scheduled', function () {
    ['project' => $project, 'template' => $template] = rtpProject();
    $template->proposals()->attach(rtpProposal($project)->id, ['joined_at' => now()]);

    rtpPage(mealManager(), $project)
        ->assertSeeHtml('data-activity-calendar')
        ->assertSee(__('report_templates.calendar.empty'));
});

test('the activity calendar marks an activity adjusted by a center override and follows it', function () {
    ['project' => $project, 'template' => $template] = rtpProject();
    $activity = rtpCalendar($template, rtpProposal($project), 'Adjusted activity', ['2026-11-01' => null]);
    Activity::withoutRecording(fn () => ActivitySchedule::query()->create(['result_id' => $activity->id, 'project_id' => $project->id, 'month' => '2027-01-01', 'weeks' => [3]]));

    rtpPage(mealManager(), $project)
        ->assertSee(__('report_templates.calendar.adjusted_center'))
        ->assertSeeHtml('data-calendar-cell="'.$activity->id.'-2027-01"')
        ->assertDontSeeHtml('data-calendar-cell="'.$activity->id.'-2026-11"');
});

test('the activity calendar links to the proposal calendar only for someone who may edit it', function () {
    ['project' => $project, 'template' => $template] = rtpProject();
    rtpCalendar($template, rtpProposal($project), 'Linked activity', ['2026-11-01' => null]);

    $editor = mealManager();
    $editor->givePermissionTo('proposals.view');
    $owner = userWithRole('Team Member', $project->user_id);

    rtpPage($editor, $project)->assertSeeHtml('data-calendar-edit-link');
    rtpPage($owner, $project)->assertDontSeeHtml('data-calendar-edit-link')->assertSeeHtml('data-activity-calendar');
});

test('the activity calendar panel has no write action', function () {
    ['project' => $project, 'template' => $template] = rtpProject();
    rtpCalendar($template, rtpProposal($project), 'Read only activity', ['2026-11-01' => null]);

    $html = rtpPage(mealManager(), $project)->html();
    preg_match('/<section[^>]*data-activity-calendar.*?<\/section>/s', $html, $section);

    expect($section)->not->toBeEmpty()
        ->and($section[0])->not->toContain('wire:click')
        ->and($section[0])->not->toContain('wire:model');
});

test('the activity calendar holds a flat query count whatever the number of activities', function () {
    ['project' => $project, 'template' => $template] = rtpProject();
    $proposal = rtpProposal($project);
    $manager = mealManager();
    rtpCalendar($template, $proposal, 'First', ['2026-11-01' => null]);

    $countQueries = function () use ($manager, $project): int {
        $page = rtpPage($manager, $project);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $page->call('$refresh');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $small = $countQueries();

    foreach (range(1, 6) as $index) {
        rtpCalendar($template, $proposal, 'More '.$index, ['2026-11-01' => [1], '2026-12-01' => null]);
    }

    expect($countQueries())->toBe($small);
});

test('the activity calendar renders in Arabic', function () {
    app()->setLocale('ar');
    ['project' => $project, 'template' => $template] = rtpProject();
    rtpCalendar($template, rtpProposal($project), 'نشاط الدعم', ['2026-11-01' => [2]]);

    rtpPage(mealManager(), $project)
        ->assertSee(__('report_templates.calendar.title'))
        ->assertSee('نشاط الدعم');
});

/**
 * A question on the template, in the order given.
 *
 * @param  array<string, mixed>  $attributes
 */
function rtpQuestion(ReportTemplate $template, string $textEn, array $attributes = []): ReportTemplateQuestion
{
    return ReportTemplateQuestion::factory()->create(['report_template_id' => $template->id, 'text_en' => $textEn, ...$attributes]);
}

function rtpAnswer(ReportTemplateQuestion $question): void
{
    Activity::withoutRecording(fn () => MonthlyReportAnswer::factory()->create(['report_template_question_id' => $question->id]));
}

function rtpXpath(Testable $page): DOMXPath
{
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8"?>'.$page->html());

    return new DOMXPath($dom);
}

$questionToast = fn (string $text): Closure => fn (string $event, array $params): bool => ($params['slots']['text'] ?? null) === $text;

test('a manager sees the questions grouped by frequency with status words, origin, menus and arrows off at the ends', function () {
    ['project' => $project, 'template' => $template] = rtpProject();
    $bank = NarrativeQuestion::factory()->create();
    $first = rtpQuestion($template, 'What went well?', ['sort_order' => 1, 'narrative_question_id' => $bank->id]);
    $last = rtpQuestion($template, 'What was hard?', ['sort_order' => 2, 'is_active' => false]);
    $quarter = rtpQuestion($template, 'What did the quarter teach?', ['frequency' => NarrativeQuestionFrequency::QuarterEnd]);

    $page = rtpPage(mealManager(), $project)
        ->assertSeeHtml('data-test="template-add-question"')
        ->assertSee(__('enums.narrative_question_frequency.monthly'))
        ->assertSee(__('enums.narrative_question_frequency.quarter_end'))
        ->assertSee(__('narrative_questions.status_on'))
        ->assertSee(__('narrative_questions.status_off'))
        ->assertSee(__('report_templates.tab.question_from_bank'))
        ->assertSee(__('report_templates.tab.question_own'))
        ->assertSeeHtml('data-test="switch-off-template-question-'.$first->id.'"')
        ->assertSeeHtml('data-test="switch-on-template-question-'.$last->id.'"')
        ->assertSeeHtml('data-test="delete-template-question-'.$quarter->id.'"')
        ->assertSeeHtml('data-test="edit-template-question-'.$first->id.'"')
        ->assertDontSeeHtml('data-test="template-questions-shared"');

    $xpath = rtpXpath($page);
    $arrow = fn (ReportTemplateQuestion $question, string $direction): DOMElement => $xpath->query('//li[@data-template-question="'.$question->id.'"]//*[@data-test="move-template-question-'.$direction.'"]')->item(0);

    expect($xpath->query('//section[@data-frequency-group="monthly"]//li[@data-template-question]')->length)->toBe(2)
        ->and($xpath->query('//section[@data-frequency-group="quarter_end"]//li[@data-template-question]')->length)->toBe(1)
        ->and($xpath->query('//li[@data-template-question="'.$last->id.'"]')->item(0)->getAttribute('data-status'))->toBe('off')
        ->and($arrow($first, 'up')->hasAttribute('disabled'))->toBeTrue()
        ->and($arrow($first, 'down')->hasAttribute('disabled'))->toBeFalse()
        ->and($arrow($last, 'up')->hasAttribute('disabled'))->toBeFalse()
        ->and($arrow($last, 'down')->hasAttribute('disabled'))->toBeTrue()
        ->and($arrow($quarter, 'up')->hasAttribute('disabled'))->toBeTrue()
        ->and($arrow($quarter, 'down')->hasAttribute('disabled'))->toBeTrue();
});

test('a viewer reads the questions without any control', function () {
    ['project' => $project, 'template' => $template] = rtpProject();
    $question = rtpQuestion($template, 'What went well?');
    $owner = userWithRole('Team Member', $project->user_id);

    rtpPage($owner, $project)
        ->assertSee('What went well?')
        ->assertSee(__('narrative_questions.status_on'))
        ->assertDontSeeHtml('data-test="template-add-question"')
        ->assertDontSeeHtml('data-test="move-template-question-up"')
        ->assertDontSeeHtml('data-test="edit-template-question-'.$question->id.'"')
        ->assertDontSeeHtml('data-test="template-question-menu-'.$question->id.'"');

    $page = fn () => rtpPage($owner, $project);
    $page()->call('openAddTemplateQuestion')->assertForbidden();
    $page()->call('moveTemplateQuestion', $question->id, 'down')->assertForbidden();
    $page()->call('askDeleteTemplateQuestion', $question->id)->assertForbidden();

    expect(ReportTemplateQuestion::query()->whereKey($question->id)->exists())->toBeTrue();
});

test('a manager adds and edits a question through the dialog', function () {
    ['project' => $project, 'template' => $template] = rtpProject();
    $existing = rtpQuestion($template, 'What went well?', ['sort_order' => 1]);

    $page = rtpPage(mealManager(), $project)
        ->call('openAddTemplateQuestion')
        ->assertSet('showTemplateQuestionModal', true)
        ->assertSet('templateQuestionFrequency', 'monthly')
        ->call('saveTemplateQuestion')
        ->assertHasErrors(['templateQuestionTextAr'])
        ->set('templateQuestionTextAr', 'ما الجديد؟')
        ->set('templateQuestionTextEn', 'What is new?')
        ->call('saveTemplateQuestion')
        ->assertHasNoErrors()
        ->assertSet('showTemplateQuestionModal', false)
        ->assertSee('What is new?');

    $added = $template->questions()->where('text_en', 'What is new?')->sole();

    expect($added->narrative_question_id)->toBeNull()
        ->and($added->sort_order)->toBe(2);

    $page->call('openEditTemplateQuestion', $existing->id)
        ->assertSet('showTemplateQuestionModal', true)
        ->assertSet('templateQuestionTextEn', 'What went well?')
        ->set('templateQuestionTextEn', 'What went really well?')
        ->call('saveTemplateQuestion')
        ->assertHasNoErrors()
        ->assertSet('showTemplateQuestionModal', false);

    expect($existing->fresh()->text_en)->toBe('What went really well?');
});

test('the frequency of an answered question is refused on its field', function () {
    ['project' => $project, 'template' => $template] = rtpProject();
    $question = rtpQuestion($template, 'What went well?');
    rtpAnswer($question);

    rtpPage(mealManager(), $project)
        ->call('openEditTemplateQuestion', $question->id)
        ->set('templateQuestionFrequency', 'quarter_end')
        ->call('saveTemplateQuestion')
        ->assertHasErrors(['templateQuestionFrequency'])
        ->assertSet('showTemplateQuestionModal', true);

    expect($question->fresh()->frequency)->toBe(NarrativeQuestionFrequency::Monthly);
});

test('a manager moves a question and focus follows it', function () {
    ['project' => $project, 'template' => $template] = rtpProject();
    $first = rtpQuestion($template, 'What went well?', ['sort_order' => 1]);
    $second = rtpQuestion($template, 'What was hard?', ['sort_order' => 2]);

    rtpPage(mealManager(), $project)
        ->call('moveTemplateQuestion', $first->id, 'down')
        ->assertDispatched('template-question-moved', questionId: $first->id, direction: 'down')
        ->assertSet('templateQuestionAnnouncement', __('narrative_questions.moved_to', ['text' => 'What went well?', 'position' => 2, 'total' => 2, 'frequency' => __('enums.narrative_question_frequency.monthly')]));

    expect($template->questions()->ordered()->pluck('id')->all())->toBe([$second->id, $first->id]);
});

test('switching off goes through a dialog that names the question, and switching on is one click', function () {
    ['project' => $project, 'template' => $template] = rtpProject();
    $question = rtpQuestion($template, 'What went well?');

    $page = rtpPage(mealManager(), $project)
        ->call('askSwitchOffTemplateQuestion', $question->id)
        ->assertSet('showSwitchOffTemplateQuestionModal', true)
        ->assertSee('Switch off “What went well?”?')
        ->call('switchOffTemplateQuestion')
        ->assertSet('showSwitchOffTemplateQuestionModal', false);

    expect($question->fresh()->is_active)->toBeFalse();

    $page->call('switchOnTemplateQuestion', $question->id);

    expect($question->fresh()->is_active)->toBeTrue();
});

test('a question without answers is deleted after a dialog that names it', function () {
    ['project' => $project, 'template' => $template] = rtpProject();
    $question = rtpQuestion($template, 'What went well?');

    rtpPage(mealManager(), $project)
        ->call('askDeleteTemplateQuestion', $question->id)
        ->assertSet('showDeleteTemplateQuestionModal', true)
        ->assertSet('deletingTemplateQuestionHasAnswers', false)
        ->assertSee('Delete “What went well?”?')
        ->call('deleteTemplateQuestion')
        ->assertSet('showDeleteTemplateQuestionModal', false);

    expect(ReportTemplateQuestion::query()->whereKey($question->id)->exists())->toBeFalse();
});

test('an answered question cannot be deleted: its dialog offers to switch it off instead', function () use ($questionToast) {
    ['project' => $project, 'template' => $template] = rtpProject();
    $question = rtpQuestion($template, 'What went well?');
    rtpAnswer($question);

    rtpPage(mealManager(), $project)
        ->call('askDeleteTemplateQuestion', $question->id)
        ->assertSet('deletingTemplateQuestionHasAnswers', true)
        ->assertSee(__('report_templates.question_has_answers'))
        ->assertSee(__('narrative_questions.deactivate_instead'))
        ->assertSeeHtml('data-test="confirm-switch-off-instead-template-question"')
        ->call('switchOffInsteadOfDeleteTemplateQuestion')
        ->assertSet('showDeleteTemplateQuestionModal', false);

    expect($question->fresh()->is_active)->toBeFalse();

    $late = rtpQuestion($template, 'What was hard?');
    $page = rtpPage(mealManager(), $project)->call('askDeleteTemplateQuestion', $late->id);
    rtpAnswer($late);

    $page->call('deleteTemplateQuestion')
        ->assertDispatched('toast-show', $questionToast(__('report_templates.question_has_answers')));

    expect(ReportTemplateQuestion::query()->whereKey($late->id)->exists())->toBeTrue();
});

test('a question of another template is not found from this tab', function () use ($questionToast) {
    ['project' => $project] = rtpProject();
    ['template' => $otherTemplate] = rtpProject();
    $foreign = rtpQuestion($otherTemplate, 'Not yours');

    rtpPage(mealManager(), $project)
        ->call('askDeleteTemplateQuestion', $foreign->id)
        ->assertDispatched('toast-show', $questionToast(__('report_templates.tab.question_missing')))
        ->assertSet('showDeleteTemplateQuestionModal', false)
        ->call('moveTemplateQuestion', $foreign->id, 'up')
        ->assertDispatched('toast-show', $questionToast(__('report_templates.tab.question_missing')));

    expect(ReportTemplateQuestion::query()->whereKey($foreign->id)->exists())->toBeTrue();
});

test('a template covering two or more proposals says so once above the questions', function () {
    ['project' => $project, 'template' => $template] = rtpProject();
    // The factory carries a legacy template with a proposal of its own (Y-03); this test names its proposals.
    $template->proposals()->detach();
    rtpQuestion($template, 'What went well?');
    $first = rtpProposal($project, 'Winter WASH');
    $template->proposals()->attach($first->id, ['joined_at' => now()]);

    rtpPage(mealManager(), $project)->assertDontSeeHtml('data-test="template-questions-shared"');

    $second = rtpProposal($project, 'Spring WASH');
    $template->proposals()->attach($second->id, ['joined_at' => now()]);

    rtpPage(mealManager(), $project)
        ->assertSeeHtml('data-test="template-questions-shared"')
        ->assertSee(__('report_templates.tab.questions_shared', ['proposals' => 'Spring WASH'.__('report_templates.tab.list_separator').'Winter WASH']));
});

test('the template tab opens the selected template and rejects templates from another project', function () {
    $fixture = placementFixture();
    $manager = mealManager();
    $this->actingAs($manager);
    $prefiller = app(ReportTemplatePrefiller::class);
    $prefiller->prefillForProposal($fixture['proposals'][0]);
    $prefiller->prefillForProposal($fixture['proposals'][1]);
    $first = ReportTemplate::query()->sole();
    $second = app(CreateReportTemplateForProposal::class)->handle($fixture['proposals'][1], $first->place());
    $foreign = ReportTemplate::factory()->create();

    $page = rtpPage($manager, $fixture['project'])->assertSeeHtml('data-test="template-selector"')
        ->set('selectedReportTemplate', (string) $second->id)
        ->assertSeeHtml('data-template-line="'.$second->lines()->sole()->id.'"')
        ->assertDontSeeHtml('data-template-line="'.$first->lines()->sole()->id.'"');

    expect($page->instance()->reportTemplate->id)->toBe($second->id);
    $page->call('selectAllPendingTemplateLines')
        ->assertSet('selectedTemplateLineIds', [$second->lines()->sole()->id])
        ->call('askConfirmReportTemplate')
        ->assertSet('showTemplateConfirmModal', true)
        ->call('confirmReportTemplate')
        ->assertHasNoErrors();

    expect($second->fresh()->status)->toBe(ReportTemplateStatus::Confirmed)
        ->and($second->lines()->sole()->status)->toBe(ReportTemplateLineStatus::Confirmed)
        ->and($first->fresh()->status)->toBe(ReportTemplateStatus::Pending)
        ->and($first->lines()->sole()->status)->toBe(ReportTemplateLineStatus::Pending);

    $page->set('selectedReportTemplate', (string) $foreign->id);
    expect($page->instance()->reportTemplate->id)->toBe($first->id);
});
