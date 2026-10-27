<?php

use App\Enums\AssignmentStatus;
use App\Enums\CollectionFrequency;
use App\Enums\DenominatorBasis;
use App\Enums\LineSchedule;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Enums\ReportTemplateLineStatus;
use App\Enums\ReportTemplateStatus;
use App\Models\Activity;
use App\Models\EvidenceType;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\MealCoverageAssignment;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateLine;
use App\Models\ReportTemplateQuestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| The "Report template" tab on projects/show (contracts.md §12.4, T33 slice 3):
| lines grouped by proposal, edited and confirmed by the responsible MEAL
| officer or the MEAL Manager only, read-only for the owner, hidden from
| everyone else.
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
